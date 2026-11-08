<?php
/**
 * SARMAD Attendance - per customer feature switches (attendance_feature_flags).
 *
 * Reading a flag is ALWAYS: "explicit row for this customer" -> else the
 * catalogue default. That keeps the table small (only real decisions are
 * stored) while still allowing every feature to be forced on or off per
 * customer.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class FeatureFlag extends Model
{
    protected static string $table = 'feature_flags';

    protected static array $fillable = [
        'customer_id', 'feature_key', 'is_enabled', 'enabled_at', 'disabled_at', 'changed_by', 'notes',
    ];

    /** @var array<int,array<string,bool>> customer_id => feature_key => enabled */
    private static array $cache = [];

    /* =================================================================
     * Reading
     * ===============================================================*/

    /**
     * Is a feature enabled for a customer?
     *
     * Unknown feature keys are treated as DISABLED: a typo can never
     * accidentally open a gate.
     */
    public static function isEnabled(int $customerId, string $featureKey): bool
    {
        if ($customerId <= 0 || $featureKey === '') {
            return false;
        }

        $map = self::mapFor($customerId);

        if (array_key_exists($featureKey, $map)) {
            return $map[$featureKey];
        }

        $feature = Feature::byKey($featureKey);

        return $feature !== null && (int) $feature['default_enabled'] === 1;
    }

    /**
     * @return array<string,bool> feature_key => enabled
     */
    public static function mapFor(int $customerId): array
    {
        if (isset(self::$cache[$customerId])) {
            return self::$cache[$customerId];
        }

        $rows = Database::select(
            'SELECT `feature_key`, `is_enabled` FROM `' . self::tableName() . '` WHERE `customer_id` = ?',
            [$customerId]
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['feature_key']] = (int) $row['is_enabled'] === 1;
        }

        return self::$cache[$customerId] = $map;
    }

    /**
     * Full state for a customer: every catalogue feature with its
     * effective value plus where that value came from.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function stateFor(int $customerId): array
    {
        $stored = [];

        $rows = Database::select(
            'SELECT * FROM `' . self::tableName() . '` WHERE `customer_id` = ?',
            [$customerId]
        );

        foreach ($rows as $row) {
            $stored[(string) $row['feature_key']] = $row;
        }

        $state = [];

        foreach (Feature::catalogue() as $feature) {
            $key      = (string) $feature['feature_key'];
            $row      = $stored[$key] ?? null;
            $explicit = $row !== null;

            $state[] = [
                'key'            => $key,
                'title_fa'       => (string) $feature['title_fa'],
                'description'    => (string) ($feature['description'] ?? ''),
                'category'       => (string) $feature['category'],
                'is_core'        => (int) $feature['is_core'] === 1,
                'module_ready'   => (int) $feature['module_ready'] === 1,
                'default'        => (int) $feature['default_enabled'] === 1,
                'enabled'        => $explicit
                    ? (int) $row['is_enabled'] === 1
                    : (int) $feature['default_enabled'] === 1,
                'is_explicit'    => $explicit,
                'enabled_at'     => $row['enabled_at'] ?? null,
                'disabled_at'    => $row['disabled_at'] ?? null,
                'changed_by'     => isset($row['changed_by']) ? (int) $row['changed_by'] : null,
                'updated_at'     => $row['updated_at'] ?? null,
            ];
        }

        return $state;
    }

    /**
     * Count of enabled features per customer (used in owner listings).
     *
     * @param array<int,int> $customerIds
     * @return array<int,int> customer_id => enabled count
     */
    public static function enabledCounts(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $params       = array_map(static fn (int $id): int => $id, array_values($customerIds));

        $rows = Database::select(
            'SELECT `customer_id`, COUNT(*) AS total FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` IN (' . $placeholders . ') AND `is_enabled` = 1 GROUP BY `customer_id`',
            $params
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['customer_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /* =================================================================
     * Writing
     * ===============================================================*/

    /**
     * Enable or disable one feature for one customer.
     *
     * @return array{changed:bool, feature:string, enabled:bool, before:bool}
     */
    public static function set(int $customerId, string $featureKey, bool $enabled, int $actorUserId = 0, string $notes = ''): array
    {
        if (!Feature::existsByKey($featureKey)) {
            throw new \RuntimeException('Unknown feature: ' . $featureKey);
        }

        $before = self::isEnabled($customerId, $featureKey);
        $now    = date('Y-m-d H:i:s');

        $existing = Database::selectOne(
            'SELECT `id` FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `feature_key` = ? LIMIT 1',
            [$customerId, $featureKey]
        );

        $data = [
            'is_enabled'  => $enabled ? 1 : 0,
            'changed_by'  => $actorUserId > 0 ? $actorUserId : null,
            'enabled_at'  => $enabled ? $now : null,
            'disabled_at' => $enabled ? null : $now,
        ];

        if ($notes !== '') {
            $data['notes'] = $notes;
        }

        if ($existing === null) {
            $data['customer_id'] = $customerId;
            $data['feature_key'] = $featureKey;

            Database::insert(self::tableName(), $data);
        } else {
            Database::update(self::tableName(), $data, ['id' => (int) $existing['id']]);
        }

        self::clearCache($customerId);

        return [
            'changed' => $before !== $enabled,
            'feature' => $featureKey,
            'enabled' => $enabled,
            'before'  => $before,
        ];
    }

    /**
     * Materialise the catalogue defaults for a brand new customer so the
     * owner panel shows an explicit decision instead of "inherited".
     */
    public static function seedDefaults(int $customerId, int $actorUserId = 0): int
    {
        $created = 0;

        foreach (Feature::catalogue() as $feature) {
            $key = (string) $feature['feature_key'];

            $exists = Database::scalar(
                'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `feature_key` = ?',
                [$customerId, $key]
            );

            if ((int) $exists > 0) {
                continue;
            }

            $enabled = (int) $feature['default_enabled'] === 1;

            Database::insert(self::tableName(), [
                'customer_id' => $customerId,
                'feature_key' => $key,
                'is_enabled'  => $enabled ? 1 : 0,
                'enabled_at'  => $enabled ? date('Y-m-d H:i:s') : null,
                'disabled_at' => $enabled ? null : date('Y-m-d H:i:s'),
                'changed_by'  => $actorUserId > 0 ? $actorUserId : null,
                'notes'       => 'مقادیر پیش‌فرض هنگام ایجاد مشتری',
            ]);

            $created++;
        }

        self::clearCache($customerId);

        return $created;
    }

    public static function clearCache(?int $customerId = null): void
    {
        if ($customerId === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$customerId]);
    }
}
