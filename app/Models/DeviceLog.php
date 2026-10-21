<?php
/**
 * SARMAD Attendance - device event log.
 *
 * Every device level event that matters operationally lands here:
 * activation, authentication, heartbeat, token reset, deactivation,
 * connection errors, recognition errors and attendance submissions.
 *
 * The same events are also written to the audit trail (App\Models\AuditLog)
 * for the owner; this table keeps the device specific detail (IP, platform,
 * error code, meta) and is what the owner/manager device pages show.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class DeviceLog extends Model
{
    protected static string $table = 'device_logs';

    public const LEVELS = ['info', 'warning', 'error'];

    /**
     * Canonical device events (used by the panels' filter and by the tests).
     *
     * @var array<string,string>
     */
    public const EVENTS = [
        'device.activation.code_issued' => 'صدور کد فعال‌سازی',
        'device.activation.success'     => 'فعال‌سازی موفق',
        'device.activation.failed'      => 'فعال‌سازی ناموفق',
        'device.auth.success'           => 'ورود موفق دستگاه',
        'device.auth.failed'            => 'ورود ناموفق دستگاه',
        'device.heartbeat'              => 'ضربان دستگاه',
        'device.config.loaded'          => 'دریافت تنظیمات',
        'device.token.reset'            => 'بازتولید توکن',
        'device.deactivated'            => 'غیرفعال‌سازی',
        'device.revoked'                => 'لغو دستگاه',
        'device.connection.error'       => 'خطای ارتباط',
        'device.recognition.error'      => 'خطای تشخیص',
        'device.attendance.submitted'   => 'ارسال حضور',
    ];

    /**
     * @param array<string,mixed> $meta
     */
    public static function record(
        string $event,
        string $level = 'info',
        string $message = '',
        ?int $deviceId = null,
        int $customerId = 0,
        array $meta = []
    ): void {
        if (!in_array($level, self::LEVELS, true)) {
            $level = 'info';
        }

        try {
            self::create([
                'device_id'   => $deviceId,
                'customer_id' => $customerId,
                'event'       => substr($event, 0, 60),
                'level'       => $level,
                'message'     => substr($message, 0, 255),
                'meta'        => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                // CLI safe: a console run (tests, cron) has no $_SERVER.
                'ip'          => isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
                'user_agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a device request.
            \App\Core\Logger::channel('device')->error('Failed to write device log', [
                'event'     => $event,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark the device row itself so the panels can show "last error".
     */
    public static function markDeviceError(int $deviceId, string $code): void
    {
        Database::run(
            'UPDATE `' . Database::table('devices') . '`'
            . ' SET `last_error_at` = NOW(), `last_error_code` = ? WHERE `id` = ?',
            [substr($code, 0, 60), $deviceId]
        );
    }

    /**
     * @return array{rows:array<int,array<string,mixed>>, total:int, page:int, perPage:int, pages:int}
     */
    public static function paginate(array $filters, int $page = 1, int $perPage = 30): array
    {
        $where  = ['1 = 1'];
        $params = [];

        $deviceId = (int) ($filters['device_id'] ?? 0);

        if ($deviceId > 0) {
            $where[] = 'l.`device_id` = :device_id';
            $params[':device_id'] = $deviceId;
        }

        $customerId = (int) ($filters['customer_id'] ?? 0);

        if ($customerId > 0) {
            $where[] = 'l.`customer_id` = :customer_id';
            $params[':customer_id'] = $customerId;
        }

        $level = (string) ($filters['level'] ?? '');

        if (in_array($level, self::LEVELS, true)) {
            $where[] = 'l.`level` = :level';
            $params[':level'] = $level;
        }

        $event = trim((string) ($filters['event'] ?? ''));

        if ($event !== '') {
            $where[] = 'l.`event` LIKE :event';
            $params[':event'] = '%' . $event . '%';
        }

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            $where[] = '(l.`event` LIKE :q OR l.`message` LIKE :q_message OR l.`ip` LIKE :q_ip)';
            $params[':q']         = '%' . $search . '%';
            $params[':q_message'] = '%' . $search . '%';
            $params[':q_ip']      = '%' . $search . '%';
        }

        $from = \App\Support\Input::date((string) ($filters['from'] ?? ''));

        if ($from !== null) {
            $where[] = 'l.`created_at` >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }

        $to = \App\Support\Input::date((string) ($filters['to'] ?? ''));

        if ($to !== null) {
            $where[] = 'l.`created_at` <= :to';
            $params[':to'] = $to . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);
        $table    = self::tableName();
        $devices  = Database::table('devices');

        $total = (int) Database::scalar('SELECT COUNT(*) FROM `' . $table . '` l WHERE ' . $whereSql, $params);
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page  = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $rows = Database::select(
            'SELECT l.*, d.`name` AS device_name, d.`device_uid`, c.`name` AS customer_name'
            . ' FROM `' . $table . '` l'
            . ' LEFT JOIN `' . $devices . '` d ON d.`id` = l.`device_id`'
            . ' LEFT JOIN `' . Database::table('customers') . '` c ON c.`id` = l.`customer_id`'
            . ' WHERE ' . $whereSql
            . ' ORDER BY l.`id` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'pages' => $pages];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recentForDevice(int $deviceId, int $limit = 10): array
    {
        return Database::select(
            'SELECT * FROM `' . self::tableName() . '` WHERE `device_id` = ? ORDER BY `id` DESC LIMIT ' . max(1, $limit),
            [$deviceId]
        );
    }

    /**
     * Newest device events across all devices (owner panel overview).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function recentForDevices(int $limit = 15): array
    {
        return Database::select(
            'SELECT l.*, d.`name` AS device_name, d.`device_uid`, c.`name` AS customer_name'
            . ' FROM `' . self::tableName() . '` l'
            . ' LEFT JOIN `' . Database::table('devices') . '` d ON d.`id` = l.`device_id`'
            . ' LEFT JOIN `' . Database::table('customers') . '` c ON c.`id` = l.`customer_id`'
            . ' ORDER BY l.`id` DESC LIMIT ' . max(1, $limit)
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recentForCustomer(int $customerId, int $limit = 10): array
    {
        return Database::select(
            'SELECT l.*, d.`name` AS device_name FROM `' . self::tableName() . '` l'
            . ' LEFT JOIN `' . Database::table('devices') . '` d ON d.`id` = l.`device_id`'
            . ' WHERE l.`customer_id` = ? ORDER BY l.`id` DESC LIMIT ' . max(1, $limit),
            [$customerId]
        );
    }

    /**
     * How many rows of one event exist for a device inside a time window.
     * Used to throttle the audit trail copy of device authentication.
     */
    public static function recentCountForEvent(int $deviceId, string $event, int $seconds = 300): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . ' WHERE `device_id` = ? AND `event` = ? AND `created_at` >= (NOW() - INTERVAL ? SECOND)',
            [$deviceId, $event, max(1, $seconds)]
        );
    }

    public static function errorCount(int $minutes = 60): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM `' . self::tableName() . '`'
            . ' WHERE `level` = ? AND `created_at` >= (NOW() - INTERVAL ? MINUTE)',
            ['error', max(1, $minutes)]
        );
    }

    public static function eventLabel(string $event): string
    {
        return self::EVENTS[$event] ?? $event;
    }
}
