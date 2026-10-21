<?php
/**
 * SARMAD Attendance - emergency access log (attendance_owner_impersonations).
 *
 * When the owner has to step into a customer's manager panel, a row is
 * opened on entry and closed on exit. Nothing about that access is silent:
 * entry, exit, duration and the reason are all stored and shown in the
 * owner audit log.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Impersonation extends Model
{
    protected static string $table = 'owner_impersonations';

    protected static array $fillable = [
        'customer_id', 'owner_user_id', 'manager_user_id', 'reason', 'ip', 'user_agent',
        'session_id', 'actions_count', 'started_at', 'last_action_at', 'ended_at', 'ended_reason',
    ];

    /**
     * @param array<string,mixed> $data
     */
    public static function open(array $data): int
    {
        return self::create($data);
    }

    public static function close(int $id, string $reason = 'logout'): void
    {
        Database::update(self::tableName(), [
            'ended_at'     => date('Y-m-d H:i:s'),
            'ended_reason' => $reason,
        ], ['id' => $id]);
    }

    public static function touchAction(int $id): void
    {
        Database::run(
            'UPDATE `' . self::tableName() . '` SET `actions_count` = `actions_count` + 1, `last_action_at` = ? WHERE `id` = ?',
            [date('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Close sessions that were left open (browser closed, session expiry).
     */
    public static function closeStale(int $hours = 12): int
    {
        $stmt = Database::run(
            'UPDATE `' . self::tableName() . '` SET `ended_at` = NOW(), `ended_reason` = ?'
            . ' WHERE `ended_at` IS NULL AND `started_at` < DATE_SUB(NOW(), INTERVAL ? HOUR)',
            ['expired', $hours]
        );

        return $stmt->rowCount();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recent(int $limit = 50, int $offset = 0, ?int $customerId = null): array
    {
        $sql    = 'SELECT i.*, c.`name` AS customer_name, u.`name` AS owner_name'
            . ' FROM `' . self::tableName() . '` i'
            . ' LEFT JOIN `' . Database::table('customers') . '` c ON c.`id` = i.`customer_id`'
            . ' LEFT JOIN `' . Database::table('users') . '` u ON u.`id` = i.`owner_user_id`'
            . ' WHERE 1 = 1';
        $params = [];

        if ($customerId !== null && $customerId > 0) {
            $sql .= ' AND i.`customer_id` = ?';
            $params[] = $customerId;
        }

        $sql .= ' ORDER BY i.`id` DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        return array_map([self::class, 'cast'], Database::select($sql, $params));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function openSessions(): array
    {
        return array_map([self::class, 'cast'], Database::select(
            'SELECT i.*, c.`name` AS customer_name, u.`name` AS owner_name'
            . ' FROM `' . self::tableName() . '` i'
            . ' LEFT JOIN `' . Database::table('customers') . '` c ON c.`id` = i.`customer_id`'
            . ' LEFT JOIN `' . Database::table('users') . '` u ON u.`id` = i.`owner_user_id`'
            . ' WHERE i.`ended_at` IS NULL ORDER BY i.`id` DESC LIMIT 50'
        ));
    }

    public static function countToday(): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE DATE(`started_at`) = CURDATE()'
        );
    }
}
