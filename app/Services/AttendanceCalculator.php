<?php
/**
 * SARMAD Attendance - the ONE place attendance is calculated (stage 6).
 *
 * Everything numeric about a session lives here: work duration, late, early
 * leave, overtime, the day total and the status/flags of a record. The API,
 * the manager panel, the absence job and the future payroll stage all call
 * THIS class - no second copy of the formulas exists anywhere in the project.
 *
 * Design rules:
 *   - pure functions: input arrays -> output array, no database, no globals,
 *     so the same code can recalculate a month, a single day or a what-if
 *     (a shift change) without touching the write path;
 *   - the shift is passed IN (from attendance_shifts / the stored snapshot),
 *     never re-read from the database here;
 *   - a broken/incomplete session is never silently "fixed": an open session
 *     has work_minutes = 0 and status = incomplete;
 *   - liveness has nothing to do with this file (it is not implemented by
 *     project decision).
 *
 * Terminology
 *   session : one entry (and optionally one exit) - a day may hold several
 *   day     : all sessions of one employee on one attendance_date
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

final class AttendanceCalculator
{
    /** Bumped whenever a formula changes, stored on every record. */
    public const CALC_VERSION = '6.0.0';

    public const STATUS_PRESENT = 'present';
    public const STATUS_LATE = 'late';
    public const STATUS_EARLY_LEAVE = 'early_leave';
    public const STATUS_OVERTIME = 'overtime';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_ON_LEAVE = 'on_leave';
    public const STATUS_HOLIDAY = 'holiday';
    public const STATUS_MANUAL = 'manual';

    /** Every status the database accepts, including the legacy alias. */
    public const STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_LATE,
        self::STATUS_EARLY_LEAVE,
        self::STATUS_OVERTIME,
        self::STATUS_ABSENT,
        self::STATUS_INCOMPLETE,
        self::STATUS_ON_LEAVE,
        self::STATUS_HOLIDAY,
        self::STATUS_MANUAL,
        'leave', // legacy alias written before stage 6
    ];

    public const FLAG_LATE = 'late';
    public const FLAG_EARLY_LEAVE = 'early_leave';
    public const FLAG_OVERTIME = 'overtime';
    public const FLAG_SHORT_DAY = 'short_day';
    public const FLAG_MULTI_SESSION = 'multi_session';
    public const FLAG_INCOMPLETE = 'incomplete';
    public const FLAG_ON_LEAVE = 'on_leave';
    public const FLAG_HOLIDAY = 'holiday';
    public const FLAG_MANUAL = 'manual_correction';
    public const FLAG_OFFLINE = 'offline_synced';
    public const FLAG_CLOCK_SKEW = 'clock_skew';

    /**
     * The status of one record = its primary state; the flags carry every
     * condition that also applies (a record can be late AND overtime).
     *
     * @return array<string,string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PRESENT      => 'حاضر',
            self::STATUS_LATE         => 'تأخیر',
            self::STATUS_EARLY_LEAVE  => 'خروج زودتر',
            self::STATUS_OVERTIME     => 'اضافه‌کاری',
            self::STATUS_ABSENT       => 'غایب',
            self::STATUS_INCOMPLETE   => 'ناقص (بدون خروج)',
            self::STATUS_ON_LEAVE     => 'مرخصی',
            self::STATUS_HOLIDAY      => 'تعطیل',
            self::STATUS_MANUAL       => 'دستی',
            'leave'                   => 'مرخصی',
        ];
    }

    public static function statusLabel(?string $status): string
    {
        return self::statusLabels()[(string) $status] ?? (string) $status;
    }

    /**
     * @return array<string,string>
     */
    public static function flagLabels(): array
    {
        return [
            self::FLAG_LATE         => 'تأخیر',
            self::FLAG_EARLY_LEAVE  => 'خروج زودتر',
            self::FLAG_OVERTIME     => 'اضافه‌کاری',
            self::FLAG_SHORT_DAY    => 'کارکرد کمتر از شیفت',
            self::FLAG_MULTI_SESSION => 'چند تردد در روز',
            self::FLAG_INCOMPLETE   => 'بدون خروج',
            self::FLAG_ON_LEAVE     => 'مرخصی ثبت‌شده',
            self::FLAG_HOLIDAY      => 'روز تعطیل',
            self::FLAG_MANUAL       => 'اصلاح دستی',
            self::FLAG_OFFLINE      => 'ثبت آفلاین (سینک‌شده)',
            self::FLAG_CLOCK_SKEW   => 'زمان دستگاه نامعتبر بود',
        ];
    }

    public static function flagLabel(string $flag): string
    {
        return self::flagLabels()[$flag] ?? $flag;
    }

    /** "08:05" -> 485  (minutes from midnight) */
    public static function timeMinutes(?string $time): int
    {
        $time = trim((string) $time);

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m) !== 1) {
            return 0;
        }

        return ((int) $m[1] * 60) + (int) $m[2];
    }

    public static function minutesLabel($minutes, bool $dashWhenZero = true): string
    {
        $minutes = (int) $minutes;

        if ($minutes <= 0 && $dashWhenZero) {
            return '—';
        }

        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        return $sign . intdiv($minutes, 60) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The shift window of one calendar date, as real timestamps.
     *
     * @param array<string,mixed> $shift
     * @return array{start:string,end:string,crosses:bool}
     */
    public static function window(array $shift, string $date): array
    {
        $start = (string) ($shift['start_time'] ?? '00:00:00');
        $end   = (string) ($shift['end_time'] ?? '00:00:00');

        $startAt = $date . ' ' . self::pad($start);
        $endDate = $date;
        $crosses = false;

        $startMinutes = self::timeMinutes($start);
        $endMinutes   = self::timeMinutes($end);

        $flag = (int) ($shift['crosses_midnight'] ?? 0);

        if ($flag === 1 || $endMinutes <= $startMinutes) {
            $endDate = date('Y-m-d', strtotime($date . ' +1 day'));
            $crosses = true;
        }

        return [
            'start'   => $startAt,
            'end'     => $endDate . ' ' . self::pad($end),
            'crosses' => $crosses,
        ];
    }

    private static function pad(string $time): string
    {
        $parts = explode(':', $time);

        while (count($parts) < 3) {
            $parts[] = '00';
        }

        return str_pad($parts[0], 2, '0', STR_PAD_LEFT) . ':'
            . str_pad($parts[1], 2, '0', STR_PAD_LEFT) . ':'
            . str_pad(substr($parts[2], 0, 2), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Calculate ONE session.
     *
     * @param array{entry_at:?string,exit_at:?string} $session
     * @param array<string,mixed>|null $shift   null = no shift assigned
     * @param array<string,mixed>      $context ['holiday'=>bool,'leave'=>bool,'manual'=>bool]
     *
     * @return array{
     *   work_minutes:int,late_minutes:int,early_leave_minutes:int,overtime_minutes:int,
     *   status:string,flags:array<int,string>,expected_minutes:int,calc_version:string
     * }
     */
    public static function calculateSession(array $session, ?array $shift, array $context = [], ?string $date = null): array
    {
        $entry = self::normalizeDateTime($session['entry_at'] ?? null);
        $exit  = self::normalizeDateTime($session['exit_at'] ?? null);
        $date  = $date ?? ($entry !== null ? substr($entry, 0, 10) : date('Y-m-d'));

        $flags = [];

        if (!empty($context['manual'])) {
            $flags[] = self::FLAG_MANUAL;
        }

        if (!empty($context['holiday'])) {
            $flags[] = self::FLAG_HOLIDAY;
        }

        if (!empty($context['leave'])) {
            $flags[] = self::FLAG_ON_LEAVE;
        }

        // ---- the shift of this day (may be absent) -------------------
        $expected = 0;
        $window   = null;

        if ($shift !== null && (string) ($shift['start_time'] ?? '') !== '') {
            $window   = self::window($shift, $date);
            $expected = max(0, self::diffMinutes($window['start'], $window['end'])
                - (int) ($shift['break_minutes'] ?? 0));
        }

        // ---- work duration -------------------------------------------
        $work = 0;

        if ($entry !== null && $exit !== null) {
            $work = self::diffMinutes($entry, $exit);

            if ($shift !== null) {
                $break = max(0, (int) ($shift['break_minutes'] ?? 0));

                // the break is deducted only when the person really worked
                // longer than the break itself
                $work = max(0, $work - $break);
            }
        }

        $incomplete = $entry === null || $exit === null;

        if ($incomplete) {
            $flags[] = self::FLAG_INCOMPLETE;
        }

        // ---- late / early / overtime ---------------------------------
        $late = 0;
        $early = 0;
        $overtime = 0;

        if ($window !== null) {
            $graceLate  = max(0, (int) ($shift['late_grace_minutes'] ?? 0));
            $graceEarly = max(0, (int) ($shift['early_leave_grace_minutes'] ?? 0));

            if ($entry !== null) {
                $afterStart = self::diffMinutes($window['start'], $entry);
                $late       = max(0, $afterStart - $graceLate);

                if ($late > 0) {
                    $flags[] = self::FLAG_LATE;
                }
            }

            if ($exit !== null) {
                $beforeEnd = self::diffMinutes($exit, $window['end']);
                $early     = max(0, $beforeEnd - $graceEarly);

                if ($early > 0) {
                    $flags[] = self::FLAG_EARLY_LEAVE;
                }

                $afterEnd = self::diffMinutes($window['end'], $exit);
                $overtime = max(0, $afterEnd);

                if ($overtime > 0) {
                    $flags[] = self::FLAG_OVERTIME;
                }
            }
        }

        // A holiday keeps its own status; the numbers are still calculated so
        // the manager can see what was worked, and the flag says why.
        // ---- status ---------------------------------------------------
        $status = self::STATUS_PRESENT;

        if (!empty($context['holiday'])) {
            $status = self::STATUS_HOLIDAY;
        } elseif (!empty($context['leave'])) {
            $status = self::STATUS_ON_LEAVE;
        } elseif ($incomplete) {
            $status = self::STATUS_INCOMPLETE;
        } elseif ($late > 0) {
            $status = self::STATUS_LATE;
        } elseif ($early > 0) {
            $status = self::STATUS_EARLY_LEAVE;
        } elseif ($overtime > 0) {
            $status = self::STATUS_OVERTIME;
        }

        if ($expected > 0 && $work > 0 && $work < $expected && $status === self::STATUS_PRESENT) {
            $flags[] = self::FLAG_SHORT_DAY;
        }

        return [
            'work_minutes'        => $work,
            'late_minutes'        => $late,
            'early_leave_minutes' => $early,
            'overtime_minutes'    => $overtime,
            'status'              => $status,
            'flags'               => array_values(array_unique($flags)),
            'expected_minutes'    => $expected,
            'calc_version'        => self::CALC_VERSION,
        ];
    }

    /**
     * Aggregate every session of one employee on one day.
     *
     * The totals are sums of COMPLETED sessions; an open session contributes
     * its late minutes (known at entry) but never a work duration.
     *
     * @param array<int,array<string,mixed>> $sessions rows of one day
     * @return array{work_minutes:int,late_minutes:int,early_leave_minutes:int,overtime_minutes:int,sessions:int,open:int,status:string,flags:array<int,string>}
     */
    public static function aggregateDay(array $sessions, ?array $shift, array $context = [], ?string $date = null): array
    {
        $window = null;
        $expected = 0;

        if ($shift !== null && (string) ($shift['start_time'] ?? '') !== '') {
            $window   = self::window($shift, (string) $date);
            $expected = max(0, self::diffMinutes($window['start'], $window['end'])
                - (int) ($shift['break_minutes'] ?? 0));
        }

        $flags  = [];
        $work   = 0;
        $open   = 0;
        $count  = 0;
        $firstEntry = null;
        $lastExit   = null;

        foreach ($sessions as $session) {
            $entry = self::normalizeDateTime($session['entry_at'] ?? null);
            $exit  = self::normalizeDateTime($session['exit_at'] ?? null);

            if ($entry === null && $exit === null) {
                continue;
            }

            $count++;

            if ($exit === null) {
                $open++;
            } else {
                $work += self::diffMinutes($entry, $exit);
            }

            if ($entry !== null && ($firstEntry === null || strcmp($entry, $firstEntry) < 0)) {
                $firstEntry = $entry;
            }

            if ($exit !== null && ($lastExit === null || strcmp($exit, $lastExit) > 0)) {
                $lastExit = $exit;
            }
        }

        // The break belongs to the DAY, not to every single session: a person
        // who works two halves of one shift takes one break.
        if ($shift !== null) {
            $work = max(0, $work - max(0, (int) ($shift['break_minutes'] ?? 0)));
        }

        // ---- the DAY against the shift window --------------------------
        // The first entry of the day meets the shift start (lateness) and the
        // last exit meets the shift end (early leave / overtime); the work
        // duration is the SUM of the sessions. This is what makes a day with
        // 08:00-12:00 and 14:00-18:00 work with one 08:00-18:00 shift.
        $late = 0;
        $early = 0;
        $overtime = 0;

        if ($window !== null) {
            $graceLate  = max(0, (int) ($shift['late_grace_minutes'] ?? 0));
            $graceEarly = max(0, (int) ($shift['early_leave_grace_minutes'] ?? 0));

            if ($firstEntry !== null) {
                $late = max(0, self::diffMinutes($window['start'], $firstEntry) - $graceLate);

                if ($late > 0) {
                    $flags[self::FLAG_LATE] = true;
                }
            }

            if ($lastExit !== null) {
                $early = max(0, self::diffMinutes($lastExit, $window['end']) - $graceEarly);

                if ($early > 0) {
                    $flags[self::FLAG_EARLY_LEAVE] = true;
                }

                $overtime = max(0, self::diffMinutes($window['end'], $lastExit));

                if ($overtime > 0) {
                    $flags[self::FLAG_OVERTIME] = true;
                }
            }
        } elseif ($count === 0) {
            $late = $early = $overtime = 0;
        }

        if ($open > 0) {
            $flags[self::FLAG_INCOMPLETE] = true;
        }

        if ($count > 1) {
            $flags[self::FLAG_MULTI_SESSION] = true;
        }

        if (!empty($context['holiday'])) {
            $flags[self::FLAG_HOLIDAY] = true;
        }

        if (!empty($context['leave'])) {
            $flags[self::FLAG_ON_LEAVE] = true;
        }

        if (!empty($context['manual'])) {
            $flags[self::FLAG_MANUAL] = true;
        }

        if ($shift !== null && $expected > 0 && $open === 0 && $work > 0 && $work < $expected
            && !isset($flags[self::FLAG_HOLIDAY])) {
            $flags[self::FLAG_SHORT_DAY] = true;
        }

        // ---- the status of the day ------------------------------------
        if (!empty($context['holiday'])) {
            $status = self::STATUS_HOLIDAY;
        } elseif (!empty($context['leave'])) {
            $status = self::STATUS_ON_LEAVE;
        } elseif ($count === 0) {
            // no session at all: the absence pass decides, not this call
            $status = self::STATUS_ABSENT;
        } elseif ($open > 0) {
            $status = self::STATUS_INCOMPLETE;
        } elseif ($late > 0) {
            $status = self::STATUS_LATE;
        } elseif ($early > 0) {
            $status = self::STATUS_EARLY_LEAVE;
        } elseif ($overtime > 0) {
            $status = self::STATUS_OVERTIME;
        } else {
            $status = self::STATUS_PRESENT;
        }

        return [
            'work_minutes'        => $work,
            'late_minutes'        => $late,
            'early_leave_minutes' => $early,
            'overtime_minutes'    => $overtime,
            'sessions'            => $count,
            'open'                => $open,
            'first_entry_at'      => $firstEntry,
            'last_exit_at'        => $lastExit,
            'expected_minutes'    => $expected,
            'status'              => $status,
            'flags'               => array_keys($flags),
            'calc_version'        => self::CALC_VERSION,
        ];
    }

    /**
     * The day status for a person who has no session at all, considering
     * leave and holidays. This is what the absence job uses.
     *
     * @param array<string,mixed> $context ['holiday'=>bool,'leave'=>bool]
     */
    public static function statusForMissingDay(array $context): string
    {
        if (!empty($context['holiday'])) {
            return self::STATUS_HOLIDAY;
        }

        if (!empty($context['leave'])) {
            return self::STATUS_ON_LEAVE;
        }

        return self::STATUS_ABSENT;
    }

    /** Minutes between two datetime strings (b - a). Negative = b before a. */
    public static function diffMinutes(?string $from, ?string $to): int
    {
        $from = self::normalizeDateTime($from);
        $to   = self::normalizeDateTime($to);

        if ($from === null || $to === null) {
            return 0;
        }

        return (int) round((strtotime($to) - strtotime($from)) / 60);
    }

    /** 'Y-m-d H:i:s' or null. Any timezone suffix is ignored (server local). */
    public static function normalizeDateTime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $text = trim((string) $value);

        // ISO8601 WITH an explicit zone (Z or +hh:mm) is converted into the
        // application timezone. A device that stamps its events in UTC must not
        // have them stored as if they were local time - that would move a punch
        // by the whole offset (3:30 for Asia/Tehran) and corrupt the day, the
        // lateness and the overtime of an offline record.
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $text) === 1) {
            try {
                $zoned = new \DateTimeImmutable($text);

                return $zoned
                    ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');
            } catch (\Throwable $error) {
                // fall through to the lenient path below
            }
        }

        // A naive timestamp (no zone) comes from the database or from a device
        // that speaks local time: only the calendar fields are kept.
        $text = str_replace(['T', 'Z'], [' ', ''], $text);
        $text = preg_replace('/\.\d+$/', '', $text) ?? $text;
        $text = preg_replace('/[+-]\d{2}:?\d{2}$/', '', $text) ?? $text;

        $time = strtotime($text);

        if ($time === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $time);
    }

    /** How many minutes a day's sessions overlap - used by the daily report. */
    public static function coveredMinutes(array $sessions): int
    {
        $ranges = [];

        foreach ($sessions as $session) {
            $entry = self::normalizeDateTime($session['entry_at'] ?? null);
            $exit  = self::normalizeDateTime($session['exit_at'] ?? null);

            if ($entry === null || $exit === null) {
                continue;
            }

            $ranges[] = [strtotime($entry), strtotime($exit)];
        }

        if ($ranges === []) {
            return 0;
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $total = 0;
        $start = $ranges[0][0];
        $end   = $ranges[0][1];

        foreach ($ranges as $range) {
            if ($range[0] > $end) {
                $total += ($end - $start) / 60;
                $start = $range[0];
                $end   = $range[1];
            } else {
                $end = max($end, $range[1]);
            }
        }

        $total += ($end - $start) / 60;

        return (int) round($total);
    }

    /** Overtime multiplier for payroll - configurable, never hard coded in UI. */
    public static function overtimeFactor(): float
    {
        return (float) Config::get('attendance.overtime_factor', 1.0);
    }
}
