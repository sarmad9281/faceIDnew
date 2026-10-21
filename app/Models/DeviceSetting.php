<?php
/**
 * SARMAD Attendance - per device, NON SENSITIVE settings.
 *
 * Only the keys in KEYS can ever be stored: success sound, how long the
 * success card stays on screen, clock visibility, camera facing, recognition
 * timeout. Nothing here can enable/disable attendance, change the branch,
 * rotate the token or move the device to another customer - those are owner
 * level operations and live in the owner panel only.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class DeviceSetting extends Model
{
    protected static string $table = 'device_settings';

    /**
     * key => [default, min, max]
     *
     * @var array<string,array{0:string,1:?int,2:?int}>
     */
    public const KEYS = [
        'success_sound'            => ['1', 0, 1],
        'success_display_seconds'  => ['5', 2, 20],
        'error_display_seconds'    => ['4', 2, 20],
        'show_clock'               => ['1', 0, 1],
        'show_seconds'             => ['1', 0, 1],
        'camera_facing'            => ['user', null, null],
        'recognition_timeout_sec'  => ['15', 5, 60],
        'idle_reset_sec'           => ['6', 2, 60],
        'offline_banner'           => ['1', 0, 1],
    ];

    /** Values accepted for the enum style keys. */
    private const ENUM_VALUES = [
        'camera_facing' => ['user', 'environment'],
    ];

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        $defaults = [];

        foreach (self::KEYS as $key => $meta) {
            $defaults[$key] = $meta[0];
        }

        return $defaults;
    }

    /**
     * Effective settings of one device = defaults overridden by stored rows.
     *
     * @return array<string,string>
     */
    public static function all(int $deviceId): array
    {
        $settings = self::defaults();

        if ($deviceId <= 0) {
            return $settings;
        }

        $rows = Database::select(
            'SELECT `setting_key`, `setting_value` FROM `' . self::tableName() . '` WHERE `device_id` = ?',
            [$deviceId]
        );

        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];

            if (array_key_exists($key, self::KEYS)) {
                $settings[$key] = (string) $row['setting_value'];
            }
        }

        return $settings;
    }

    /**
     * Same as all() but typed for the device UI (booleans + integers).
     *
     * @return array<string,mixed>
     */
    public static function allTyped(int $deviceId): array
    {
        $settings = self::all($deviceId);

        return [
            'success_sound'           => $settings['success_sound'] === '1',
            'success_display_seconds' => (int) $settings['success_display_seconds'],
            'error_display_seconds'   => (int) $settings['error_display_seconds'],
            'show_clock'              => $settings['show_clock'] === '1',
            'show_seconds'            => $settings['show_seconds'] === '1',
            'camera_facing'           => $settings['camera_facing'],
            'recognition_timeout_sec' => (int) $settings['recognition_timeout_sec'],
            'idle_reset_sec'          => (int) $settings['idle_reset_sec'],
            'offline_banner'          => $settings['offline_banner'] === '1',
        ];
    }

    /**
     * Store one setting. Unknown keys are refused (returns false) so a buggy or
     * hostile client can not write a security relevant key.
     */
    public static function put(int $deviceId, int $customerId, string $key, string $value, int $updatedBy = 0): bool
    {
        $clean = self::sanitize($key, $value);

        if ($clean === null) {
            return false;
        }

        Database::run(
            'INSERT INTO `' . self::tableName() . '`'
            . ' (`device_id`, `customer_id`, `setting_key`, `setting_value`, `updated_by`)'
            . ' VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`),'
            . ' `updated_by` = VALUES(`updated_by`), `updated_at` = NOW()',
            [$deviceId, $customerId, $key, $clean, $updatedBy]
        );

        return true;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<int,string> saved keys
     */
    public static function putMany(int $deviceId, int $customerId, array $values, int $updatedBy = 0): array
    {
        $saved = [];

        foreach ($values as $key => $value) {
            if (self::put($deviceId, $customerId, (string) $key, (string) $value, $updatedBy)) {
                $saved[] = (string) $key;
            }
        }

        return $saved;
    }

    /**
     * @return array<int,string> keys that were rejected
     */
    public static function rejectedKeys(array $values): array
    {
        $rejected = [];

        foreach ($values as $key => $value) {
            if (self::sanitize((string) $key, (string) $value) === null) {
                $rejected[] = (string) $key;
            }
        }

        return $rejected;
    }

    public static function isAllowedKey(string $key): bool
    {
        return array_key_exists($key, self::KEYS);
    }

    /**
     * @return array<string,int>
     */
    public static function countsByKey(int $customerId): array
    {
        $rows = Database::select(
            'SELECT `setting_key`, COUNT(*) AS total FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? GROUP BY `setting_key`',
            [$customerId]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['setting_key']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Validate + normalise one value. Returns null when the key/value pair is
     * not acceptable.
     */
    public static function sanitize(string $key, string $value): ?string
    {
        if (!self::isAllowedKey($key)) {
            return null;
        }

        if (isset(self::ENUM_VALUES[$key])) {
            $value = strtolower(trim($value));

            return in_array($value, self::ENUM_VALUES[$key], true) ? $value : null;
        }

        [$default, $min, $max] = self::KEYS[$key];
        $value = trim($value);

        if (!is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        if ($min !== null && $number < $min) {
            return null;
        }

        if ($max !== null && $number > $max) {
            return null;
        }

        return (string) $number;
    }
}
