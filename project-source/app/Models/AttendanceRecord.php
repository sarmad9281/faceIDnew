<?php
/**
 * SARMAD Attendance - attendance records (stage 3 structure).
 *
 * The capture engine (device driven clock in / clock out) belongs to a later
 * stage; what exists now is the real table plus the real read side: the
 * manager panel filters, paginates and aggregates attendance_records, and
 * every query is scoped to one customer. Until the engine writes rows the
 * panel shows honest empty states - no sample data is ever inserted here.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class AttendanceRecord extends Model
{
    /** Short table name -> attendance_records. */
    protected static string $table = 'records';
    protected static bool $softDeletes = true;

    public const STATUSES = [
        'present'     => 'حاضر',
        'late'        => 'تأخیر',
        'early_leave' => 'خروج زودتر',
        'overtime'    => 'اضافه‌کار',
        'absent'      => 'غایب',
        'incomplete'  => 'ناقص',
        'leave'       => 'مرخصی',
        'on_leave'    => 'مرخصی تأییدشده',
        'holiday'     => 'تعطیل',
        'manual'      => 'دستی',
    ];

    /** فقط این وضعیت‌ها در جمع‌های حضور معنا دارند. */
    public const COUNTED_STATUSES = ['present', 'late', 'early_leave', 'overtime', 'incomplete', 'manual'];

    /** منابع مجاز یک رکورد. */
    public const SOURCES = [
        'device'          => 'دستگاه (آنلاین)',
        'device_offline'  => 'دستگاه (آفلاین - هم‌گام‌شده)',
        'manual'          => 'اصلاح دستی',
        'import'          => 'ورود گروهی',
        'api'             => 'API',
        'system'          => 'سیستم (محاسبه‌شده)',
    ];

    protected static array $fillable = [
        'uuid', 'customer_id', 'employee_id', 'branch_id', 'device_id', 'shift_id', 'attendance_date',
        'entry_at', 'exit_at', 'work_minutes', 'late_minutes', 'early_leave_minutes', 'overtime_minutes',
        'status', 'source', 'match_score', 'meta', 'flags', 'session_index', 'open_key',
        'idempotency_key', 'local_event_id', 'local_exit_event_id', 'device_event_at', 'device_exit_at', 'server_received_at', 'server_exit_received_at', 'recognition_uuid',
        'shift_snapshot', 'calc_version', 'last_event', 'recalculated_at',
        'modified_by', 'modify_reason', 'voided_at', 'voided_by', 'void_reason', 'deleted_by', 'delete_reason',
    ];

    public static function statusLabel(?string $status): string
    {
        return self::STATUSES[(string) $status] ?? (string) $status;
    }

    public static function minutesLabel($minutes): string
    {
        $minutes = (int) $minutes;

        if ($minutes <= 0) {
            return '—';
        }

        return intdiv($minutes, 60) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Filtered, paginated attendance list of ONE customer.
     *
     * @param array{date_from?:string,date_to?:string,employee_id?:int,branch_id?:int,status?:string,shift_id?:int,late?:int,early?:int,overtime?:int} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int,totals:array<string,int>}
     */
    public static function paginate(int $customerId, array $filters, int $page = 1, int $perPage = 25): array
    {
        [$where, $params] = self::buildFilters($customerId, $filters);

        $whereSql = implode(' AND ', $where);

        $from = '`' . self::tableName() . '` r'
            . ' INNER JOIN `' . Database::table('employees') . '` e ON e.`id` = r.`employee_id`'
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`'
            . ' LEFT JOIN `' . Database::table('shifts') . '` s ON s.`id` = r.`shift_id`'
            . ' LEFT JOIN `' . Database::table('devices') . '` d ON d.`id` = r.`device_id`';

        $total = (int) Database::scalar('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $whereSql, $params);

        $perPage = max(5, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            'SELECT r.*, e.`first_name`, e.`last_name`, e.`employee_code`, b.`name` AS branch_name,'
            . ' s.`name` AS shift_name, s.`start_time`, s.`end_time`, s.`late_grace_minutes`,'
            . ' d.`name` AS device_name'
            . ' FROM ' . $from . ' WHERE ' . $whereSql
            . ' ORDER BY r.`attendance_date` DESC, r.`entry_at` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'rows'     => array_map([static::class, 'cast'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) max(1, (int) ceil($total / $perPage)),
            'totals'   => self::totalsFor($from, $whereSql, $params),
        ];
    }

    /**
     * Aggregate of the CURRENT filter (so the header cards always match the
     * table below them).
     *
     * @param array<string,mixed> $params
     * @return array<string,int>
     */
    private static function totalsFor(string $from, string $whereSql, array $params): array
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS records,'
            . " COALESCE(SUM(r.`late_minutes` > 0), 0) AS late,"
            . " COALESCE(SUM(r.`early_leave_minutes` > 0), 0) AS early_leave,"
            . " COALESCE(SUM(r.`overtime_minutes` > 0), 0) AS overtime,"
            . " COALESCE(SUM(r.`status` = 'absent'), 0) AS absent,"
            . " COALESCE(SUM(r.`status` IN ('leave','on_leave')), 0) AS on_leave,"
            . " COALESCE(SUM(r.`status` = 'incomplete' OR (r.`entry_at` IS NOT NULL AND r.`exit_at` IS NULL)), 0) AS incomplete,"
            . ' COALESCE(SUM(r.`work_minutes`), 0) AS work_minutes'
            . ' FROM ' . $from . ' WHERE ' . $whereSql,
            $params
        ) ?? [];

        return [
            'records'      => (int) ($row['records'] ?? 0),
            'late'         => (int) ($row['late'] ?? 0),
            'early_leave'  => (int) ($row['early_leave'] ?? 0),
            'overtime'     => (int) ($row['overtime'] ?? 0),
            'absent'       => (int) ($row['absent'] ?? 0),
            'on_leave'     => (int) ($row['on_leave'] ?? 0),
            'incomplete'   => (int) ($row['incomplete'] ?? 0),
            'work_minutes' => (int) ($row['work_minutes'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:array<int,string>,1:array<string,mixed>}
     */
    private static function buildFilters(int $customerId, array $filters): array
    {
        $where  = ['r.`deleted_at` IS NULL', 'r.`customer_id` = :tenant'];
        $params = [':tenant' => $customerId];

        $from = trim((string) ($filters['date_from'] ?? ''));
        $to   = trim((string) ($filters['date_to'] ?? ''));

        if ($from !== '') {
            $where[]       = 'r.`attendance_date` >= :date_from';
            $params[':date_from'] = $from;
        }

        if ($to !== '') {
            $where[]     = 'r.`attendance_date` <= :date_to';
            $params[':date_to'] = $to;
        }

        $employeeId = (int) ($filters['employee_id'] ?? 0);

        if ($employeeId > 0) {
            $where[]            = 'r.`employee_id` = :employee_id';
            $params[':employee_id'] = $employeeId;
        }

        $branchId = (int) ($filters['branch_id'] ?? 0);

        if ($branchId > 0) {
            $where[]          = 'r.`branch_id` = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        $shiftId = (int) ($filters['shift_id'] ?? 0);

        if ($shiftId > 0) {
            $where[]         = 'r.`shift_id` = :shift_id';
            $params[':shift_id'] = $shiftId;
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status !== '' && isset(self::STATUSES[$status])) {
            $where[]        = 'r.`status` = :status';
            $params[':status'] = $status;
        }

        if (!empty($filters['late'])) {
            $where[] = 'r.`late_minutes` > 0';
        }

        if (!empty($filters['early'])) {
            $where[] = 'r.`early_leave_minutes` > 0';
        }

        if (!empty($filters['overtime'])) {
            $where[] = 'r.`overtime_minutes` > 0';
        }

        // آنلاین / آفلاین / دستی - بر اساس ستون source
        $origin = (string) ($filters['origin'] ?? '');

        if ($origin === 'online') {
            $where[] = "r.`source` = 'device'";
        } elseif ($origin === 'offline') {
            $where[] = "r.`source` = 'device_offline'";
        } elseif ($origin === 'manual') {
            $where[] = "r.`source` IN ('manual','import','api')";
        }

        // برچسب‌ها: هر پرچم داخل رشته flags
        $flag = trim((string) ($filters['flag'] ?? ''));

        if ($flag !== '' && preg_match('/^[a-z_]{2,30}$/', $flag) === 1) {
            // named placeholder: this query mixes :named filters with a few
            // positional ones, and PDO refuses to mix the two styles.
            $where[]        = 'FIND_IN_SET(:flag, r.`flags`) > 0';
            $params[':flag'] = $flag;
        }

        if (!empty($filters['inside'])) {
            $where[] = 'r.`open_key` IS NOT NULL';
        }

        if (!empty($filters['voided'])) {
            $where[0] = 'r.`deleted_at` IS NOT NULL';
        }

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $where[] = '(e.`first_name` LIKE :q_first OR e.`last_name` LIKE :q_last OR e.`employee_code` LIKE :q_code)';
            $like = '%' . $search . '%';
            $params[':q_first'] = $like;
            $params[':q_last']  = $like;
            $params[':q_code']  = $like;
        }

        return [$where, $params];
    }

    /**
     * Today's counters for the dashboard (all from real rows).
     *
     * @return array<string,int>
     */
    public static function kpis(int $customerId, string $date): array
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS records,'
            . ' COUNT(DISTINCT CASE WHEN `entry_at` IS NOT NULL THEN `employee_id` END) AS present,'
            . " COALESCE(SUM(`entry_at` IS NOT NULL), 0) AS entries,"
            . " COALESCE(SUM(`exit_at` IS NOT NULL), 0) AS exits,"
            . " COALESCE(SUM(`open_key` IS NOT NULL), 0) AS currently_inside,"
            . " COALESCE(SUM(`exit_at` IS NOT NULL), 0) AS completed,"
            . " COALESCE(SUM(`late_minutes` > 0), 0) AS late,"
            . " COALESCE(SUM(`early_leave_minutes` > 0), 0) AS early_leave,"
            . " COALESCE(SUM(`overtime_minutes` > 0), 0) AS overtime,"
            . " COALESCE(SUM(`status` = 'absent'), 0) AS absent,"
            . " COALESCE(SUM(`status` IN ('leave','on_leave')), 0) AS on_leave,"
            . " COALESCE(SUM(`status` = 'holiday'), 0) AS holiday,"
            . " COALESCE(SUM(`status` = 'incomplete'), 0) AS incomplete,"
            . ' COALESCE(SUM(`work_minutes`), 0) AS work_minutes,'
            . ' COALESCE(SUM(`overtime_minutes`), 0) AS overtime_minutes'
            . ' FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `attendance_date` = ? AND `deleted_at` IS NULL',
            [$customerId, $date]
        ) ?? [];

        return [
            'records'          => (int) ($row['records'] ?? 0),
            'entries'          => (int) ($row['entries'] ?? 0),
            'exits'            => (int) ($row['exits'] ?? 0),
            'currently_inside' => (int) ($row['currently_inside'] ?? 0),
            'completed'        => (int) ($row['completed'] ?? 0),
            'late'             => (int) ($row['late'] ?? 0),
            'early_leave'      => (int) ($row['early_leave'] ?? 0),
            'overtime'         => (int) ($row['overtime'] ?? 0),
            'absent'           => (int) ($row['absent'] ?? 0),
            'on_leave'         => (int) ($row['on_leave'] ?? 0),
            'holiday'          => (int) ($row['holiday'] ?? 0),
            'present'          => (int) ($row['present'] ?? 0),
            'incomplete'       => (int) ($row['incomplete'] ?? 0),
            'work_minutes'     => (int) ($row['work_minutes'] ?? 0),
            'overtime_minutes' => (int) ($row['overtime_minutes'] ?? 0),
        ];
    }

    /**
     * Records of one customer with a given status on a given day.
     */
    public static function countStatus(int $customerId, string $date, string $status): int
    {
        if (!isset(self::STATUSES[$status])) {
            return 0;
        }

        // «مرخصی» در نسخه‌های قبلی با کلید leave ذخیره می‌شد؛ هر دو کلید
        // یک چیز را می‌گویند و نباید آمار را دو تکه کنند.
        $statuses = $status === 'on_leave' ? ['on_leave', 'leave'] : [$status];
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));

        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `attendance_date` = ?'
            . ' AND `status` IN (' . $placeholders . ') AND `deleted_at` IS NULL',
            array_merge([$customerId, $date], $statuses)
        );
    }

    /**
     * Last records of one employee (employee profile page).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recentForEmployee(int $employeeId, int $customerId, int $limit = 10): array
    {
        if ($employeeId <= 0 || $customerId <= 0) {
            return [];
        }

        $rows = Database::select(
            'SELECT r.*, s.`name` AS shift_name, s.`start_time`, s.`end_time`, b.`name` AS branch_name'
            . ' FROM `' . self::tableName() . '` r'
            . ' LEFT JOIN `' . Database::table('shifts') . '` s ON s.`id` = r.`shift_id`'
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`'
            . ' WHERE r.`customer_id` = ? AND r.`employee_id` = ? AND r.`deleted_at` IS NULL'
            . ' ORDER BY r.`attendance_date` DESC, r.`id` DESC LIMIT ' . max(1, min(100, $limit)),
            [$customerId, $employeeId]
        );

        return array_map([static::class, 'cast'], $rows);
    }

    /**
     * Per employee aggregation over a date range (report "per employee").
     *
     * @return array<int,array<string,mixed>>
     */
    public static function perEmployeeTotals(int $customerId, string $from, string $to, int $branchId = 0): array
    {
        $where  = ['r.`deleted_at` IS NULL', 'r.`customer_id` = ?', 'r.`attendance_date` BETWEEN ? AND ?'];
        $params = [$customerId, $from, $to];

        if ($branchId > 0) {
            $where[]  = 'e.`branch_id` = ?';
            $params[] = $branchId;
        }

        $rows = Database::select(
            'SELECT e.`id` AS employee_id, e.`first_name`, e.`last_name`, e.`employee_code`,'
            . ' COUNT(r.`id`) AS records,'
            . ' COALESCE(SUM(r.`work_minutes`), 0) AS work_minutes,'
            . ' COALESCE(SUM(r.`late_minutes`), 0) AS late_minutes,'
            . ' COALESCE(SUM(r.`early_leave_minutes`), 0) AS early_leave_minutes,'
            . ' COALESCE(SUM(r.`overtime_minutes`), 0) AS overtime_minutes,'
            . " COALESCE(SUM(r.`status` = 'absent'), 0) AS absences,"
            . ' COALESCE(SUM(r.`late_minutes` > 0), 0) AS late_days,'
            . ' COALESCE(SUM(r.`early_leave_minutes` > 0), 0) AS early_days,'
            . ' COALESCE(SUM(r.`overtime_minutes` > 0), 0) AS overtime_days'
            . ' FROM `' . Database::table('employees') . '` e'
            . ' LEFT JOIN `' . self::tableName() . '` r ON r.`employee_id` = e.`id` AND ' . implode(' AND ', $where)
            . ' WHERE e.`customer_id` = ? AND e.`deleted_at` IS NULL'
            . ' GROUP BY e.`id` ORDER BY e.`last_name` ASC, e.`first_name` ASC',
            array_merge($params, [$customerId])
        );

        return array_map([static::class, 'cast'], $rows);
    }

    /**
     * Day by day aggregation (report "daily" / "monthly").
     *
     * @return array<int,array<string,mixed>>
     */
    public static function perDayTotals(int $customerId, string $from, string $to): array
    {
        $rows = Database::select(
            'SELECT r.`attendance_date`,'
            . ' COUNT(r.`id`) AS records,'
            . " COALESCE(SUM(r.`entry_at` IS NOT NULL), 0) AS entries,"
            . " COALESCE(SUM(r.`exit_at` IS NOT NULL), 0) AS exits,"
            . ' COALESCE(SUM(r.`work_minutes`), 0) AS work_minutes,'
            . ' COALESCE(SUM(r.`late_minutes`), 0) AS late_minutes,'
            . ' COALESCE(SUM(r.`early_leave_minutes`), 0) AS early_leave_minutes,'
            . ' COALESCE(SUM(r.`overtime_minutes`), 0) AS overtime_minutes,'
            . " COALESCE(SUM(r.`status` = 'absent'), 0) AS absences"
            . ' FROM `' . self::tableName() . '` r'
            . ' WHERE r.`customer_id` = ? AND r.`attendance_date` BETWEEN ? AND ? AND r.`deleted_at` IS NULL'
            . ' GROUP BY r.`attendance_date` ORDER BY r.`attendance_date` DESC',
            [$customerId, $from, $to]
        );

        return array_map([static::class, 'cast'], $rows);
    }

    /**
     * Per branch aggregation (report "branch"). Returns every branch of the
     * customer, even the ones without a single record.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function perBranchTotals(int $customerId, string $from, string $to): array
    {
        $rows = Database::select(
            'SELECT b.`id` AS branch_id, b.`name` AS branch_name,'
            . ' COUNT(r.`id`) AS records,'
            . ' COALESCE(SUM(r.`work_minutes`), 0) AS work_minutes,'
            . ' COALESCE(SUM(r.`late_minutes`), 0) AS late_minutes,'
            . " COALESCE(SUM(r.`status` = 'absent'), 0) AS absences"
            . ' FROM `' . Database::table('branches') . '` b'
            . ' LEFT JOIN `' . self::tableName() . '` r ON r.`branch_id` = b.`id`'
            . '   AND r.`deleted_at` IS NULL AND r.`attendance_date` BETWEEN ? AND ?'
            . ' WHERE b.`customer_id` = ? AND b.`deleted_at` IS NULL'
            . ' GROUP BY b.`id` ORDER BY b.`name` ASC',
            [$from, $to, $customerId]
        );

        return array_map([static::class, 'cast'], $rows);
    }

    /* =====================================================================
     * Availability / lookup helpers used by the attendance ENGINE (stage 6).
     * All of them are tenant scoped and ignore voided / deleted rows unless
     * stated otherwise.
     * =================================================================== */

    /**
     * One record of ONE customer (a manager of customer A can never load a
     * record of customer B just by guessing the id).
     *
     * @return array<string,mixed>|null
     */
    public static function findInCustomer(int $recordId, int $customerId): ?array
    {
        if ($recordId <= 0 || $customerId <= 0) {
            return null;
        }

        $row = Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '`'
            . ' WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$recordId, $customerId]
        );

        return $row === null ? null : static::cast($row);
    }

    /**
     * The record a repeated request already produced - the basis of
     * idempotency for the device API.
     *
     * @return array<string,mixed>|null
     */
    public static function byIdempotency(int $customerId, string $key): ?array
    {
        if ($customerId <= 0 || $key === '') {
            return null;
        }

        $row = Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `idempotency_key` = ? LIMIT 1',
            [$customerId, substr($key, 0, 64)]
        );

        return $row === null ? null : static::cast($row);
    }

    /**
     * Has this device already pushed this offline queue item?
     *
     * @return array<string,mixed>|null
     */
    public static function byDeviceLocalEvent(int $deviceId, string $localEventId): ?array
    {
        if ($deviceId <= 0 || $localEventId === '') {
            return null;
        }

        $row = Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '`'
            . ' WHERE `device_id` = ? AND (`local_event_id` = ? OR `local_exit_event_id` = ?) LIMIT 1',
            [$deviceId, substr($localEventId, 0, 64), substr($localEventId, 0, 64)]
        );

        return $row === null ? null : static::cast($row);
    }

    /**
     * The (single) open session of one employee, if any.
     *
     * `$forUpdate` locks the returned row so two requests arriving at the
     * same moment cannot both pass the "no open entry" check.
     *
     * @return array<string,mixed>|null
     */
    public static function openSessionFor(int $customerId, int $employeeId, bool $forUpdate = false): ?array
    {
        if ($customerId <= 0 || $employeeId <= 0) {
            return null;
        }

        $row = Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `employee_id` = ? AND `open_key` IS NOT NULL'
            . ' AND `deleted_at` IS NULL'
            . ' ORDER BY `entry_at` ASC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$customerId, $employeeId]
        );

        return $row === null ? null : static::cast($row);
    }

    /**
     * Every live session of one employee on one date (multi-session days are
     * normal here - never assume one entry/exit pair).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function sessionsOn(int $customerId, int $employeeId, string $date): array
    {
        if ($customerId <= 0 || $employeeId <= 0 || $date === '') {
            return [];
        }

        $rows = Database::select(
            'SELECT * FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `employee_id` = ? AND `attendance_date` = ?'
            . ' AND `deleted_at` IS NULL AND `open_key` IS NULL'
            . ' ORDER BY `entry_at` ASC, `id` ASC',
            [$customerId, $employeeId, substr($date, 0, 10)]
        );

        return array_map([static::class, 'cast'], $rows);
    }

    /** فهرست زنده رکوردهای یک روز برای یک مشتری (پنل مدیر). */
    public static function dayRecords(int $customerId, string $date, int $branchId = 0): array
    {
        if ($customerId <= 0 || $date === '') {
            return [];
        }

        $sql = 'SELECT r.*, e.`first_name`, e.`last_name`, e.`employee_code`, b.`name` AS branch_name,'
            . ' s.`name` AS shift_name, d.`name` AS device_name'
            . ' FROM `' . self::tableName() . '` r'
            . ' INNER JOIN `' . Database::table('employees') . '` e ON e.`id` = r.`employee_id`'
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`'
            . ' LEFT JOIN `' . Database::table('shifts') . '` s ON s.`id` = r.`shift_id`'
            . ' LEFT JOIN `' . Database::table('devices') . '` d ON d.`id` = r.`device_id`'
            . ' WHERE r.`customer_id` = ? AND r.`attendance_date` = ? AND r.`deleted_at` IS NULL';

        $params = [$customerId, substr($date, 0, 10)];

        if ($branchId > 0) {
            $sql .= ' AND r.`branch_id` = ?';
            $params[] = $branchId;
        }

        $rows = Database::select($sql . ' ORDER BY e.`last_name` ASC, e.`first_name` ASC, r.`entry_at` ASC', $params);

        return array_map([static::class, 'cast'], $rows);
    }

    /** Session counter used to number the sessions of a day. */
    public static function nextSessionIndex(int $customerId, int $employeeId, string $date): int
    {
        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `employee_id` = ? AND `attendance_date` = ?'
            . ' AND `deleted_at` IS NULL',
            [$customerId, $employeeId, substr($date, 0, 10)]
        );

        return $count + 1;
    }
}
