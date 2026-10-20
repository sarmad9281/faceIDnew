<?php
/**
 * SARMAD Attendance - which shift applies to an employee on a given date?
 * (stage 6)
 *
 * The assignment table (attendance_employee_shifts) already supports:
 *   - one row with weekday = 127  -> every day (base version),
 *   - seven rows with weekday 0..6 -> a weekly pattern,
 *   - effective_from / effective_to -> a date range,
 *   - several rows                 -> more than one shift per person.
 *
 * The engine asks THIS class, and stores the answer on the record
 * (shift_snapshot), so a later shift change never rewrites history: a
 * recalculation uses the snapshot unless the manager explicitly asks for the
 * current shift.
 *
 * Weekday numbering is the Iranian week:
 *   0 = شنبه (Saturday) … 6 = جمعه (Friday)   [EmployeeShift::EVERY_DAY = 127]
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\EmployeeShift;

final class ShiftResolver
{
    /**
     * @return array<string,mixed>|null the shift row, or null when the person
     *                                  has no shift on that date
     */
    public static function resolve(int $employeeId, int $customerId, string $date): ?array
    {
        if ($employeeId <= 0 || $customerId <= 0 || $date === '') {
            return null;
        }

        $weekday = self::weekday($date);

        $row = Database::selectOne(
            'SELECT s.*, es.`weekday`, es.`effective_from`, es.`effective_to`'
            . ' FROM `' . Database::table('employee_shifts') . '` es'
            . ' INNER JOIN `' . Database::table('shifts') . '` s ON s.`id` = es.`shift_id`'
            . ' WHERE es.`employee_id` = ? AND es.`customer_id` = ?'
            . ' AND es.`is_active` = 1 AND s.`deleted_at` IS NULL AND s.`is_active` = 1'
            . ' AND (es.`weekday` = ? OR es.`weekday` = ?)'
            . ' AND (es.`effective_from` IS NULL OR es.`effective_from` <= ?)'
            . ' AND (es.`effective_to` IS NULL OR es.`effective_to` >= ?)'
            . ' ORDER BY FIELD(es.`weekday`, ?, ?) ASC, s.`sort_order` ASC, s.`start_time` ASC'
            . ' LIMIT 1',
            [$employeeId, $customerId, $weekday, EmployeeShift::EVERY_DAY, $date, $date, $weekday, EmployeeShift::EVERY_DAY]
        );

        return $row === null ? null : $row;
    }

    /**
     * Iranian weekday of a date: 0 = Saturday … 6 = Friday.
     */
    public static function weekday(string $date): int
    {
        $time = strtotime($date . ' 00:00:00');

        if ($time === false) {
            return 0;
        }

        // PHP: 0 = Sunday … 6 = Saturday
        return ((int) date('w', $time) + 1) % 7;
    }

    /**
     * A snapshot is what the record keeps forever: the numbers that were used
     * for the calculation, not a live join.
     *
     * @param array<string,mixed>|null $shift
     * @return array<string,mixed>|null
     */
    public static function snapshot(?array $shift): ?array
    {
        if ($shift === null) {
            return null;
        }

        return [
            'shift_id'                 => (int) $shift['id'],
            'name'                     => (string) ($shift['name'] ?? ''),
            'start_time'               => (string) ($shift['start_time'] ?? ''),
            'end_time'                 => (string) ($shift['end_time'] ?? ''),
            'crosses_midnight'         => (int) ($shift['crosses_midnight'] ?? 0),
            'late_grace_minutes'       => (int) ($shift['late_grace_minutes'] ?? 0),
            'early_leave_grace_minutes' => (int) ($shift['early_leave_grace_minutes'] ?? 0),
            'break_minutes'            => (int) ($shift['break_minutes'] ?? 0),
            'weekday'                  => (int) ($shift['weekday'] ?? EmployeeShift::EVERY_DAY),
            'calc_version'             => AttendanceCalculator::CALC_VERSION,
        ];
    }

    /**
     * Rebuild a shift-shaped array from a stored snapshot (used by
     * recalculation so history stays stable).
     *
     * @param array<string,mixed>|null $snapshot
     * @return array<string,mixed>|null
     */
    public static function fromSnapshot(?array $snapshot): ?array
    {
        if (!is_array($snapshot) || !isset($snapshot['start_time'])) {
            return null;
        }

        return [
            'id'                       => (int) ($snapshot['shift_id'] ?? 0),
            'name'                     => (string) ($snapshot['name'] ?? ''),
            'start_time'               => (string) $snapshot['start_time'],
            'end_time'                 => (string) ($snapshot['end_time'] ?? ''),
            'crosses_midnight'         => (int) ($snapshot['crosses_midnight'] ?? 0),
            'late_grace_minutes'       => (int) ($snapshot['late_grace_minutes'] ?? 0),
            'early_leave_grace_minutes' => (int) ($snapshot['early_leave_grace_minutes'] ?? 0),
            'break_minutes'            => (int) ($snapshot['break_minutes'] ?? 0),
        ];
    }

    /** Is this date inside an approved leave of the employee? */
    public static function onLeave(int $employeeId, int $customerId, string $date): bool
    {
        $row = Database::selectOne(
            'SELECT `id` FROM `' . Database::table('leaves') . '`'
            . ' WHERE `employee_id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL'
            . " AND `status` = 'approved' AND `start_date` <= ? AND `end_date` >= ? LIMIT 1",
            [$employeeId, $customerId, $date, $date]
        );

        return $row !== null;
    }

    /** Is this date a holiday of the customer (or of the device's branch)? */
    public static function isHoliday(int $customerId, string $date, int $branchId = 0): bool
    {
        $sql = 'SELECT `id` FROM `' . Database::table('holidays') . '`'
            . ' WHERE `customer_id` = ? AND `deleted_at` IS NULL AND `is_active` = 1'
            . ' AND (`holiday_date` = ? OR (`is_recurring_yearly` = 1 AND DATE_FORMAT(`holiday_date`, "%m-%d") = ?))';

        $params = [$customerId, $date, date('m-d', strtotime($date))];

        if ($branchId > 0) {
            $sql .= ' AND (`branch_id` IS NULL OR `branch_id` = ?)';
            $params[] = $branchId;
        }

        $sql .= ' LIMIT 1';

        return Database::selectOne($sql, $params) !== null;
    }
}
