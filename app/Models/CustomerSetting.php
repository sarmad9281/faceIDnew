<?php
/**
 * SARMAD Attendance - per customer settings (stage 3).
 *
 * attendance_settings stays the global/owner store; this table is the
 * customer scoped key/value store the MANAGER may edit - and only for the
 * customer resolved from the session, never for an id coming from the
 * request. Owner level settings (subscription, feature flags, device reset)
 * are not stored here and can not be touched through this model.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class CustomerSetting extends Model
{
    protected static string $table = 'customer_settings';

    protected static array $fillable = [
        'customer_id', 'setting_key', 'setting_value', 'value_type', 'updated_by',
    ];

    /**
     * Keys the manager panel is allowed to write. Anything else is ignored,
     * which keeps the store from becoming a way around the feature flags.
     */
    public const MANAGER_KEYS = [
        'business_name', 'contact_mobile', 'contact_email', 'address', 'description',
        'work_start_hint', 'work_end_hint', 'late_grace_minutes', 'early_grace_minutes',
        'week_start', 'currency', 'telegram_chat_id', 'telegram_notifications',
        'telegram_daily_summary', 'telegram_late_alert', 'telegram_absent_alert',
        'telegram_leave_alert', 'telegram_updated_at',
    ];

    /**
     * @return array<string,string>
     */
    public static function all(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        $rows = Database::select(
            'SELECT `setting_key`, `setting_value` FROM `' . self::tableName() . '` WHERE `customer_id` = ?',
            [$customerId]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }

        return $out;
    }

    public static function get(int $customerId, string $key, ?string $default = null): ?string
    {
        if ($customerId <= 0 || $key === '') {
            return $default;
        }

        $value = Database::scalar(
            'SELECT `setting_value` FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `setting_key` = ? LIMIT 1',
            [$customerId, $key]
        );

        return $value === null || $value === false ? $default : (string) $value;
    }

    public static function put(int $customerId, string $key, ?string $value, string $type = 'string', int $actorId = 0): void
    {
        if ($customerId <= 0 || $key === '') {
            return;
        }

        Database::run(
            'INSERT INTO `' . self::tableName() . '` (`customer_id`, `setting_key`, `setting_value`, `value_type`, `updated_by`)'
            . ' VALUES (?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `value_type` = VALUES(`value_type`), `updated_by` = VALUES(`updated_by`)',
            [$customerId, $key, $value, $type, $actorId > 0 ? $actorId : null]
        );
    }

    /**
     * Write several keys at once, ignoring everything outside MANAGER_KEYS.
     *
     * @param array<string,string|null> $values
     * @return array<int,string> keys that actually changed
     */
    public static function putMany(int $customerId, array $values, int $actorId = 0): array
    {
        $before  = self::all($customerId);
        $changed = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (!in_array($key, self::MANAGER_KEYS, true)) {
                continue;
            }

            $value = $value === null ? null : (string) $value;

            if (($before[$key] ?? null) === $value) {
                continue;
            }

            self::put($customerId, $key, $value, 'string', $actorId);
            $changed[] = $key;
        }

        return $changed;
    }
}
