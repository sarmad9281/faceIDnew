<?php
/**
 * SARMAD Attendance - stage 7 report queries.
 *
 * ONE place for the SQL behind every report. The manager panel and the Excel
 * exporter both call this service, so a number on the screen and the same
 * number in the .xlsx can never come from two different queries.
 *
 * Rules respected here:
 *   - every query is scoped by `customer_id` (tenant isolation in the query,
 *     never in the view),
 *   - every query is bounded by a date range, and the row level reports are
 *     capped, so a huge range can not exhaust memory,
 *   - the table names always come from Database::table(), so only attendance_*
 *     objects can be touched,
 *   - deleted rows (soft deleted) are always excluded.
 *
 * `attendance_date` is the real (Gregorian) date column. The UI converts it to
 * Jalali for display only.
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class ReportService
{
    /** Hard cap for row level reports (Excel / screen). */
    public const ROW_LIMIT = 20000;

    /** Default page size of the on-screen tables. */
    public const PAGE_SIZE = 100;

    public const TYPES = [
        'daily'        => 'گزارش روزانه',
        'monthly'      => 'گزارش ماهانه',
        'per_employee' => 'گزارش خلاصه پرسنل',
        'employee'     => 'کارنامه پرسنل',
        'late'         => 'گزارش تأخیر',
        'early_leave'  => 'گزارش تعجیل در خروج',
        'overtime'     => 'گزارش اضافه‌کاری',
        'absence'      => 'گزارش غیبت',
        'leave'        => 'گزارش مرخصی‌ها',
        'branch'       => 'گزارش شعب',
        'inside'       => 'افراد حاضر در مجموعه',
        'payroll'      => 'گزارش حقوق و دستمزد',
    ];

    /** Reports that only make sense when the branches feature is on. */
    public const BRANCH_TYPES = ['branch'];

    /** Reports behind the advanced_reports feature. */
    public const ADVANCED_TYPES = ['trend', 'compare_employees', 'compare_branches'];

    private function records(): string
    {
        return '`' . Database::table('records') . '` r';
    }

    private function employeesJoin(): string
    {
        return ' INNER JOIN `' . Database::table('employees') . '` e ON e.`id` = r.`employee_id`';
    }

    /**
     * Filters shared by every report.
     *
     * @param array<string,mixed> $filters
     * @return array{0:array<int,string>,1:array<string,mixed>}
     */
    private function scope(int $customerId, array $filters): array
    {
        $where = ['r.`customer_id` = :tenant', 'r.`deleted_at` IS NULL'];
        $params = [':tenant' => $customerId];

        if (!empty($filters['date_from'])) {
            $where[] = 'r.`attendance_date` >= :from';
            $params[':from'] = (string) $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'r.`attendance_date` <= :to';
            $params[':to'] = (string) $filters['date_to'];
        }

        if (!empty($filters['employee_id'])) {
            $where[] = 'r.`employee_id` = :employee';
            $params[':employee'] = (int) $filters['employee_id'];
        }

        if (!empty($filters['branch_id'])) {
            $where[] = 'r.`branch_id` = :branch';
            $params[':branch'] = (int) $filters['branch_id'];
        }

        if (!empty($filters['shift_id'])) {
            $where[] = 'r.`shift_id` = :shift';
            $params[':shift'] = (int) $filters['shift_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'r.`status` = :status';
            $params[':status'] = (string) $filters['status'];
        }

        if (!empty($filters['origin'])) {
            $origin = (string) $filters['origin'];

            if ($origin === 'offline') {
                $where[] = "r.`source` = 'device_offline'";
            } elseif ($origin === 'online') {
                $where[] = "r.`source` = 'device'";
            } elseif ($origin === 'manual') {
                $where[] = "r.`source` = 'manual'";
            }
        }

        return [$where, $params];
    }

    /* ==================================================================
     * 1. Daily attendance - one row per employee/day session
     * ==================================================================*/

    /**
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,summary:array<string,int|string>}
     */
    public function daily(int $customerId, array $filters, int $page = 1, int $perPage = self::PAGE_SIZE): array
    {
        [$where, $params] = $this->scope($customerId, $filters);
        $whereSql = implode(' AND ', $where);

        $from = $this->records() . $this->employeesJoin()
            . ' LEFT JOIN `' . Database::table('shifts') . '` s ON s.`id` = r.`shift_id`'
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`';

        $total = (int) Database::scalar('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $whereSql, $params);

        $perPage = max(10, min(500, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            'SELECT r.`id`, r.`employee_id`, r.`attendance_date`, r.`entry_at`, r.`exit_at`,'
            . ' r.`work_minutes`, r.`late_minutes`, r.`early_leave_minutes`, r.`overtime_minutes`,'
            . ' r.`status`, r.`flags`, r.`source`, r.`branch_id`, r.`device_id`, r.`session_index`,'
            . ' e.`first_name`, e.`last_name`, e.`employee_code`,'
            . ' s.`name` AS shift_name, s.`start_time`, s.`end_time`, b.`name` AS branch_name'
            . ' FROM ' . $from . ' WHERE ' . $whereSql
            . ' ORDER BY r.`attendance_date` DESC, r.`entry_at` ASC, r.`id` ASC'
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'rows'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'per_page' => $perPage,
            'pages'   => (int) max(1, (int) ceil($total / $perPage)),
            'summary' => $this->summaryFor($from, $whereSql, $params),
        ];
    }

    /**
     * Range summary: employees, present, absent, leave, late, overtime, work.
     *
     * @param array<string,mixed> $params
     * @return array<string,int|string>
     */
    private function summaryFor(string $from, string $whereSql, array $params): array
    {
        $row = Database::selectOne(
            'SELECT COUNT(DISTINCT r.`employee_id`) AS employees,'
            . ' SUM(CASE WHEN r.`status` IN (\'present\',\'late\',\'early_leave\',\'overtime\',\'incomplete\',\'manual\') THEN 1 ELSE 0 END) AS present_days,'
            . ' SUM(CASE WHEN r.`status` = \'absent\' THEN 1 ELSE 0 END) AS absent_days,'
            . ' SUM(CASE WHEN r.`status` = \'on_leave\' THEN 1 ELSE 0 END) AS leave_days,'
            . ' SUM(CASE WHEN r.`status` = \'holiday\' THEN 1 ELSE 0 END) AS holiday_days,'
            . ' SUM(CASE WHEN r.`late_minutes` > 0 THEN 1 ELSE 0 END) AS late_count,'
            . ' COALESCE(SUM(r.`late_minutes`), 0) AS late_minutes,'
            . ' COALESCE(SUM(r.`early_leave_minutes`), 0) AS early_minutes,'
            . ' COALESCE(SUM(r.`overtime_minutes`), 0) AS overtime_minutes,'
            . ' COALESCE(SUM(r.`work_minutes`), 0) AS work_minutes,'
            . ' SUM(CASE WHEN r.`source` = \'device_offline\' THEN 1 ELSE 0 END) AS offline_rows'
            . ' FROM ' . $from . ' WHERE ' . $whereSql,
            $params
        ) ?? [];

        return [
            'employees'        => (int) ($row['employees'] ?? 0),
            'present_days'     => (int) ($row['present_days'] ?? 0),
            'absent_days'      => (int) ($row['absent_days'] ?? 0),
            'leave_days'       => (int) ($row['leave_days'] ?? 0),
            'holiday_days'     => (int) ($row['holiday_days'] ?? 0),
            'late_count'       => (int) ($row['late_count'] ?? 0),
            'late_minutes'     => (int) ($row['late_minutes'] ?? 0),
            'early_minutes'    => (int) ($row['early_minutes'] ?? 0),
            'overtime_minutes' => (int) ($row['overtime_minutes'] ?? 0),
            'work_minutes'     => (int) ($row['work_minutes'] ?? 0),
            'offline_rows'     => (int) ($row['offline_rows'] ?? 0),
        ];
    }

    /* ==================================================================
     * 2. Monthly per employee
     * ==================================================================*/

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function monthly(int $customerId, array $filters, int $limit = 500): array
    {
        [$where, $params] = $this->scope($customerId, $filters);

        $from = $this->records() . $this->employeesJoin();

        return Database::select(
            'SELECT r.`employee_id`, e.`first_name`, e.`last_name`, e.`employee_code`,'
            . ' COUNT(DISTINCT r.`attendance_date`) AS work_days,'
            . ' SUM(CASE WHEN r.`status` IN (\'present\',\'late\',\'early_leave\',\'overtime\',\'incomplete\',\'manual\') THEN 1 ELSE 0 END) AS present_days,'
            . ' SUM(CASE WHEN r.`status` = \'absent\' THEN 1 ELSE 0 END) AS absence_days,'
            . ' SUM(CASE WHEN r.`status` = \'on_leave\' THEN 1 ELSE 0 END) AS leave_days,'
            . ' SUM(CASE WHEN r.`status` = \'holiday\' THEN 1 ELSE 0 END) AS holiday_days,'
            . ' COALESCE(SUM(r.`work_minutes`), 0) AS work_minutes,'
            . ' COALESCE(SUM(r.`late_minutes`), 0) AS late_minutes,'
            . ' COALESCE(SUM(r.`early_leave_minutes`), 0) AS early_leave_minutes,'
            . ' COALESCE(SUM(r.`overtime_minutes`), 0) AS overtime_minutes,'
            . ' COALESCE(ROUND(AVG(NULLIF(r.`work_minutes`, 0))), 0) AS avg_work_minutes,'
            . ' MIN(r.`attendance_date`) AS first_date, MAX(r.`attendance_date`) AS last_date'
            . ' FROM ' . $from . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY r.`employee_id`, e.`first_name`, e.`last_name`, e.`employee_code`'
            . ' ORDER BY e.`last_name` ASC, e.`first_name` ASC'
            . ' LIMIT ' . max(1, min(2000, $limit)),
            $params
        );
    }

    /* ==================================================================
     * 3. One employee, one row per day (the "کارنامه")
     * ==================================================================*/

    /**
     * @param array<string,mixed> $filters
     * @return array{employee:array<string,mixed>|null,rows:array<int,array<string,mixed>>,summary:array<string,int|string>}
     */
    public function employee(int $customerId, int $employeeId, array $filters): array
    {
        $employee = Database::selectOne(
            'SELECT `id`, `first_name`, `last_name`, `employee_code`, `status`, `branch_id`'
            . ' FROM `' . Database::table('employees') . '`'
            . ' WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL',
            [$employeeId, $customerId]
        );

        if ($employee === null) {
            return ['employee' => null, 'rows' => [], 'summary' => []];
        }

        $filters['employee_id'] = $employeeId;
        $report = $this->daily($customerId, $filters, 1, 500);

        return ['employee' => $employee, 'rows' => $report['rows'], 'summary' => $report['summary']];
    }

    /* ==================================================================
     * 4. Late arrivals
     * ==================================================================*/

    /**
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,int|float|string>}
     */
    public function late(int $customerId, array $filters, int $limit = self::ROW_LIMIT): array
    {
        [$where, $params] = $this->scope($customerId, $filters);
        $where[] = 'r.`late_minutes` > 0';
        $whereSql = implode(' AND ', $where);

        $from = $this->records() . $this->employeesJoin()
            . ' LEFT JOIN `' . Database::table('shifts') . '` s ON s.`id` = r.`shift_id`'
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`';

        $rows = Database::select(
            'SELECT r.`id`, r.`employee_id`, r.`attendance_date`, r.`entry_at`, r.`late_minutes`,'
            . ' e.`first_name`, e.`last_name`, e.`employee_code`,'
            . ' s.`name` AS shift_name, s.`start_time`, s.`late_grace_minutes`, b.`name` AS branch_name'
            . ' FROM ' . $from . ' WHERE ' . $whereSql
            . ' ORDER BY r.`attendance_date` DESC, r.`late_minutes` DESC, r.`id` DESC'
            . ' LIMIT ' . max(1, min(self::ROW_LIMIT, $limit)),
            $params
        );

        $count  = (int) Database::scalar('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $whereSql, $params);
        $sum    = (int) Database::scalar('SELECT COALESCE(SUM(r.`late_minutes`), 0) FROM ' . $from . ' WHERE ' . $whereSql, $params);
        $people = (int) Database::scalar('SELECT COUNT(DISTINCT r.`employee_id`) FROM ' . $from . ' WHERE ' . $whereSql, $params);

        return [
            'rows'    => $rows,
            'summary' => [
                'count'          => $count,
                'employees'      => $people,
                'late_minutes'   => $sum,
                'average_minutes' => $count > 0 ? round($sum / $count, 1) : 0.0,
            ],
        ];
    }

    /* ==================================================================
     * 5. Early leave
     * ==================================================================*/

    /**
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,int|string>}
     */
    public function earlyLeave(int $customerId, array $filters, int $limit = self::ROW_LIMIT): array
    {
        [$where, $params] = $this->scope($customerId, $filters);
        $where[] = 'r.`early_leave_minutes` > 0';
        $whereSql = implode(' AND ', $where);

        $from = $this->records() . $this->employeesJoin()
            . ' LEFT JOIN `' . Database::table('shifts') . '` s ON s.`id` = r.`shift_id`'
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`';

        $rows = Database::select(
            'SELECT r.`id`, r.`employee_id`, r.`attendance_date`, r.`exit_at`, r.`early_leave_minutes`,'
            . ' e.`first_name`, e.`last_name`, e.`employee_code`,'
            . ' s.`name` AS shift_name, s.`end_time`, b.`name` AS branch_name'
            . ' FROM ' . $from . ' WHERE ' . $whereSql
            . ' ORDER BY r.`attendance_date` DESC, r.`early_leave_minutes` DESC, r.`id` DESC'
            . ' LIMIT ' . max(1, min(self::ROW_LIMIT, $limit)),
            $params
        );

        $count = (int) Database::scalar('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $whereSql, $params);
        $sum   = (int) Database::scalar('SELECT COALESCE(SUM(r.`early_leave_minutes`), 0) FROM ' . $from . ' WHERE ' . $whereSql, $params);

        return [
            'rows'    => $rows,
            'summary' => [
                'count'        => $count,
                'early_minutes' => $sum,
                'average_minutes' => $count > 0 ? round($sum / $count, 1) : 0.0,
            ],
        ];
    }

    /* ==================================================================
     * 6. Overtime
     * ==================================================================*/

    /**
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,mixed>,per_employee:array<int,array<string,mixed>>}
     */
    public function overtime(int $customerId, array $filters, int $limit = self::ROW_LIMIT): array
    {
        [$where, $params] = $this->scope($customerId, $filters);
        $where[] = 'r.`overtime_minutes` > 0';
        $whereSql = implode(' AND ', $where);

        $from = $this->records() . $this->employeesJoin()
            . ' LEFT JOIN `' . Database::table('shifts') . '` s ON s.`id` = r.`shift_id`'
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`';

        $rows = Database::select(
            'SELECT r.`id`, r.`employee_id`, r.`attendance_date`, r.`exit_at`, r.`overtime_minutes`,'
            . ' e.`first_name`, e.`last_name`, e.`employee_code`,'
            . ' s.`name` AS shift_name, s.`end_time`, b.`name` AS branch_name'
            . ' FROM ' . $from . ' WHERE ' . $whereSql
            . ' ORDER BY r.`attendance_date` DESC, r.`overtime_minutes` DESC, r.`id` DESC'
            . ' LIMIT ' . max(1, min(self::ROW_LIMIT, $limit)),
            $params
        );

        $perEmployee = Database::select(
            'SELECT r.`employee_id`, e.`first_name`, e.`last_name`,'
            . ' COALESCE(SUM(r.`overtime_minutes`), 0) AS overtime_minutes,'
            . ' COUNT(*) AS days'
            . ' FROM ' . $from . ' WHERE ' . $whereSql
            . ' GROUP BY r.`employee_id`, e.`first_name`, e.`last_name`'
            . ' ORDER BY overtime_minutes DESC LIMIT 500',
            $params
        );

        $count = (int) Database::scalar('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $whereSql, $params);
        $sum   = (int) Database::scalar('SELECT COALESCE(SUM(r.`overtime_minutes`), 0) FROM ' . $from . ' WHERE ' . $whereSql, $params);

        return [
            'rows'    => $rows,
            'per_employee' => $perEmployee,
            'summary' => [
                'count'            => $count,
                'overtime_minutes' => $sum,
                'average_minutes'  => $count > 0 ? round($sum / $count, 1) : 0.0,
            ],
        ];
    }

    /* ==================================================================
     * 7. Absence
     * ==================================================================*/

    /**
     * Only rows the engine really marked `absent` (a holiday or a leave day is
     * never an absence - the day-close pass decides that, not this query).
     *
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,int>}
     */
    public function absence(int $customerId, array $filters, int $limit = self::ROW_LIMIT): array
    {
        $filters['status'] = 'absent';
        [$where, $params] = $this->scope($customerId, $filters);
        $whereSql = implode(' AND ', $where);

        $from = $this->records() . $this->employeesJoin()
            . ' LEFT JOIN `' . Database::table('shifts') . '` s ON s.`id` = r.`shift_id`'
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`';

        $rows = Database::select(
            'SELECT r.`id`, r.`employee_id`, r.`attendance_date`, r.`status`, r.`source`,'
            . ' e.`first_name`, e.`last_name`, e.`employee_code`, s.`name` AS shift_name, b.`name` AS branch_name'
            . ' FROM ' . $from . ' WHERE ' . $whereSql
            . ' ORDER BY r.`attendance_date` DESC, e.`last_name` ASC'
            . ' LIMIT ' . max(1, min(self::ROW_LIMIT, $limit)),
            $params
        );

        $days   = (int) Database::scalar('SELECT COUNT(*) FROM ' . $from . ' WHERE ' . $whereSql, $params);
        $people = (int) Database::scalar('SELECT COUNT(DISTINCT r.`employee_id`) FROM ' . $from . ' WHERE ' . $whereSql, $params);

        return ['rows' => $rows, 'summary' => ['days' => $days, 'employees' => $people]];
    }

    /* ==================================================================
     * 8. Leave
     * ==================================================================*/

    /**
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,summary:array<string,float|int>}
     */
    public function leave(int $customerId, array $filters, int $limit = 2000): array
    {
        $where  = ['l.`customer_id` = :tenant', 'l.`deleted_at` IS NULL'];
        $params = [':tenant' => $customerId];

        if (!empty($filters['employee_id'])) {
            $where[] = 'l.`employee_id` = :employee';
            $params[':employee'] = (int) $filters['employee_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'l.`end_date` >= :from';
            $params[':from'] = (string) $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'l.`start_date` <= :to';
            $params[':to'] = (string) $filters['date_to'];
        }

        if (!empty($filters['leave_type'])) {
            $where[] = 'l.`leave_type` = :leave_type';
            $params[':leave_type'] = (string) $filters['leave_type'];
        }

        $from = '`' . Database::table('leaves') . '` l'
            . ' INNER JOIN `' . Database::table('employees') . '` e ON e.`id` = l.`employee_id`';

        $rows = Database::select(
            'SELECT l.`id`, l.`employee_id`, l.`leave_type`, l.`start_date`, l.`end_date`, l.`days`,'
            . ' l.`status`, l.`description`, e.`first_name`, e.`last_name`, e.`employee_code`'
            . ' FROM ' . $from . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY l.`start_date` DESC, l.`id` DESC LIMIT ' . max(1, min(5000, $limit)),
            $params
        );

        $totalDays = 0.0;
        $approved  = 0;

        foreach ($rows as $row) {
            $totalDays += (float) $row['days'];

            if ((string) $row['status'] === 'approved') {
                $approved++;
            }
        }

        return [
            'rows'    => $rows,
            'summary' => ['records' => count($rows), 'days' => round($totalDays, 1), 'approved' => $approved],
        ];
    }

    /* ==================================================================
     * 9. Branches
     * ==================================================================*/

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function branch(int $customerId, array $filters, int $limit = 200): array
    {
        [$where, $params] = $this->scope($customerId, $filters);

        $from = $this->records()
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`';

        return Database::select(
            'SELECT r.`branch_id`, COALESCE(b.`name`, \'بدون شعبه\') AS branch_name,'
            . ' COUNT(*) AS records, COUNT(DISTINCT r.`employee_id`) AS employees,'
            . ' COALESCE(SUM(r.`work_minutes`), 0) AS work_minutes,'
            . ' COALESCE(SUM(r.`late_minutes`), 0) AS late_minutes,'
            . ' COALESCE(SUM(r.`overtime_minutes`), 0) AS overtime_minutes,'
            . ' SUM(CASE WHEN r.`status` = \'absent\' THEN 1 ELSE 0 END) AS absence_days'
            . ' FROM ' . $from . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY r.`branch_id`, b.`name` ORDER BY records DESC LIMIT ' . max(1, min(500, $limit)),
            $params
        );
    }

    /* ==================================================================
     * 10. Currently inside (entry with no exit)
     * ==================================================================*/

    /**
     * Live query - no cache, no table: employees with an open session right now.
     *
     * @return array<int,array<string,mixed>>
     */
    public function inside(int $customerId, int $branchId = 0): array
    {
        $sql = 'SELECT r.`id` AS record_id, r.`employee_id`, r.`entry_at`, r.`attendance_date`,'
            . ' r.`branch_id`, r.`device_id`, r.`source`, r.`status`,'
            . ' e.`first_name`, e.`last_name`, e.`employee_code`,'
            . ' b.`name` AS branch_name, d.`name` AS device_name,'
            . ' TIMESTAMPDIFF(MINUTE, r.`entry_at`, NOW()) AS inside_minutes'
            . ' FROM ' . $this->records() . $this->employeesJoin()
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = r.`branch_id`'
            . ' LEFT JOIN `' . Database::table('devices') . '` d ON d.`id` = r.`device_id`'
            . ' WHERE r.`customer_id` = ? AND r.`deleted_at` IS NULL'
            . ' AND r.`entry_at` IS NOT NULL AND r.`exit_at` IS NULL AND r.`voided_at` IS NULL';

        $params = [$customerId];

        if ($branchId > 0) {
            $sql .= ' AND r.`branch_id` = ?';
            $params[] = $branchId;
        }

        $sql .= ' ORDER BY r.`entry_at` ASC LIMIT 500';

        return Database::select($sql, $params);
    }

    /* ==================================================================
     * 11. Advanced: trends and comparisons (feature advanced_reports)
     * ==================================================================*/

    /**
     * Monthly trend of presence / late / overtime over the selected range.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function trend(int $customerId, array $filters): array
    {
        [$where, $params] = $this->scope($customerId, $filters);

        $from = $this->records() . $this->employeesJoin();

        return Database::select(
            'SELECT DATE_FORMAT(r.`attendance_date`, \'%Y-%m\') AS month,'
            . ' COUNT(DISTINCT r.`employee_id`) AS employees,'
            . ' COUNT(*) AS records,'
            . ' COALESCE(SUM(r.`work_minutes`), 0) AS work_minutes,'
            . ' COALESCE(ROUND(AVG(NULLIF(r.`work_minutes`, 0))), 0) AS avg_work_minutes,'
            . ' COALESCE(SUM(r.`late_minutes`), 0) AS late_minutes,'
            . ' COALESCE(SUM(r.`overtime_minutes`), 0) AS overtime_minutes,'
            . ' SUM(CASE WHEN r.`status` = \'absent\' THEN 1 ELSE 0 END) AS absence_days'
            . ' FROM ' . $from . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY month ORDER BY month ASC LIMIT 60',
            $params
        );
    }

    /**
     * Employee comparison: the same metrics side by side.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function compareEmployees(int $customerId, array $filters, int $limit = 100): array
    {
        $rows = $this->monthly($customerId, $filters, $limit);

        usort($rows, static fn (array $a, array $b): int => (int) $b['work_minutes'] <=> (int) $a['work_minutes']);

        return $rows;
    }

    /**
     * Branch comparison (needs the branches feature - the caller enforces it).
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function compareBranches(int $customerId, array $filters): array
    {
        $rows = $this->branch($customerId, $filters);

        foreach ($rows as &$row) {
            $records = max(1, (int) $row['records']);
            $row['avg_work_minutes'] = (int) round(((int) $row['work_minutes']) / $records);
            $row['avg_late_minutes'] = (int) round(((int) $row['late_minutes']) / $records);
        }

        return $rows;
    }

    /* ==================================================================
     * 12. Owner summary (platform level, no tenant data leakage in the UI)
     * ==================================================================*/

    /**
     * @return array<string,int|string>
     */
    public function platformSummary(): array
    {
        $customers = (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('customers') . '` WHERE `deleted_at` IS NULL');
        $records   = (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('records') . '`');
        $today     = (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('records') . '` WHERE `attendance_date` = ?', [date('Y-m-d')]);
        $devices   = (int) Database::scalar("SELECT COUNT(*) FROM `" . Database::table('devices') . "` WHERE `status` = 'active'");

        $telegram = (int) Database::scalar('SELECT COUNT(*) FROM `' . Database::table('telegram_settings') . '` WHERE `is_active` = 1');
        $payroll  = (int) Database::scalar("SELECT COUNT(*) FROM `" . Database::table('feature_flags') . "` WHERE `feature_key` = 'payroll' AND `is_enabled` = 1");

        $queuePending = (int) Database::scalar("SELECT COUNT(*) FROM `" . Database::table('telegram_queue') . "` WHERE `status` IN ('pending','processing')");
        $queueFailed  = (int) Database::scalar("SELECT COUNT(*) FROM `" . Database::table('telegram_queue') . "` WHERE `status` = 'failed'");

        return [
            'customers'      => $customers,
            'attendance_rows' => $records,
            'today_rows'     => $today,
            'active_devices' => $devices,
            'telegram_active' => $telegram,
            'payroll_enabled_customers' => $payroll,
            'telegram_queue_pending' => $queuePending,
            'telegram_queue_failed'  => $queueFailed,
            'generated_at'   => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * The shape the Excel exporter and the view both consume:
     * a title, columns (key/label/format) and rows as flat arrays.
     *
     * @param array<string,mixed> $filters
     * @return array{type:string,title:string,columns:array<int,array{key:string,label:string,format?:string}>,rows:array<int,array<string,mixed>>,summary:array<string,array{label:string,value:mixed}>,note:string}
     */
    public function build(int $customerId, string $type, array $filters): array
    {
        $type = array_key_exists($type, self::TYPES) ? $type : 'daily';

        switch ($type) {
            case 'monthly':
            case 'per_employee':
                $rows = $this->monthly($customerId, $filters);

                return [
                    'type'    => $type,
                    'title'   => self::TYPES[$type],
                    'columns' => [
                        ['key' => 'employee', 'label' => 'پرسنل'],
                        ['key' => 'code', 'label' => 'کد پرسنلی'],
                        ['key' => 'work_days', 'label' => 'روزهای کارکرد', 'format' => 'int'],
                        ['key' => 'present_days', 'label' => 'روزهای حضور', 'format' => 'int'],
                        ['key' => 'absence_days', 'label' => 'روزهای غیبت', 'format' => 'int'],
                        ['key' => 'leave_days', 'label' => 'روزهای مرخصی', 'format' => 'int'],
                        ['key' => 'work_minutes', 'label' => 'مجموع کارکرد', 'format' => 'minutes'],
                        ['key' => 'late_minutes', 'label' => 'مجموع تأخیر', 'format' => 'minutes'],
                        ['key' => 'early_leave_minutes', 'label' => 'مجموع تعجیل خروج', 'format' => 'minutes'],
                        ['key' => 'overtime_minutes', 'label' => 'مجموع اضافه‌کاری', 'format' => 'minutes'],
                        ['key' => 'avg_work_minutes', 'label' => 'میانگین کارکرد روزانه', 'format' => 'minutes'],
                    ],
                    'rows'    => array_map(static fn (array $r): array => [
                        'employee' => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])),
                        'code'     => (string) ($r['employee_code'] ?? ''),
                        'work_days' => (int) $r['work_days'],
                        'present_days' => (int) $r['present_days'],
                        'absence_days' => (int) $r['absence_days'],
                        'leave_days'   => (int) $r['leave_days'],
                        'work_minutes' => (int) $r['work_minutes'],
                        'late_minutes' => (int) $r['late_minutes'],
                        'early_leave_minutes' => (int) $r['early_leave_minutes'],
                        'overtime_minutes'    => (int) $r['overtime_minutes'],
                        'avg_work_minutes'    => (int) $r['avg_work_minutes'],
                    ], $rows),
                    'summary' => [
                        ['label' => 'تعداد پرسنل', 'value' => count($rows)],
                        ['label' => 'مجموع کارکرد', 'value' => array_sum(array_column($rows, 'work_minutes'))],
                        ['label' => 'مجموع تأخیر', 'value' => array_sum(array_column($rows, 'late_minutes'))],
                        ['label' => 'مجموع اضافه‌کاری', 'value' => array_sum(array_column($rows, 'overtime_minutes'))],
                    ],
                    'note' => 'مجموع‌ها بر پایه رکوردهای حضور بازه انتخاب‌شده محاسبه شده است.',
                ];

            case 'late':
                $report = $this->late($customerId, $filters);

                return [
                    'type'    => 'late',
                    'title'   => self::TYPES['late'],
                    'columns' => [
                        ['key' => 'employee', 'label' => 'پرسنل'],
                        ['key' => 'code', 'label' => 'کد پرسنلی'],
                        ['key' => 'date', 'label' => 'تاریخ'],
                        ['key' => 'shift', 'label' => 'شیفت'],
                        ['key' => 'shift_start', 'label' => 'شروع شیفت'],
                        ['key' => 'entry_time', 'label' => 'ورود ثبت‌شده'],
                        ['key' => 'grace', 'label' => 'مهلت مجاز', 'format' => 'int'],
                        ['key' => 'late_minutes', 'label' => 'تأخیر (دقیقه)', 'format' => 'int'],
                    ],
                    'rows'    => array_map(static fn (array $r): array => [
                        'employee' => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])),
                        'code'     => (string) ($r['employee_code'] ?? ''),
                        'date'     => (string) $r['attendance_date'],
                        'shift'    => (string) ($r['shift_name'] ?? '—'),
                        'shift_start' => (string) ($r['start_time'] ?? ''),
                        'entry_time'  => (string) ($r['entry_at'] ?? ''),
                        'grace'       => (int) ($r['late_grace_minutes'] ?? 0),
                        'late_minutes' => (int) $r['late_minutes'],
                    ], $report['rows']),
                    'summary' => [
                        ['label' => 'تعداد تأخیر', 'value' => $report['summary']['count']],
                        ['label' => 'مجموع دقیقه تأخیر', 'value' => $report['summary']['late_minutes']],
                        ['label' => 'میانگین تأخیر (دقیقه)', 'value' => $report['summary']['average_minutes']],
                    ],
                    'note' => '',
                ];

            case 'early_leave':
                $report = $this->earlyLeave($customerId, $filters);

                return [
                    'type'    => 'early_leave',
                    'title'   => self::TYPES['early_leave'],
                    'columns' => [
                        ['key' => 'employee', 'label' => 'پرسنل'],
                        ['key' => 'code', 'label' => 'کد پرسنلی'],
                        ['key' => 'date', 'label' => 'تاریخ'],
                        ['key' => 'shift', 'label' => 'شیفت'],
                        ['key' => 'shift_end', 'label' => 'پایان شیفت'],
                        ['key' => 'exit_time', 'label' => 'خروج ثبت‌شده'],
                        ['key' => 'early_leave_minutes', 'label' => 'تعجیل خروج (دقیقه)', 'format' => 'int'],
                    ],
                    'rows'    => array_map(static fn (array $r): array => [
                        'employee' => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])),
                        'code'     => (string) ($r['employee_code'] ?? ''),
                        'date'     => (string) $r['attendance_date'],
                        'shift'    => (string) ($r['shift_name'] ?? '—'),
                        'shift_end' => (string) ($r['end_time'] ?? ''),
                        'exit_time' => (string) ($r['exit_at'] ?? ''),
                        'early_leave_minutes' => (int) $r['early_leave_minutes'],
                    ], $report['rows']),
                    'summary' => [
                        ['label' => 'تعداد موارد', 'value' => $report['summary']['count']],
                        ['label' => 'مجموع دقیقه', 'value' => $report['summary']['early_minutes']],
                        ['label' => 'میانگین (دقیقه)', 'value' => $report['summary']['average_minutes']],
                    ],
                    'note' => '',
                ];

            case 'overtime':
                $report = $this->overtime($customerId, $filters);

                return [
                    'type'    => 'overtime',
                    'title'   => self::TYPES['overtime'],
                    'columns' => [
                        ['key' => 'employee', 'label' => 'پرسنل'],
                        ['key' => 'code', 'label' => 'کد پرسنلی'],
                        ['key' => 'date', 'label' => 'تاریخ'],
                        ['key' => 'shift', 'label' => 'شیفت'],
                        ['key' => 'shift_end', 'label' => 'پایان شیفت'],
                        ['key' => 'exit_time', 'label' => 'خروج ثبت‌شده'],
                        ['key' => 'overtime_minutes', 'label' => 'اضافه‌کاری (دقیقه)', 'format' => 'int'],
                    ],
                    'rows'    => array_map(static fn (array $r): array => [
                        'employee' => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])),
                        'code'     => (string) ($r['employee_code'] ?? ''),
                        'date'     => (string) $r['attendance_date'],
                        'shift'    => (string) ($r['shift_name'] ?? '—'),
                        'shift_end' => (string) ($r['end_time'] ?? ''),
                        'exit_time' => (string) ($r['exit_at'] ?? ''),
                        'overtime_minutes' => (int) $r['overtime_minutes'],
                    ], $report['rows']),
                    'summary' => [
                        ['label' => 'مجموع اضافه‌کاری', 'value' => $report['summary']['overtime_minutes']],
                        ['label' => 'میانگین (دقیقه)', 'value' => $report['summary']['average_minutes']],
                        ['label' => 'تعداد موارد', 'value' => $report['summary']['count']],
                    ],
                    'note' => '',
                ];

            case 'absence':
                $report = $this->absence($customerId, $filters);

                return [
                    'type'    => 'absence',
                    'title'   => self::TYPES['absence'],
                    'columns' => [
                        ['key' => 'employee', 'label' => 'پرسنل'],
                        ['key' => 'code', 'label' => 'کد پرسنلی'],
                        ['key' => 'date', 'label' => 'تاریخ'],
                        ['key' => 'shift', 'label' => 'شیفت'],
                        ['key' => 'status', 'label' => 'وضعیت'],
                        ['key' => 'source', 'label' => 'منبع'],
                    ],
                    'rows'    => array_map(static fn (array $r): array => [
                        'employee' => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])),
                        'code'     => (string) ($r['employee_code'] ?? ''),
                        'date'     => (string) $r['attendance_date'],
                        'shift'    => (string) ($r['shift_name'] ?? '—'),
                        'status'   => \App\Models\AttendanceRecord::statusLabel((string) $r['status']),
                        'source'   => (string) $r['source'],
                    ], $report['rows']),
                    'summary' => [
                        ['label' => 'روزهای غیبت', 'value' => $report['summary']['days']],
                        ['label' => 'پرسنل غایب', 'value' => $report['summary']['employees']],
                    ],
                    'note' => 'تعطیلات و مرخصی‌ها در این گزارش غیبت شمرده نمی‌شوند.',
                ];

            case 'leave':
                $report = $this->leave($customerId, $filters);

                return [
                    'type'    => 'leave',
                    'title'   => self::TYPES['leave'],
                    'columns' => [
                        ['key' => 'employee', 'label' => 'پرسنل'],
                        ['key' => 'leave_type', 'label' => 'نوع مرخصی'],
                        ['key' => 'start_date', 'label' => 'از تاریخ'],
                        ['key' => 'end_date', 'label' => 'تا تاریخ'],
                        ['key' => 'days', 'label' => 'تعداد روز'],
                        ['key' => 'status', 'label' => 'وضعیت'],
                        ['key' => 'description', 'label' => 'توضیحات'],
                    ],
                    'rows'    => array_map(static fn (array $r): array => [
                        'employee' => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])),
                        'leave_type' => (string) $r['leave_type'],
                        'start_date' => (string) $r['start_date'],
                        'end_date'   => (string) $r['end_date'],
                        'days'       => (float) $r['days'],
                        'status'     => (string) $r['status'],
                        'description' => (string) ($r['description'] ?? ''),
                    ], $report['rows']),
                    'summary' => [
                        ['label' => 'تعداد مرخصی', 'value' => $report['summary']['records']],
                        ['label' => 'مجموع روز', 'value' => $report['summary']['days']],
                        ['label' => 'تأیید شده', 'value' => $report['summary']['approved']],
                    ],
                    'note' => '',
                ];

            case 'branch':
                $rows = $this->branch($customerId, $filters);

                return [
                    'type'    => 'branch',
                    'title'   => self::TYPES['branch'],
                    'columns' => [
                        ['key' => 'branch', 'label' => 'شعبه'],
                        ['key' => 'employees', 'label' => 'پرسنل', 'format' => 'int'],
                        ['key' => 'records', 'label' => 'تردد', 'format' => 'int'],
                        ['key' => 'work_minutes', 'label' => 'کارکرد', 'format' => 'minutes'],
                        ['key' => 'late_minutes', 'label' => 'تأخیر', 'format' => 'minutes'],
                        ['key' => 'overtime_minutes', 'label' => 'اضافه‌کاری', 'format' => 'minutes'],
                        ['key' => 'absence_days', 'label' => 'غیبت', 'format' => 'int'],
                    ],
                    'rows'    => array_map(static fn (array $r): array => [
                        'branch'  => (string) $r['branch_name'],
                        'employees' => (int) $r['employees'],
                        'records' => (int) $r['records'],
                        'work_minutes' => (int) $r['work_minutes'],
                        'late_minutes' => (int) $r['late_minutes'],
                        'overtime_minutes' => (int) $r['overtime_minutes'],
                        'absence_days' => (int) $r['absence_days'],
                    ], $rows),
                    'summary' => [
                        ['label' => 'تعداد شعب', 'value' => count($rows)],
                        ['label' => 'مجموع کارکرد', 'value' => array_sum(array_column($rows, 'work_minutes'))],
                    ],
                    'note' => '',
                ];

            case 'inside':
                $rows = $this->inside($customerId, (int) ($filters['branch_id'] ?? 0));

                return [
                    'type'    => 'inside',
                    'title'   => self::TYPES['inside'],
                    'columns' => [
                        ['key' => 'employee', 'label' => 'پرسنل'],
                        ['key' => 'entry_time', 'label' => 'زمان ورود'],
                        ['key' => 'duration', 'label' => 'مدت حضور', 'format' => 'minutes'],
                        ['key' => 'branch', 'label' => 'شعبه'],
                        ['key' => 'device', 'label' => 'دستگاه'],
                    ],
                    'rows'    => array_map(static fn (array $r): array => [
                        'employee' => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])),
                        'entry_time' => (string) $r['entry_at'],
                        'duration'   => (int) $r['inside_minutes'],
                        'branch'     => (string) ($r['branch_name'] ?? '—'),
                        'device'     => (string) ($r['device_name'] ?? '—'),
                    ], $rows),
                    'summary' => [['label' => 'تعداد حاضر', 'value' => count($rows)]],
                    'note'    => 'این گزارش زنده است و از رکوردهای باز (بدون خروج) ساخته می‌شود.',
                ];

            case 'payroll':
                $filters['date_from'] = (string) ($filters['date_from'] ?? '');
                $filters['date_to']   = (string) ($filters['date_to'] ?? '');

                $service = new PayrollService();
                $items   = $service->previewRows($customerId, $filters);

                return [
                    'type'    => 'payroll',
                    'title'   => 'گزارش حقوق و دستمزد (پیش‌نمایش)',
                    'columns' => [
                        ['key' => 'employee', 'label' => 'پرسنل'],
                        ['key' => 'pay_type', 'label' => 'نوع پرداخت'],
                        ['key' => 'work_days', 'label' => 'روز کارکرد', 'format' => 'int'],
                        ['key' => 'work_minutes', 'label' => 'کارکرد', 'format' => 'minutes'],
                        ['key' => 'overtime_minutes', 'label' => 'اضافه‌کاری', 'format' => 'minutes'],
                        ['key' => 'late_minutes', 'label' => 'تأخیر', 'format' => 'minutes'],
                        ['key' => 'absence_days', 'label' => 'غیبت', 'format' => 'int'],
                        ['key' => 'base_amount', 'label' => 'حق پایه', 'format' => 'money'],
                        ['key' => 'overtime_amount', 'label' => 'فوق‌العاده', 'format' => 'money'],
                        ['key' => 'deductions', 'label' => 'کسورات', 'format' => 'money'],
                        ['key' => 'net_amount', 'label' => 'خالص پرداختی', 'format' => 'money'],
                    ],
                    'rows'    => array_map(static fn (array $r): array => [
                        'employee' => (string) $r['employee_name'],
                        'pay_type' => (string) $r['pay_type_label'],
                        'work_days' => (int) $r['work_days'],
                        'work_minutes' => (int) $r['work_minutes'],
                        'overtime_minutes' => (int) $r['overtime_minutes'],
                        'late_minutes' => (int) $r['late_minutes'],
                        'absence_days' => (int) $r['absence_days'],
                        'base_amount' => (float) $r['base_amount'],
                        'overtime_amount' => (float) $r['overtime_amount'],
                        'deductions' => (float) $r['total_deductions'],
                        'net_amount' => (float) $r['net_amount'],
                    ], $items),
                    'summary' => [
                        ['label' => 'تعداد پرسنل', 'value' => count($items)],
                        ['label' => 'مجموع خالص', 'value' => array_sum(array_column($items, 'net_amount'))],
                    ],
                    'note'    => 'این گزارش پیش‌نمایش محاسبه است؛ برای قطعی‌سازی از صفحه حقوق و دستمزد استفاده کنید.',
                ];

            default:
                $report = $this->daily($customerId, $filters, 1, 500);

                return [
                    'type'    => 'daily',
                    'title'   => self::TYPES['daily'],
                    'columns' => [
                        ['key' => 'employee', 'label' => 'پرسنل'],
                        ['key' => 'code', 'label' => 'کد پرسنلی'],
                        ['key' => 'date', 'label' => 'تاریخ'],
                        ['key' => 'shift', 'label' => 'شیفت'],
                        ['key' => 'entry_time', 'label' => 'ورود'],
                        ['key' => 'exit_time', 'label' => 'خروج'],
                        ['key' => 'work_minutes', 'label' => 'کارکرد', 'format' => 'minutes'],
                        ['key' => 'late_minutes', 'label' => 'تأخیر', 'format' => 'minutes'],
                        ['key' => 'early_leave_minutes', 'label' => 'تعجیل خروج', 'format' => 'minutes'],
                        ['key' => 'overtime_minutes', 'label' => 'اضافه‌کاری', 'format' => 'minutes'],
                        ['key' => 'status', 'label' => 'وضعیت'],
                        ['key' => 'source', 'label' => 'منبع'],
                    ],
                    'rows'    => array_map(static fn (array $r): array => [
                        'employee' => trim(((string) $r['first_name']) . ' ' . ((string) $r['last_name'])),
                        'code'     => (string) ($r['employee_code'] ?? ''),
                        'date'     => (string) $r['attendance_date'],
                        'shift'    => (string) ($r['shift_name'] ?? '—'),
                        'entry_time' => (string) ($r['entry_at'] ?? ''),
                        'exit_time'  => (string) ($r['exit_at'] ?? ''),
                        'work_minutes' => (int) $r['work_minutes'],
                        'late_minutes' => (int) $r['late_minutes'],
                        'early_leave_minutes' => (int) $r['early_leave_minutes'],
                        'overtime_minutes'    => (int) $r['overtime_minutes'],
                        'status' => \App\Models\AttendanceRecord::statusLabel((string) $r['status']),
                        'source' => (string) $r['source'],
                    ], $report['rows']),
                    'summary' => [
                        ['label' => 'تعداد پرسنل', 'value' => $report['summary']['employees']],
                        ['label' => 'حاضر (روز/نفر)', 'value' => $report['summary']['present_days']],
                        ['label' => 'غایب (روز/نفر)', 'value' => $report['summary']['absent_days']],
                        ['label' => 'مرخصی (روز/نفر)', 'value' => $report['summary']['leave_days']],
                        ['label' => 'تأخیر (دقیقه)', 'value' => $report['summary']['late_minutes']],
                        ['label' => 'اضافه‌کاری (دقیقه)', 'value' => $report['summary']['overtime_minutes']],
                        ['label' => 'مجموع کارکرد', 'value' => $report['summary']['work_minutes']],
                    ],
                    'note' => '',
                ];
        }
    }
}
