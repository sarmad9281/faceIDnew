<?php
/**
 * SARMAD Attendance - login attempts (brute force protection source).
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class LoginAttempt extends Model
{
    protected static string $table = 'login_attempts';

    protected static array $fillable = [
        'identifier', 'user_id', 'customer_id', 'ip', 'user_agent', 'successful', 'reason',
    ];

    public static function log(string $identifier, bool $successful, string $ip, string $userAgent, ?int $userId = null, ?int $customerId = null, string $reason = ''): void
    {
        self::create([
            // Never log a raw secret; identifiers are usernames / emails only.
            'identifier'  => substr($identifier, 0, 190),
            'user_id'     => $userId,
            'customer_id' => $customerId,
            'ip'          => substr($ip, 0, 45),
            'user_agent'  => substr($userAgent, 0, 255),
            'successful'  => $successful ? 1 : 0,
            'reason'      => $reason === '' ? null : substr($reason, 0, 120),
        ]);
    }

    public static function countFailures(string $identifier, string $ip, int $windowMinutes): int
    {
        return self::countFailuresForIdentifier($identifier, $windowMinutes)
            + self::countFailuresForIp($ip, $windowMinutes);
    }

    /**
     * Failures against one account name (any IP).
     */
    public static function countFailuresForIdentifier(string $identifier, int $windowMinutes): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . ' WHERE `successful` = 0 AND `created_at` >= ? AND `identifier` = ?',
            [self::since($windowMinutes), substr($identifier, 0, 190)]
        );
    }

    /**
     * Failures from one IP across all accounts.
     */
    public static function countFailuresForIp(string $ip, int $windowMinutes): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . ' WHERE `successful` = 0 AND `created_at` >= ? AND `ip` = ?',
            [self::since($windowMinutes), substr($ip, 0, 45)]
        );
    }

    /**
     * Throttling rule:
     *   - an account is blocked after N failures (default 5)
     *   - an IP is blocked after 4 x N failures across all accounts, so a
     *     single bad actor cannot lock out everyone behind a shared IP.
     */
    public static function isLocked(string $identifier, string $ip, int $maxAttempts, int $windowMinutes): bool
    {
        $maxAttempts = max(1, $maxAttempts);

        if (self::countFailuresForIdentifier($identifier, $windowMinutes) >= $maxAttempts) {
            return true;
        }

        return self::countFailuresForIp($ip, $windowMinutes) >= ($maxAttempts * 4);
    }

    private static function since(int $windowMinutes): string
    {
        return date('Y-m-d H:i:s', time() - (max(1, $windowMinutes) * 60));
    }

    public static function purgeOlderThan(int $days): int
    {
        return Database::run(
            'DELETE FROM `' . self::tableName() . '` WHERE `created_at` < ?',
            [date('Y-m-d H:i:s', time() - (max(1, $days) * 86400))]
        )->rowCount();
    }

    /* ---------------------------------------------------------------- */

    public static function recentFailures(int $limit = 20): array
    {
        return array_map([self::class, 'cast'], Database::select(
            'SELECT * FROM `' . self::tableName() . '` WHERE `successful` = 0 ORDER BY `id` DESC LIMIT ' . max(1, $limit)
        ));
    }
}
