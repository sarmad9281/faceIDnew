<?php
/**
 * SARMAD Attendance - tiny active-record-ish base model.
 *
 * Models work with plain arrays (fast, easy to audit) and always talk to
 * the database through Database, which enforces the attendance_ prefix.
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\TenantContext;
use RuntimeException;

abstract class Model
{
    /** Short table name WITHOUT the project prefix, e.g. "users". */
    protected static string $table = '';

    /** Primary key column. */
    protected static string $primaryKey = 'id';

    /** Columns that may be mass assigned. */
    protected static array $fillable = [];

    /** Guarded columns: never assignable from user input. */
    protected static array $guarded = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /** Soft delete support. */
    protected static bool $softDeletes = false;

    /* ================================================================= */

    public static function tableName(): string
    {
        if (static::$table === '') {
            throw new RuntimeException('Table name is not defined on ' . static::class);
        }

        return Database::table(static::$table);
    }

    /**
     * @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public static function filter(array $attributes): array
    {
        $allowed = static::$fillable;

        $clean = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, static::$guarded, true)) {
                continue;
            }

            if ($allowed !== [] && !in_array($key, $allowed, true)) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public static function create(array $attributes): int
    {
        $data = static::filter($attributes);

        if (static::$softDeletes) {
            $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        }

        return Database::insert(static::tableName(), $data);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public static function updateById(int $id, array $attributes): int
    {
        $data = static::filter($attributes);

        if ($data === []) {
            return 0;
        }

        return Database::update(static::tableName(), $data, [static::$primaryKey => $id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $sql    = 'SELECT * FROM `' . static::tableName() . '` WHERE `' . static::$primaryKey . '` = ?';
        $params = [$id];

        if (static::$softDeletes) {
            $sql .= ' AND `deleted_at` IS NULL';
        }

        $row = Database::selectOne($sql . ' LIMIT 1', $params);

        return $row === null ? null : static::cast($row);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findOrFail(int $id): array
    {
        $row = static::find($id);

        if ($row === null) {
            throw new RuntimeException(static::class . ' #' . $id . ' not found.');
        }

        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findBy(string $column, $value): ?array
    {
        Database::identifier($column);

        $sql    = 'SELECT * FROM `' . static::tableName() . '` WHERE `' . $column . '` = ?';
        $params = [$value];

        if (static::$softDeletes) {
            $sql .= ' AND `deleted_at` IS NULL';
        }

        $row = Database::selectOne($sql . ' LIMIT 1', $params);

        return $row === null ? null : static::cast($row);
    }

    /**
     * @param array<string,mixed> $conditions
     * @return array<int,array<string,mixed>>
     */
    public static function where(array $conditions, string $orderBy = 'id DESC', int $limit = 0, int $offset = 0): array
    {
        $clauses = [];
        $params  = [];
        $i       = 0;

        foreach ($conditions as $column => $value) {
            $column = Database::identifier((string) $column);

            if ($value === null) {
                $clauses[] = '`' . $column . '` IS NULL';
                continue;
            }

            if (is_array($value)) {
                $placeholders = [];
                foreach ($value as $item) {
                    $key            = ':w' . $i++;
                    $placeholders[] = $key;
                    $params[$key]   = $item;
                }
                $clauses[] = '`' . $column . '` IN (' . implode(', ', $placeholders) . ')';
                continue;
            }

            $key         = ':w' . $i++;
            $clauses[]   = '`' . $column . '` = ' . $key;
            $params[$key] = $value;
        }

        if (static::$softDeletes) {
            $clauses[] = '`deleted_at` IS NULL';
        }

        $sql = 'SELECT * FROM `' . static::tableName() . '`';

        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        $sql .= ' ORDER BY ' . static::safeOrder($orderBy);

        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, $offset);
        }

        return array_map([static::class, 'cast'], Database::select($sql, $params));
    }

    /**
     * @param array<string,mixed> $conditions
     */
    public static function countWhere(array $conditions = []): int
    {
        $clauses = [];
        $params  = [];
        $i       = 0;

        foreach ($conditions as $column => $value) {
            $column = Database::identifier((string) $column);

            if ($value === null) {
                $clauses[] = '`' . $column . '` IS NULL';
                continue;
            }

            $key          = ':w' . $i++;
            $clauses[]    = '`' . $column . '` = ' . $key;
            $params[$key] = $value;
        }

        if (static::$softDeletes) {
            $clauses[] = '`deleted_at` IS NULL';
        }

        $sql = 'SELECT COUNT(*) FROM `' . static::tableName() . '`';

        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        return (int) Database::scalar($sql, $params);
    }

    public static function deleteById(int $id, string $reason = ''): int
    {
        if (static::$softDeletes) {
            return Database::update(static::tableName(), [
                'deleted_at' => date('Y-m-d H:i:s'),
            ], [static::$primaryKey => $id]);
        }

        return Database::delete(static::tableName(), [static::$primaryKey => $id]);
    }

    /* =================================================================
     * Tenant helpers - the ONLY way rows are read/written for a customer
     * ===============================================================*/

    /**
     * Fetch a row by id AND prove it belongs to the active tenant.
     *
     * @return array<string,mixed>|null
     */
    public static function findForTenant(int $id, string $column = 'customer_id'): ?array
    {
        $row = static::find($id);

        if ($row === null) {
            return null;
        }

        $expected = TenantContext::customerId();

        if ($expected === null) {
            // Platform level actor without scope: allowed, caller decides.
            return $row;
        }

        if ((int) ($row[$column] ?? 0) !== $expected) {
            \App\Core\Logger::channel('security')->error('Cross tenant read blocked', [
                'model'             => static::class,
                'id'                => $id,
                'expected_customer' => $expected,
                'actual_customer'   => (int) ($row[$column] ?? 0),
            ]);

            return null;
        }

        return $row;
    }

    /**
     * Conditions array already scoped to the active tenant.
     *
     * @param array<string,mixed> $conditions
     * @return array<string,mixed>
     */
    public static function tenantScoped(array $conditions = [], string $column = 'customer_id'): array
    {
        $id = TenantContext::requireCustomerId();

        return array_merge([$column => $id], $conditions);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function allForTenant(string $orderBy = 'id DESC', int $limit = 0, int $offset = 0): array
    {
        return static::where(static::tenantScoped(), $orderBy, $limit, $offset);
    }

    /* =================================================================
     * Casting / formatting
     * ===============================================================*/

    /**
     * Cast numeric columns to int, decode json columns.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function cast(array $row): array
    {
        foreach (['id', 'customer_id', 'branch_id', 'manager_id', 'user_id', 'device_id',
                     'role_id', 'created_by', 'entity_id', 'actor_id'] as $column) {
            if (isset($row[$column]) && is_numeric($row[$column])) {
                $row[$column] = (int) $row[$column];
            }
        }

        foreach (['is_active', 'is_primary', 'is_blocked', 'successful', 'face_enrolled',
                     'must_change_password', 'is_system', 'is_encrypted', 'api_enabled'] as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (int) $row[$column];
            }
        }

        foreach (['meta', 'settings', 'capabilities', 'abilities'] as $column) {
            if (!empty($row[$column]) && is_string($row[$column])) {
                $decoded = json_decode($row[$column], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row[$column . '_json'] = $decoded;
                }
            }
        }

        return $row;
    }

    /**
     * Only allow "column ASC|DESC" style orders to reach the query.
     */
    protected static function safeOrder(string $orderBy): string
    {
        $parts = [];

        foreach (explode(',', $orderBy) as $chunk) {
            $chunk = trim($chunk);

            if ($chunk === '') {
                continue;
            }

            $segments = preg_split('/\s+/', $chunk) ?: [];
            // Tolerate callers that already quoted the column ("`level` DESC").
            $column   = trim($segments[0], "`\"' ");
            $column   = Database::identifier($column);
            $direction = strtoupper($segments[1] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

            $parts[] = '`' . $column . '` ' . $direction;
        }

        return $parts === [] ? '`' . static::$primaryKey . '` DESC' : implode(', ', $parts);
    }
}
