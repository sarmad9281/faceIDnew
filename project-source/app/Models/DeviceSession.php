<?php
/**
 * SARMAD Attendance - device sessions (token rotation & revocation).
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class DeviceSession extends Model
{
    protected static string $table = 'device_sessions';

    protected static array $fillable = [
        'device_id', 'customer_id', 'session_token_hash', 'ip', 'user_agent',
        'last_activity_at', 'expires_at', 'revoked_at', 'revoke_reason',
    ];

    public static function issue(int $deviceId, int $customerId, string $tokenHash, string $ip, string $userAgent, int $days = 30): int
    {
        return self::create([
            'device_id'          => $deviceId,
            'customer_id'        => $customerId,
            'session_token_hash' => $tokenHash,
            'ip'                 => substr($ip, 0, 45),
            'user_agent'         => substr($userAgent, 0, 255),
            'expires_at'         => date('Y-m-d H:i:s', time() + ($days * 86400)),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findActiveByHash(string $tokenHash): ?array
    {
        $row = self::findBy('session_token_hash', $tokenHash);

        if ($row === null) {
            return null;
        }

        if (!empty($row['revoked_at'])) {
            return null;
        }

        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    public static function touch(int $sessionId): void
    {
        self::updateById($sessionId, ['last_activity_at' => date('Y-m-d H:i:s')]);
    }

    public static function revokeAllForDevice(int $deviceId, string $reason): void
    {
        Database::run(
            'UPDATE `' . self::tableName() . '` SET `revoked_at` = NOW(), `revoke_reason` = ?'
            . ' WHERE `device_id` = ? AND `revoked_at` IS NULL',
            [substr($reason, 0, 120), $deviceId]
        );
    }
}
