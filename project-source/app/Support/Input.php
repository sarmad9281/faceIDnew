<?php
/**
 * SARMAD Attendance - small input normalisers shared by the manager panel.
 *
 * They never trust the request: every value is trimmed, stripped of control
 * characters and rejected (null / 0) when it is not exactly what the form is
 * supposed to send. Nothing here writes to the database.
 */

declare(strict_types=1);

namespace App\Support;

final class Input
{
    /** YYYY-MM-DD (real date, not just a matching string). */
    public static function isDate($value): bool
    {
        $value = trim((string) ($value ?? ''));

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function date($value): ?string
    {
        return self::isDate($value) ? trim((string) $value) : null;
    }

    /** HH:MM (00:00 - 23:59). */
    public static function isTime($value): bool
    {
        return (bool) preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim((string) ($value ?? '')));
    }

    public static function time($value): ?string
    {
        if (!self::isTime($value)) {
            return null;
        }

        $parts = explode(':', trim((string) $value));

        return str_pad($parts[0], 2, '0', STR_PAD_LEFT) . ':' . $parts[1];
    }

    /** Any non negative integer within a range (minutes, grace windows, ids). */
    public static function int($value, int $min = 0, int $max = PHP_INT_MAX): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number < $min || $number > $max ? null : $number;
    }

    /** Money / rates: never negative, rounded to two decimals. */
    public static function money($value): float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return 0.0;
        }

        return round(max(0.0, (float) $value), 2);
    }

    /** "1" / "on" / true -> 1, everything else -> 0. */
    public static function bool($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return in_array((string) $value, ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
    }

    /** Trimmed single line text, control characters removed. */
    public static function text($value, int $max = 255): string
    {
        $value = (string) ($value ?? '');
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = trim(preg_replace('/[ \t]+/u', ' ', $value) ?? $value);

        return $max > 0 && mb_strlen($value, 'UTF-8') > $max
            ? mb_substr($value, 0, $max, 'UTF-8')
            : $value;
    }

    /** Multiline text (notes / descriptions) with the newlines kept. */
    public static function multiline($value, int $max = 1000): string
    {
        $value = (string) ($value ?? '');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = trim($value);

        return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') : $value;
    }

    /**
     * A whitelist value: returns $default when the value is not in the list.
     *
     * @param array<int,string>|array<string,string> $allowed
     */
    public static function choice($value, array $allowed, string $default = ''): string
    {
        $value = trim((string) ($value ?? ''));

        return in_array($value, array_keys($allowed), true) || in_array($value, $allowed, true) ? $value : $default;
    }

    /** Mobile number in the project's canonical shape (۰۹... -> 09...). */
    public static function mobile($value): string
    {
        $value = preg_replace('/\D+/', '', \App\Core\Str::toAsciiDigits((string) ($value ?? ''))) ?? '';

        if (strpos($value, '98') === 0 && strlen($value) === 12) {
            $value = '0' . substr($value, 2);
        }

        if (strpos($value, '9') === 0 && strlen($value) === 10) {
            $value = '0' . $value;
        }

        return $value;
    }

    public static function isMobile($value): bool
    {
        return (bool) preg_match('/^09\d{9}$/', self::mobile($value));
    }

    public static function isEmail($value): bool
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' || (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}
