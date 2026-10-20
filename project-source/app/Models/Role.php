<?php
/**
 * SARMAD Attendance - roles & capabilities.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Role extends Model
{
    protected static string $table = 'roles';
    protected static array $fillable = ['name', 'title_fa', 'description', 'level', 'is_system', 'capabilities'];

    /** Cached map name => row, avoids a query per permission check. */
    private static ?array $cache = null;

    public const OWNER      = 'owner';
    public const MANAGER    = 'manager';
    public const SUPERVISOR = 'supervisor';
    public const DEVICE     = 'device';

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        return self::where([], '`level` DESC');
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function byName(string $name): ?array
    {
        $name = strtolower(trim($name));

        if (self::$cache === null) {
            self::$cache = [];

            foreach (self::all() as $row) {
                self::$cache[(string) $row['name']] = $row;
            }
        }

        return self::$cache[$name] ?? null;
    }

    public static function idFor(string $name): int
    {
        $row = self::byName($name);

        return $row === null ? 0 : (int) $row['id'];
    }

    /**
     * @return array<int,string>
     */
    public static function capabilitiesOf(string $roleName): array
    {
        $row = self::byName($roleName);

        if ($row === null) {
            return [];
        }

        $decoded = json_decode((string) ($row['capabilities'] ?? '[]'), true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    public static function roleHasCapability(string $roleName, string $capability): bool
    {
        $capabilities = self::capabilitiesOf($roleName);

        if (in_array('*', $capabilities, true)) {
            return true;
        }

        return in_array($capability, $capabilities, true);
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Roles that a given actor may create / manage.
     *
     * @return array<int,string>
     */
    public static function assignableBy(string $actorRole): array
    {
        switch (strtolower($actorRole)) {
            case self::OWNER:
                return [self::MANAGER, self::SUPERVISOR];
            case self::MANAGER:
                return [self::SUPERVISOR];
            default:
                return [];
        }
    }

    /**
     * @return array<int,string>
     */
    public static function names(): array
    {
        return array_map(static fn (array $row): string => (string) $row['name'], self::all());
    }

    public static function existsByName(string $name): bool
    {
        return self::byName($name) !== null;
    }

    /**
     * Count users per role - used on the owner dashboard.
     *
     * @return array<string,int>
     */
    public static function userCounts(): array
    {
        $rows = Database::select(
            'SELECT `role`, COUNT(*) AS total FROM `' . Database::table('users') . '`'
            . ' WHERE `deleted_at` IS NULL GROUP BY `role`'
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['role']] = (int) $row['total'];
        }

        return $counts;
    }
}
