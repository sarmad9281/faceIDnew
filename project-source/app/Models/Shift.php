<?php
/**
 * SARMAD Attendance - shifts (stage 3, manager panel).
 *
 * A shift is a reusable time window of one customer: start, end, the two
 * grace windows and an optional break. week_minutes() is the contract the
 * later attendance / payroll engine will use, so the numbers stay in one
 * place instead of being recomputed in the UI.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;

final class Shift extends Model
{
    protected static string $table = 'shifts';
    protected static bool $softDeletes = true;

    protected static array $fillable = [
        'uuid', 'customer_id', 'name', 'code', 'start_time', 'end_time', 'crosses_midnight',
        'late_grace_minutes', 'early_leave_grace_minutes', 'break_minutes', 'weekly_pattern',
        'is_active', 'sort_order', 'notes', 'created_by',
    ];

    /**
     * @param array<string,mixed> $data
     */
    public static function createShift(array $data): int
    {
        $data['uuid'] = $data['uuid'] ?? Security::uuid4();

        return self::create($data);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forCustomer(int $customerId, bool $onlyActive = false): array
    {
        $sql    = 'SELECT * FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL';
        $params = [$customerId];

        if ($onlyActive) {
            $sql .= ' AND `is_active` = 1';
        }

        $sql .= ' ORDER BY `sort_order` ASC, `start_time` ASC, `name` ASC';

        return array_map([static::class, 'cast'], Database::select($sql, $params));
    }

    /**
     * Shift list with the number of employees currently attached.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function detailed(int $customerId): array
    {
        $rows = Database::select(
            'SELECT s.*,'
            . ' (SELECT COUNT(DISTINCT es.`employee_id`) FROM `' . Database::table('employee_shifts') . '` es'
            . '   WHERE es.`shift_id` = s.`id` AND es.`is_active` = 1 AND es.`customer_id` = s.`customer_id`) AS employee_count'
            . ' FROM `' . self::tableName() . '` s'
            . ' WHERE s.`customer_id` = ? AND s.`deleted_at` IS NULL'
            . ' ORDER BY s.`sort_order` ASC, s.`start_time` ASC, s.`name` ASC',
            [$customerId]
        );

        return array_map([static::class, 'cast'], $rows);
    }

    /**
     * Tenant safe lookup: a shift of another customer is never returned.
     *
     * @return array<string,mixed>|null
     */
    public static function findInCustomer(int $shiftId, int $customerId): ?array
    {
        if ($shiftId <= 0 || $customerId <= 0) {
            return null;
        }

        $row = Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '` WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$shiftId, $customerId]
        );

        return $row === null ? null : self::cast($row);
    }

    public static function codeTaken(int $customerId, string $code, int $exceptId = 0): bool
    {
        if ($code === '') {
            return false;
        }

        $sql    = 'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `code` = ? AND `deleted_at` IS NULL';
        $params = [$customerId, $code];

        if ($exceptId > 0) {
            $sql .= ' AND `id` <> ?';
            $params[] = $exceptId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    public static function activeCount(int $customerId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `is_active` = 1 AND `deleted_at` IS NULL',
            [$customerId]
        );
    }

    /* =================================================================
     * Time helpers - shared by the panel and the future engine
     * ===============================================================*/

    /** "08:30" | "08:30:00" | "8:30" -> minutes since midnight. */
    public static function timeMinutes($value): int
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '' || !preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            return 0;
        }

        return (((int) $m[1]) * 60) + (int) $m[2];
    }

    /** "08:30:00" -> "08:30" (what the inputs and tables show). */
    public static function timeLabel($value): string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return '—';
        }

        $parts = explode(':', $value);

        return str_pad($parts[0], 2, '0', STR_PAD_LEFT) . ':' . ($parts[1] ?? '00');
    }

    public static function crossesMidnight(string $start, string $end): int
    {
        return self::timeMinutes($end) <= self::timeMinutes($start) ? 1 : 0;
    }

    /** Gross duration of the shift window (midnight aware). */
    public static function spanMinutes(array $shift): int
    {
        $start = self::timeMinutes($shift['start_time'] ?? '00:00');
        $end   = self::timeMinutes($shift['end_time'] ?? '00:00');

        if ($end <= $start) {
            $end += 1440;
        }

        return max(0, $end - $start);
    }

    /** Net working minutes = span - break (never negative). */
    public static function workMinutes(array $shift): int
    {
        return max(0, self::spanMinutes($shift) - (int) ($shift['break_minutes'] ?? 0));
    }

    /** 480 -> "8 ساعت" / 450 -> "7 ساعت و 30 دقیقه". */
    public static function hoursLabel(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours   = intdiv($minutes, 60);
        $rest    = $minutes % 60;

        if ($hours === 0 && $rest === 0) {
            return '—';
        }

        $out = $hours > 0 ? $hours . ' ساعت' : '';

        if ($rest > 0) {
            $out .= ($out !== '' ? ' و ' : '') . $rest . ' دقیقه';
        }

        return $out;
    }

    /** Plain text summary used in tables: "08:00 - 16:00 (8 ساعت)". */
    public static function summary(array $shift): string
    {
        $minutes = self::workMinutes($shift);
        $hours   = $minutes / 60;

        return self::timeLabel($shift['start_time'] ?? '') . ' - ' . self::timeLabel($shift['end_time'] ?? '')
            . ' (' . rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.') . ' ساعت)';
    }

    /**
     * Validates a "HH:MM" time coming from a form.
     */
    public static function isValidTime(string $value): bool
    {
        return (bool) preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim($value));
    }
}
