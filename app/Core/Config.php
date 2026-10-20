<?php
/**
 * SARMAD Attendance - Configuration repository.
 */

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    private static string $root = '';
    private static bool $loaded = false;

    /**
     * @param array<string,mixed> $items
     */
    public static function hydrate(array $items, string $root): void
    {
        $items['installed'] = !empty($items['installed']);

        self::$items  = $items;
        self::$root   = rtrim($root, '/');
        self::$loaded = true;
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Dot-notation getter: Config::get('db.prefix', 'attendance_')
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $segments = explode('.', $key);
        $value    = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    public static function set(string $key, $value): void
    {
        $segments = explode('.', $key);
        $ref      = &self::$items;

        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }

        $ref = $value;
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__missing__') !== '__missing__';
    }

    /**
     * Absolute path inside the project root.
     */
    public static function path(string $relative = ''): string
    {
        $relative = ltrim($relative, '/');

        return $relative === '' ? self::$root : self::$root . '/' . $relative;
    }

    public static function root(): string
    {
        return self::$root;
    }

    /**
     * Full application key (32 bytes decoded) or a hard failure.
     */
    public static function appKey(): string
    {
        $key = (string) self::get('app.key', '');

        $decoded = base64_decode($key, true);

        if ($decoded === false || strlen($decoded) < 32) {
            throw new RuntimeException('Application key is missing or invalid. Re-run the installer.');
        }

        return $decoded;
    }

    public static function isDebug(): bool
    {
        return (bool) self::get('app.debug', false);
    }
}
