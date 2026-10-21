<?php
/**
 * SARMAD Attendance - PDO database layer.
 *
 * ---------------------------------------------------------------------
 * TENANT / PROJECT SAFETY
 * ---------------------------------------------------------------------
 * This application shares a physical database with other SARMAD
 * projects (barber_*, cafe_*, clinic_*, zibaei_*, sarmadpf_*).
 * To make it impossible to touch foreign tables by accident:
 *
 *   1. Every table name goes through Database::table() which enforces
 *      the project prefix ("attendance_").
 *   2. Any identifier that looks like it belongs to another project is
 *      rejected with an exception.
 *   3. All values are bound with prepared statements - never string
 *      concatenation.
 */

declare(strict_types=1);

namespace App\Core;

use PDO;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    /** Table prefixes that belong to OTHER projects and are strictly off-limits. */
    public const FOREIGN_PREFIXES = ['barber_', 'cafe_', 'clinic_', 'zibaei_', 'sarmadpf_'];

    private static string $prefix = 'attendance_';

    /* =================================================================
     * Connections
     * ===============================================================*/

    /**
     * Establish the main connection using config/config.php values.
     */
    public static function connect(?array $override = null): PDO
    {
        if (self::$pdo instanceof PDO && $override === null) {
            return self::$pdo;
        }

        $conf = $override ?? [
            'host'       => Config::get('db.host'),
            'port'       => Config::get('db.port'),
            'database'   => Config::get('db.database'),
            'username'   => Config::get('db.username'),
            'password'   => Config::get('db.password'),
            'charset'    => Config::get('db.charset', 'utf8mb4'),
            'prefix'     => Config::get('db.prefix', 'attendance_'),
            'persistent' => Config::get('db.persistent', false),
        ];

        self::assertPrefix($conf['prefix'] ?? 'attendance_');
        self::$prefix = (string) ($conf['prefix'] ?? 'attendance_');

        $pdo = self::makeConnection($conf, true);

        if ($override === null) {
            self::$pdo = $pdo;
        }

        return $pdo;
    }

    /**
     * Connection WITHOUT selecting a database (used by the installer to
     * verify the account before touching anything).
     */
    public static function connectServer(array $conf): PDO
    {
        return self::makeConnection($conf, false);
    }

    private static function makeConnection(array $conf, bool $withDatabase): PDO
    {
        $host     = (string) ($conf['host'] ?? 'localhost');
        $port     = (int) ($conf['port'] ?? 3306);
        $charset  = (string) ($conf['charset'] ?? 'utf8mb4');
        $database = (string) ($conf['database'] ?? '');
        $username = (string) ($conf['username'] ?? '');
        $password = (string) ($conf['password'] ?? '');

        // Direct DSN with database and charset:
        // mysql:host=localhost;port=3306;dbname=sarmadfa_clinic;charset=utf8mb4
        $dsn = 'mysql:host=' . $host . ';port=' . $port;

        if ($withDatabase && $database !== '') {
            $dsn .= ';dbname=' . $database;
        }

        $dsn .= ';charset=' . $charset;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (!empty($conf['persistent'])) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        try {
            $pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        self::alignSessionClock($pdo);

        return $pdo;
    }

    /**
     * Make the DATABASE session clock agree with the application clock.
     *
     * MySQL/MariaDB run with their own time_zone (often SYSTEM = UTC on a
     * hosting server) while PHP runs in app.timezone (Asia/Tehran here). That
     * mismatch is poison for an attendance system: PHP writes entry_at with
     * one clock, `NOW()` / `CURRENT_TIMESTAMP` defaults answer with another
     * one, and every duration computed in SQL (TIMESTAMPDIFF ... NOW()) or
     * every audit row would be off by the offset.
     *
     * The fix is one statement per connection: set the session time zone to
     * the offset of the configured application time zone. The schema keeps
     * plain DATETIME columns (no zone conversion), so both sides then produce
     * the same wall clock time.
     */
    private static function alignSessionClock(PDO $pdo): void
    {
        $name = 'UTC';

        if (class_exists(Config::class)) {
            $name = (string) Config::get('app.timezone', 'UTC');
        }

        try {
            $zone = new DateTimeZone($name);
        } catch (Throwable $e) {
            $zone = new DateTimeZone('UTC');
        }

        $offset = (new DateTimeImmutable('now', $zone))->format('P');

        try {
            $statement = $pdo->prepare('SET time_zone = ?');
            $statement->execute([$offset]);
        } catch (Throwable $e) {
            // A hosting account may not be allowed to change the session
            // time zone; the application never hard fails because of it.
            Logger::channel('database')->warning('Could not set the database session time zone', [
                'offset' => $offset,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Adopt an already established connection (used by the installer,
     * which verifies credentials before the config file exists).
     */
    public static function adopt(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo instanceof PDO) {
            throw new RuntimeException('Database is not connected yet.');
        }

        return self::$pdo;
    }

    public static function connected(): bool
    {
        return self::$pdo instanceof PDO;
    }

    /* =================================================================
     * Table name helpers (project safety net)
     * ===============================================================*/

    public static function prefix(): string
    {
        return self::$prefix;
    }

    public static function setPrefix(string $prefix): void
    {
        self::assertPrefix($prefix);
        self::$prefix = $prefix;
    }

    /**
     * Turn a short logical name into a fully prefixed, safe table name.
     *
     *   Database::table('users')        => `attendance_users`
     *   Database::table('attendance_users') => `attendance_users`
     *   Database::table('barber_orders')    => RuntimeException
     */
    public static function table(string $name): string
    {
        $name = strtolower(trim($name));

        if ($name === '') {
            throw new RuntimeException('Empty table name requested.');
        }

        foreach (self::FOREIGN_PREFIXES as $foreign) {
            if (strpos($name, $foreign) === 0) {
                throw new RuntimeException(
                    'Refused: "' . $name . '" belongs to another project. This application may only touch '
                    . self::$prefix . '* tables.'
                );
            }
        }

        if (strpos($name, self::$prefix) !== 0) {
            $name = self::$prefix . $name;
        }

        if (!preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
            throw new RuntimeException('Invalid table name: ' . $name);
        }

        return $name;
    }

    public static function assertPrefix(string $prefix): void
    {
        if (!preg_match('/^[a-z0-9_]{1,32}_$/', $prefix)) {
            throw new RuntimeException('Invalid table prefix: ' . $prefix);
        }

        foreach (self::FOREIGN_PREFIXES as $foreign) {
            if ($prefix === $foreign) {
                throw new RuntimeException('Refused: prefix "' . $prefix . '" belongs to another project.');
            }
        }
    }

    /**
     * Safe identifier for a column / alias. Never accepts anything that is
     * not [A-Za-z0-9_] so it can be interpolated safely.
     */
    public static function identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $identifier)) {
            throw new RuntimeException('Invalid SQL identifier: ' . $identifier);
        }

        return $identifier;
    }

    /* =================================================================
     * Query helpers - always prepared statements
     * ===============================================================*/

    /**
     * @param array<string|int,mixed> $params
     */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        try {
            $statement = self::pdo()->prepare($sql);
            self::bind($statement, $params);
            $statement->execute();

            return $statement;
        } catch (PDOException $e) {
            Logger::channel('database')->error('Query failed', [
                'sql'    => preg_replace('/\s+/', ' ', $sql),
                'error'  => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return mixed
     */
    public static function scalar(string $sql, array $params = [])
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function insert(string $table, array $data): int
    {
        $table  = self::table($table);
        $cols   = [];
        $marks  = [];
        $params = [];

        foreach ($data as $column => $value) {
            $column   = self::identifier((string) $column);
            $cols[]   = '`' . $column . '`';
            $marks[]  = ':' . $column;
            $params[':' . $column] = $value;
        }

        $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $marks) . ')';

        self::run($sql, $params);

        return (int) self::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public static function update(string $table, array $data, array $where): int
    {
        $table  = self::table($table);
        $sets   = [];
        $params = [];

        foreach ($data as $column => $value) {
            $column = self::identifier((string) $column);
            $sets[] = '`' . $column . '` = :set_' . $column;
            $params[':set_' . $column] = $value;
        }

        $clauses = [];
        $i       = 0;

        foreach ($where as $column => $value) {
            $column  = self::identifier((string) $column);
            $key     = ':w' . $i++;
            $clauses[] = '`' . $column . '` = ' . $key;
            $params[$key] = $value;
        }

        if ($clauses === []) {
            throw new RuntimeException('Refusing to run UPDATE without a WHERE clause.');
        }

        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $clauses);

        return self::run($sql, $params)->rowCount();
    }

    /**
     * @param array<string,mixed> $where
     */
    public static function delete(string $table, array $where): int
    {
        $table   = self::table($table);
        $clauses = [];
        $params  = [];
        $i       = 0;

        foreach ($where as $column => $value) {
            $column  = self::identifier((string) $column);
            $key     = ':w' . $i++;
            $clauses[] = '`' . $column . '` = ' . $key;
            $params[$key] = $value;
        }

        if ($clauses === []) {
            throw new RuntimeException('Refusing to run DELETE without a WHERE clause.');
        }

        $sql = 'DELETE FROM `' . $table . '` WHERE ' . implode(' AND ', $clauses);

        return self::run($sql, $params)->rowCount();
    }

    /**
     * @param array<string|int,mixed> $params
     */
    private static function bind(PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : $key;

            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_INT;
                    $value = $value ? 1 : 0;
                    break;
                case $value === null:
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }

            $statement->bindValue($parameter, $value, $type);
        }
    }

    /**
     * @return mixed
     */
    public static function transaction(callable $callback)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /* =================================================================
     * Schema introspection
     * ===============================================================*/

    public static function tableExists(string $shortName): bool
    {
        $table = self::table($shortName);

        $found = self::scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return (int) $found > 0;
    }

    /**
     * Every existing table in the current database.
     *
     * @return array<int,string>
     */
    public static function allTables(): array
    {
        $rows = self::select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        );

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    /**
     * Tables belonging to this project only.
     *
     * @return array<int,string>
     */
    public static function projectTables(): array
    {
        return array_values(array_filter(
            self::allTables(),
            static fn (string $name): bool => strpos($name, self::$prefix) === 0
        ));
    }

    /**
     * Tables in the shared database that belong to OTHER projects. The
     * application must never modify them; the installer reports them so
     * the operator can see they were detected and left alone.
     *
     * @return array<int,string>
     */
    public static function foreignTables(): array
    {
        return array_values(array_filter(
            self::allTables(),
            static function (string $name): bool {
                if (strpos($name, self::$prefix) === 0) {
                    return false;
                }

                foreach (self::FOREIGN_PREFIXES as $foreign) {
                    if (strpos($name, $foreign) === 0) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }

    public static function serverVersion(): string
    {
        return (string) self::scalar('SELECT VERSION()');
    }

    /* =================================================================
     * DDL (schema creation) - guarded
     * ===============================================================*/

    /**
     * Execute a raw DDL statement after the SqlGuard inspection.
     * Only used by the installer / migrations.
     */
    public static function statement(string $sql): void
    {
        $violations = SqlGuard::inspect($sql, self::$prefix);

        if ($violations !== []) {
            throw new RuntimeException('Blocked unsafe SQL: ' . implode(' | ', $violations));
        }

        self::pdo()->exec($sql);
    }
}
