<?php
/**
 * SARMAD Attendance - hierarchical settings store.
 *
 * Lookup order for a key: device -> branch -> customer -> global.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Setting extends Model
{
    protected static string $table = 'settings';
    protected static array $fillable = [
        'scope', 'customer_id', 'branch_id', 'device_id', 'setting_key',
        'setting_value', 'setting_type', 'is_encrypted', 'updated_by',
    ];

    /** Runtime cache of global settings. */
    private static array $cache = [];

    public const SCOPE_GLOBAL   = 'global';
    public const SCOPE_CUSTOMER = 'customer';
    public const SCOPE_BRANCH   = 'branch';
    public const SCOPE_DEVICE   = 'device';

    public static function loadOverrides(): void
    {
        self::$cache = [];

        // Before installation finishes the settings table does not exist yet.
        // Skipping the overrides keeps the panel/CLI usable in that state
        // instead of dying with an unhandled PDOException.
        if (!Database::tableExists('settings')) {
            return;
        }

        $rows = Database::select(
            'SELECT `setting_key`, `setting_value`, `setting_type` FROM `' . self::tableName()
            . '` WHERE `scope` = ? AND `customer_id` = 0 AND `branch_id` = 0 AND `device_id` = 0',
            [self::SCOPE_GLOBAL]
        );

        foreach ($rows as $row) {
            self::$cache[(string) $row['setting_key']] = self::castValue((string) $row['setting_value'], (string) $row['setting_type']);
        }
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $row = Database::selectOne(
            'SELECT `setting_value`, `setting_type` FROM `' . self::tableName()
            . '` WHERE `scope` = ? AND `customer_id` = 0 AND `branch_id` = 0 AND `device_id` = 0 AND `setting_key` = ? LIMIT 1',
            [self::SCOPE_GLOBAL, $key]
        );

        if ($row === null) {
            return $default;
        }

        return self::$cache[$key] = self::castValue((string) $row['setting_value'], (string) $row['setting_type']);
    }

    /**
     * Resolution with tenant hierarchy.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function resolve(string $key, int $customerId = 0, int $branchId = 0, int $deviceId = 0, $default = null)
    {
        $chain = [];

        if ($deviceId > 0) {
            $chain[] = [self::SCOPE_DEVICE, $customerId, $branchId, $deviceId];
        }
        if ($branchId > 0) {
            $chain[] = [self::SCOPE_BRANCH, $customerId, 0, 0];
        }
        if ($customerId > 0) {
            $chain[] = [self::SCOPE_CUSTOMER, $customerId, 0, 0];
        }
        $chain[] = [self::SCOPE_GLOBAL, 0, 0, 0];

        foreach ($chain as $scope) {
            $row = Database::selectOne(
                'SELECT `setting_value`, `setting_type` FROM `' . self::tableName()
                . '` WHERE `scope` = ? AND `customer_id` = ? AND `branch_id` = ? AND `device_id` = ? AND `setting_key` = ? LIMIT 1',
                [$scope[0], $scope[1], $scope[2], $scope[3], $key]
            );

            if ($row !== null) {
                return self::castValue((string) $row['setting_value'], (string) $row['setting_type']);
            }
        }

        return $default;
    }

    /**
     * @param mixed $value
     */
    public static function put(
        string $key,
        $value,
        string $scope = self::SCOPE_GLOBAL,
        int $customerId = 0,
        int $branchId = 0,
        int $deviceId = 0,
        int $updatedBy = 0
    ): void {
        $type = self::detectType($value);

        $sql = 'INSERT INTO `' . self::tableName() . '`'
            . ' (`scope`, `customer_id`, `branch_id`, `device_id`, `setting_key`, `setting_value`, `setting_type`, `updated_by`)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `setting_type` = VALUES(`setting_type`), `updated_by` = VALUES(`updated_by`)';

        Database::run($sql, [
            $scope, $customerId, $branchId, $deviceId, $key,
            self::serialize($value, $type), $type, $updatedBy > 0 ? $updatedBy : null,
        ]);

        if ($scope === self::SCOPE_GLOBAL && $customerId === 0 && $branchId === 0 && $deviceId === 0) {
            self::$cache[$key] = $value;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function globals(): array
    {
        $rows = Database::select(
            'SELECT `setting_key`, `setting_value`, `setting_type` FROM `' . self::tableName()
            . '` WHERE `scope` = ? AND `customer_id` = 0 AND `branch_id` = 0 AND `device_id` = 0 ORDER BY `setting_key` ASC',
            [self::SCOPE_GLOBAL]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = self::castValue((string) $row['setting_value'], (string) $row['setting_type']);
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forCustomer(int $customerId): array
    {
        return self::where([
            'scope'       => self::SCOPE_CUSTOMER,
            'customer_id' => $customerId,
        ], 'setting_key ASC');
    }

    /* ================================================================= */

    /**
     * @param mixed $value
     */
    private static function detectType($value): string
    {
        if (is_bool($value)) {
            return 'bool';
        }

        if (is_int($value)) {
            return 'int';
        }

        if (is_array($value)) {
            return 'json';
        }

        return 'string';
    }

    /**
     * @param mixed $value
     */
    private static function serialize($value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($type === 'json') {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ($type === 'bool') {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /**
     * @return mixed
     */
    private static function castValue(?string $value, string $type)
    {
        switch ($type) {
            case 'int':
                return (int) $value;
            case 'bool':
                return $value === '1' || strtolower((string) $value) === 'true';
            case 'json':
                $decoded = json_decode((string) $value, true);

                return $decoded === null ? [] : $decoded;
            default:
                return $value;
        }
    }
}
