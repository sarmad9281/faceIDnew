<?php
/**
 * SARMAD Attendance - attendance devices (kiosk / PWA clients).
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;

final class Device extends Model
{
    protected static string $table = 'devices';
    protected static bool $softDeletes = true;

    protected static array $fillable = [
        'uuid', 'device_uid', 'customer_id', 'branch_id', 'manager_id', 'name', 'type',
        'platform', 'app_version', 'serial_number', 'token_prefix', 'api_token_hash',
        'token_expires_at', 'token_rotated_at', 'pairing_code', 'pairing_expires_at',
        'status', 'capabilities', 'clock_offset_seconds', 'last_seen_at', 'last_seen_ip',
        'last_sync_at', 'activated_at', 'registered_by',
        // stage 2: reset bookkeeping + customer suspension cascade
        'reset_count', 'last_reset_at', 'last_reset_reason', 'disabled_by_customer',
    ];

    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_REVOKED  = 'revoked';

    /**
     * Register a device: it starts in "pending" and only becomes usable
     * after a manager/owner activates it (or hands over a pairing code).
     *
     * @param array<string,mixed> $data
     * @return array{device_id:int, device_uid:string, pairing_code:string}
     */
    public static function register(array $data): array
    {
        $deviceUid   = (string) ($data['device_uid'] ?? Security::uuid4());
        $pairingCode = Security::deviceCode();

        $deviceId = self::create([
            'uuid'              => Security::uuid4(),
            'device_uid'        => $deviceUid,
            'customer_id'       => (int) $data['customer_id'],
            'branch_id'         => $data['branch_id'] ?? null,
            'manager_id'        => $data['manager_id'] ?? null,
            'name'              => (string) ($data['name'] ?? 'دستگاه جدید'),
            'type'              => (string) ($data['type'] ?? 'kiosk'),
            'platform'          => $data['platform'] ?? null,
            'app_version'       => $data['app_version'] ?? null,
            'serial_number'     => $data['serial_number'] ?? null,
            'pairing_code'      => $pairingCode,
            'pairing_expires_at'=> date('Y-m-d H:i:s', time() + 3600),
            'status'            => self::STATUS_PENDING,
            'capabilities'      => json_encode(['camera' => true, 'liveness' => false, 'gps' => false], JSON_UNESCAPED_UNICODE),
            'registered_by'     => $data['registered_by'] ?? null,
        ]);

        return [
            'device_id'    => $deviceId,
            'device_uid'   => $deviceUid,
            'pairing_code' => $pairingCode,
        ];
    }

    /**
     * Issue (or rotate) the device API token. The plain token is returned
     * exactly once and never stored.
     *
     * @return array{token:string, prefix:string, expires_at:string}
     */
    public static function issueToken(int $deviceId, int $days = 365): array
    {
        $token  = 'att_' . Security::randomToken(32);
        $prefix = substr($token, 0, 12);
        $days   = max(1, $days);

        self::updateById($deviceId, [
            'api_token_hash'   => Security::hashToken($token),
            'token_prefix'     => $prefix,
            'token_expires_at' => date('Y-m-d H:i:s', time() + ($days * 86400)),
            'token_rotated_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'token'      => $token,
            'prefix'     => $prefix,
            'expires_at' => (string) date('Y-m-d H:i:s', time() + ($days * 86400)),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findByTokenHash(string $hash): ?array
    {
        return self::findBy('api_token_hash', $hash);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findByDeviceUid(string $deviceUid): ?array
    {
        return self::findBy('device_uid', $deviceUid);
    }

    public static function findByPairingCode(string $code): ?array
    {
        $row = self::findBy('pairing_code', strtoupper(trim($code)));

        if ($row === null) {
            return null;
        }

        if (!empty($row['pairing_expires_at']) && strtotime((string) $row['pairing_expires_at']) < time()) {
            return null;
        }

        return $row;
    }

    public static function activate(int $deviceId): void
    {
        self::updateById($deviceId, [
            'status'            => self::STATUS_ACTIVE,
            'activated_at'      => date('Y-m-d H:i:s'),
            'pairing_code'      => null,
            'pairing_expires_at'=> null,
        ]);
    }

    public static function revoke(int $deviceId, string $reason = ''): void
    {
        self::updateById($deviceId, [
            'status'         => self::STATUS_REVOKED,
            'api_token_hash' => null,
        ]);

        DeviceSession::revokeAllForDevice($deviceId, $reason !== '' ? $reason : 'device revoked');
    }

    public static function heartbeat(int $deviceId, string $ip, int $clockOffset = 0, ?string $appVersion = null): void
    {
        $data = [
            'last_seen_at'         => date('Y-m-d H:i:s'),
            'last_seen_ip'         => substr($ip, 0, 45),
            'clock_offset_seconds' => $clockOffset,
        ];

        if ($appVersion !== null && $appVersion !== '') {
            $data['app_version'] = substr($appVersion, 0, 30);
        }

        self::updateById($deviceId, $data);
    }

    public static function isOnline(array $device, int $offlineAfter = 300): bool
    {
        if (empty($device['last_seen_at'])) {
            return false;
        }

        return (time() - strtotime((string) $device['last_seen_at'])) <= $offlineAfter;
    }

    public static function tokenExpired(array $device): bool
    {
        if (empty($device['token_expires_at'])) {
            return false;
        }

        return strtotime((string) $device['token_expires_at']) < time();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forCustomer(int $customerId, string $status = '', int $limit = 100): array
    {
        $sql    = 'SELECT * FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL';
        $params = [$customerId];

        if ($status !== '') {
            $sql .= ' AND `status` = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY `id` DESC LIMIT ' . max(1, $limit);

        return array_map([self::class, 'cast'], Database::select($sql, $params));
    }

    /**
     * @return array<string,int>
     */
    public static function statusCounts(?int $customerId = null): array
    {
        $sql    = 'SELECT `status`, COUNT(*) AS total FROM `' . self::tableName() . '` WHERE `deleted_at` IS NULL';
        $params = [];

        if ($customerId !== null) {
            $sql .= ' AND `customer_id` = ?';
            $params[] = $customerId;
        }

        $sql .= ' GROUP BY `status`';

        $counts = ['pending' => 0, 'active' => 0, 'disabled' => 0, 'revoked' => 0];

        foreach (Database::select($sql, $params) as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /* =================================================================
     * Owner panel queries (stage 2)
     * ===============================================================*/

    /**
     * @param array{q?:string,customer_id?:int,status?:string,online?:string,branch_id?:int} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public static function paginateForOwner(array $filters, int $page = 1, int $perPage = 20, int $offlineAfter = 300): array
    {
        $where  = ['d.`deleted_at` IS NULL'];
        $params = [];

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $where[] = '(d.`name` LIKE :q_name OR d.`device_uid` LIKE :q_uid OR d.`token_prefix` LIKE :q_token'
                . ' OR d.`serial_number` LIKE :q_serial OR c.`name` LIKE :q_customer)';
            $like = '%' . $search . '%';
            $params[':q_name']     = $like;
            $params[':q_uid']      = $like;
            $params[':q_token']    = $like;
            $params[':q_serial']   = $like;
            $params[':q_customer'] = $like;
        }

        $customerId = (int) ($filters['customer_id'] ?? 0);

        if ($customerId > 0) {
            $where[] = 'd.`customer_id` = :customer_id';
            $params[':customer_id'] = $customerId;
        }

        $branchId = (int) ($filters['branch_id'] ?? 0);

        if ($branchId > 0) {
            $where[] = 'd.`branch_id` = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status !== '' && in_array($status, [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_DISABLED, self::STATUS_REVOKED], true)) {
            $where[] = 'd.`status` = :status';
            $params[':status'] = $status;
        }

        $online = (string) ($filters['online'] ?? '');

        if ($online === 'online') {
            $where[] = "d.`status` = 'active' AND d.`last_seen_at` >= DATE_SUB(NOW(), INTERVAL " . max(30, $offlineAfter) . ' SECOND)';
        } elseif ($online === 'offline') {
            $where[] = '(d.`last_seen_at` IS NULL OR d.`last_seen_at` < DATE_SUB(NOW(), INTERVAL ' . max(30, $offlineAfter) . ' SECOND))';
        }

        $whereSql = implode(' AND ', $where);

        $from = '`' . self::tableName() . '` d'
            . ' LEFT JOIN `' . \App\Core\Database::table('customers') . '` c ON c.`id` = d.`customer_id`'
            . ' LEFT JOIN `' . \App\Core\Database::table('branches') . '` b ON b.`id` = d.`branch_id`';

        $total = (int) \App\Core\Database::scalar('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $whereSql, $params);

        $perPage = max(5, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = \App\Core\Database::select(
            'SELECT d.*, c.`name` AS customer_name, c.`uuid` AS customer_uuid, b.`name` AS branch_name'
            . ' FROM ' . $from . ' WHERE ' . $whereSql
            . ' ORDER BY d.`id` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'rows'     => array_map([static::class, 'cast'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Look up a device and make sure it belongs to the expected customer.
     *
     * @return array<string,mixed>|null
     */
    public static function findScoped(int $deviceId, ?int $customerId = null): ?array
    {
        if ($deviceId <= 0) {
            return null;
        }

        $sql    = 'SELECT * FROM `' . self::tableName() . '` WHERE `id` = ? AND `deleted_at` IS NULL';
        $params = [$deviceId];

        if ($customerId !== null && $customerId > 0) {
            $sql .= ' AND `customer_id` = ?';
            $params[] = $customerId;
        }

        $row = \App\Core\Database::selectOne($sql . ' LIMIT 1', $params);

        return $row === null ? null : self::cast($row);
    }

    /**
     * Full device reset used by the owner panel:
     *   - every open device session is revoked,
     *   - the API token is destroyed,
     *   - a fresh pairing code is generated,
     *   - the status goes back to "pending" so the device must register
     *     and be activated again.
     *
     * @return array{device_id:int, pairing_code:string}
     */
    public static function resetRegistration(int $deviceId, string $reason = 'owner_reset'): array
    {
        $pairing = Security::deviceCode();

        self::updateById($deviceId, [
            'api_token_hash'     => null,
            'token_prefix'       => null,
            'token_expires_at'   => null,
            'token_rotated_at'   => date('Y-m-d H:i:s'),
            'pairing_code'       => $pairing,
            'pairing_expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'status'             => self::STATUS_PENDING,
            'activated_at'       => null,
            'last_seen_at'       => null,
            'last_reset_at'      => date('Y-m-d H:i:s'),
            'last_reset_reason'  => substr($reason, 0, 60),
        ]);

        // reset_count is incremented in SQL so concurrent resets can not
        // overwrite each other.
        Database::run(
            'UPDATE `' . self::tableName() . '` SET `reset_count` = `reset_count` + 1 WHERE `id` = ?',
            [$deviceId]
        );

        DeviceSession::revokeAllForDevice($deviceId, $reason);

        return ['device_id' => $deviceId, 'pairing_code' => $pairing];
    }

    public static function setStatus(int $deviceId, string $status): bool
    {
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_DISABLED, self::STATUS_REVOKED], true)) {
            return false;
        }

        $data = ['status' => $status];

        if ($status === self::STATUS_ACTIVE) {
            $data['activated_at'] = date('Y-m-d H:i:s');
        }

        self::updateById($deviceId, $data);

        if ($status !== self::STATUS_ACTIVE) {
            DeviceSession::revokeAllForDevice($deviceId, 'status_' . $status);
        }

        return true;
    }

    /**
     * Devices seen in the last few minutes (owner dashboard widget).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function latestOnline(int $limit = 8, int $offlineAfter = 300): array
    {
        $rows = \App\Core\Database::select(
            'SELECT d.*, c.`name` AS customer_name FROM `' . self::tableName() . '` d'
            . ' LEFT JOIN `' . \App\Core\Database::table('customers') . '` c ON c.`id` = d.`customer_id`'
            . ' WHERE d.`deleted_at` IS NULL AND d.`last_seen_at` IS NOT NULL'
            . ' ORDER BY d.`last_seen_at` DESC LIMIT ' . max(1, $limit)
        );

        return array_map([static::class, 'cast'], $rows);
    }

    public static function countForCustomer(int $customerId): int
    {
        return (int) \App\Core\Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL',
            [$customerId]
        );
    }

    /**
     * Bulk status change for every device of a customer (used when a
     * customer is suspended). Also revokes the device sessions.
     */
    /**
     * Switch every device of a customer to one status.
     *
     * @param bool $wipeTokens When true the device credentials are dropped as
     *                         well, so the device has to register again
     *                         (used when a suspended customer is re-enabled).
     */
    public static function setStatusForCustomer(int $customerId, string $status, bool $wipeTokens = false): int
    {
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_DISABLED, self::STATUS_REVOKED], true)) {
            return 0;
        }

        $devices = Database::select(
            'SELECT `id` FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL AND `status` <> ?',
            [$customerId, $status]
        );

        foreach ($devices as $device) {
            if ($wipeTokens) {
                self::updateById((int) $device['id'], [
                    'status'             => $status,
                    'api_token_hash'     => null,
                    'token_prefix'       => null,
                    'token_expires_at'   => null,
                    'pairing_code'       => Security::deviceCode(),
                    'pairing_expires_at' => date('Y-m-d H:i:s', time() + 3600),
                    'activated_at'       => null,
                    'last_seen_at'       => null,
                    'disabled_by_customer' => $status === self::STATUS_DISABLED ? 1 : 0,
                ]);

                DeviceSession::revokeAllForDevice((int) $device['id'], 'customer_status_change');

                continue;
            }

            self::updateById((int) $device['id'], [
                'status' => $status,
                'disabled_by_customer' => $status === self::STATUS_DISABLED ? 1 : 0,
            ]);
        }

        return count($devices);
    }
}
