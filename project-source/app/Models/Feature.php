<?php
/**
 * SARMAD Attendance - feature catalogue (attendance_features).
 *
 * A feature is a platform capability that can be switched on or off per
 * customer (see FeatureFlag). The catalogue is data, not code, so a new
 * feature is added with an INSERT - no schema change, no migration of
 * existing rows.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Feature extends Model
{
    protected static string $table = 'features';

    protected static array $fillable = [
        'feature_key', 'title_fa', 'description', 'category', 'is_core',
        'default_enabled', 'module_ready', 'sort_order',
    ];

    public const CATEGORY_BASIC    = 'basic';
    public const CATEGORY_OPTIONAL = 'optional';

    private static array $cache = [];

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function catalogue(): array
    {
        if (self::$cache !== []) {
            return self::$cache;
        }

        $rows = Database::select(
            'SELECT * FROM `' . self::tableName() . '` ORDER BY `sort_order` ASC, `feature_key` ASC'
        );

        return self::$cache = array_map([self::class, 'cast'], $rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function byCategory(string $category): array
    {
        return array_values(array_filter(
            self::catalogue(),
            static fn (array $feature): bool => (string) $feature['category'] === $category
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function byKey(string $key): ?array
    {
        foreach (self::catalogue() as $feature) {
            if ((string) $feature['feature_key'] === $key) {
                return $feature;
            }
        }

        return null;
    }

    public static function existsByKey(string $key): bool
    {
        return self::byKey($key) !== null;
    }

    /**
     * @return array<int,string>
     */
    public static function keys(): array
    {
        return array_map(static fn (array $f): string => (string) $f['feature_key'], self::catalogue());
    }

    /**
     * Features that have a working module in the current release.
     *
     * @return array<int,string>
     */
    public static function readyKeys(): array
    {
        return array_map(
            static fn (array $f): string => (string) $f['feature_key'],
            array_values(array_filter(self::catalogue(), static fn (array $f): bool => (int) $f['module_ready'] === 1))
        );
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
