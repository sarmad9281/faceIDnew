<?php
/**
 * SARMAD Attendance - Gregorian <-> Jalali (Shamsi) display helper.
 *
 * The database always stores real DATE columns (Gregorian) - this class only
 * converts for the user interface, so "1404/07/14" can be shown next to
 * "2025-10-06" without ever putting a text date into a query.
 */

declare(strict_types=1);

namespace App\Support;

final class Jalali
{
    /** @var array<int,string> */
    private const MONTHS = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];

    /**
     * @return array{0:int,1:int,2:int} [year, month, day]
     */
    public static function fromGregorian(int $year, int $month, int $day): array
    {
        $gregorianDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $gy = $year - 1600;
        $gm = $month - 1;
        $gd = $day - 1;

        $gDayNo = 365 * $gy + intdiv($gy + 3, 4) - intdiv($gy + 99, 100) + intdiv($gy + 399, 400)
            + $gregorianDays[$gm] + $gd;

        if ($gm > 1 && (($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0)) {
            $gDayNo++;
        }

        $jDayNo = $gDayNo - 79;

        $jNp = intdiv($jDayNo, 12053);
        $jDayNo %= 12053;

        $jy = 979 + 33 * $jNp + 4 * intdiv($jDayNo, 1461);
        $jDayNo %= 1461;

        if ($jDayNo >= 366) {
            $jy += intdiv($jDayNo - 1, 365);
            $jDayNo = ($jDayNo - 1) % 365;
        }

        $jm = 0;

        for ($i = 0; $i < 11; $i++) {
            $monthLength = $i < 6 ? 31 : 30;

            if ($jDayNo < $monthLength) {
                $jm = $i + 1;
                break;
            }

            $jDayNo -= $monthLength;
        }

        if ($jm === 0) {
            $jm = 12;
        }

        $jd = $jDayNo + 1;

        return [$jy, $jm, $jd];
    }

    /**
     * STAGE 25 - the inverse of fromGregorian(): [1405, 8, 20] -> [2026, 11, 11].
     *
     * The panel's date fields show and accept the Jalali calendar, while the
     * database keeps real Gregorian DATE columns, so the two directions are now
     * both needed: this one turns what a form posts into what a query stores.
     *
     * It is the same 33-year-cycle arithmetic as fromGregorian() (the reference
     * jalaali algorithm), not a lookup table and not a second, slightly
     * different calendar rule: tests/stage25_shamsi_date_test.js round-trips
     * every day of a 60 year window through fromGregorian() -> toGregorian()
     * and compares the result with the platform's own Persian calendar.
     *
     * @return array{0:int,1:int,2:int} [year, month, day]
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;

        $days = -355668 + (365 * $jy) + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4) + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
            $days %= 36524;

            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;

        $february = (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0) ? 29 : 28;
        $gregorianDays = [0, 31, $february, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $gm = 1;

        while ($gm <= 12 && $gd > $gregorianDays[$gm]) {
            $gd -= $gregorianDays[$gm];
            $gm++;
        }

        return [$gy, $gm, $gd];
    }

    /**
     * How many days one Jalali month has: 31 for Farvardin..Shahrivar, 30 for
     * Mehr..Bahman, and 29 or 30 for Esfand depending on the year.
     *
     * Esfand is decided by walking ONE Gregorian day past 29 Esfand and asking
     * fromGregorian() where it landed, instead of keeping a second leap-year
     * rule next to the converter. A rule of its own could drift from the
     * arithmetic above; this cannot, because it is the same function that
     * renders every other date in the panel.
     *
     * Returns 0 for a month outside 1..12 so a caller can use it as a validity
     * check as well.
     */
    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm < 1 || $jm > 12) {
            return 0;
        }

        if ($jm <= 6) {
            return 31;
        }

        if ($jm <= 11) {
            return 30;
        }

        [$gy, $gm, $gd] = self::toGregorian($jy, 12, 29);
        [$ny, $nm, $nd] = self::fromGregorian(...self::nextGregorianDay($gy, $gm, $gd));

        return $ny === $jy && $nm === 12 && $nd === 30 ? 30 : 29;
    }

    /**
     * The day after a Gregorian one, in pure integer arithmetic.
     *
     * Deliberately NOT mktime()/strtotime(): those go through the platform's
     * time_t, which on a 32-bit build stops in 2038 and answers a warning
     * instead of a date. A calendar helper must not inherit that ceiling - the
     * converter itself has none.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function nextGregorianDay(int $gy, int $gm, int $gd): array
    {
        $february = (($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0) ? 29 : 28;
        $lengths  = [0, 31, $february, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $gd++;

        if ($gd > $lengths[$gm]) {
            $gd = 1;
            $gm++;

            if ($gm > 12) {
                $gm = 1;
                $gy++;
            }
        }

        return [$gy, $gm, $gd];
    }

    /**
     * True for a real Jalali date, so "1405/07/31" (Mehr has 30 days) is
     * rejected instead of silently rolling into Aban.
     */
    public static function isJalaliDate(int $jy, int $jm, int $jd): bool
    {
        return $jm >= 1 && $jm <= 12 && $jd >= 1 && $jd <= self::monthLength($jy, $jm);
    }

    /**
     * "2025-10-06" / "2025-10-06 08:30:00" -> "۱۴۰۴/۰۷/۱۴" (as latin digits: 1404/07/14).
     *
     * The method is idempotent: a value that is ALREADY a Jalali date
     * ("1404/07/14", optionally with a time) comes back in the same shape
     * instead of being converted twice, so a display helper may be applied on
     * top of an already-localised value without corrupting it.
     */
    public static function date($value, bool $withTime = false): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || $value === '0000-00-00' || strpos($value, '0000-00-00') === 0) {
            return '—';
        }

        if (preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})(?:[ T](\d{2}:\d{2}))?#', $value, $jalaliMatch) === 1) {
            $jalali = sprintf('%04d/%02d/%02d', (int) $jalaliMatch[1], (int) $jalaliMatch[2], (int) $jalaliMatch[3]);
            $clock  = (string) ($jalaliMatch[4] ?? '');

            return $withTime && $clock !== '' ? $jalali . ' ' . $clock : $jalali;
        }

        $time = '';

        if (strlen($value) > 10) {
            $time    = substr($value, 11, 5);
            $value   = substr($value, 0, 10);
        }

        $parts = explode('-', $value);

        if (count($parts) !== 3) {
            return $value;
        }

        [$jy, $jm, $jd] = self::fromGregorian((int) $parts[0], (int) $parts[1], (int) $parts[2]);

        $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);

        return $withTime && $time !== '' ? $out . ' ' . $time : $out;
    }

    /**
     * STAGE 25 - the value a date FIELD shows: "1405/08/20", or "" when there
     * is no date at all.
     *
     * date() answers "—" for an empty value because a table cell must not look
     * blank; an input must, or it would submit an em dash. Anything that is not
     * a real date - empty, "0000-00-00", or a stray string from a query
     * parameter - also comes back empty, so no unparsable value is ever printed
     * into an attribute.
     *
     * @param mixed $value
     */
    public static function input($value): string
    {
        $label = self::date($value);

        return preg_match('#^\d{4}/\d{2}/\d{2}$#', $label) === 1 ? $label : '';
    }

    /**
     * A clean 24h clock label: "2025-10-06 08:30:00", "08:30:00" and "08:30"
     * all become "08:30" (never the raw timestamp, never "08:30:00").
     *
     * "—" is returned for an empty value or a date without a time, so a table
     * cell never shows a dangling colon or a zeroed placeholder.
     */
    public static function time($value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return '—';
        }

        if (preg_match('/(\d{1,2}):(\d{2})/', $value, $match) !== 1) {
            return '—';
        }

        $hour   = (int) $match[1];
        $minute = (int) $match[2];

        if ($hour > 23 || $minute > 59) {
            return '—';
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * Jalali date AND clean time: "2025-10-06 08:30:00" -> "1404/07/14 08:30".
     */
    public static function dateTime($value): string
    {
        $date = self::date($value);

        if ($date === '—') {
            return $date;
        }

        $clock = self::time($value);

        return $clock === '—' ? $date : $date . ' ' . $clock;
    }

    /**
     * A month bucket -> Jalali month label.
     *
     * "2025-10"       -> "1404/07"
     * "2025-03"       -> "1403/12 - 1404/01" (the Gregorian bucket straddles
     *                    two Jalali months, and saying so is the honest label)
     * "2025-10-06"    -> "1404/07"
     */
    public static function monthLabel($value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return '—';
        }

        if (preg_match('#^(\d{4})[/-](\d{1,2})$#', $value, $monthMatch) === 1) {
            $year  = (int) $monthMatch[1];
            $month = (int) $monthMatch[2];

            if ($month < 1 || $month > 12) {
                return $value;
            }

            $daysInMonth = (int) date('t', (int) mktime(0, 0, 0, $month, 1, $year));
            $first       = self::monthOf($year, $month, 1);
            $last        = self::monthOf($year, $month, $daysInMonth);

            return $first === $last ? $first : $first . ' - ' . $last;
        }

        $date = self::date($value);

        return $date === '—' ? $date : substr($date, 0, 7);
    }

    /** "1404/07" for one specific Gregorian day. */
    private static function monthOf(int $year, int $month, int $day): string
    {
        [$jy, $jm] = self::fromGregorian($year, $month, $day);

        return sprintf('%04d/%02d', $jy, $jm);
    }

    /**
     * "2025-10-06" -> "14 مهر 1404" (long form used in headers).
     */
    public static function longDate($value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return '—';
        }

        $parts = explode('-', substr($value, 0, 10));

        if (count($parts) !== 3) {
            return $value;
        }

        [$jy, $jm, $jd] = self::fromGregorian((int) $parts[0], (int) $parts[1], (int) $parts[2]);

        return $jd . ' ' . (self::MONTHS[$jm] ?? '') . ' ' . $jy;
    }

    public static function monthName(int $month): string
    {
        return self::MONTHS[$month] ?? '';
    }

    /** Today as Jalali string (dashboards). */
    public static function today(): string
    {
        return self::date(date('Y-m-d'));
    }
}
