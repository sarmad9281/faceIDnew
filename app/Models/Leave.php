<?php
/**
 * SARMAD Attendance - leaves (stage 3, manager panel).
 *
 * Basic CRUD over attendance_leaves with a fixed set of base types. The type
 * is a plain string column, so Persian / Shamsi specific types can be added
 * later without a schema change.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;

final class Leave extends Model
{
    protected static string $table = 'leaves';
    protected static bool $softDeletes = true;

    public const TYPES = [
        'annual' => 'استحقاقی',
        'sick'   => 'استعلاجی',
        'unpaid' => 'بدون حقوق',
        'other'  => 'سایر',
    ];

    public const STATUSES = [
        'pending'  => 'در انتظار',
        'approved' => 'تأیید شده',
        'rejected' => 'رد شده',
        'canceled' => 'لغو شده',
    ];

    protected static array $fillable = [
        'uuid', 'customer_id', 'employee_id', 'leave_type', 'start_date', 'end_date', 'days',
        'description', 'status', 'decided_by', 'decided_at', 'decision_note', 'created_by',
    ];

    public static function typeLabel(?string $type): string
    {
        return self::TYPES[(string) $type] ?? (string) $type;
    }

    public static function statusLabel(?string $status): string
    {
        return self::STATUSES[(string) $status] ?? (string) $status;
    }

    /**
     * Inclusive day count between two dates (1 day minimum).
     */
    public static function days(string $start, string $end): float
    {
        $from = strtotime($start);
        $to   = strtotime($end);

        if ($from === false || $to === false) {
            return 1.0;
        }

        if ($to < $from) {
            return 1.0;
        }

        return (float) (intdiv($to - $from, 86400) + 1);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function createLeave(array $data): int
    {
        $data['uuid'] = $data['uuid'] ?? Security::uuid4();

        return self::create($data);
    }

    /**
     * @param array{q?:string,employee_id?:int,leave_type?:string,status?:string,date_from?:string,date_to?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int,counts:array<string,int>}
     */
    public static function paginate(int $customerId, array $filters, int $page = 1, int $perPage = 20): array
    {
        $where  = ['l.`deleted_at` IS NULL', 'l.`customer_id` = :tenant'];
        $params = [':tenant' => $customerId];

        $employeeId = (int) ($filters['employee_id'] ?? 0);

        if ($employeeId > 0) {
            $where[] = 'l.`employee_id` = :employee_id';
            $params[':employee_id'] = $employeeId;
        }

        $type = (string) ($filters['leave_type'] ?? '');

        if ($type !== '' && isset(self::TYPES[$type])) {
            $where[] = 'l.`leave_type` = :leave_type';
            $params[':leave_type'] = $type;
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status !== '' && isset(self::STATUSES[$status])) {
            $where[] = 'l.`status` = :status';
            $params[':status'] = $status;
        }

        $from = trim((string) ($filters['date_from'] ?? ''));

        if ($from !== '') {
            $where[] = 'l.`end_date` >= :date_from';
            $params[':date_from'] = $from;
        }

        $to = trim((string) ($filters['date_to'] ?? ''));

        if ($to !== '') {
            $where[] = 'l.`start_date` <= :date_to';
            $params[':date_to'] = $to;
        }

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $where[] = '(e.`first_name` LIKE :q_first OR e.`last_name` LIKE :q_last OR e.`employee_code` LIKE :q_code)';
            $like = '%' . $search . '%';
            $params[':q_first'] = $like;
            $params[':q_last']  = $like;
            $params[':q_code']  = $like;
        }

        $whereSql = implode(' AND ', $where);

        $from_sql = '`' . self::tableName() . '` l'
            . ' INNER JOIN `' . Database::table('employees') . '` e ON e.`id` = l.`employee_id`';

        $total = (int) Database::scalar('SELECT COUNT(*) FROM ' . $from_sql . ' WHERE ' . $whereSql, $params);

        $perPage = max(5, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            'SELECT l.*, e.`first_name`, e.`last_name`, e.`employee_code`, e.`status` AS employee_status'
            . ' FROM ' . $from_sql . ' WHERE ' . $whereSql
            . ' ORDER BY l.`start_date` DESC, l.`id` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'rows'     => array_map([static::class, 'cast'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) max(1, (int) ceil($total / $perPage)),
            'counts'   => self::statusCounts($customerId),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findInCustomer(int $leaveId, int $customerId): ?array
    {
        if ($leaveId <= 0 || $customerId <= 0) {
            return null;
        }

        $row = Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '` WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$leaveId, $customerId]
        );

        return $row === null ? null : self::cast($row);
    }

    /**
     * @return array<string,int>
     */
    public static function statusCounts(int $customerId): array
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS total,'
            . " COALESCE(SUM(`status` = 'pending'), 0) AS pending,"
            . " COALESCE(SUM(`status` = 'approved'), 0) AS approved,"
            . " COALESCE(SUM(`status` = 'rejected'), 0) AS rejected,"
            . " COALESCE(SUM(`status` = 'canceled'), 0) AS canceled"
            . ' FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `deleted_at` IS NULL',
            [$customerId]
        ) ?? [];

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'pending'  => (int) ($row['pending'] ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
            'canceled' => (int) ($row['canceled'] ?? 0),
        ];
    }

    /**
     * Overlapping leave of the same employee (keeps the data honest).
     *
     * @return array<string,mixed>|null
     */
    public static function overlapping(int $employeeId, int $customerId, string $start, string $end, int $exceptId = 0): ?array
    {
        $sql = 'SELECT * FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `employee_id` = ? AND `deleted_at` IS NULL'
            . " AND `status` IN ('pending','approved') AND `start_date` <= ? AND `end_date` >= ?";
        $params = [$customerId, $employeeId, $end, $start];

        if ($exceptId > 0) {
            $sql .= ' AND `id` <> ?';
            $params[] = $exceptId;
        }

        $row = Database::selectOne($sql . ' LIMIT 1', $params);

        return $row === null ? null : self::cast($row);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function forEmployee(int $employeeId, int $customerId, int $limit = 10): array
    {
        if ($employeeId <= 0 || $customerId <= 0) {
            return [];
        }

        return Database::select(
            'SELECT * FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `employee_id` = ? AND `deleted_at` IS NULL'
            . ' ORDER BY `start_date` DESC LIMIT ' . max(1, min(50, $limit)),
            [$customerId, $employeeId]
        );
    }

    /**
     * Approved leaves covering the given day (dashboard card).
     */
    public static function approvedOn(int $customerId, string $date): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . " WHERE `customer_id` = ? AND `status` = 'approved' AND `deleted_at` IS NULL AND `start_date` <= ? AND `end_date` >= ?",
            [$customerId, $date, $date]
        );
    }

    /**
     * Approve / reject / cancel a request.
     */
    public static function decide(int $leaveId, int $customerId, string $status, int $actorId, string $note = ''): bool
    {
        if (!isset(self::STATUSES[$status])) {
            return false;
        }

        return Database::run(
            'UPDATE `' . self::tableName() . '` SET `status` = ?, `decided_by` = ?, `decided_at` = NOW(), `decision_note` = ?'
            . ' WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL',
            [$status, $actorId > 0 ? $actorId : null, $note !== '' ? $note : null, $leaveId, $customerId]
        )->rowCount() >= 0;
    }
}
