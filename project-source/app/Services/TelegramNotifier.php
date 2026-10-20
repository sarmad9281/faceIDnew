<?php
/**
 * SARMAD Attendance - stage 7 Telegram message builder.
 *
 * The single place that turns a real attendance fact into the Persian text
 * that is stored in the outbox (attendance_telegram_queue) and sent by the
 * worker. It reads the real customer/employee facts it is given and never
 * invents numbers.
 *
 * Lives in its own file, because it is used by the scheduler (daily report)
 * as well as by the event dispatcher - both load it through the autoloader.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Support\Jalali;

final class TelegramNotifier
{
    public const HANDLED = [
        AttendanceEvent::ENTRY_CREATED,
        AttendanceEvent::EXIT_CREATED,
        AttendanceEvent::LATE_DETECTED,
        AttendanceEvent::OVERTIME_DETECTED,
    ];

    /**
     * The notification switch that decides whether an event is wanted.
     */
    public static function wants(array $settings, string $event): bool
    {
        return match ($event) {
            AttendanceEvent::ENTRY_CREATED    => (int) ($settings['notify_entry'] ?? 0) === 1,
            AttendanceEvent::EXIT_CREATED     => (int) ($settings['notify_exit'] ?? 0) === 1,
            AttendanceEvent::LATE_DETECTED    => (int) ($settings['notify_late'] ?? 0) === 1,
            AttendanceEvent::OVERTIME_DETECTED => (int) ($settings['notify_overtime'] ?? 0) === 1,
            default => false,
        };
    }

    /**
     * Build the Persian message of one attendance event.
     *
     * @param array<string,mixed> $eventRow attendance_events row
     * @param array<string,mixed> $context  employee / branch / record facts
     */
    public static function message(array $eventRow, array $context): string
    {
        $event    = (string) $eventRow['event'];
        $employee = (string) ($context['employee_name'] ?? 'پرسنل');
        $date     = Jalali::date((string) ($context['attendance_date'] ?? date('Y-m-d')));
        $time     = (string) ($context['time'] ?? '');
        $branch   = (string) ($context['branch_name'] ?? '');
        $minutes  = (int) ($context['minutes'] ?? 0);

        $lines = [];

        switch ($event) {
            case AttendanceEvent::ENTRY_CREATED:
                $lines[] = 'ورود ثبت شد';
                $lines[] = '';
                $lines[] = 'کارمند: ' . $employee;
                $lines[] = 'تاریخ: ' . $date;
                $lines[] = 'ساعت: ' . $time;

                if ($branch !== '') {
                    $lines[] = 'شعبه: ' . $branch;
                }

                break;

            case AttendanceEvent::EXIT_CREATED:
                $lines[] = 'خروج ثبت شد';
                $lines[] = '';
                $lines[] = 'کارمند: ' . $employee;
                $lines[] = 'تاریخ: ' . $date;
                $lines[] = 'ساعت: ' . $time;
                $lines[] = 'مدت کار: ' . self::minutesLabel((int) ($context['work_minutes'] ?? 0));
                $lines[] = 'اضافه‌کاری: ' . self::minutesLabel((int) ($context['overtime_minutes'] ?? 0));

                break;

            case AttendanceEvent::LATE_DETECTED:
                $lines[] = 'تأخیر ثبت شد';
                $lines[] = '';
                $lines[] = 'کارمند: ' . $employee;
                $lines[] = 'تاریخ: ' . $date;
                $lines[] = 'ساعت ورود: ' . $time;
                $lines[] = 'میزان تأخیر: ' . self::minutesLabel($minutes);

                break;

            case AttendanceEvent::OVERTIME_DETECTED:
                $lines[] = 'اضافه‌کاری ثبت شد';
                $lines[] = '';
                $lines[] = 'کارمند: ' . $employee;
                $lines[] = 'تاریخ: ' . $date;
                $lines[] = 'ساعت خروج: ' . $time;
                $lines[] = 'میزان اضافه‌کاری: ' . self::minutesLabel($minutes);

                break;

            default:
                $lines[] = (string) ($eventRow['event'] ?? 'رویداد حضور');
        }

        if ((string) ($context['source'] ?? '') === 'device_offline') {
            $lines[] = '';
            $lines[] = 'منبع: ثبت آفلاین (هم‌گام‌شده)';
        }

        return implode("\n", $lines);
    }

    public static function minutesLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return '۰ دقیقه';
        }

        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        if ($hours === 0) {
            return $rest . ' دقیقه';
        }

        return $hours . ' ساعت' . ($rest > 0 ? ' و ' . $rest . ' دقیقه' : '');
    }
}
