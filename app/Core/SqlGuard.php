<?php
/**
 * SARMAD Attendance - SQL safety inspector.
 *
 * Every DDL statement produced by this project (installer / migrations)
 * passes through SqlGuard before it reaches MySQL. It guarantees:
 *
 *   - only tables with the project prefix (attendance_) may be touched
 *   - no DROP / TRUNCATE / RENAME / GRANT / REVOKE anywhere
 *   - no reference (even in a WHERE clause) to another project's tables
 *   - no filesystem level statements (INTO OUTFILE / LOAD DATA)
 */

declare(strict_types=1);

namespace App\Core;

final class SqlGuard
{
    /** Statements that are never allowed inside this project. */
    private const FORBIDDEN_PATTERNS = [
        '/\bdrop\s+(table|database|view|schema)\b/i'    => 'DROP is forbidden',
        '/\btruncate\b/i'                               => 'TRUNCATE is forbidden',
        '/\brename\s+table\b/i'                         => 'RENAME TABLE is forbidden',
        '/\balter\s+database\b/i'                       => 'ALTER DATABASE is forbidden',
        '/\bgrant\b/i'                                   => 'GRANT is forbidden',
        '/\brevoke\b/i'                                  => 'REVOKE is forbidden',
        '/\bcreate\s+(user|database|schema)\b/i'         => 'CREATE USER/DATABASE is forbidden',
        '/\binto\s+(outfile|dumpfile)\b/i'               => 'INTO OUTFILE is forbidden',
        '/\bload\s+data\b/i'                             => 'LOAD DATA is forbidden',
        '/\bshutdown\b/i'                                => 'SHUTDOWN is forbidden',
        '/\bflush\s+privileges\b/i'                      => 'FLUSH PRIVILEGES is forbidden',
        '/\bdelimiter\b/i'                               => 'DELIMITER is forbidden',
    ];

    /**
     * Table reference positions we inspect. UPDATE / DELETE are anchored to
     * the start of the statement so that column clauses such as
     * "ON UPDATE CURRENT_TIMESTAMP" are not mistaken for table names.
     */
    private const TABLE_POSITIONS = [
        '/create\s+table\s+(?:if\s+not\s+exists\s+)?([`"\']?[a-z0-9_.`"\']+)/i',
        '/insert\s+(?:ignore\s+)?into\s+([`"\']?[a-z0-9_.`"\']+)/i',
        '/^\s*update\s+([`"\']?[a-z0-9_.`"\']+)/i',
        '/^\s*delete\s+from\s+([`"\']?[a-z0-9_.`"\']+)/i',
        '/alter\s+table\s+([`"\']?[a-z0-9_.`"\']+)/i',
        '/\bfrom\s+([`"\']?[a-z0-9_.`"\']+)/i',
        '/\bjoin\s+([`"\']?[a-z0-9_.`"\']+)/i',
        '/references\s+([`"\']?[a-z0-9_.`"\']+)/i',
        '/create\s+(?:unique\s+)?index\s+[`"\']?[a-z0-9_]+[`"\']?\s+on\s+([`"\']?[a-z0-9_.`"\']+)/i',
    ];

    /**
     * Tokens that appear after FROM / UPDATE / JOIN but are not tables
     * (built in functions, DDL attribute keywords, ...).
     */
    private const NON_TABLE_TOKENS = [
        'current_timestamp', 'current_date', 'current_time', 'localtime', 'localtimestamp',
        'utc_timestamp', 'utc_date', 'utc_time', 'now', 'sysdate', 'dual', 'values',
        'cascade', 'restrict', 'set', 'select', 'table', 'json_table', 'information_schema',
    ];

    /**
     * @return array<int,string> list of violations (empty = statement is safe)
     */
    public static function inspect(string $sql, string $prefix = 'attendance_'): array
    {
        $violations = [];
        $statements = self::splitStatements($sql);

        if ($statements === []) {
            return ['Empty SQL statement'];
        }

        foreach ($statements as $statement) {
            $clean = self::stripComments($statement);

            foreach (self::FORBIDDEN_PATTERNS as $pattern => $message) {
                if (preg_match($pattern, $clean)) {
                    $violations[] = $message . ' -> ' . self::excerpt($clean);
                }
            }

            // Any explicit mention of a foreign project prefix is fatal.
            foreach (Database::FOREIGN_PREFIXES as $foreign) {
                if (stripos($clean, $foreign) !== false) {
                    $violations[] = 'Reference to another project\'s table (' . $foreign . '*) -> ' . self::excerpt($clean);
                }
            }

            // Every table position must be a project table.
            foreach (self::TABLE_POSITIONS as $pattern) {
                if (!preg_match_all($pattern, $clean, $matches)) {
                    continue;
                }

                foreach ($matches[1] as $raw) {
                    $table = strtolower(trim($raw, "`\"' "));
                    $table = str_replace('`', '', $table);

                    if ($table === '' || strpos($table, '(') !== false) {
                        continue;
                    }

                    // Built in functions and DDL attribute keywords are not tables.
                    if (in_array($table, self::NON_TABLE_TOKENS, true)) {
                        continue;
                    }

                    if (preg_match('/^[a-z_]+$/', $table) === 1 && preg_match('/\w+\s*\($/', $raw) === 1) {
                        continue;
                    }

                    if (strpos($table, $prefix) !== 0) {
                        $violations[] = 'Table "' . $table . '" does not start with "' . $prefix . '"';
                    }
                }
            }
        }

        return array_values(array_unique($violations));
    }

    public static function isSafe(string $sql, string $prefix = 'attendance_'): bool
    {
        return self::inspect($sql, $prefix) === [];
    }

    /**
     * Remove --, # and /* *\/ comments so that documentation text may
     * mention other projects without tripping the guard.
     */
    public static function stripComments(string $sql): string
    {
        $sql = preg_replace('#/\*.*?\*/#s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/(^|\s)--[^\n]*/', ' ', $sql) ?? $sql;
        $sql = preg_replace('/(^|\s)#[^\n]*/', ' ', $sql) ?? $sql;

        return $sql;
    }

    /**
     * Split a SQL script into single statements.
     *
     * @return array<int,string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer     = '';
        $length     = strlen($sql);
        $inString   = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($inString !== null) {
                $buffer .= $char;

                if ($char === '\\') {
                    if ($i + 1 < $length) {
                        $buffer .= $sql[++$i];
                    }
                    continue;
                }

                if ($char === $inString) {
                    $inString = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $inString = $char;
                $buffer  .= $char;
                continue;
            }

            // Line comments inside the script
            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $buffer .= "\n";
                continue;
            }

            if ($char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                $buffer .= "\n";
                continue;
            }

            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    private static function excerpt(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

        return strlen($sql) > 90 ? substr($sql, 0, 90) . '...' : $sql;
    }
}
