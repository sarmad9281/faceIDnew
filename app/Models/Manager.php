<?php
/**
 * SARMAD Attendance - managers (customer side employers).
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Security;

final class Manager extends Model
{
    protected static string $table = 'managers';
    protected static bool $softDeletes = true;

    protected static array $fillable = [
        'uuid', 'customer_id', 'user_id', 'name', 'title', 'mobile', 'email',
        'is_primary', 'is_active', 'created_by',
    ];

    /**
     * @param array<string,mixed> $data
     */
    public static function createManager(array $data): int
    {
        $data['uuid'] = $data['uuid'] ?? Security::uuid4();

        return self::create($data);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forCustomer(int $customerId): array
    {
        return self::where(['customer_id' => $customerId], 'is_primary DESC, id ASC');
    }

    public static function linkUser(int $managerId, int $userId): void
    {
        self::updateById($managerId, ['user_id' => $userId]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function primaryFor(int $customerId): ?array
    {
        $rows = self::where(['customer_id' => $customerId], 'is_primary DESC, id ASC', 1);

        return $rows[0] ?? null;
    }

    /* =================================================================
     * Owner panel queries (stage 2)
     * ===============================================================*/

    /**
     * @param array{q?:string,customer_id?:int,status?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public static function paginateForOwner(array $filters, int $page = 1, int $perPage = 20): array
    {
        $where  = ['m.`deleted_at` IS NULL'];
        $params = [];

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $where[] = '(m.`name` LIKE :q_name OR m.`email` LIKE :q_email OR m.`mobile` LIKE :q_mobile'
                . ' OR u.`username` LIKE :q_username OR c.`name` LIKE :q_customer)';
            $like = '%' . $search . '%';
            $params[':q_name']     = $like;
            $params[':q_email']    = $like;
            $params[':q_mobile']   = $like;
            $params[':q_username'] = $like;
            $params[':q_customer'] = $like;
        }

        $customerId = (int) ($filters['customer_id'] ?? 0);

        if ($customerId > 0) {
            $where[] = 'm.`customer_id` = :customer_id';
            $params[':customer_id'] = $customerId;
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status === 'active') {
            $where[] = 'm.`is_active` = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'm.`is_active` = 0';
        }

        $whereSql = implode(' AND ', $where);

        $from = '`' . self::tableName() . '` m'
            . ' LEFT JOIN `' . \App\Core\Database::table('customers') . '` c ON c.`id` = m.`customer_id`'
            . ' LEFT JOIN `' . \App\Core\Database::table('users') . '` u ON u.`id` = m.`user_id`';

        $total = (int) \App\Core\Database::scalar('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $whereSql, $params);

        $perPage = max(5, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = \App\Core\Database::select(
            'SELECT m.*, c.`name` AS customer_name, c.`uuid` AS customer_uuid, c.`status` AS customer_status,'
            . ' u.`username`, u.`last_login_at`, u.`is_active` AS user_active, u.`must_change_password`'
            . ' FROM ' . $from . ' WHERE ' . $whereSql
            . ' ORDER BY m.`id` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'rows'     => array_map([self::class, 'cast'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * Authoritative tenant check for a manager row: the manager must exist
     * AND belong to the given customer. Never trust an id coming from the
     * browser.
     *
     * @return array<string,mixed>|null
     */
    public static function findInCustomer(int $managerId, int $customerId): ?array
    {
        if ($managerId <= 0 || $customerId <= 0) {
            return null;
        }

        $row = \App\Core\Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '` WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$managerId, $customerId]
        );

        return $row === null ? null : self::cast($row);
    }

    /**
     * Latest manager logins across the platform (owner dashboard widget).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function latestLogins(int $limit = 8): array
    {
        $rows = \App\Core\Database::select(
            'SELECT m.`id`, m.`name`, m.`email`, m.`customer_id`, u.`username`, u.`last_login_at`, u.`last_login_ip`,'
            . ' c.`name` AS customer_name'
            . ' FROM `' . self::tableName() . '` m'
            . ' JOIN `' . \App\Core\Database::table('users') . '` u ON u.`id` = m.`user_id`'
            . ' LEFT JOIN `' . \App\Core\Database::table('customers') . '` c ON c.`id` = m.`customer_id`'
            . ' WHERE m.`deleted_at` IS NULL AND u.`last_login_at` IS NOT NULL'
            . ' ORDER BY u.`last_login_at` DESC LIMIT ' . max(1, $limit)
        );

        return array_map([self::class, 'cast'], $rows);
    }

    public static function setActive(int $managerId, bool $active): void
    {
        \App\Core\Database::update(self::tableName(), ['is_active' => $active ? 1 : 0], ['id' => $managerId]);
    }
}
