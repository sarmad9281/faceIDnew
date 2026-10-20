<?php
/**
 * SARMAD Attendance - customer branches.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Security;

final class Branch extends Model
{
    protected static string $table = 'branches';
    protected static bool $softDeletes = true;

    protected static array $fillable = [
        'uuid', 'customer_id', 'manager_id', 'name', 'code', 'address', 'city', 'timezone',
        'geo_lat', 'geo_lng', 'geofence_radius', 'phone', 'notes', 'is_active', 'created_by',
    ];

    /**
     * @param array<string,mixed> $data
     */
    public static function createBranch(array $data): int
    {
        $data['uuid'] = $data['uuid'] ?? Security::uuid4();

        return self::create($data);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forCustomer(int $customerId): array
    {
        return self::where(['customer_id' => $customerId], 'name ASC');
    }

    public static function codeTaken(int $customerId, string $code, int $exceptId = 0): bool
    {
        $sql = 'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `code` = ? AND `deleted_at` IS NULL';

        $params = [$customerId, $code];

        if ($exceptId > 0) {
            $sql .= ' AND `id` <> ?';
            $params[] = $exceptId;
        }

        return (int) \App\Core\Database::scalar($sql, $params) > 0;
    }

    /**
     * Branch listing with the device counter used by the manager page.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forCustomerDetailed(int $customerId): array
    {
        $rows = \App\Core\Database::select(
            'SELECT b.*,'
            . ' (SELECT COUNT(*) FROM `' . \App\Core\Database::table('devices') . '` d'
            . '   WHERE d.`branch_id` = b.`id` AND d.`deleted_at` IS NULL) AS device_count,'
            . ' (SELECT COUNT(*) FROM `' . \App\Core\Database::table('employees') . '` e'
            . '   WHERE e.`branch_id` = b.`id` AND e.`deleted_at` IS NULL) AS employee_count'
            . ' FROM `' . self::tableName() . '` b'
            . ' WHERE b.`customer_id` = ? AND b.`deleted_at` IS NULL ORDER BY b.`name` ASC',
            [$customerId]
        );

        return array_map([static::class, 'cast'], $rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    /**
     * Stage 5 - may this DEVICE work with this EMPLOYEE?
     *
     * A device belongs to exactly one customer (checked by its token). When
     * the branches feature is on AND the device is bound to a branch, the
     * employee has to be in the same branch. A branch-less deployment (or a
     * device without a branch) simply allows the whole customer.
     *
     * The device can never change its own branch - this is the server side
     * half of that rule.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $employee
     */
    public static function deviceMayUseEmployee(array $device, array $employee): bool
    {
        $customerId = (int) ($device['customer_id'] ?? 0);

        if ($customerId <= 0 || (int) ($employee['customer_id'] ?? 0) !== $customerId) {
            return false;
        }

        $branchesEnabled = function_exists('feature_enabled') && \feature_enabled($customerId, 'branches');

        if (!$branchesEnabled) {
            return true;
        }

        $deviceBranch   = (int) ($device['branch_id'] ?? 0);
        $employeeBranch = (int) ($employee['branch_id'] ?? 0);

        if ($deviceBranch <= 0) {
            // Not bound to a branch: the whole customer is in scope.
            return true;
        }

        return $employeeBranch === $deviceBranch;
    }

    public static function findInCustomer(int $branchId, int $customerId): ?array
    {
        if ($branchId <= 0 || $customerId <= 0) {
            return null;
        }

        $row = \App\Core\Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '` WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$branchId, $customerId]
        );

        return $row === null ? null : self::cast($row);
    }
}
