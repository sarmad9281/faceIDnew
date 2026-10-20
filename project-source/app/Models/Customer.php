<?php
/**
 * SARMAD Attendance - customers (tenants).
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;

final class Customer extends Model
{
    protected static string $table = 'customers';
    protected static bool $softDeletes = true;

    protected static array $fillable = [
        'uuid', 'name', 'slug', 'company_name', 'contact_email', 'contact_phone', 'address',
        'logo_path', 'timezone', 'locale', 'date_system', 'subscription_plan',
        'max_employees', 'max_devices', 'status', 'settings', 'trial_ends_at', 'created_by',
    ];

    /**
     * The ONLY customer columns a manager may change: the business identity
     * of his own customer. Subscription, feature flags and status are owner
     * level and are silently dropped here even if they arrive in the payload.
     *
     * @param array<string,mixed> $data
     */
    public static function updateProfile(int $customerId, array $data): int
    {
        $allowed = ['name', 'company_name', 'contact_email', 'contact_phone', 'address', 'logo_path', 'timezone'];

        $payload = [];

        foreach ($allowed as $column) {
            if (array_key_exists($column, $data)) {
                $payload[$column] = $data[$column];
            }
        }

        if ($payload === []) {
            return 0;
        }

        return self::updateById($customerId, $payload);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findByUuid(string $uuid): ?array
    {
        if (!preg_match('/^[0-9a-f\-]{36}$/i', $uuid)) {
            return null;
        }

        return self::findBy('uuid', strtolower($uuid));
    }

    public static function findBySlug(string $slug): ?array
    {
        return self::findBy('slug', $slug);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function createCustomer(array $data): int
    {
        $data['uuid'] = $data['uuid'] ?? Security::uuid4();
        $data['slug'] = $data['slug'] ?? self::uniqueSlug((string) $data['name']);

        return self::create($data);
    }

    public static function uniqueSlug(string $name): string
    {
        $base = \App\Core\Str::slug($name);
        $slug = $base;
        $i    = 1;

        while (self::findBy('slug', $slug) !== null) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    public static function isActive(int $customerId): bool
    {
        $row = self::find($customerId);

        return $row !== null && (string) $row['status'] === 'active';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function listAll(string $search = '', int $limit = 50, int $offset = 0): array
    {
        $sql    = 'SELECT * FROM `' . self::tableName() . '` WHERE `deleted_at` IS NULL';
        $params = [];

        if ($search !== '') {
            $sql .= ' AND (`name` LIKE :q OR `slug` LIKE :q2 OR `company_name` LIKE :q3)';
            $params[':q']  = '%' . $search . '%';
            $params[':q2'] = '%' . $search . '%';
            $params[':q3'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY `id` DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        return array_map([self::class, 'cast'], Database::select($sql, $params));
    }

    public static function totalCount(string $search = ''): int
    {
        $sql    = 'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `deleted_at` IS NULL';
        $params = [];

        if ($search !== '') {
            $sql .= ' AND (`name` LIKE :q OR `slug` LIKE :q2)';
            $params[':q']  = '%' . $search . '%';
            $params[':q2'] = '%' . $search . '%';
        }

        return (int) Database::scalar($sql, $params);
    }

    /**
     * Customer level counters used on the owner dashboard.
     *
     * @return array<string,int>
     */
    public static function stats(int $customerId): array
    {
        return [
            'managers'  => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('managers') . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL', [$customerId]),
            'users'     => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('users') . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL', [$customerId]),
            'branches'  => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('branches') . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL', [$customerId]),
            'employees' => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('employees') . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL', [$customerId]),
            'devices'   => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('devices') . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL', [$customerId]),
        ];
    }

    /* =================================================================
     * Owner panel queries (stage 2)
     * ===============================================================*/

    /**
     * Filtered + paginated listing for the owner customer table.
     *
     * All filters are bound as prepared statement parameters; the ORDER BY
     * is whitelisted by Model::safeOrder().
     *
     * @param array{q?:string,status?:string,subscription?:string,plan?:string,sort?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public static function paginateForOwner(array $filters, int $page = 1, int $perPage = 20): array
    {
        $where  = ['`deleted_at` IS NULL'];
        $params = [];

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $where[] = '(`name` LIKE :q_name OR `company_name` LIKE :q_company OR `slug` LIKE :q_slug'
                . ' OR `contact_email` LIKE :q_email OR `contact_phone` LIKE :q_phone)';
            $like = '%' . $search . '%';
            $params[':q_name']    = $like;
            $params[':q_company'] = $like;
            $params[':q_slug']    = $like;
            $params[':q_email']   = $like;
            $params[':q_phone']   = $like;
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status !== '' && in_array($status, ['active', 'suspended', 'archived'], true)) {
            $where[] = '`status` = :status';
            $params[':status'] = $status;
        }

        $subscription = (string) ($filters['subscription'] ?? '');

        if ($subscription !== '') {
            if ($subscription === 'expired') {
                $where[] = "(`subscription_status` = 'expired' OR (`subscription_expire_date` IS NOT NULL AND `subscription_expire_date` < CURDATE()))";
            } elseif ($subscription === 'expiring') {
                $where[] = '(`subscription_expire_date` IS NOT NULL AND `subscription_expire_date` BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY))';
            } elseif (in_array($subscription, ['trial', 'active', 'suspended', 'canceled'], true)) {
                $where[] = '`subscription_status` = :subscription';
                $params[':subscription'] = $subscription;
            }
        }

        $plan = (string) ($filters['plan'] ?? '');

        if ($plan !== '') {
            $where[] = '`plan_name` = :plan';
            $params[':plan'] = $plan;
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE ' . $whereSql, $params);

        $orderBy = self::safeOrder((string) ($filters['sort'] ?? 'id DESC'));
        $perPage = max(5, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            'SELECT * FROM `' . self::tableName() . '` WHERE ' . $whereSql
            . ' ORDER BY ' . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
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
     * Enrich a list of customers with the counters the owner panel shows.
     *
     * @param array<int,array<string,mixed>> $customers
     * @return array<int,array<string,mixed>>
     */
    public static function withCounters(array $customers): array
    {
        if ($customers === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $customers);

        $employees = self::countByCustomer('employees', $ids);
        $devices   = self::countByCustomer('devices', $ids);
        $managers  = self::countByCustomer('managers', $ids);
        $online    = self::countByCustomer('devices', $ids, "AND `status` = 'active' AND `last_seen_at` >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        $flags     = \App\Models\FeatureFlag::enabledCounts($ids);

        foreach ($customers as $index => $customer) {
            $id = (int) $customer['id'];

            $customers[$index]['employee_count'] = $employees[$id] ?? 0;
            $customers[$index]['device_count']   = $devices[$id] ?? 0;
            $customers[$index]['manager_count']  = $managers[$id] ?? 0;
            $customers[$index]['online_count']   = $online[$id] ?? 0;
            $customers[$index]['feature_count']  = $flags[$id] ?? 0;
            $customers[$index]['effective_subscription_status'] = \App\Models\Subscription::effectiveStatus([
                'status'      => (string) ($customer['subscription_status'] ?? 'trial'),
                'expire_date' => $customer['subscription_expire_date'] ?? null,
            ]);
        }

        return $customers;
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    private static function countByCustomer(string $shortTable, array $ids, string $extra = ''): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = Database::select(
            'SELECT `customer_id`, COUNT(*) AS total FROM `' . Database::table($shortTable) . '`'
            . ' WHERE `customer_id` IN (' . $placeholders . ') AND `deleted_at` IS NULL ' . $extra
            . ' GROUP BY `customer_id`',
            array_values($ids)
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(int) $row['customer_id']] = (int) $row['total'];
        }

        return $map;
    }

    public static function setStatus(int $customerId, string $status): bool
    {
        if (!in_array($status, ['active', 'suspended', 'archived'], true)) {
            return false;
        }

        self::updateById($customerId, ['status' => $status]);

        return true;
    }

    /**
     * Relative URL of the customer logo (or null).
     *
     * @param array<string,mixed> $customer
     */
    public static function logoUrl(array $customer): ?string
    {
        $path = trim((string) ($customer['logo_path'] ?? ''));

        if ($path === '') {
            return null;
        }

        return \App\Support\Url::upload($path);
    }

    /**
     * Overall counters for the owner dashboard (real SQL aggregates).
     *
     * @return array<string,int>
     */
    public static function platformCounters(): array
    {
        $customers = Database::selectOne(
            'SELECT COUNT(*) AS total,'
            . " SUM(`status` = 'active') AS active,"
            . " SUM(`status` = 'suspended') AS suspended,"
            . " SUM(`status` = 'archived') AS archived"
            . ' FROM `' . self::tableName() . '` WHERE `deleted_at` IS NULL'
        ) ?? [];

        $devices = Database::selectOne(
            'SELECT COUNT(*) AS total,'
            . " SUM(`status` = 'active') AS active,"
            . " SUM(`status` = 'pending') AS pending,"
            . " SUM(`status` = 'disabled') AS disabled,"
            . " SUM(`status` = 'revoked') AS revoked,"
            . " SUM(`status` = 'active' AND `last_seen_at` >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)) AS online"
            . ' FROM `' . Database::table('devices') . '` WHERE `deleted_at` IS NULL'
        ) ?? [];

        return [
            'customers_total'     => (int) ($customers['total'] ?? 0),
            'customers_active'    => (int) ($customers['active'] ?? 0),
            'customers_suspended' => (int) ($customers['suspended'] ?? 0),
            'customers_inactive'  => (int) ($customers['suspended'] ?? 0) + (int) ($customers['archived'] ?? 0),
            'managers_total'      => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('managers') . '` WHERE `deleted_at` IS NULL'),
            'managers_active'     => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('managers') . '` WHERE `deleted_at` IS NULL AND `is_active` = 1'),
            'employees_total'     => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('employees') . '` WHERE `deleted_at` IS NULL'),
            'employees_active'    => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('employees') . "` WHERE `deleted_at` IS NULL AND `status` = 'active'"),
            'branches_total'      => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('branches') . '` WHERE `deleted_at` IS NULL'),
            'users_total'         => (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('users') . '` WHERE `deleted_at` IS NULL'),
            'devices_total'       => (int) ($devices['total'] ?? 0),
            'devices_active'      => (int) ($devices['active'] ?? 0),
            'devices_pending'     => (int) ($devices['pending'] ?? 0),
            'devices_disabled'    => (int) ($devices['disabled'] ?? 0),
            'devices_revoked'     => (int) ($devices['revoked'] ?? 0),
            'devices_online'      => (int) ($devices['online'] ?? 0),
        ];
    }
}
