-- =============================================================================
-- SARMAD Attendance - stage 3 upgrade (MANAGER PANEL)
-- -----------------------------------------------------------------------------
-- Everything here is created under the attendance_ prefix only. The file is
-- idempotent (CREATE TABLE IF NOT EXISTS / INSERT ... ON DUPLICATE KEY UPDATE)
-- and is executed statement by statement through App\Core\SqlGuard, which
-- refuses anything that is not an attendance_* object.
--
-- Deliberately NOT part of this stage (structure only, no fake functionality):
--   * face recognition engine, camera capture, liveness detection
--     -> attendance_employee_faces stores the three samples as IMAGES only;
--        there is no liveness column because liveness is not used at all.
--   * device driven entry/exit engine
--     -> attendance_records is created with every column the
--        engine will need, and the manager panel reads it (real, empty until
--        the device stage writes into it).
--   * payroll calculation, telegram bot, final excel export
--     -> only profiles / configuration are stored.
-- =============================================================================

-- ---------------------------------------------------------------------
-- Shifts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_shifts` (
  `id`                        BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`                      CHAR(36)            NOT NULL,
  `customer_id`               BIGINT(20) UNSIGNED NOT NULL,
  `name`                      VARCHAR(120)        NOT NULL,
  `code`                      VARCHAR(40)         DEFAULT NULL,
  `start_time`                TIME                NOT NULL,
  `end_time`                  TIME                NOT NULL,
  `crosses_midnight`          TINYINT(1)          NOT NULL DEFAULT 0,
  `late_grace_minutes`        SMALLINT UNSIGNED   NOT NULL DEFAULT 10,
  `early_leave_grace_minutes` SMALLINT UNSIGNED   NOT NULL DEFAULT 10,
  `break_minutes`             SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
  `weekly_pattern`            VARCHAR(32)         NOT NULL DEFAULT 'all',
  `is_active`                 TINYINT(1)          NOT NULL DEFAULT 1,
  `sort_order`                SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
  `notes`                     VARCHAR(255)        DEFAULT NULL,
  `created_by`                BIGINT(20) UNSIGNED DEFAULT NULL,
  `created_at`                DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`                DATETIME            DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_shifts_uuid` (`uuid`),
  KEY `idx_shifts_customer` (`customer_id`, `is_active`),
  CONSTRAINT `fk_shifts_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Employee <-> shift (basic assignment now, weekly patterns later)
--   weekday: 0..6 = a single weekday, 127 = every day (current behaviour).
--   The column exists so "different shift per weekday" and "multiple shifts"
--   can be enabled later without a schema change.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_employee_shifts` (
  `id`             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`           CHAR(36)            NOT NULL,
  `customer_id`    BIGINT(20) UNSIGNED NOT NULL,
  `employee_id`    BIGINT(20) UNSIGNED NOT NULL,
  `shift_id`       BIGINT(20) UNSIGNED NOT NULL,
  `weekday`        SMALLINT UNSIGNED   NOT NULL DEFAULT 127,
  `effective_from` DATE                DEFAULT NULL,
  `effective_to`   DATE                DEFAULT NULL,
  `is_active`      TINYINT(1)          NOT NULL DEFAULT 1,
  `assigned_by`    BIGINT(20) UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_employee_shift` (`employee_id`, `shift_id`, `weekday`),
  KEY `idx_employee_shifts_scope` (`customer_id`, `is_active`),
  KEY `idx_employee_shifts_shift` (`shift_id`),
  CONSTRAINT `fk_empshift_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_empshift_employee` FOREIGN KEY (`employee_id`)
    REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_empshift_shift` FOREIGN KEY (`shift_id`)
    REFERENCES `attendance_shifts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Face samples - exactly three slots per employee (sample_index 1..3).
-- Image storage only: the recognition engine is a later stage, and liveness
-- detection is not used anywhere in this project.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_employee_faces` (
  `id`           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`         CHAR(36)            NOT NULL,
  `customer_id`  BIGINT(20) UNSIGNED NOT NULL,
  `employee_id`  BIGINT(20) UNSIGNED NOT NULL,
  `sample_index` TINYINT UNSIGNED    NOT NULL,
  `label`        VARCHAR(60)         DEFAULT NULL,
  `file_path`    VARCHAR(255)        NOT NULL,
  `mime_type`    VARCHAR(80)         DEFAULT NULL,
  `file_size`    INT UNSIGNED        NOT NULL DEFAULT 0,
  `image_hash`   CHAR(64)            DEFAULT NULL,
  `status`       ENUM('pending','enrolled','rejected') NOT NULL DEFAULT 'pending',
  `captured_at`  DATETIME            DEFAULT NULL,
  `created_by`   BIGINT(20) UNSIGNED DEFAULT NULL,
  `created_at`   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`   DATETIME            DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_face_sample` (`employee_id`, `sample_index`),
  KEY `idx_faces_scope` (`customer_id`, `status`),
  CONSTRAINT `fk_faces_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_faces_employee` FOREIGN KEY (`employee_id`)
    REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Attendance records - filled by the device engine in the next stage.
-- The manager panel already filters / paginates / reports on this table.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_records` (
  `id`                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`                CHAR(36)            NOT NULL,
  `customer_id`         BIGINT(20) UNSIGNED NOT NULL,
  `employee_id`         BIGINT(20) UNSIGNED NOT NULL,
  `branch_id`           BIGINT(20) UNSIGNED DEFAULT NULL,
  `device_id`           BIGINT(20) UNSIGNED DEFAULT NULL,
  `shift_id`            BIGINT(20) UNSIGNED DEFAULT NULL,
  `work_date`           DATE                NOT NULL,
  `entry_at`            DATETIME            DEFAULT NULL,
  `exit_at`             DATETIME            DEFAULT NULL,
  `work_minutes`        INT                 NOT NULL DEFAULT 0,
  `late_minutes`        INT                 NOT NULL DEFAULT 0,
  `early_leave_minutes` INT                 NOT NULL DEFAULT 0,
  `overtime_minutes`    INT                 NOT NULL DEFAULT 0,
  `status`              ENUM('present','absent','late','leave','holiday','incomplete','manual') NOT NULL DEFAULT 'present',
  `source`              ENUM('device','manual','import','api') NOT NULL DEFAULT 'device',
  `match_score`         DECIMAL(5,4)        DEFAULT NULL,
  `meta`                TEXT                DEFAULT NULL,
  `created_at`          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`          DATETIME            DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_record_day` (`employee_id`, `work_date`),
  KEY `idx_records_scope_date` (`customer_id`, `work_date`),
  KEY `idx_records_status` (`customer_id`, `status`),
  KEY `idx_records_branch` (`branch_id`, `work_date`),
  CONSTRAINT `fk_records_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_records_employee` FOREIGN KEY (`employee_id`)
    REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_records_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `attendance_branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_records_device` FOREIGN KEY (`device_id`)
    REFERENCES `attendance_devices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_records_shift` FOREIGN KEY (`shift_id`)
    REFERENCES `attendance_shifts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Leaves
--   leave_type is a plain VARCHAR on purpose: the current set is
--   annual / sick / unpaid / other, and Persian labels or new types can be
--   added without touching the schema.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_leaves` (
  `id`             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`           CHAR(36)            NOT NULL,
  `customer_id`    BIGINT(20) UNSIGNED NOT NULL,
  `employee_id`    BIGINT(20) UNSIGNED NOT NULL,
  `leave_type`     VARCHAR(30)         NOT NULL DEFAULT 'annual',
  `start_date`     DATE                NOT NULL,
  `end_date`       DATE                NOT NULL,
  `days`           DECIMAL(5,2)        NOT NULL DEFAULT 1.00,
  `description`    VARCHAR(500)        DEFAULT NULL,
  `status`         ENUM('pending','approved','rejected','canceled') NOT NULL DEFAULT 'pending',
  `decided_by`     BIGINT(20) UNSIGNED DEFAULT NULL,
  `decided_at`     DATETIME            DEFAULT NULL,
  `decision_note`  VARCHAR(255)        DEFAULT NULL,
  `created_by`     BIGINT(20) UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`     DATETIME            DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_leaves_uuid` (`uuid`),
  KEY `idx_leaves_scope` (`customer_id`, `status`),
  KEY `idx_leaves_employee` (`employee_id`, `start_date`),
  CONSTRAINT `fk_leaves_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_leaves_employee` FOREIGN KEY (`employee_id`)
    REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Holidays (per customer, optionally per branch)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_holidays` (
  `id`                  BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`                CHAR(36)            NOT NULL,
  `customer_id`         BIGINT(20) UNSIGNED NOT NULL,
  `branch_id`           BIGINT(20) UNSIGNED DEFAULT NULL,
  `title`               VARCHAR(150)        NOT NULL,
  `holiday_date`        DATE                NOT NULL,
  `description`         VARCHAR(255)        DEFAULT NULL,
  `is_recurring_yearly` TINYINT(1)          NOT NULL DEFAULT 0,
  `is_active`           TINYINT(1)          NOT NULL DEFAULT 1,
  `created_by`          BIGINT(20) UNSIGNED DEFAULT NULL,
  `created_at`          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`          DATETIME            DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_holidays_uuid` (`uuid`),
  KEY `idx_holidays_scope` (`customer_id`, `holiday_date`),
  KEY `idx_holidays_branch` (`branch_id`),
  CONSTRAINT `fk_holidays_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_holidays_branch` FOREIGN KEY (`branch_id`)
    REFERENCES `attendance_branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Payroll profiles - the calculation engine is a later stage; the money
-- columns the engine will need are already here.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_payroll_profiles` (
  `id`               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`             CHAR(36)            NOT NULL,
  `customer_id`      BIGINT(20) UNSIGNED NOT NULL,
  `employee_id`      BIGINT(20) UNSIGNED NOT NULL,
  `pay_type`         ENUM('monthly','daily','hourly') NOT NULL DEFAULT 'monthly',
  `base_amount`      DECIMAL(14,2)       NOT NULL DEFAULT 0.00,
  `hourly_rate`      DECIMAL(14,2)       NOT NULL DEFAULT 0.00,
  `overtime_rate`    DECIMAL(14,2)       NOT NULL DEFAULT 0.00,
  `deficit_rate`     DECIMAL(14,2)       NOT NULL DEFAULT 0.00,
  `penalty_amount`   DECIMAL(14,2)       NOT NULL DEFAULT 0.00,
  `benefits_amount`  DECIMAL(14,2)       NOT NULL DEFAULT 0.00,
  `currency`         VARCHAR(8)          NOT NULL DEFAULT 'IRT',
  `is_active`        TINYINT(1)          NOT NULL DEFAULT 1,
  `notes`            VARCHAR(255)        DEFAULT NULL,
  `created_by`       BIGINT(20) UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`       DATETIME            DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payroll_employee` (`employee_id`),
  KEY `idx_payroll_scope` (`customer_id`, `is_active`),
  CONSTRAINT `fk_payroll_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_payroll_employee` FOREIGN KEY (`employee_id`)
    REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Customer scoped settings written by the MANAGER panel
-- (attendance_settings stays the owner/global store; this table is the
--  per-customer store the manager may edit for his own customer only.)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_customer_settings` (
  `id`            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`   BIGINT(20) UNSIGNED NOT NULL,
  `setting_key`   VARCHAR(120)        NOT NULL,
  `setting_value` TEXT                DEFAULT NULL,
  `value_type`    VARCHAR(20)         NOT NULL DEFAULT 'string',
  `updated_by`    BIGINT(20) UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_setting` (`customer_id`, `setting_key`),
  CONSTRAINT `fk_customer_settings_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Marks the upgrade as applied (MigrationService also records it).
-- ---------------------------------------------------------------------
INSERT INTO `attendance_schema_migrations` (`migration`, `batch`)
VALUES ('attendance_upgrade_3.0.0', 3)
ON DUPLICATE KEY UPDATE `batch` = VALUES(`batch`);
