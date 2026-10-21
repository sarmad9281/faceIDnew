<?php
/**
 * SARMAD Attendance - string helpers (Persian friendly).
 */

declare(strict_types=1);

namespace App\Core;

final class Str
{
    public static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    public static function slug(string $value): string
    {
        $value = self::lower(trim($value));
        $value = preg_replace('/[^a-z0-9\p{L}]+/u', '-', $value) ?? $value;
        $value = trim($value, '-');

        return $value === '' ? 'item' : substr($value, 0, 60);
    }

    /**
     * Normalise Persian/Arabic digits and remove the Persian thousands
     * separator so numbers coming from a Persian keyboard still work.
     */
    public static function toAsciiDigits(string $value): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

        $value = str_replace($persian, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $value);
        $value = str_replace($arabic, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $value);

        return str_replace([',', '،', ' '], '', $value);
    }

    public static function normalizeMobile(string $value): string
    {
        $value = self::toAsciiDigits($value);
        $value = preg_replace('/\D+/', '', $value) ?? $value;

        if (strpos($value, '0098') === 0) {
            $value = substr($value, 4);
        } elseif (strpos($value, '98') === 0 && strlen($value) === 12) {
            $value = substr($value, 2);
        }

        if (strlen($value) === 10 && strpos($value, '9') === 0) {
            $value = '0' . $value;
        }

        return $value;
    }

    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);

        if (count($parts) !== 2) {
            return '***';
        }

        $name = $parts[0];
        $keep = self::length($name) > 2 ? 2 : 1;

        return substr($name, 0, $keep) . str_repeat('*', 3) . '@' . $parts[1];
    }

    public static function random(int $length = 10): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }
}
