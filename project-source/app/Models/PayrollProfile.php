<?php
/**
 * SARMAD Attendance - payroll profiles (stage 3).
 *
 * One row per employee holding the CONTRACT data (payment type, base amount,
 * hourly / overtime / deficit rates, fixed penalty and benefits). The final
 * calculation engine belongs to a later stage - the manager panel only
 * maintains and displays these real numbers, it never fabricates a salary.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;

final class PayrollProfile extends Model
{
    protected static string $table = 'payroll_profiles';

    public const TYPES = [
        'monthly' => 'ماهانه',
        'daily'   => 'روزمزد',
        'hourly'  => 'ساعتی',
    ];

    protected static array $fillable = [
        'uuid', 'customer_id', 'employee_id', 'pay_type', 'base_amount', 'hourly_rate',
        'overtime_rate', 'deficit_rate', 'penalty_amount', 'benefits_amount', 'currency',
        'is_active', 'notes', 'created_by',
    ];

    public static function typeLabel(?string $type): string
    {
        return self::TYPES[(string) $type] ?? (string) $type;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function forEmployee(int $employeeId, int $customerId): ?array
    {
        if ($employeeId <= 0 || $customerId <= 0) {
            return null;
        }

        $row = Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '` WHERE `employee_id` = ? AND `customer_id` = ? LIMIT 1',
            [$employeeId, $customerId]
        );

        return $row === null ? null : self::cast($row);
    }

    /**
     * Insert or update the profile of one employee.
     *
     * @param array<string,mixed> $data
     */
    public static function save(array $data): int
    {
        $employeeId = (int) ($data['employee_id'] ?? 0);
        $customerId = (int) ($data['customer_id'] ?? 0);

        if ($employeeId <= 0 || $customerId <= 0) {
            return 0;
        }

        $existing = self::forEmployee($employeeId, $customerId);

        $payload = [
            'pay_type'        => isset(self::TYPES[(string) ($data['pay_type'] ?? '')]) ? (string) $data['pay_type'] : 'monthly',
            'base_amount'     => (float) ($data['base_amount'] ?? 0),
            'hourly_rate'     => (float) ($data['hourly_rate'] ?? 0),
            'overtime_rate'   => (float) ($data['overtime_rate'] ?? 0),
            'deficit_rate'    => (float) ($data['deficit_rate'] ?? 0),
            'penalty_amount'  => (float) ($data['penalty_amount'] ?? 0),
            'benefits_amount' => (float) ($data['benefits_amount'] ?? 0),
            'currency'        => (string) ($data['currency'] ?? 'IRT'),
            'is_active'       => !empty($data['is_active']) ? 1 : 0,
            'notes'           => $data['notes'] ?? null,
        ];

        if ($existing !== null) {
            self::updateById((int) $existing['id'], $payload);

            return (int) $existing['id'];
        }

        $payload['uuid']        = Security::uuid4();
        $payload['customer_id'] = $customerId;
        $payload['employee_id'] = $employeeId;
        $payload['created_by']  = $data['created_by'] ?? null;

        return self::create($payload);
    }

    /**
     * Employees of one customer with their (optional) payroll profile.
     * Employees without a profile are listed too, so the manager sees who
     * still needs one - nothing is invented for them.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function listingForCustomer(int $customerId, bool $onlyActiveEmployees = false): array
    {
        $sql = 'SELECT e.`id` AS employee_id, e.`first_name`, e.`last_name`, e.`employee_code`,'
            . ' e.`status` AS employee_status, b.`name` AS branch_name,'
            . ' p.`id` AS profile_id, p.`pay_type`, p.`base_amount`, p.`hourly_rate`, p.`overtime_rate`,'
            . ' p.`deficit_rate`, p.`penalty_amount`, p.`benefits_amount`, p.`currency`, p.`is_active` AS profile_active,'
            . ' p.`notes` AS payroll_notes'
            . ' FROM `' . Database::table('employees') . '` e'
            . ' LEFT JOIN `' . self::tableName() . '` p ON p.`employee_id` = e.`id` AND p.`customer_id` = e.`customer_id`'
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = e.`branch_id`'
            . ' WHERE e.`customer_id` = ? AND e.`deleted_at` IS NULL';

        if ($onlyActiveEmployees) {
            $sql .= " AND e.`status` = 'active'";
        }

        $sql .= ' ORDER BY e.`last_name` ASC, e.`first_name` ASC';

        return array_map([static::class, 'cast'], Database::select($sql, [$customerId]));
    }

    public static function profileCount(int $customerId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `customer_id` = ?',
            [$customerId]
        );
    }

    /**
     * @return array<string,int>
     */
    public static function typeCounts(int $customerId): array
    {
        $rows = Database::select(
            'SELECT `pay_type`, COUNT(*) AS total FROM `' . self::tableName() . '` WHERE `customer_id` = ? GROUP BY `pay_type`',
            [$customerId]
        );

        $counts = ['monthly' => 0, 'daily' => 0, 'hourly' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['pay_type']] = (int) $row['total'];
        }

        return $counts;
    }
}
