<?php
/**
 * SARMAD Attendance - owner / manager API tokens.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;

final class ApiToken extends Model
{
    protected static string $table = 'api_tokens';

    protected static array $fillable = [
        'uuid', 'user_id', 'customer_id', 'name', 'token_prefix', 'token_hash',
        'abilities', 'expires_at', 'last_used_at', 'last_used_ip', 'revoked_at', 'created_by',
    ];

    /**
     * @param array<int,string> $abilities
     * @return array{id:int, token:string}
     */
    public static function issue(int $userId, ?int $customerId, string $name, array $abilities = ['*'], int $days = 90, int $createdBy = 0): array
    {
        $token = 'sat_' . Security::randomToken(32);

        $id = self::create([
            'uuid'         => Security::uuid4(),
            'user_id'      => $userId,
            'customer_id'  => $customerId,
            'name'         => substr($name, 0, 120),
            'token_prefix' => substr($token, 0, 12),
            'token_hash'   => Security::hashToken($token),
            'abilities'    => json_encode(array_values($abilities)),
            'expires_at'   => date('Y-m-d H:i:s', time() + (max(1, $days) * 86400)),
            'created_by'   => $createdBy > 0 ? $createdBy : $userId,
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findActiveByToken(string $plainToken): ?array
    {
        if ($plainToken === '') {
            return null;
        }

        $row = self::findBy('token_hash', Security::hashToken($plainToken));

        if ($row === null || !empty($row['revoked_at'])) {
            return null;
        }

        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    public static function touch(string $plainToken, string $ip): void
    {
        Database::run(
            'UPDATE `' . self::tableName() . '` SET `last_used_at` = NOW(), `last_used_ip` = ? WHERE `token_hash` = ?',
            [substr($ip, 0, 45), Security::hashToken($plainToken)]
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function hasAbility(array $row, string $ability): bool
    {
        $abilities = json_decode((string) ($row['abilities'] ?? '[]'), true);

        if (!is_array($abilities)) {
            return false;
        }

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public static function revoke(int $id): void
    {
        self::updateById($id, ['revoked_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forUser(int $userId): array
    {
        return self::where(['user_id' => $userId], 'id DESC');
    }
}
