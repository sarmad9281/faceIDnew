<?php
/**
 * SARMAD Attendance - stage 7 event dispatcher.
 *
 * THE seam between the attendance engine (stage 6) and the integrations
 * (Telegram now, payroll/webhooks later).
 *
 * The engine writes a durable attendance_events row for every attendance fact.
 * This dispatcher is the only reader:
 *
 *   attendance.entry.created      -> telegram "ورود ثبت شد"        (if wanted)
 *   attendance.exit.created       -> telegram "خروج ثبت شد"        (if wanted)
 *   attendance.late.detected      -> telegram "تأخیر"              (if wanted)
 *   attendance.overtime.detected  -> telegram "اضافه‌کاری"          (if wanted)
 *   attendance.absence.detected   -> payroll is computed on demand -> skipped
 *   others                        -> skipped (no consumer registered)
 *
 * Nothing here runs inside the attendance request: the kiosk never waits for
 * Telegram. The dispatcher is called by the cron worker (tools/telegram_worker.php)
 * and by the "send pending now" action of the panel.
 *
 * A row is only marked `sent` once its Telegram queue entry exists, so a crash
 * between the two steps can not lose a notification (the event stays pending).
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Support\Jalali;

final class EventDispatcher
{
    /**
     * Drain the attendance event queue into the integrations.
     *
     * @return array{scanned:int,notified:int,skipped:int,failed:int,rows:array<int,array<string,mixed>>}
     */
    public function dispatch(int $limit = 100): array
    {
        $rows = Database::select(
            'SELECT * FROM `' . Database::table('events') . '`'
            . " WHERE `status` = 'pending' AND `available_at` <= ?"
            . ' ORDER BY `id` ASC LIMIT ' . max(1, min(500, $limit)),
            [date('Y-m-d H:i:s')]
        );

        $report = ['scanned' => 0, 'notified' => 0, 'skipped' => 0, 'failed' => 0, 'rows' => []];
        $service = new TelegramService();

        foreach ($rows as $row) {
            $report['scanned']++;
            $result = $this->dispatchOne($service, $row);
            $report['rows'][] = $result;

            if ($result['status'] === 'notified') {
                $report['notified']++;
            } elseif ($result['status'] === 'skipped') {
                $report['skipped']++;
            } else {
                $report['failed']++;
            }
        }

        return $report;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function dispatchOne(TelegramService $service, array $row): array
    {
        $eventId    = (int) $row['id'];
        $customerId = (int) $row['customer_id'];
        $event      = (string) $row['event'];

        if (!in_array($event, TelegramNotifier::HANDLED, true)) {
            $this->mark($eventId, 'skipped', null, 'برای این رویداد مصرف‌کننده‌ای ثبت نشده است.');

            return ['event_id' => $eventId, 'event' => $event, 'status' => 'skipped', 'reason' => 'no_consumer'];
        }

        if (!$service->usable($customerId)) {
            $this->mark($eventId, 'skipped', null, 'تلگرام برای این مجموعه فعال یا تنظیم نشده است.');

            return ['event_id' => $eventId, 'event' => $event, 'status' => 'skipped', 'reason' => 'telegram_unavailable'];
        }

        $settings = $service->settings($customerId);

        if (!TelegramNotifier::wants($settings, $event)) {
            $this->mark($eventId, 'skipped', null, 'این اعلان توسط مدیر خاموش شده است.');

            return ['event_id' => $eventId, 'event' => $event, 'status' => 'skipped', 'reason' => 'notification_off'];
        }

        $context = $this->contextFor($row);
        $text    = TelegramNotifier::message($row, $context);
        $dedupe  = 'event:' . $eventId;

        $queued = $service->enqueue($customerId, $event, $dedupe, $text, '', [
            'event_id' => $eventId,
            'record_id' => (int) ($row['record_id'] ?? 0),
        ]);

        $this->mark($eventId, 'sent', $queued['queue_id'], null);

        return [
            'event_id'  => $eventId,
            'event'     => $event,
            'status'    => 'notified',
            'queue_id'  => $queued['queue_id'],
            'duplicate' => $queued['duplicate'],
        ];
    }

    /**
     * Facts used by the message text: employee name, branch, times and totals.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function contextFor(array $row): array
    {
        $recordId = (int) ($row['record_id'] ?? 0);
        $context  = [];

        if ($recordId > 0) {
            $record = Database::selectOne(
                'SELECT r.`attendance_date`, r.`entry_at`, r.`exit_at`, r.`work_minutes`, r.`overtime_minutes`,'
                . ' r.`late_minutes`, r.`source`, b.`name` AS branch_name'
                . ' FROM `' . Database::table('records') . '` r'
                . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`'
                . ' WHERE r.`id` = ? AND r.`customer_id` = ? LIMIT 1',
                [$recordId, (int) $row['customer_id']]
            );

            if ($record !== null) {
                $context = [
                    'attendance_date'  => (string) $record['attendance_date'],
                    'branch_name'      => (string) ($record['branch_name'] ?? ''),
                    'work_minutes'     => (int) $record['work_minutes'],
                    'overtime_minutes' => (int) $record['overtime_minutes'],
                    'late_minutes'     => (int) $record['late_minutes'],
                    'source'           => (string) $record['source'],
                ];
            }
        }

        $payload = json_decode((string) ($row['payload'] ?? ''), true);

        if (is_array($payload)) {
            foreach (['minutes', 'attendance_date', 'branch_name', 'work_minutes', 'overtime_minutes'] as $key) {
                if (isset($payload[$key]) && !isset($context[$key])) {
                    $context[$key] = $payload[$key];
                }
            }
        }

        $employeeId = (int) ($row['employee_id'] ?? 0);

        if ($employeeId > 0) {
            $employee = Database::selectOne(
                'SELECT `first_name`, `last_name` FROM `' . Database::table('employees') . '` WHERE `id` = ? LIMIT 1',
                [$employeeId]
            );

            if ($employee !== null) {
                $context['employee_name'] = Employee::fullName($employee);
            }
        }

        $event = (string) $row['event'];

        $stamp = $event === AttendanceEvent::EXIT_CREATED || $event === AttendanceEvent::OVERTIME_DETECTED
            ? (string) ($context['exit_time'] ?? '')
            : (string) ($context['entry_time'] ?? '');

        if ($stamp === '' && $recordId > 0) {
            $times = Database::selectOne(
                'SELECT `entry_at`, `exit_at` FROM `' . Database::table('records') . '` WHERE `id` = ? LIMIT 1',
                [$recordId]
            );

            $stamp = $times === null
                ? ''
                : (string) ($event === AttendanceEvent::EXIT_CREATED ? ($times['exit_at'] ?? '') : ($times['entry_at'] ?? ''));
        }

        $context['time'] = $stamp === '' ? '' : substr($stamp, 11, 5);
        $context['minutes'] = (int) ($context['minutes'] ?? ($event === AttendanceEvent::OVERTIME_DETECTED
            ? ($context['overtime_minutes'] ?? 0)
            : ($context['late_minutes'] ?? 0)));

        return $context;
    }

    private function mark(int $eventId, string $status, ?int $queueId, ?string $note): void
    {
        // attempts is incremented in SQL so two concurrent workers can not
        // overwrite each other's counter.
        Database::run(
            'UPDATE `' . Database::table('events') . '`'
            . ' SET `status` = ?, `sent_at` = ?, `attempts` = `attempts` + 1, `last_error` = ?'
            . ' WHERE `id` = ?',
            [$status, date('Y-m-d H:i:s'), $note === null ? null : substr($note, 0, 255), $eventId]
        );
    }
}
