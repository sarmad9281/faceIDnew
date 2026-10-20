<?php
/**
 * SARMAD Attendance - schema migration runner (stage 2).
 *
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------
 * The project shares a physical database with other SARMAD projects, so
 * there is no "php artisan migrate" style tool that scans the schema and
 * generates generic SQL. Every migration here is explicit, idempotent and
 * hard limited to the project prefix:
 *
 *   1. SQL files are executed statement by statement and each statement
 *      passes App\Core\SqlGuard (which blocks DROP / TRUNCATE / RENAME /
 *      ALTER of foreign tables and any reference to another project).
 *   2. Column additions go through addColumnIfMissing(), which checks
 *      information_schema for THIS database only, refuses any table that
 *      does not start with the project prefix and then issues a fully
 *      hard coded ALTER TABLE.
 *   3. Everything that ran is written to attendance_schema_migrations so
 *      a re-run is a no-op.
 *
 * It can be triggered from three places:
 *   - the installer (fresh installation),
 *   - the owner panel (Settings > database structure),
 *   - the CLI:  php tools/migrate.php
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Core\SqlGuard;
use PDO;
use RuntimeException;

final class MigrationService
{
    /**
     * Ordered list of upgrade files (stage 1 base install excluded - the
     * installer runs it explicitly so a fresh install and an upgrade stay
     * distinguishable).
     *
     * @var array<int,string>
     */
    public const UPGRADE_FILES = [
        'sql/attendance_upgrade_2.0.0.sql',
        'sql/attendance_upgrade_3.0.0.sql',
        'sql/attendance_upgrade_4.0.0.sql',
        'sql/attendance_upgrade_5.0.0.sql',
        'sql/attendance_upgrade_6.0.0.sql',
        'sql/attendance_upgrade_7.0.0.sql',
    ];

    /**
     * Base schema of a stage 1 installation (= InstallerService::SCHEMA_FILES).
     *
     * The web installer applies it itself, but `migrate.php migrate` can be
     * the very first command that ever touches a zero kilometre database and
     * the upgrade files carry foreign keys onto these base tables - so run()
     * bootstraps the base file first when it is missing.
     */
    public const BASE_FILE = 'sql/attendance_install.sql';

    /** Core base tables: when one of them is missing there is no base schema. */
    private const BASE_TABLES = ['roles', 'customers', 'users', 'employees', 'schema_migrations'];

    /**
     * Columns added to existing (project owned) tables. Table names are
     * short names - Database::table() adds the prefix and refuses
     * anything foreign.
     *
     * @var array<int,array{table:string,column:string,definition:string,after:string}>
     */
    private const COLUMN_ADDITIONS = [
        [
            'table'      => 'customers',
            'column'     => 'plan_name',
            'definition' => "VARCHAR(40) NOT NULL DEFAULT 'trial'",
            'after'      => 'subscription_plan',
        ],
        [
            'table'      => 'customers',
            'column'     => 'subscription_status',
            'definition' => "VARCHAR(20) NOT NULL DEFAULT 'trial'",
            'after'      => 'plan_name',
        ],
        [
            'table'      => 'customers',
            'column'     => 'subscription_start_date',
            'definition' => 'DATE DEFAULT NULL',
            'after'      => 'subscription_status',
        ],
        [
            'table'      => 'customers',
            'column'     => 'subscription_expire_date',
            'definition' => 'DATE DEFAULT NULL',
            'after'      => 'subscription_start_date',
        ],
        [
            'table'      => 'audit_logs',
            'column'     => 'action',
            'definition' => 'VARCHAR(60) DEFAULT NULL',
            'after'      => 'event',
        ],
        // The stage 1 audit table already has actor_id / role / entity_type /
        // entity_id. The stage 2 contract asks for the same information under
        // actor_user_id / actor_role / target_type / target_id, so those are
        // added as GENERATED columns: always in sync with the stage 1 columns,
        // never a second place to write to.
        [
            'table'      => 'audit_logs',
            'column'     => 'actor_user_id',
            'definition' => "INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `actor_type` IN ('owner','manager','supervisor') THEN `actor_id` ELSE NULL END) VIRTUAL",
            'after'      => 'actor_id',
        ],
        [
            'table'      => 'audit_logs',
            'column'     => 'actor_role',
            'definition' => 'VARCHAR(30) GENERATED ALWAYS AS (`role`) VIRTUAL',
            'after'      => 'role',
        ],
        [
            'table'      => 'audit_logs',
            'column'     => 'target_type',
            'definition' => 'VARCHAR(60) GENERATED ALWAYS AS (`entity_type`) VIRTUAL',
            'after'      => 'entity_type',
        ],
        [
            'table'      => 'audit_logs',
            'column'     => 'target_id',
            'definition' => 'BIGINT UNSIGNED GENERATED ALWAYS AS (`entity_id`) VIRTUAL',
            'after'      => 'entity_id',
        ],
        [
            'table'      => 'branches',
            'column'     => 'phone',
            'definition' => 'VARCHAR(25) DEFAULT NULL',
            'after'      => 'address',
        ],
        [
            'table'      => 'branches',
            'column'     => 'notes',
            'definition' => 'VARCHAR(255) DEFAULT NULL',
            'after'      => 'geofence_radius',
        ],
        [
            'table'      => 'employees',
            'column'     => 'notes',
            'definition' => 'VARCHAR(500) DEFAULT NULL',
            'after'      => 'face_enrolled',
        ],
        [
            'table'      => 'employees',
            'column'     => 'face_status',
            'definition' => "VARCHAR(20) NOT NULL DEFAULT 'not_enrolled'",
            'after'      => 'notes',
        ],
        [
            'table'      => 'users',
            'column'     => 'disabled_by_customer',
            'definition' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'after'      => 'is_active',
        ],
        [
            'table'      => 'devices',
            'column'     => 'disabled_by_customer',
            'definition' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'after'      => 'status',
        ],
        [
            'table'      => 'devices',
            'column'     => 'reset_count',
            'definition' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'after'      => 'token_rotated_at',
        ],
        [
            'table'      => 'devices',
            'column'     => 'last_reset_at',
            'definition' => 'DATETIME DEFAULT NULL',
            'after'      => 'reset_count',
        ],
        [
            'table'      => 'devices',
            'column'     => 'last_reset_reason',
            'definition' => 'VARCHAR(60) DEFAULT NULL',
            'after'      => 'last_reset_at',
        ],
        [
            'table'      => 'devices',
            'column'     => 'last_error_at',
            'definition' => 'DATETIME DEFAULT NULL',
            'after'      => 'last_seen_at',
        ],
        [
            'table'      => 'devices',
            'column'     => 'last_error_code',
            'definition' => 'VARCHAR(60) DEFAULT NULL',
            'after'      => 'last_error_at',
        ],
        /* ---- stage 6: attendance engine ------------------------------ */
        [
            'table'      => 'records',
            'column'     => 'flags',
            'definition' => "VARCHAR(120) NOT NULL DEFAULT ''",
            'after'      => 'status',
        ],
        /* --- stage 7: payroll profile fields ------------------------------
           attendance_payroll_profiles already existed (stage 2) with the base
           amounts. The calculator needs explicit, documented rate fields so
           no formula has to guess what a number means. */
        [
            'table'      => 'payroll_profiles',
            'column'     => 'late_rate',
            'definition' => 'DECIMAL(14,4) NOT NULL DEFAULT 0.0000',
            'after'      => 'deficit_rate',
        ],
        [
            'table'      => 'payroll_profiles',
            'column'     => 'early_leave_rate',
            'definition' => 'DECIMAL(14,4) NOT NULL DEFAULT 0.0000',
            'after'      => 'late_rate',
        ],
        [
            'table'      => 'payroll_profiles',
            'column'     => 'absence_mode',
            'definition' => "ENUM('per_day','per_minute','none') NOT NULL DEFAULT 'per_day'",
            'after'      => 'early_leave_rate',
        ],
        [
            'table'      => 'payroll_profiles',
            'column'     => 'absence_rate',
            'definition' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00',
            'after'      => 'absence_mode',
        ],
        [
            'table'      => 'payroll_profiles',
            'column'     => 'overtime_multiplier',
            'definition' => 'DECIMAL(6,2) NOT NULL DEFAULT 1.50',
            'after'      => 'overtime_rate',
        ],
        [
            'table'      => 'payroll_profiles',
            'column'     => 'fixed_deductions',
            'definition' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00',
            'after'      => 'benefits_amount',
        ],
        [
            'table'      => 'payroll_profiles',
            'column'     => 'work_days_per_month',
            'definition' => 'DECIMAL(6,2) NOT NULL DEFAULT 30.00',
            'after'      => 'fixed_deductions',
        ],
        [
            'table'      => 'payroll_profiles',
            'column'     => 'work_hours_per_day',
            'definition' => 'DECIMAL(6,2) NOT NULL DEFAULT 8.00',
            'after'      => 'work_days_per_month',
        ],
        [
            'table'      => 'payroll_profiles',
            'column'     => 'job_title',
            'definition' => 'VARCHAR(120) DEFAULT NULL',
            'after'      => 'currency',
        ],
        [
            'table'      => 'payroll_profiles',
            'column'     => 'calc_version',
            'definition' => "VARCHAR(20) NOT NULL DEFAULT '7.0.0'",
            'after'      => 'job_title',
        ],
        [
            /* Equals employee_id while the session is open, NULL afterwards.
               The UNIQUE index on this column is what makes a double ENTRY
               impossible even under concurrent requests. */
            'table'      => 'records',
            'column'     => 'open_key',
            'definition' => 'BIGINT UNSIGNED DEFAULT NULL',
            'after'      => 'flags',
        ],
        [
            'table'      => 'records',
            'column'     => 'session_index',
            'definition' => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',
            'after'      => 'open_key',
        ],
        [
            'table'      => 'records',
            'column'     => 'idempotency_key',
            'definition' => 'VARCHAR(64) DEFAULT NULL',
            'after'      => 'session_index',
        ],
        [
            'table'      => 'records',
            'column'     => 'local_event_id',
            'definition' => 'VARCHAR(64) DEFAULT NULL',
            'after'      => 'idempotency_key',
        ],
        [
            /* The local queue id of the CLOSING event. Both ids live side by
               side: local_event_id belongs to the event that OPENED the row
               and local_exit_event_id to the one that closed it, so a replayed
               queue item can be recognised on both sides. */
            'table'      => 'records',
            'column'     => 'local_exit_event_id',
            'definition' => 'VARCHAR(64) DEFAULT NULL',
            'after'      => 'local_event_id',
        ],
        [
            'table'      => 'records',
            'column'     => 'device_event_at',
            'definition' => 'DATETIME DEFAULT NULL',
            'after'      => 'local_event_id',
        ],
        [
            /* IMPORTANT ORDER: this entry must come AFTER device_event_at above,
               exactly like server_exit_received_at must come after
               server_received_at. On a fresh install the earlier column does
               not exist yet, and `AFTER <missing column>` fails. */
            'table'      => 'records',
            'column'     => 'device_exit_at',
            'definition' => 'DATETIME DEFAULT NULL',
            'after'      => 'device_event_at',
        ],
        [
            'table'      => 'records',
            'column'     => 'server_received_at',
            'definition' => 'DATETIME DEFAULT NULL',
            'after'      => 'device_exit_at',
        ],
        [
            /* The exit side of an OFFLINE session keeps its own device time and
               its own server receive time, so `device_event_at` stays the entry
               event and nothing is silently overwritten. */
            'table'      => 'records',
            'column'     => 'server_exit_received_at',
            'definition' => 'DATETIME DEFAULT NULL',
            'after'      => 'server_received_at',
        ],
        [
            'table'      => 'records',
            'column'     => 'recognition_uuid',
            'definition' => 'CHAR(36) DEFAULT NULL',
            'after'      => 'server_received_at',
        ],
        [
            'table'      => 'records',
            'column'     => 'shift_snapshot',
            'definition' => 'TEXT DEFAULT NULL',
            'after'      => 'recognition_uuid',
        ],
        [
            'table'      => 'records',
            'column'     => 'calc_version',
            'definition' => "VARCHAR(20) NOT NULL DEFAULT '6.0.0'",
            'after'      => 'shift_snapshot',
        ],
        [
            'table'      => 'records',
            'column'     => 'recalculated_at',
            'definition' => 'DATETIME DEFAULT NULL',
            'after'      => 'calc_version',
        ],
        [
            'table'      => 'records',
            'column'     => 'last_event',
            'definition' => 'VARCHAR(20) DEFAULT NULL',
            'after'      => 'recalculated_at',
        ],
        [
            'table'      => 'records',
            'column'     => 'modified_by',
            'definition' => 'BIGINT UNSIGNED DEFAULT NULL',
            'after'      => 'last_event',
        ],
        [
            'table'      => 'records',
            'column'     => 'modify_reason',
            'definition' => 'VARCHAR(255) DEFAULT NULL',
            'after'      => 'modified_by',
        ],
        [
            'table'      => 'records',
            'column'     => 'voided_at',
            'definition' => 'DATETIME DEFAULT NULL',
            'after'      => 'modify_reason',
        ],
        [
            'table'      => 'records',
            'column'     => 'voided_by',
            'definition' => 'BIGINT UNSIGNED DEFAULT NULL',
            'after'      => 'voided_at',
        ],
        [
            'table'      => 'records',
            'column'     => 'void_reason',
            'definition' => 'VARCHAR(255) DEFAULT NULL',
            'after'      => 'voided_by',
        ],
        [
            'table'      => 'records',
            'column'     => 'deleted_by',
            'definition' => 'BIGINT UNSIGNED DEFAULT NULL',
            'after'      => 'void_reason',
        ],
        [
            'table'      => 'records',
            'column'     => 'delete_reason',
            'definition' => 'VARCHAR(255) DEFAULT NULL',
            'after'      => 'deleted_by',
        ],

        /* ---- stage 5: face recognition ------------------------------- */
        [
            'table'      => 'customers',
            'column'     => 'face_data_version',
            'definition' => 'INT UNSIGNED NOT NULL DEFAULT 1',
            'after'      => 'locale',
        ],
        [
            'table'      => 'devices',
            'column'     => 'face_synced_version',
            'definition' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'after'      => 'last_seen_at',
        ],
        [
            'table'      => 'devices',
            'column'     => 'face_synced_at',
            'definition' => 'DATETIME DEFAULT NULL',
            'after'      => 'face_synced_version',
        ],
        [
            'table'      => 'employees',
            'column'     => 'face_samples_count',
            'definition' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'after'      => 'face_status',
        ],
        [
            'table'      => 'employees',
            'column'     => 'face_model_version',
            'definition' => 'VARCHAR(60) DEFAULT NULL',
            'after'      => 'face_samples_count',
        ],
    ];

    /**
     * Indexes that the owner panel relies on (filters + pagination).
     *
     * @var array<int,array{table:string,name:string,columns:string}>
     */
    private const INDEX_ADDITIONS = [
        /* Stage 6 - the attendance engine reads and writes these columns on
         * every request; none of them may stay unindexed. */
        ['table' => 'records', 'name' => 'idx_records_employee_date', 'columns' => '`customer_id`, `employee_id`, `attendance_date`'],
        ['table' => 'records', 'name' => 'uniq_records_device_exit_event', 'unique' => true, 'columns' => '`device_id`, `local_exit_event_id`'],
        ['table' => 'records', 'name' => 'idx_records_customer_date_status', 'columns' => '`customer_id`, `attendance_date`, `status`'],
        ['table' => 'records', 'name' => 'idx_records_customer_source', 'columns' => '`customer_id`, `source`, `attendance_date`'],
        ['table' => 'leaves', 'name' => 'idx_leaves_customer_range', 'columns' => '`customer_id`, `start_date`, `end_date`, `status`'],
        ['table' => 'events', 'name' => 'idx_events_status_available', 'columns' => '`status`, `available_at`'],
        ['table' => 'events', 'name' => 'idx_events_customer_event', 'columns' => '`customer_id`, `event`, `created_at`'],
        ['table' => 'records', 'name' => 'idx_records_date_status', 'columns' => '`customer_id`, `attendance_date`, `status`'],
        ['table' => 'records', 'name' => 'idx_records_open_lookup', 'columns' => '`customer_id`, `exit_at`'],
        ['table' => 'records', 'name' => 'idx_records_entry_at', 'columns' => '`customer_id`, `entry_at`'],
        ['table' => 'records', 'name' => 'idx_records_device_date', 'columns' => '`device_id`, `attendance_date`'],
        ['table' => 'records', 'name' => 'idx_records_branch_date', 'columns' => '`customer_id`, `branch_id`, `attendance_date`'],
        /* Concurrency + idempotency are enforced by the DATABASE, not only by
           a SELECT before the INSERT:
             open_key   - equals employee_id while the session is open (NULL
                          once closed, and MySQL/MariaDB allow many NULLs in a
                          unique index), so a second simultaneous ENTRY for the
                          same person cannot exist,
             idempotency- one attendance request key per customer,
             device+local_event_id - an offline batch replayed twice can never
                          create a second row. */
        ['table' => 'records', 'name' => 'uniq_records_open', 'columns' => '`open_key`', 'unique' => true],
        ['table' => 'records', 'name' => 'uniq_records_idempotency', 'columns' => '`customer_id`, `idempotency_key`', 'unique' => true],
        ['table' => 'records', 'name' => 'uniq_records_device_event', 'columns' => '`device_id`, `local_event_id`', 'unique' => true],
        ['table' => 'recognition_proofs', 'name' => 'idx_proofs_device_state', 'columns' => '`device_id`, `consumed_at`, `expires_at`'],
        ['table' => 'events', 'name' => 'idx_events_dispatch', 'columns' => '`status`, `available_at`'],
        ['table' => 'events', 'name' => 'idx_events_customer_event', 'columns' => '`customer_id`, `event`'],
        /* audit_logs already has idx_audit_action from stage 4. */
        ['table' => 'audit_logs', 'name' => 'idx_audit_action', 'columns' => '`action`'],
        ['table' => 'devices',    'name' => 'idx_devices_online', 'columns' => '`status`, `last_seen_at`'],
        ['table' => 'employees',  'name' => 'idx_employees_face', 'columns' => '`customer_id`, `face_status`'],
    ];

    /**
     * Schema evolution that is neither "add a column" nor "add an index":
     * a table rename, a column rename, a widened ENUM, a dropped constraint.
     *
     * Every step carries a `when` guard, so running the migration twice is a
     * no-op and a fresh installation simply walks the same path. Names in
     * `{braces}` are short table names - Database::table() adds the project
     * prefix and refuses anything outside attendance_*.
     *
     * @var array<int,array{key:string,sql:string,when:string}>
     */
    private const DDL_STEPS = [
        [
            'key'  => 'records.date-column',
            'sql'  => 'ALTER TABLE {records} CHANGE COLUMN `work_date` `attendance_date` DATE NOT NULL',
            'when' => 'column_exists:records.work_date',
        ],
        [
            // The old unique key (employee_id, work_date) is the only index
            // that satisfies the employee foreign key, so the constraint has
            // to be lifted before the index can go - and put back right after.
            'key'  => 'records.drop-employee-fk',
            'sql'  => 'ALTER TABLE {records} DROP FOREIGN KEY `fk_records_employee`',
            'when' => 'all_of:index_exists:records.uniq_record_day'
                . '&fk_exists:records.fk_records_employee',
        ],
        [
            // One employee may work several sessions in one day, so the old
            // "one record per employee per day" unique key has to go.
            'key'  => 'records.drop-daily-unique',
            'sql'  => 'ALTER TABLE {records} DROP INDEX `uniq_record_day`',
            'when' => 'index_exists:records.uniq_record_day',
        ],
        [
            'key'  => 'records.add-employee-fk',
            'sql'  => 'ALTER TABLE {records} ADD CONSTRAINT `fk_records_employee` '
                . 'FOREIGN KEY (`employee_id`) REFERENCES {employees} (`id`) ON DELETE CASCADE ON UPDATE CASCADE',
            'when' => 'fk_missing:records.fk_records_employee',
        ],
        [
            // 'leave' stays in the list as a legacy alias of 'on_leave' so rows
            // written before stage 6 keep working.
            'key'  => 'records.status-enum',
            'sql'  => "ALTER TABLE {records} MODIFY COLUMN `status` "
                . "ENUM('present','late','early_leave','overtime','absent','incomplete','leave','on_leave','holiday','manual') "
                . "NOT NULL DEFAULT 'present'",
            'when' => 'type_lacks:records.status:early_leave',
        ],
        [
            'key'  => 'records.source-enum',
            'sql'  => "ALTER TABLE {records} MODIFY COLUMN `source` "
                . "ENUM('device','device_offline','manual','import','api','system') NOT NULL DEFAULT 'device'",
            'when' => 'type_lacks:records.source:device_offline',
        ],
    ];

    /**
     * Generated (alias) columns whose DEFINITION must match the canonical one.
     * They are re-applied when an older installation created them with a
     * different expression. A generated column is always recomputed by the
     * server, so this can never lose data.
     *
     * @var array<int,array{table:string,column:string,definition:string}>
     */
    private const COLUMN_DEFINITION_FIXES = [
        [
            'table'      => 'audit_logs',
            'column'     => 'actor_user_id',
            'definition' => "INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN `actor_type` IN ('owner','manager','supervisor') THEN `actor_id` ELSE NULL END) VIRTUAL",
        ],
        [
            'table'      => 'audit_logs',
            'column'     => 'actor_role',
            'definition' => 'VARCHAR(30) GENERATED ALWAYS AS (`role`) VIRTUAL',
        ],
        [
            'table'      => 'audit_logs',
            'column'     => 'target_type',
            'definition' => 'VARCHAR(60) GENERATED ALWAYS AS (`entity_type`) VIRTUAL',
        ],
        [
            'table'      => 'audit_logs',
            'column'     => 'target_id',
            'definition' => 'BIGINT UNSIGNED GENERATED ALWAYS AS (`entity_id`) VIRTUAL',
        ],
    ];

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? Config::path(''), '/');
    }

    /* =================================================================
     * Public API
     * ===============================================================*/

    /**
     * Which migrations have not been applied yet?
     *
     * @return array<int,string>
     */
    public function pending(): array
    {
        $applied = $this->appliedMigrations();
        $pending = [];

        foreach (self::UPGRADE_FILES as $file) {
            $name = basename($file, '.sql');

            if (!in_array($name, $applied, true)) {
                $pending[] = $file;
            }
        }

        foreach (self::COLUMN_ADDITIONS as $addition) {
            if (!$this->columnExists($addition['table'], $addition['column'])) {
                $pending[] = 'column:' . $addition['table'] . '.' . $addition['column'];
            }
        }

        foreach (self::DDL_STEPS as $step) {
            if ($this->ddlStepApplies($step)) {
                $pending[] = 'ddl:' . $step['key'];
            }
        }

        foreach (self::INDEX_ADDITIONS as $index) {
            if (!$this->indexExists($index['table'], $index['name'])) {
                $pending[] = 'index:' . $index['name'];
            }
        }

        return $pending;
    }

    public function upToDate(): bool
    {
        return $this->pending() === [];
    }

    /**
     * Apply every pending migration. Safe to call repeatedly.
     *
     * @return array{ok:bool,files:array<int,string>,columns:array<int,string>,messages:array<int,string>}
     */
    public function run(): array
    {
        $messages = [];
        $files    = [];
        $columns  = [];

        // Zero kilometre database: create the base tables first, then evaluate
        // the changes. Every statement of the base file is idempotent, so this
        // is a no-op the moment the base schema exists.
        if (!$this->baseSchemaPresent()) {
            $base = $this->root . '/' . self::BASE_FILE;

            if (is_file($base)) {
                $count = $this->executeSqlFile($base);

                $files[]    = self::BASE_FILE;
                $messages[] = 'فایل ' . basename($base) . ' اجرا شد (' . $count . ' دستور).';
            }
        }

        foreach (self::UPGRADE_FILES as $file) {
            $path = $this->root . '/' . $file;

            if (!is_file($path)) {
                continue;
            }

            $name = basename($file, '.sql');

            if (in_array($name, $this->appliedMigrations(), true)) {
                continue;
            }

            $count = $this->executeSqlFile($path);

            $this->markApplied($name, 2);
            $files[]    = $file;
            $messages[] = 'فایل ' . basename($file) . ' اجرا شد (' . $count . ' دستور).';
        }

        foreach ($this->applyDdlSteps() as $message) {
            $messages[] = $message;
        }

        foreach (self::COLUMN_ADDITIONS as $addition) {
            if ($this->addColumnIfMissing($addition['table'], $addition['column'], $addition['definition'], $addition['after'])) {
                $columns[]  = $addition['table'] . '.' . $addition['column'];
                $messages[] = 'ستون ' . $addition['table'] . '.' . $addition['column'] . ' اضافه شد.';
            }
        }

        foreach (self::COLUMN_DEFINITION_FIXES as $fix) {
            if ($this->fixGeneratedColumn($fix['table'], $fix['column'], $fix['definition'])) {
                $messages[] = 'تعریف ستون ' . $fix['table'] . '.' . $fix['column'] . ' به‌روزرسانی شد.';
            }
        }

        foreach (self::INDEX_ADDITIONS as $index) {
            if ($this->addIndexIfMissing($index['table'], $index['name'], $index['columns'], (bool) ($index['unique'] ?? false))) {
                $messages[] = 'ایندکس ' . $index['name'] . ' اضافه شد.';
            }
        }

        if ($messages === []) {
            $messages[] = 'ساختار دیتابیس به‌روز است و نیازی به تغییر نداشت.';
        }

        Logger::channel('database')->info('Migrations applied', [
            'files'   => $files,
            'columns' => $columns,
        ]);

        return ['ok' => true, 'files' => $files, 'columns' => $columns, 'messages' => $messages];
    }

    /* =================================================================
     * SQL file execution
     * ===============================================================*/

    /**
     * Execute a .sql file statement by statement.
     */
    public function executeSqlFile(string $path): int
    {
        $sql = (string) file_get_contents($path);

        if (trim($sql) === '') {
            return 0;
        }

        $statements = $this->splitStatements($sql);
        $executed   = 0;

        foreach ($statements as $statement) {
            // SqlGuard::inspect() returns a list of violations (empty = safe).
            $violations = SqlGuard::inspect($statement);

            if ($violations !== []) {
                throw new RuntimeException(
                    'SqlGuard refused a statement from ' . basename($path) . ': ' . implode('; ', $violations)
                );
            }

            Database::pdo()->exec($statement);
            $executed++;
        }

        return $executed;
    }

    /**
     * Split a dump into single statements, tolerant of semicolons inside
     * strings and of "--" comments.
     *
     * @return array<int,string>
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $length     = strlen($sql);
        $inString   = false;
        $quote      = '';
        $inComment  = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inComment) {
                if ($char === "\n") {
                    $inComment = false;
                    $current  .= "\n";
                }

                continue;
            }

            if (!$inString && $char === '-' && $next === '-') {
                $inComment = true;
                $i++;
                continue;
            }

            if ($inString) {
                if ($char === '\\' && $quote === "'") {
                    $current .= $char . $next;
                    $i++;
                    continue;
                }

                if ($char === $quote) {
                    $inString = false;
                }

                $current .= $char;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $inString = true;
                $quote    = $char;
                $current .= $char;
                continue;
            }

            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }

                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /* =================================================================
     * Controlled DDL (own tables only)
     * ===============================================================*/

    public function addColumnIfMissing(string $shortTable, string $column, string $definition, string $after = ''): bool
    {
        // Database::table() throws for any foreign project prefix.
        $table = Database::table($shortTable);
        $column = Database::identifier($column);

        if ($this->columnExists($shortTable, $column)) {
            return false;
        }

        $sql = 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition;

        if ($after !== '') {
            $sql .= ' AFTER `' . Database::identifier($after) . '`';
        }

        Database::pdo()->exec($sql);

        Logger::channel('database')->info('Column added', ['table' => $table, 'column' => $column]);

        return true;
    }

    public function addIndexIfMissing(string $shortTable, string $indexName, string $columns, bool $unique = false): bool
    {
        $table = Database::table($shortTable);
        $index = Database::identifier($indexName);

        if ($this->indexExists($shortTable, $index)) {
            return false;
        }

        // $columns is a hard coded constant in this class (never user input).
        Database::pdo()->exec(
            'ALTER TABLE `' . $table . '` ADD ' . ($unique ? 'UNIQUE ' : '') . 'INDEX `' . $index . '` (' . $columns . ')'
        );

        return true;
    }

    /**
     * Re-apply the definition of a generated column when it drifted.
     *
     * @return bool true when the column was modified
     */
    private function fixGeneratedColumn(string $shortTable, string $column, string $definition): bool
    {
        $table = Database::table($shortTable);

        $current = Database::scalar(
            'SELECT `GENERATION_EXPRESSION` FROM information_schema.`COLUMNS`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = ?',
            [$table, $column]
        );

        // Missing column (created by addColumnIfMissing) or not generated.
        if ($current === null || trim((string) $current) === '') {
            return false;
        }

        // Expression out of "INT GENERATED ALWAYS AS (<expression>) VIRTUAL".
        if (preg_match('/AS\s*\((.*)\)\s*(VIRTUAL|STORED|PERSISTENT)?\s*$/is', $definition, $match) !== 1) {
            return false;
        }

        $normalize = static function (string $sql): string {
            return (string) preg_replace('/[`\s]+/', '', strtolower($sql));
        };

        if ($normalize($match[1]) === $normalize((string) $current)) {
            return false;
        }

        Database::pdo()->exec(
            'ALTER TABLE `' . $table . '` MODIFY COLUMN `' . Database::identifier($column) . '` ' . $definition
        );

        Logger::channel('database')->info('Generated column definition updated', [
            'table'  => $table,
            'column' => $column,
        ]);

        return true;
    }

    /* =================================================================
     * Guarded DDL steps
     * ===============================================================*/

    /**
     * Apply every DDL step whose guard still matches.
     *
     * @return array<int,string> human readable messages (Persian, shown in the
     *                           owner panel migration screen)
     */
    public function applyDdlSteps(): array
    {
        $messages = [];

        foreach (self::DDL_STEPS as $step) {
            if (!$this->ddlStepApplies($step)) {
                continue;
            }

            $sql = $this->resolveStepSql($step['sql']);

            Database::pdo()->exec($sql);

            Logger::channel('database')->info('Schema step applied', ['step' => $step['key']]);

            $messages[] = 'گام ساختاری «' . $step['key'] . '» اجرا شد.';
        }

        return $messages;
    }

    /**
     * Replace placeholders with real table names.
     *
     *   {name}    -> Database::table('name')   (prefix added only when missing)
     *   [[name]]  -> prefix + name            (ALWAYS prefixed - a rename
     *              target such as the new name of the records table already
     *              carries the prefix and would otherwise resolve to itself)
     */
    private function resolveStepSql(string $sql): string
    {
        $sql = (string) preg_replace_callback(
            '/\[\[([a-z0-9_]+)\]\]/',
            static fn (array $match): string => '`' . Database::prefix() . $match[1] . '`',
            $sql
        );

        return (string) preg_replace_callback(
            '/\{([a-z0-9_]+)\}/',
            static fn (array $match): string => '`' . Database::table($match[1]) . '`',
            $sql
        );
    }

    /** Does the guard of one step still match the live schema? */
    private function ddlStepApplies(array $step): bool
    {
        $when = (string) $step['when'];

        if (strpos($when, 'all_of:') === 0) {
            foreach (explode('&', substr($when, 7)) as $condition) {
                if (!$this->ddlStepApplies(['when' => trim($condition)])) {
                    return false;
                }
            }

            return true;
        }

        $parts = explode(':', $when, 3);

        if ($parts[0] === 'table_exists') {
            return $this->tableExists($parts[1] ?? '');
        }

        if ($parts[0] === 'column_exists') {
            [$table, $column] = array_pad(explode('.', $parts[1] ?? ''), 2, '');

            return $table !== '' && $column !== '' && $this->columnExists($table, $column);
        }

        if ($parts[0] === 'index_exists') {
            [$table, $index] = array_pad(explode('.', $parts[1] ?? ''), 2, '');

            return $table !== '' && $index !== '' && $this->indexExists($table, $index);
        }

        if ($parts[0] === 'fk_exists' || $parts[0] === 'fk_missing') {
            [$table, $name] = array_pad(explode('.', $parts[1] ?? ''), 2, '');

            if ($table === '' || $name === '') {
                return false;
            }

            $exists = $this->foreignKeyExists($table, $name);

            return $parts[0] === 'fk_exists' ? $exists : !$exists;
        }

        if ($parts[0] === 'type_lacks') {
            // type_lacks:<table>.<column>:<needle>
            [$table, $column] = array_pad(explode('.', $parts[1] ?? ''), 2, '');
            $needle = strtolower((string) ($parts[2] ?? ''));

            if ($table === '' || $column === '' || $needle === '') {
                return false;
            }

            // On a brand new database the table (or the column) may not exist
            // yet: the SQL file of this very run creates it. Reading the live
            // type would throw (42S02) and, worse, abort the whole migration
            // run before a single table was created. A step simply does not
            // apply until there is a column whose type can be read - by then
            // (run() applies the SQL files first) the guard decides for real.
            if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
                return false;
            }

            $type = strtolower($this->columnType($table, $column));

            return $type !== '' && strpos($type, $needle) === false;
        }

        return false;
    }

    /** Does a named foreign key exist on one of our tables? */
    public function foreignKeyExists(string $shortTable, string $name): bool
    {
        $row = Database::pdo()->prepare(
            'SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS'
            . ' WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            . " AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = ?"
        );
        $row->execute([Database::table($shortTable), $name]);

        return (int) ($row->fetch()['n'] ?? 0) > 0;
    }

    /**
     * The live column type (from SHOW COLUMNS), '' when the column is gone.
     *
     * SHOW COLUMNS does not accept a bound parameter, so the column name is
     * validated by Database::identifier() and inlined - both values are hard
     * coded constants of this class, and the identifier filter makes injection
     * impossible even if that ever changed.
     */
    public function columnType(string $shortTable, string $column): string
    {
        $sql = 'SHOW COLUMNS FROM `' . Database::table($shortTable) . '` LIKE '
            . Database::pdo()->quote(Database::identifier($column));

        $row = Database::pdo()->query($sql)->fetch();

        return is_array($row) ? (string) ($row['Type'] ?? '') : '';
    }

    public function columnExists(string $shortTable, string $column): bool
    {
        $table = Database::table($shortTable);

        $found = Database::scalar(
            'SELECT COUNT(*) FROM information_schema.`COLUMNS`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `COLUMN_NAME` = ?',
            [$table, $column]
        );

        return (int) $found > 0;
    }

    public function indexExists(string $shortTable, string $indexName): bool
    {
        $table = Database::table($shortTable);

        $found = Database::scalar(
            'SELECT COUNT(*) FROM information_schema.`STATISTICS`'
            . ' WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = ? AND `INDEX_NAME` = ?',
            [$table, $indexName]
        );

        return (int) $found > 0;
    }

    public function tableExists(string $shortTable): bool
    {
        return Database::tableExists($shortTable);
    }

    /** Is the base schema of a stage 1 installation already present? */
    private function baseSchemaPresent(): bool
    {
        foreach (self::BASE_TABLES as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    /* =================================================================
     * Bookkeeping
     * ===============================================================*/

    /**
     * @return array<int,string>
     */
    public function appliedMigrations(): array
    {
        if (!Database::tableExists('schema_migrations')) {
            return [];
        }

        $rows = Database::select('SELECT `migration` FROM `' . Database::table('schema_migrations') . '`');

        return array_map(static fn (array $row): string => (string) $row['migration'], $rows);
    }

    public function markApplied(string $migration, int $batch = 1): void
    {
        Database::run(
            'INSERT INTO `' . Database::table('schema_migrations') . '` (`migration`, `batch`) VALUES (?, ?)'
            . ' ON DUPLICATE KEY UPDATE `migration` = VALUES(`migration`)',
            [$migration, $batch]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function history(): array
    {
        if (!Database::tableExists('schema_migrations')) {
            return [];
        }

        return Database::select('SELECT * FROM `' . Database::table('schema_migrations') . '` ORDER BY `id` DESC LIMIT 50');
    }

    /**
     * Only for diagnostics: which project tables exist right now.
     *
     * @return array<int,string>
     */
    public function projectTables(): array
    {
        return Database::projectTables();
    }
}
