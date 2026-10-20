<?php
/**
 * SARMAD Attendance - holidays (stage 3, manager panel).
 *
 * Every customer keeps its own calendar. The rows created here are the ones
 * the later attendance / payroll calculations will skip, so the date is
 * stored as a real DATE column - no Persian text dates in the database.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Security;

final class Holiday extends Model
{
    protected static string $table = 'holidays';
    protected static bool $softDeletes = true;

    protected static array $fillable = [
        'uuid', 'customer_id', 'branch_id', 'title', 'holiday_date', 'description',
        'is_recurring_yearly', 'is_active', 'created_by',
    ];

    /**
     * @param array<string,mixed> $data
     */
    public static function createHoliday(array $data): int
    {
        $data['uuid'] = $data['uuid'] ?? Security::uuid4();

        return self::create($data);
    }

    /**
     * @param array{q?:string,year?:string,month?:string,branch_id?:int,status?:string} $filters
     * @return array<int,array<string,mixed>>
     */
    public static function forCustomer(int $customerId, array $filters = [], int $limit = 200, int $offset = 0): array
    {
        $where  = ['h.`deleted_at` IS NULL', 'h.`customer_id` = :tenant'];
        $params = [':tenant' => $customerId];

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $where[] = '(h.`title` LIKE :q_title OR h.`description` LIKE :q_desc)';
            $like = '%' . $search . '%';
            $params[':q_title'] = $like;
            $params[':q_desc']  = $like;
        }

        $year = trim((string) ($filters['year'] ?? ''));

        if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
            $where[] = 'YEAR(h.`holiday_date`) = :year';
            $params[':year'] = (int) $year;
        }

        $month = trim((string) ($filters['month'] ?? ''));

        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $where[] = "DATE_FORMAT(h.`holiday_date`, '%Y-%m') = :month";
            $params[':month'] = $month;
        }

        $branchId = (int) ($filters['branch_id'] ?? 0);

        if ($branchId > 0) {
            $where[] = '(h.`branch_id` = :branch_id OR h.`branch_id` IS NULL)';
            $params[':branch_id'] = $branchId;
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status === 'active') {
            $where[] = 'h.`is_active` = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'h.`is_active` = 0';
        }

        $rows = Database::select(
            'SELECT h.*, b.`name` AS branch_name FROM `' . self::tableName() . '` h'
            . ' LEFT JOIN `' . Database::table('branches') . '` b ON b.`id` = h.`branch_id`'
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY h.`holiday_date` DESC, h.`id` DESC'
            . ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        );

        return array_map([static::class, 'cast'], $rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findInCustomer(int $holidayId, int $customerId): ?array
    {
        if ($holidayId <= 0 || $customerId <= 0) {
            return null;
        }

        $row = Database::selectOne(
            'SELECT * FROM `' . self::tableName() . '` WHERE `id` = ? AND `customer_id` = ? AND `deleted_at` IS NULL LIMIT 1',
            [$holidayId, $customerId]
        );

        return $row === null ? null : self::cast($row);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function upcoming(int $customerId, int $limit = 5): array
    {
        $rows = Database::select(
            'SELECT * FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `is_active` = 1 AND `deleted_at` IS NULL AND `holiday_date` >= CURDATE()'
            . ' ORDER BY `holiday_date` ASC LIMIT ' . max(1, min(20, $limit)),
            [$customerId]
        );

        return array_map([static::class, 'cast'], $rows);
    }

    public static function countForMonth(int $customerId, string $month, int $branchId = 0): int
    {
        $sql    = 'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . " WHERE `customer_id` = ? AND `deleted_at` IS NULL AND DATE_FORMAT(`holiday_date`, '%Y-%m') = ?";
        $params = [$customerId, $month];

        if ($branchId > 0) {
            $sql .= ' AND (`branch_id` = ? OR `branch_id` IS NULL)';
            $params[] = $branchId;
        }

        return (int) Database::scalar($sql, $params);
    }

    /**
     * Is the date a holiday of this customer? (used by the holiday page and,
     * later, by the attendance engine)
     */
    public static function existsOn(int $customerId, string $date, int $branchId = 0, int $exceptId = 0): bool
    {
        $sql    = 'SELECT COUNT(*) FROM `' . self::tableName() . '` WHERE `customer_id` = ? AND `holiday_date` = ? AND `deleted_at` IS NULL';
        $params = [$customerId, $date];

        if ($branchId > 0) {
            $sql .= ' AND (`branch_id` IS NULL OR `branch_id` = ?)';
            $params[] = $branchId;
        }

        if ($exceptId > 0) {
            $sql .= ' AND `id` <> ?';
            $params[] = $exceptId;
        }

        return (int) Database::scalar($sql, $params) > 0;
    }

    public static function upcomingCount(int $customerId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . ' WHERE `customer_id` = ? AND `is_active` = 1 AND `deleted_at` IS NULL AND `holiday_date` >= CURDATE()',
            [$customerId]
        );
    }
}
