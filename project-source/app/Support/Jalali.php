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
     * "2025-10-06" / "2025-10-06 08:30:00" -> "۱۴۰۴/۰۷/۱۴" (as latin digits: 1404/07/14).
     */
    public static function date($value, bool $withTime = false): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || $value === '0000-00-00' || strpos($value, '0000-00-00') === 0) {
            return '—';
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
