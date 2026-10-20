<?php
/**
 * SARMAD Attendance - stage 7 Telegram daily report scheduler.
 *
 * The cron runs `tools/telegram_worker.php` every minute (DirectAdmin:
 * `*​/1 * * * *`). Each run asks this class whether any customer's daily report
 * is due; the default is 23:55 local time and a manager can change it per
 * customer.
 *
 * IDEMPOTENCY
 * ---------------------------------------------------------------------
 * attendance_telegram_reports has UNIQUE (customer_id, report_date, report_type).
 * A row is claimed BEFORE the message is queued, so no matter how often the
 * cron fires (or how many workers run at once) the summary of a given day is
 * sent exactly once. A FAILED report is retried at most MAX_ATTEMPTS times and
 * then left failed for the operator to see.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Security;
use App\Support\Jalali;
use Throwable;

final class TelegramScheduler
{
    public const REPORT_TYPE = 'daily';
    public const MAX_ATTEMPTS = 3;

    /** Queue states that mean "this message is already on its way". */
    private const IN_FLIGHT = ['pending', 'sent'];

    /**
     * Send every report that is due right now.
     *
     * @param string|null $now injectable clock (tests)
     * @return array{due:int,sent:int,skipped:int,failed:int,rows:array<int,array<string,mixed>>}
     */
    public function runDue(?string $now = null, ?TelegramService $service = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');

        $report = ['due' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'reconciled' => 0, 'rows' => []];
        $service = $service ?? new TelegramService();

        // the outbox may have finished (or given up) since the last run
        $report['reconciled'] = $this->reconcile()['updated'];

        foreach ($this->dueCandidates($now) as $candidate) {
            $report['due']++;
            $result = $this->sendFor($service, (int) $candidate['customer_id'], (string) $candidate['report_date'], (string) $candidate['report_time']);
            $report['rows'][] = $result;

            if ($result['status'] === 'sent') {
                $report['sent']++;
            } elseif ($result['status'] === 'failed') {
                $report['failed']++;
            } else {
                $report['skipped']++;
            }
        }

        return $report;
    }

    /**
     * Force the daily report of one customer/date (the "send now" button).
     *
     * @return array<string,mixed>
     */
    public function sendNow(int $customerId, string $date, ?TelegramService $service = null): array
    {
        return $this->sendFor($service ?? new TelegramService(), $customerId, $date, '', true);
    }

    /**
     * Reconcile the daily-report ledger with the outbox: the ledger is the
     * idempotency guard, the queue owns the delivery, so after every worker run
     * a report whose message was delivered (or gave up) must say so.
     *
     * @return array{checked:int,updated:int,sent:int,failed:int}
     */
    public function reconcile(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        $rows = Database::select(
            'SELECT r.`id` AS ledger_id, r.`customer_id`, r.`report_date`, r.`status` AS ledger_status, r.`attempts` AS ledger_attempts,'
            . ' q.`id` AS queue_id, q.`status` AS queue_status, q.`attempts` AS queue_attempts,'
            . ' q.`message_id`, q.`last_error`, q.`sent_at`'
            . ' FROM `' . Database::table('telegram_reports') . '` r'
            . ' JOIN `' . Database::table('telegram_queue') . '` q'
            . '   ON q.`customer_id` = r.`customer_id` AND q.`dedupe_key` = CONCAT(?, r.`report_date`)'
            . ' WHERE r.`report_type` = ? AND r.`status` <> ?'
            . '   AND q.`status` IN (?, ?)'
            . ' ORDER BY r.`id` ASC LIMIT ' . $limit,
            ['daily:', self::REPORT_TYPE, 'sent', TelegramService::STATUS_SENT, TelegramService::STATUS_FAILED]
        );

        $report = ['checked' => count($rows), 'updated' => 0, 'sent' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            // already reconciled in an earlier pass - do not touch it again
            if ((string) $row['queue_status'] === TelegramService::STATUS_FAILED && (string) $row['ledger_status'] === 'failed') {
                $report['checked']--;
                continue;
            }

            $sent = (string) $row['queue_status'] === TelegramService::STATUS_SENT;

            Database::update(Database::table('telegram_reports'), [
                'status'     => $sent ? 'sent' : 'failed',
                'sent_at'    => $sent ? ($row['sent_at'] ?? date('Y-m-d H:i:s')) : null,
                'message_id' => $sent ? $row['message_id'] : null,
                'last_error' => $sent ? null : substr((string) ($row['last_error'] ?? ''), 0, 255),
                'attempts'   => max((int) $row['ledger_attempts'], (int) $row['queue_attempts']),
            ], ['id' => (int) $row['ledger_id']]);

            $report['updated']++;
            $sent ? $report['sent']++ : $report['failed']++;
        }

        return $report;
    }

    /**
     * Which customers still need their report.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueCandidates(string $now): array
    {
        $today = substr($now, 0, 10);
        $clock = substr($now, 11, 5);

        // Every minute, but attendance data of "today" is only summed AFTER the
        // work day; a report for a past day that failed is retried as well.
        $rows = Database::select(
            'SELECT s.`customer_id`, s.`daily_report_time`'
            . ' FROM `' . Database::table('telegram_settings') . '` s'
            . ' WHERE s.`is_active` = 1 AND s.`daily_report` = 1 AND s.`chat_id` IS NOT NULL AND s.`chat_id` <> \'\''
            . ' ORDER BY s.`customer_id` ASC LIMIT 500'
        );

        $due = [];

        foreach ($rows as $row) {
            $customerId = (int) $row['customer_id'];

            if (!FeatureService::allows($customerId, 'telegram')) {
                continue;
            }

            $time = (string) $row['daily_report_time'];

            // due when the clock has passed the configured time today, or when
            // an earlier report of this customer is still waiting/failed.
            $passedTime = $clock >= $time;
            $pending    = $this->hasUnfinished($customerId, $today);

            if ($passedTime || $pending) {
                $due[] = [
                    'customer_id' => $customerId,
                    'report_date' => $today,
                    'report_time' => $time,
                ];
            }
        }

        return $due;
    }

    private function hasUnfinished(int $customerId, string $date): bool
    {
        $row = Database::selectOne(
            'SELECT `id` FROM `' . Database::table('telegram_reports') . '`'
            . " WHERE `customer_id` = ? AND `report_date` = ? AND `report_type` = ? AND `status` = ? AND `attempts` < ? LIMIT 1",
            [$customerId, $date, self::REPORT_TYPE, 'failed', self::MAX_ATTEMPTS]
        );

        return $row !== null;
    }

    /**
     * @return array<string,mixed>
     */
    private function sendFor(TelegramService $service, int $customerId, string $date, string $time, bool $force = false): array
    {
        if (!FeatureService::allows($customerId, 'telegram')) {
            return ['customer_id' => $customerId, 'status' => 'skipped', 'reason' => 'feature_disabled'];
        }

        if (!$service->usable($customerId)) {
            return ['customer_id' => $customerId, 'status' => 'skipped', 'reason' => 'telegram_unavailable'];
        }

        $report = $this->claim($customerId, $date);

        if ($report === null) {
            return ['customer_id' => $customerId, 'status' => 'skipped', 'reason' => 'already_sent_or_in_flight', 'report_date' => $date];
        }

        $reportId = (int) $report['id'];
        $attempts = (int) $report['attempts'];

        if ($attempts >= self::MAX_ATTEMPTS && !$force) {
            return ['customer_id' => $customerId, 'status' => 'failed', 'reason' => 'max_attempts_reached', 'report_date' => $date];
        }

        $summary = $this->summary($customerId, $date);
        $text    = $this->message($customerId, $date, $summary);

        // The queue row is the delivery attempt; the ledger keeps the report
        // itself idempotent even if the queue is empty for a while. The enqueue
        // happens BEFORE the attempt counter moves so that a refused enqueue
        // never looks like a delivery attempt.
        $queued = $service->enqueue($customerId, 'telegram.daily_report', 'daily:' . $date, $text, '', [
            'report_date' => $date,
        ], $force);

        if (!$queued['queued']) {
            if (!empty($queued['duplicate'])) {
                // Somebody else queued it, or it is still waiting/failed: the
                // report is already in flight, so this is NOT a second attempt.
                $this->reconcile(1);
                $current = Database::selectOne(
                    'SELECT `status` FROM `' . Database::table('telegram_reports') . '` WHERE `id` = ? LIMIT 1',
                    [$reportId]
                );
                $status = (string) ($current['status'] ?? 'pending');

                if ($status === 'failed') {
                    return ['customer_id' => $customerId, 'status' => 'failed', 'reason' => 'queue_failed', 'report_date' => $date];
                }

                return ['customer_id' => $customerId, 'status' => 'skipped', 'reason' => 'already_sent_or_in_flight', 'report_date' => $date];
            }

            $this->finish($reportId, 'failed', 'صف ارسال ساخته نشد.');

            return ['customer_id' => $customerId, 'status' => 'failed', 'reason' => 'queue_failed', 'report_date' => $date];
        }

        Database::update(Database::table('telegram_reports'), [
            'payload'  => json_encode($summary, JSON_UNESCAPED_UNICODE),
            'attempts' => $attempts + 1,
        ], ['id' => $reportId]);

        // Send immediately in the same run; the queue stays as the retry path.
        $drain = $service->drain(5);
        $sent  = false;

        foreach ($drain['rows'] as $row) {
            if ((int) ($row['queue_id'] ?? 0) === (int) $queued['queue_id'] && ($row['status'] ?? '') === 'sent') {
                $sent = true;
            }
        }

        if ($sent) {
            $sentRow = Database::selectOne(
                'SELECT `message_id` FROM `' . Database::table('telegram_queue') . '` WHERE `id` = ? LIMIT 1',
                [(int) $queued['queue_id']]
            );

            $this->finish($reportId, 'sent', null, $sentRow === null ? null : (string) $sentRow['message_id']);

            return ['customer_id' => $customerId, 'status' => 'sent', 'report_date' => $date, 'summary' => $summary];
        }

        // The queue holds it for retry; do not mark the ledger failed yet, the
        // worker will finish it. Marking it failed would allow a second send.
        Logger::channel('telegram')->info('Daily report queued, not delivered in this run', [
            'customer_id' => $customerId,
            'report_date' => $date,
        ]);

        return ['customer_id' => $customerId, 'status' => 'queued', 'report_date' => $date, 'queue_id' => $queued['queue_id']];
    }

    /**
     * Claim today's report row. Returns null when another worker already has it
     * (or it was already sent).
     *
     * @return array<string,mixed>|null
     */
    private function claim(int $customerId, string $date): ?array
    {
        $existing = Database::selectOne(
            'SELECT * FROM `' . Database::table('telegram_reports') . '`'
            . ' WHERE `customer_id` = ? AND `report_date` = ? AND `report_type` = ? LIMIT 1',
            [$customerId, $date, self::REPORT_TYPE]
        );

        if ($existing === null) {
            try {
                Database::insert(Database::table('telegram_reports'), [
                    'uuid'        => Security::uuid4(),
                    'customer_id' => $customerId,
                    'report_date' => $date,
                    'report_type' => self::REPORT_TYPE,
                    'status'      => 'pending',
                    'attempts'    => 0,
                ]);
            } catch (Throwable $error) {
                // Unique key: another worker claimed it in the same second.
                return null;
            }

            return Database::selectOne(
                'SELECT * FROM `' . Database::table('telegram_reports') . '`'
                . ' WHERE `customer_id` = ? AND `report_date` = ? AND `report_type` = ? LIMIT 1',
                [$customerId, $date, self::REPORT_TYPE]
            );
        }

        if ((string) $existing['status'] === 'sent') {
            return null;
        }

        // In flight: the message of this day is still in the outbox (pending) or
        // was already delivered (sent). Claiming it again would send a second
        // copy. Only a FAILED row may be claimed again (the manual send button
        // re-arms it).
        $queue = Database::selectOne(
            'SELECT `status` FROM `' . Database::table('telegram_queue') . '` WHERE `customer_id` = ? AND `dedupe_key` = ? LIMIT 1',
            [$customerId, 'daily:' . $date]
        );

        if ($queue !== null && in_array((string) $queue['status'], self::IN_FLIGHT, true)) {
            return null;
        }

        return $existing;
    }

    private function finish(int $reportId, string $status, ?string $error, ?string $messageId = null): void
    {
        Database::update(Database::table('telegram_reports'), [
            'status'     => $status,
            'sent_at'    => $status === 'sent' ? date('Y-m-d H:i:s') : null,
            'message_id' => $status === 'sent' ? ($messageId === null ? null : substr($messageId, 0, 64)) : null,
            'last_error' => $error === null ? null : substr($error, 0, 255),
        ], ['id' => $reportId]);
    }

    /**
     * The numbers of the daily report - same source as the panel and Excel.
     *
     * @return array<string,int|string>
     */
    public function summary(int $customerId, string $date): array
    {
        $reports = new ReportService();
        $data    = $reports->daily($customerId, ['date_from' => $date, 'date_to' => $date], 1, 10);
        $summary = $data['summary'];

        return [
            'report_date'      => $date,
            'employees'        => (int) $summary['employees'],
            'present'          => (int) $summary['present_days'],
            'absent'           => (int) $summary['absent_days'],
            'on_leave'         => (int) $summary['leave_days'],
            'late_count'       => (int) $summary['late_count'],
            'late_minutes'     => (int) $summary['late_minutes'],
            'overtime_minutes' => (int) $summary['overtime_minutes'],
            'work_minutes'     => (int) $summary['work_minutes'],
        ];
    }

    /**
     * @param array<string,int|string> $summary
     */
    public function message(int $customerId, string $date, array $summary): string
    {
        $customer = Database::selectOne(
            'SELECT `name` FROM `' . Database::table('customers') . '` WHERE `id` = ? LIMIT 1',
            [$customerId]
        );

        $lines = [
            'گزارش روزانه حضور و غیاب',
            'مجموعه: ' . (string) ($customer['name'] ?? '—'),
            'تاریخ: ' . Jalali::date($date),
            '',
            'تعداد پرسنل: ' . (int) $summary['employees'],
            'حاضر: ' . (int) $summary['present'],
            'غایب: ' . (int) $summary['absent'],
            'مرخصی: ' . (int) $summary['on_leave'],
            'تأخیر: ' . (int) $summary['late_count'] . ' مورد / ' . TelegramNotifier::minutesLabel((int) $summary['late_minutes']),
            'اضافه‌کاری: ' . TelegramNotifier::minutesLabel((int) $summary['overtime_minutes']),
            'مجموع کارکرد: ' . TelegramNotifier::minutesLabel((int) $summary['work_minutes']),
        ];

        return implode("\n", $lines);
    }
}
