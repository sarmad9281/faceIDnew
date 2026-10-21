<?php
/**
 * SARMAD Attendance - audit trail.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\BrowserInfo;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Session;
use App\Core\TenantContext;

final class AuditLog extends Model
{
    protected static string $table = 'audit_logs';

    /** null = not probed yet (see hasActionColumn()). */
    private static ?bool $hasActionColumn = null;

    protected static array $fillable = [
        'channel', 'event', 'action', 'level', 'result', 'actor_type', 'actor_id', 'actor_label', 'role',
        'customer_id', 'entity_type', 'entity_id', 'description', 'ip', 'user_agent',
        'request_method', 'request_path', 'meta',
    ];

    /* -----------------------------------------------------------------
     * Convenience writers
     * ----------------------------------------------------------------*/

    /**
     * @param array<string,mixed> $meta
     */
    public static function record(
        string $event,
        string $result = 'success',
        string $description = '',
        array $meta = [],
        string $channel = 'app',
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $customerId = null
    ): void {
        $data = self::context() + [
            'channel'        => $channel,
            'event'          => substr($event, 0, 60),
            // Canonical business action (OWNER panel contract). Only written
            // once the stage 2 column exists, so an un-migrated database
            // still records every event through the classic columns.
            'action'         => self::hasActionColumn()
                ? \App\Services\AuditService::actionFor($event)
                : null,
            'level'          => self::levelFor($result),
            'result'         => $result,
            'description'    => $description === '' ? null : substr($description, 0, 500),
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'customer_id'    => $customerId,
            'meta'           => $meta === [] ? null : json_encode(Logger::redact($meta), JSON_UNESCAPED_UNICODE),
        ];

        if ($data['customer_id'] === null) {
            $data['customer_id'] = TenantContext::customerId();
        }

        try {
            self::create($data);
        } catch (\Throwable $e) {
            // Auditing must never break the request; fall back to the file log.
            Logger::channel('audit')->error('Failed to write audit log', [
                'event'     => $event,
                'exception' => $e->getMessage(),
            ]);
        }

        Logger::channel($channel)->write(self::levelFor($result), $event, $meta);
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function denied(string $description, array $meta = []): void
    {
        self::record('access.denied', 'denied', $description, $meta, 'security');
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function auth(
        string $event,
        string $result,
        string $description,
        array $meta = [],
        ?int $customerId = null
    ): void {
        self::record($event, $result, $description, $meta, 'auth', null, null, $customerId);
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function security(string $event, string $result, string $description, array $meta = []): void
    {
        self::record($event, $result, $description, $meta, 'security');
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function device(string $event, string $result, string $description, array $meta = []): void
    {
        self::record($event, $result, $description, $meta, 'device');
    }

    /* -----------------------------------------------------------------
     * Reading
     * ----------------------------------------------------------------*/

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recent(?int $customerId = null, int $limit = 50, int $offset = 0): array
    {
        $sql    = 'SELECT * FROM `' . self::tableName() . '`';
        $params = [];

        if ($customerId !== null && $customerId > 0) {
            $sql .= ' WHERE `customer_id` = ?';
            $params[] = $customerId;
        }

        $sql .= ' ORDER BY `id` DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        return array_map([self::class, 'cast'], Database::select($sql, $params));
    }

    /**
     * @return array<string,int>
     */
    public static function summary(?int $customerId = null, int $days = 7): array
    {
        $sql    = 'SELECT `result`, COUNT(*) AS total FROM `' . self::tableName() . '` WHERE `created_at` >= ?';
        $params = [date('Y-m-d H:i:s', time() - ($days * 86400))];

        if ($customerId !== null && $customerId > 0) {
            $sql .= ' AND `customer_id` = ?';
            $params[] = $customerId;
        }

        $sql .= ' GROUP BY `result`';

        $summary = ['success' => 0, 'failure' => 0, 'denied' => 0, 'error' => 0];

        foreach (Database::select($sql, $params) as $row) {
            $summary[(string) $row['result']] = (int) $row['total'];
        }

        return $summary;
    }

    public static function purgeOlderThan(int $days): int
    {
        return Database::run(
            'DELETE FROM `' . self::tableName() . '` WHERE `created_at` < ?',
            [date('Y-m-d H:i:s', time() - (max(1, $days) * 86400))]
        )->rowCount();
    }

    /* ----------------------------------------------------------------- */

    /**
     * @return array<string,mixed>
     */
    private static function context(): array
    {
        $actor     = Session::actor();
        $actorType = 'guest';
        $actorId   = null;
        $role      = Session::role();

        if ($actor !== null) {
            $type = (string) ($actor['type'] ?? '');

            if ($type === 'user') {
                $actorType = $role ?? 'manager';
                $actorId   = (int) ($actor['id'] ?? 0);
            } elseif ($type === 'device') {
                $actorType = 'device';
                $actorId   = (int) ($actor['id'] ?? 0);
            }
        }

        if ($actorType === 'guest' && (PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD']))) {
            $actorType = 'system';
        }

        return [
            'actor_type'     => in_array($actorType, ['owner', 'manager', 'supervisor', 'device', 'system', 'guest'], true) ? $actorType : 'guest',
            'actor_id'       => $actorId,
            'actor_label'    => Session::actorLabel(),
            'role'           => $role,
            'ip'             => Request::ip(),
            'user_agent'     => BrowserInfo::label() . ' | ' . substr(Request::userAgent(), 0, 200),
            'request_method' => isset($_SERVER['REQUEST_METHOD']) ? substr((string) $_SERVER['REQUEST_METHOD'], 0, 10) : 'CLI',
            'request_path'   => isset($_SERVER['REQUEST_URI']) ? substr((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), 0, 255) : 'cli',
        ];
    }

    private static function levelFor(string $result): string
    {
        switch ($result) {
            case 'error':
                return 'error';
            case 'denied':
                return 'warning';
            case 'failure':
                return 'notice';
            default:
                return 'info';
        }
    }

    /**
     * Does the stage 2 "action" column exist yet?
     */
    private static function hasActionColumn(): bool
    {
        if (self::$hasActionColumn !== null) {
            return self::$hasActionColumn;
        }

        try {
            self::$hasActionColumn = (new \App\Services\MigrationService())->columnExists('audit_logs', 'action');
        } catch (\Throwable $e) {
            self::$hasActionColumn = false;
        }

        return self::$hasActionColumn;
    }

    /* -----------------------------------------------------------------
     * Owner panel queries (stage 2)
     * ----------------------------------------------------------------*/

    /**
     * @param array{action?:string,actor?:string,result?:string,customer_id?:int,from?:string,to?:string,q?:string,channel?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public static function paginate(array $filters, int $page = 1, int $perPage = 30): array
    {
        $actionColumn = self::hasActionColumn() ? 'a.`action`' : 'a.`event`';

        $where  = ['1 = 1'];
        $params = [];

        $action = trim((string) ($filters['action'] ?? ''));

        if ($action !== '') {
            $where[] = $actionColumn . ' = :action';
            $params[':action'] = $action;
        }

        $channel = trim((string) ($filters['channel'] ?? ''));

        if ($channel !== '') {
            $where[] = 'a.`channel` = :channel';
            $params[':channel'] = $channel;
        }

        $result = trim((string) ($filters['result'] ?? ''));

        if (in_array($result, ['success', 'failure', 'denied', 'error'], true)) {
            $where[] = 'a.`result` = :result';
            $params[':result'] = $result;
        }

        $actor = trim((string) ($filters['actor'] ?? ''));

        if ($actor !== '') {
            $where[] = '(a.`actor_label` LIKE :actor OR a.`description` LIKE :actor_desc)';
            $params[':actor']      = '%' . $actor . '%';
            $params[':actor_desc'] = '%' . $actor . '%';
        }

        $customerId = (int) ($filters['customer_id'] ?? 0);

        if ($customerId > 0) {
            $where[] = 'a.`customer_id` = :customer_id';
            $params[':customer_id'] = $customerId;
        }

        $from = self::normalizeDate((string) ($filters['from'] ?? ''));

        if ($from !== '') {
            $where[] = 'a.`created_at` >= :from';
            $params[':from'] = $from . ' 00:00:00';
        }

        $to = self::normalizeDate((string) ($filters['to'] ?? ''));

        if ($to !== '') {
            $where[] = 'a.`created_at` <= :to';
            $params[':to'] = $to . ' 23:59:59';
        }

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $where[] = '(a.`request_path` LIKE :q OR a.`ip` LIKE :q_ip OR a.`entity_type` LIKE :q_entity)';
            $params[':q']        = '%' . $q . '%';
            $params[':q_ip']     = '%' . $q . '%';
            $params[':q_entity'] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);

        $from0 = '`' . self::tableName() . '` a'
            . ' LEFT JOIN `' . Database::table('customers') . '` c ON c.`id` = a.`customer_id`'
            . ' LEFT JOIN `' . Database::table('users') . '` u ON u.`id` = a.`actor_id` AND a.`actor_type` <> \'device\'';

        $total = (int) Database::scalar('SELECT COUNT(*) FROM ' . $from0 . ' WHERE ' . $whereSql, $params);

        $perPage = max(5, min(200, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            'SELECT a.*, c.`name` AS customer_name, u.`name` AS actor_name'
            . ' FROM ' . $from0 . ' WHERE ' . $whereSql
            . ' ORDER BY a.`id` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
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

    private static function normalizeDate(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // Accept only a strict ISO date; anything else is ignored instead of
        // being pasted into the query.
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * Latest activity feed for the owner dashboard.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function latestForOwner(int $limit = 12): array
    {
        $rows = Database::select(
            'SELECT a.*, c.`name` AS customer_name FROM `' . self::tableName() . '` a'
            . ' LEFT JOIN `' . Database::table('customers') . '` c ON c.`id` = a.`customer_id`'
            . ' ORDER BY a.`id` DESC LIMIT ' . max(1, $limit)
        );

        return array_map([static::class, 'cast'], $rows);
    }

    /**
     * How many rows each canonical action has (used for the filter chips).
     *
     * @return array<string,int>
     */
    public static function actionCounts(int $days = 30): array
    {
        $column = self::hasActionColumn() ? '`action`' : '`event`';

        $rows = Database::select(
            'SELECT ' . $column . ' AS act, COUNT(*) AS total FROM `' . self::tableName() . '`'
            . ' WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL ' . max(1, $days) . ' DAY)'
            . ' GROUP BY act ORDER BY total DESC'
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['act']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return array<int,string>
     */
    public static function channels(): array
    {
        $rows = Database::select('SELECT DISTINCT `channel` FROM `' . self::tableName() . '` ORDER BY `channel` ASC');

        return array_map(static fn (array $row): string => (string) $row['channel'], $rows);
    }
}
