<?php
/**
 * SARMAD Attendance - user accounts (owner / manager / supervisor).
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;
use App\Core\Str;

final class User extends Model
{
    protected static string $table = 'users';
    protected static bool $softDeletes = true;

    protected static array $fillable = [
        'uuid', 'role_id', 'role', 'customer_id', 'manager_id', 'name', 'username', 'email',
        'mobile', 'password_hash', 'must_change_password', 'is_active', 'api_enabled',
        'timezone', 'locale', 'created_by',
        'disabled_by_customer',
    ];

    /**
     * Columns that may be exposed to the session / API (never the hash).
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public static function sessionPayload(array $user): array
    {
        return [
            'id'                   => (int) $user['id'],
            'uuid'                 => (string) $user['uuid'],
            'name'                 => (string) $user['name'],
            'username'             => (string) $user['username'],
            'email'                => $user['email'] ?? null,
            'role'                 => (string) $user['role'],
            'role_id'              => (int) $user['role_id'],
            'customer_id'          => isset($user['customer_id']) ? (int) $user['customer_id'] : null,
            'manager_id'           => isset($user['manager_id']) && $user['manager_id'] !== null ? (int) $user['manager_id'] : null,
            'is_active'            => (int) ($user['is_active'] ?? 1),
            'is_blocked'           => (int) ($user['is_active'] ?? 1) === 1 && empty($user['deleted_at']) ? 0 : 1,
            'must_change_password' => (int) ($user['must_change_password'] ?? 0),
            'timezone'             => $user['timezone'] ?? null,
            'locale'               => $user['locale'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findByUsername(string $username): ?array
    {
        $username = Str::lower(trim($username));

        if ($username === '') {
            return null;
        }

        return self::findBy('username', $username);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findByLogin(string $identifier): ?array
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        if (strpos($identifier, '@') !== false) {
            $byEmail = self::findBy('email', Str::lower($identifier));

            if ($byEmail !== null) {
                return $byEmail;
            }
        }

        $byUsername = self::findByUsername($identifier);

        if ($byUsername !== null) {
            return $byUsername;
        }

        $row = self::findBy('mobile', Str::normalizeMobile($identifier));

        return $row;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function createUser(array $data): int
    {
        $data['uuid']     = $data['uuid'] ?? Security::uuid4();
        $data['username'] = self::uniqueUsername((string) ($data['username'] ?? $data['name'] ?? 'user'));

        if (isset($data['email']) && $data['email'] !== null && $data['email'] !== '') {
            $data['email'] = Str::lower((string) $data['email']);
        } else {
            $data['email'] = null;
        }

        if (isset($data['mobile']) && $data['mobile'] !== null && $data['mobile'] !== '') {
            $data['mobile'] = Str::normalizeMobile((string) $data['mobile']);
        }

        if (!isset($data['role_id'])) {
            $data['role_id'] = Role::idFor((string) ($data['role'] ?? Role::MANAGER));
        }

        if (!isset($data['password_hash']) && isset($data['password'])) {
            $data['password_hash'] = Security::hashPassword((string) $data['password']);
        }

        unset($data['password'], $data['password_confirmation']);

        return self::create($data);
    }

    public static function uniqueUsername(string $candidate): string
    {
        $base = preg_replace('/[^a-z0-9_\.]/', '', Str::lower($candidate)) ?: 'user';
        $base = substr($base, 0, 40);
        $name = $base;
        $i    = 1;

        while (self::findBy('username', $name) !== null) {
            $name = $base . $i++;
        }

        return $name;
    }

    public static function emailTaken(string $email, int $exceptId = 0): bool
    {
        $row = self::findBy('email', Str::lower(trim($email)));

        return $row !== null && (int) $row['id'] !== $exceptId;
    }

    public static function usernameTaken(string $username, int $exceptId = 0): bool
    {
        $row = self::findBy('username', Str::lower(trim($username)));

        return $row !== null && (int) $row['id'] !== $exceptId;
    }

    /* =================================================================
     * Safety helpers
     * ===============================================================*/

    public static function isOwner(array $user): bool
    {
        return (string) $user['role'] === Role::OWNER;
    }

    public static function ownerExists(): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `role` = ? AND `deleted_at` IS NULL',
            [Role::OWNER]
        ) > 0;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function owners(): array
    {
        return self::where(['role' => Role::OWNER, 'deleted_at' => null], 'id ASC');
    }

    public static function touchLogin(int $userId, string $ip): void
    {
        Database::run(
            'UPDATE `' . self::tableName() . '` SET `last_login_at` = ?, `last_login_ip` = ?, `failed_attempts` = 0, `locked_until` = NULL WHERE `id` = ?',
            [date('Y-m-d H:i:s'), $ip, $userId]
        );
    }

    public static function recordFailedAttempt(int $userId, int $maxAttempts, int $lockMinutes): void
    {
        Database::run(
            'UPDATE `' . self::tableName() . '` SET `failed_attempts` = `failed_attempts` + 1,'
            . ' `locked_until` = CASE WHEN (`failed_attempts` + 1) >= ? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE) ELSE `locked_until` END'
            . ' WHERE `id` = ?',
            [$maxAttempts, $lockMinutes, $userId]
        );
    }

    public static function clearFailedAttempts(int $userId): void
    {
        Database::update(self::tableName(), ['failed_attempts' => 0, 'locked_until' => null], ['id' => $userId]);
    }

    public static function isLocked(array $user): bool
    {
        if (empty($user['locked_until'])) {
            return false;
        }

        return strtotime((string) $user['locked_until']) > time();
    }

    public static function setPassword(int $userId, string $plainPassword, bool $mustChange = false): void
    {
        Database::update(self::tableName(), [
            'password_hash'        => Security::hashPassword($plainPassword),
            'password_changed_at'  => date('Y-m-d H:i:s'),
            'must_change_password' => $mustChange ? 1 : 0,
            'failed_attempts'      => 0,
            'locked_until'         => null,
        ], ['id' => $userId]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function listForCustomer(int $customerId, string $role = '', int $limit = 100): array
    {
        $sql    = 'SELECT * FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL';
        $params = [$customerId];

        if ($role !== '') {
            $sql .= ' AND `role` = ?';
            $params[] = $role;
        }

        $sql .= ' ORDER BY `id` DESC LIMIT ' . max(1, $limit);

        return array_map([self::class, 'cast'], Database::select($sql, $params));
    }
}
