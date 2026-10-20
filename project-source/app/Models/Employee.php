<?php
/**
 * SARMAD Attendance - employees (identity only in stage 1).
 *
 * NOTE: the full employee management UI belongs to the manager panel in
 * the next stage. The model exists now so that devices, attendance and
 * face data can be attached to a stable identity later.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;

final class Employee extends Model
{
    protected static string $table = 'employees';
    protected static bool $softDeletes = true;

    protected static array $fillable = [
        'uuid', 'customer_id', 'branch_id', 'user_id', 'employee_code', 'first_name', 'last_name',
        'national_id', 'mobile', 'email', 'position', 'department', 'employment_type',
        'hire_date', 'status', 'photo_path', 'face_enrolled', 'face_status', 'notes', 'meta', 'created_by',
    ];

    /**
     * @param array<string,mixed> $data
     */
    public static function createEmployee(array $data): int
    {
        $data['uuid'] = $data['uuid'] ?? Security::uuid4();

        return self::create($data);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forCustomer(int $customerId, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL'
            . ' ORDER BY `last_name` ASC, `first_name` ASC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        return array_map([self::class, 'cast'], Database::select($sql, [$customerId]));
    }

    public static function codeTaken(int $customerId, string $code, int $exceptId = 0): bool
    {
        $sql    = 'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `employee_code` = ? AND `deleted_at` IS NULL';
        $params = [$customerId, $code];

        if ($exceptId > 0) {
            $sql .= ' AND `id` <> ?';
            $params[] = $exceptId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    /**
     * The attendance contract for the future capture engine: a disabled
     * (suspended / terminated) employee can never create a NEW attendance
     * record, while all of his earlier records stay untouched in the reports.
     *
     * @param array<string,mixed> $employee
     */
    public static function canAttend(array $employee): bool
    {
        return (string) ($employee['status'] ?? '') === 'active'
            && empty($employee['deleted_at']);
    }

    /**
     * Tenant safe lookup - the ONLY way the manager panel loads an employee.
     * An id that belongs to another customer simply returns null.
     *
     * @return array<string,mixed>|null
     */
    public static function findInCustomer(int $employeeId, int $customerId): ?array
    {
        if ($employeeId <= 0 || $customerId <= 0) {
            return null;
        }

        $row = Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '` WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$employeeId, $customerId]
        );

        if ($row === null) {
            return null;
        }

        return self::cast($row);
    }

    /**
     * Employee of one customer with branch + shift attached (profile page).
     *
     * @return array<string,mixed>|null
     */
    public static function profileInCustomer(int $employeeId, int $customerId): ?array
    {
        $employee = self::findInCustomer($employeeId, $customerId);

        if ($employee === null) {
            return null;
        }

        $branch = null;

        if (!empty($employee['branch_id'])) {
            $branch = Database::selectOne(
                'SELECT `id`, `name`, `code`, `city` FROM `' . Database::table('branches') . '` WHERE `id` = ? AND `customer_id` = ? LIMIT 1',
                [(int) $employee['branch_id'], $customerId]
            );
        }

        $employee['branch_name'] = $branch['name'] ?? null;
        $employee['branch_code'] = $branch['code'] ?? null;

        return $employee;
    }

    /**
     * Active head count of one customer (dashboard card).
     */
    public static function activeCount(int $customerId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . "` WHERE `customer_id` = ? AND `status` = 'active' AND `deleted_at` IS NULL",
            [$customerId]
        );
    }

    /**
     * Total head count of one customer (dashboard card).
     */
    public static function totalCount(int $customerId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL',
            [$customerId]
        );
    }

    /**
     * The employees of one customer that are NOT present today - the base for
     * the "absence" card. Nothing is invented: the list contains real
     * employees without a record for today and without an approved leave.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function withoutAttendanceOn(int $customerId, string $date, int $limit = 8): array
    {
        $sql = 'SELECT e.`id`, e.`first_name`, e.`last_name`, e.`employee_code`'
            . ' FROM `' . self::tableName() . '` e'
            . ' WHERE e.`customer_id` = ? AND e.`deleted_at` IS NULL AND e.`status` = \'active\''
            . ' AND NOT EXISTS (SELECT 1 FROM `' . Database::table('records') . '` r'
            . '   WHERE r.`employee_id` = e.`id` AND r.`attendance_date` = ? AND r.`deleted_at` IS NULL)'
            . ' AND NOT EXISTS (SELECT 1 FROM `' . Database::table('leaves') . '` l'
            . "   WHERE l.`employee_id` = e.`id` AND l.`status` = 'approved' AND l.`deleted_at` IS NULL"
            . '     AND l.`start_date` <= ? AND l.`end_date` >= ?)'
            . ' ORDER BY e.`last_name` ASC, e.`first_name` ASC LIMIT ' . max(1, min(50, $limit));

        return Database::select($sql, [$customerId, $date, $date, $date]);
    }

    /**
     * Short label used in selects and tables.
     */
    public static function optionLabel(array $employee): string
    {
        $name = self::fullName($employee);

        if ($name === '') {
            $name = 'پرسنل #' . (int) ($employee['id'] ?? 0);
        }

        $code = trim((string) ($employee['employee_code'] ?? ''));

        return $code === '' ? $name : $name . ' (' . $code . ')';
    }

    public static function fullName(array $employee): string
    {
        return trim(((string) ($employee['first_name'] ?? '')) . ' ' . ((string) ($employee['last_name'] ?? '')));
    }

    /* =================================================================
     * Listings used by the owner / manager panels (stage 2)
     * ===============================================================*/

    /**
     * @param array{q?:string,customer_id?:int,branch_id?:int,status?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public static function paginate(array $filters, int $page = 1, int $perPage = 20): array
    {
        $where  = ['e.`deleted_at` IS NULL'];
        $params = [];

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $where[] = '(e.`first_name` LIKE :q_first OR e.`last_name` LIKE :q_last OR e.`employee_code` LIKE :q_code'
                . ' OR e.`mobile` LIKE :q_mobile OR e.`national_id` LIKE :q_national OR c.`name` LIKE :q_customer)';
            $like = '%' . $search . '%';
            $params[':q_first']    = $like;
            $params[':q_last']     = $like;
            $params[':q_code']     = $like;
            $params[':q_mobile']   = $like;
            $params[':q_national'] = $like;
            $params[':q_customer'] = $like;
        }

        $customerId = (int) ($filters['customer_id'] ?? 0);

        if ($customerId > 0) {
            $where[] = 'e.`customer_id` = :customer_id';
            $params[':customer_id'] = $customerId;
        }

        $branchId = (int) ($filters['branch_id'] ?? 0);

        if ($branchId > 0) {
            $where[] = 'e.`branch_id` = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        $status = (string) ($filters['status'] ?? '');

        if (in_array($status, ['active', 'suspended', 'terminated'], true)) {
            $where[] = 'e.`status` = :status';
            $params[':status'] = $status;
        }

        $whereSql = implode(' AND ', $where);

        $from = '`' . self::tableName() . '` e'
            . ' LEFT JOIN `' . \App\Core\Database::table('customers') . '` c ON c.`id` = e.`customer_id`'
            . ' LEFT JOIN `' . \App\Core\Database::table('branches') . '` b ON b.`id` = e.`branch_id`';

        $total = (int) \App\Core\Database::scalar('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $whereSql, $params);

        $perPage = max(5, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = \App\Core\Database::select(
            'SELECT e.*, c.`name` AS customer_name, c.`uuid` AS customer_uuid, b.`name` AS branch_name'
            . ' FROM ' . $from . ' WHERE ' . $whereSql
            . ' ORDER BY e.`last_name` ASC, e.`first_name` ASC LIMIT ' . $perPage . ' OFFSET ' . $offset,
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
     * Top customers by employee count (owner "employees overview" page).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function perCustomer(int $limit = 50): array
    {
        $rows = \App\Core\Database::select(
            'SELECT c.`id`, c.`uuid`, c.`name`, c.`status`,'
            . ' COUNT(e.`id`) AS employee_count,'
            . " SUM(e.`status` = 'active') AS active_count,"
            . ' SUM(e.`face_enrolled` = 1) AS face_count'
            . ' FROM `' . \App\Core\Database::table('customers') . '` c'
            . ' LEFT JOIN `' . self::tableName() . '` e ON e.`customer_id` = c.`id` AND e.`deleted_at` IS NULL'
            . ' WHERE c.`deleted_at` IS NULL GROUP BY c.`id`'
            . ' ORDER BY employee_count DESC, c.`name` ASC LIMIT ' . max(1, $limit)
        );

        return array_map([static::class, 'cast'], $rows);
    }

    public static function statusCounts(int $customerId = 0): array
    {
        $sql    = 'SELECT COUNT(*) AS total,'
            . " SUM(`status` = 'active') AS active,"
            . " SUM(`status` = 'suspended') AS suspended,"
            . " SUM(`status` = 'terminated') AS `terminated`,"
            . ' SUM(`face_enrolled` = 1) AS face_enrolled'
            . ' FROM `' . self::tableName() . '` WHERE `deleted_at` IS NULL';
        $params = [];

        if ($customerId > 0) {
            $sql .= ' AND `customer_id` = ?';
            $params[] = $customerId;
        }

        $row = \App\Core\Database::selectOne($sql, $params) ?? [];

        return [
            'total'         => (int) ($row['total'] ?? 0),
            'active'        => (int) ($row['active'] ?? 0),
            'suspended'     => (int) ($row['suspended'] ?? 0),
            'terminated'    => (int) ($row['terminated'] ?? 0),
            'face_enrolled' => (int) ($row['face_enrolled'] ?? 0),
        ];
    }
}
