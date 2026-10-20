<?php
/**
 * SARMAD Attendance - employee <-> shift assignment (stage 3).
 *
 * TODAY (base version): one employee holds exactly one active shift row.
 * The row is stored with weekday = 127 (= every day). The table is already
 * built for the later versions of the project:
 *
 *   - fixed shift      : a single row with weekday = 127
 *   - per weekday shift: seven rows with weekday = 0..6
 *   - multiple shifts  : several rows per employee (any weekday combination)
 *
 * Nothing in the UI or in the models assumes "one shift only", the panel
 * simply reads the active rows of the employee.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;

final class EmployeeShift extends Model
{
    protected static string $table = 'employee_shifts';

    /** weekday = 127 means "every day" (the base version). */
    public const EVERY_DAY = 127;

    protected static array $fillable = [
        'uuid', 'customer_id', 'employee_id', 'shift_id', 'weekday',
        'effective_from', 'effective_to', 'is_active', 'assigned_by',
    ];

    /**
     * Attach one shift to one employee (base version = one active shift).
     *
     * @param string|null $date effective date (YYYY-MM-DD), defaults to today
     */
    public static function assign(int $customerId, int $employeeId, int $shiftId, int $actorId = 0, ?string $date = null): int
    {
        if ($customerId <= 0 || $employeeId <= 0 || $shiftId <= 0) {
            return 0;
        }

        $date = $date !== null && $date !== '' ? $date : date('Y-m-d');

        // 1. drop the previous assignment(s) of this employee - history stays.
        Database::run(
            'UPDATE `' . self::tableName() . '` SET `is_active` = 0 WHERE `customer_id` = ? AND `employee_id` = ? AND `is_active` = 1',
            [$customerId, $employeeId]
        );

        // 2. reuse the row if this pair was assigned before (unique key).
        $existing = Database::selectOne(
            'SELECT `id` FROM `' . self::tableName() . '` WHERE `employee_id` = ? AND `shift_id` = ? AND `weekday` = ? LIMIT 1',
            [$employeeId, $shiftId, self::EVERY_DAY]
        );

        if ($existing !== null) {
            Database::run(
                'UPDATE `' . self::tableName() . '` SET `is_active` = 1, `customer_id` = ?, `effective_from` = ?, `effective_to` = NULL, `assigned_by` = ? WHERE `id` = ?',
                [$customerId, $date, $actorId > 0 ? $actorId : null, (int) $existing['id']]
            );

            return (int) $existing['id'];
        }

        return self::create([
            'uuid'           => Security::uuid4(),
            'customer_id'    => $customerId,
            'employee_id'    => $employeeId,
            'shift_id'       => $shiftId,
            'weekday'        => self::EVERY_DAY,
            'effective_from' => $date,
            'is_active'      => 1,
            'assigned_by'    => $actorId > 0 ? $actorId : null,
        ]);
    }

    /**
     * Active shift(s) of an employee with the shift row attached.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forEmployee(int $employeeId, int $customerId): array
    {
        if ($employeeId <= 0 || $customerId <= 0) {
            return [];
        }

        return Database::select(
            'SELECT es.*, s.`name` AS shift_name, s.`code` AS shift_code, s.`start_time`, s.`end_time`,'
            . ' s.`late_grace_minutes`, s.`early_leave_grace_minutes`, s.`break_minutes`, s.`is_active` AS shift_active'
            . ' FROM `' . self::tableName() . '` es'
            . ' INNER JOIN `' . Database::table('shifts') . '` s ON s.`id` = es.`shift_id` AND s.`deleted_at` IS NULL'
            . ' WHERE es.`customer_id` = ? AND es.`employee_id` = ? AND es.`is_active` = 1'
            . ' ORDER BY es.`weekday` ASC',
            [$customerId, $employeeId]
        );
    }

    /**
     * The single active shift of an employee, ready for the employee profile.
     *
     * @return array<string,mixed>|null
     */
    public static function currentFor(int $employeeId, int $customerId): ?array
    {
        $rows = self::forEmployee($employeeId, $customerId);

        return $rows === [] ? null : $rows[0];
    }

    public static function clearFor(int $employeeId, int $customerId): int
    {
        return Database::run(
            'UPDATE `' . self::tableName() . '` SET `is_active` = 0 WHERE `customer_id` = ? AND `employee_id` = ? AND `is_active` = 1',
            [$customerId, $employeeId]
        )->rowCount();
    }

    /**
     * employee_id => shift_id map for one customer (list views).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function activeMapForCustomer(int $customerId): array
    {
        $rows = Database::select(
            'SELECT es.`employee_id`, es.`shift_id`, s.`name` AS shift_name, s.`start_time`, s.`end_time`'
            . ' FROM `' . self::tableName() . '` es'
            . ' INNER JOIN `' . Database::table('shifts') . '` s ON s.`id` = es.`shift_id` AND s.`deleted_at` IS NULL'
            . ' WHERE es.`customer_id` = ? AND es.`is_active` = 1',
            [$customerId]
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['employee_id']] = $row;
        }

        return $map;
    }

    /**
     * shift_id => employee count (shift cards / list).
     *
     * @return array<int,int>
     */
    public static function countsByShift(int $customerId): array
    {
        $rows = Database::select(
            'SELECT `shift_id`, COUNT(DISTINCT `employee_id`) AS total FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `is_active` = 1 GROUP BY `shift_id`',
            [$customerId]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['shift_id']] = (int) $row['total'];
        }

        return $counts;
    }

    public static function activeCount(int $customerId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(DISTINCT `employee_id`) FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `is_active` = 1',
            [$customerId]
        );
    }
}
