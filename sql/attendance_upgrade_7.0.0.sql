-- =====================================================================
-- SARMAD Attendance - stage 7 upgrade (Reports + Excel + Payroll + Telegram)
-- File: sql/attendance_upgrade_7.0.0.sql
-- =====================================================================
-- Target : MySQL 5.7+ / MariaDB 10.3+  (DirectAdmin shared hosting)
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- Engine : InnoDB
--
-- SHARED DATABASE RULE
-- ---------------------------------------------------------------------
-- This file ONLY creates tables that start with  attendance_
-- It contains NO DROP / TRUNCATE / RENAME and never references another
-- project (barber_*, cafe_*, clinic_*, zibaei_*, sarmadpf_*).
-- Every statement is idempotent, so a re-run is a no-op.
--
-- Column level additions (ALTER TABLE) are deliberately NOT in this file
-- because "ADD COLUMN IF NOT EXISTS" is MariaDB only. They are applied in
-- PHP by App\Services\MigrationService::addColumnIfMissing() which checks
-- information_schema first and refuses any table outside attendance_*.
--
-- attendance_payroll_profiles already exists (stage 2). Stage 7 extends it
-- with the fields the payroll engine needs instead of creating a second,
-- competing table.
-- =====================================================================

SET NAMES utf8mb4;
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------
-- 1) Payroll periods: one payroll run for a date range of one customer.
--
--    A period is either OPEN (calculations are a live preview and can be
--    recalculated as often as the manager wants) or FINALIZED (the item
--    amounts are frozen; later changes are written as revisions and the
--    frozen snapshot never changes silently).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_payroll_periods` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `customer_id`   BIGINT UNSIGNED NOT NULL,
    `title`         VARCHAR(120) NOT NULL,
    `start_date`    DATE NOT NULL,
    `end_date`      DATE NOT NULL,
    `status`        ENUM('open','finalized','cancelled') NOT NULL DEFAULT 'open',
    `currency`      VARCHAR(8) NOT NULL DEFAULT 'IRR',
    `employee_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `total_gross`   DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `total_net`     DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `total_overtime_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `total_late_minutes`     INT UNSIGNED NOT NULL DEFAULT 0,
    `total_absence_days`     INT UNSIGNED NOT NULL DEFAULT 0,
    `notes`         VARCHAR(500) DEFAULT NULL,
    `created_by`    BIGINT UNSIGNED DEFAULT NULL,
    `finalized_by`  BIGINT UNSIGNED DEFAULT NULL,
    `finalized_at`  DATETIME DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`    DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_payroll_period_uuid` (`uuid`),
    UNIQUE KEY `uniq_payroll_period_range` (`customer_id`, `start_date`, `end_date`),
    KEY `idx_payroll_period_status` (`customer_id`, `status`, `start_date`),
    CONSTRAINT `fk_payroll_period_customer` FOREIGN KEY (`customer_id`) REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) Payroll items: one frozen row per employee and period.
--
--    Every input of the calculation is stored next to its result, so a
--    payslip can always be explained later (audit) even if the employee's
--    rate, shift or attendance row changes afterwards.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_payroll_items` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `customer_id`   BIGINT UNSIGNED NOT NULL,
    `period_id`     BIGINT UNSIGNED NOT NULL,
    `employee_id`   BIGINT UNSIGNED NOT NULL,
    `pay_type`      ENUM('monthly','daily','hourly') NOT NULL DEFAULT 'monthly',
    `currency`      VARCHAR(8) NOT NULL DEFAULT 'IRR',
    `work_days`             SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `present_days`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `absence_days`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `leave_days`            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `holiday_days`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `work_minutes`          INT UNSIGNED NOT NULL DEFAULT 0,
    `overtime_minutes`      INT UNSIGNED NOT NULL DEFAULT 0,
    `late_minutes`          INT UNSIGNED NOT NULL DEFAULT 0,
    `early_leave_minutes`   INT UNSIGNED NOT NULL DEFAULT 0,
    `base_amount`           DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `hourly_rate`           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `overtime_rate`         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `overtime_amount`       DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `late_rate`             DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `late_deduction`        DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `early_leave_rate`      DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    `early_leave_deduction` DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `absence_rate`          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `absence_deduction`     DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `benefits`              DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `fixed_deductions`      DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `other_deductions`      DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `gross_amount`          DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `net_amount`            DECIMAL(16,2) NOT NULL DEFAULT 0.00,
    `calc_version`          VARCHAR(20) NOT NULL DEFAULT '7.0.0',
    `inputs`                TEXT DEFAULT NULL,
    `calc_at`               DATETIME NOT NULL,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_payroll_item_uuid` (`uuid`),
    UNIQUE KEY `uniq_payroll_item_scope` (`period_id`, `employee_id`),
    KEY `idx_payroll_item_customer` (`customer_id`, `employee_id`),
    CONSTRAINT `fk_payroll_item_customer` FOREIGN KEY (`customer_id`) REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_payroll_item_period`   FOREIGN KEY (`period_id`)   REFERENCES `attendance_payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_payroll_item_employee` FOREIGN KEY (`employee_id`) REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) Payroll revisions: every recalculation of a FINALIZED period.
--
--    Nothing is overwritten in place: the previous amounts are archived
--    here with old value, new value, reason and the acting user.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_payroll_revisions` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `customer_id`   BIGINT UNSIGNED NOT NULL,
    `period_id`     BIGINT UNSIGNED NOT NULL,
    `item_id`       BIGINT UNSIGNED DEFAULT NULL,
    `employee_id`   BIGINT UNSIGNED DEFAULT NULL,
    `action`        VARCHAR(60) NOT NULL,
    `old_values`    TEXT DEFAULT NULL,
    `new_values`    TEXT DEFAULT NULL,
    `reason`        VARCHAR(500) DEFAULT NULL,
    `actor_id`      BIGINT UNSIGNED DEFAULT NULL,
    `actor_label`   VARCHAR(120) DEFAULT NULL,
    `ip`            VARCHAR(45) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_payroll_revision_uuid` (`uuid`),
    KEY `idx_payroll_revision_period` (`period_id`, `created_at`),
    KEY `idx_payroll_revision_employee` (`employee_id`, `created_at`),
    CONSTRAINT `fk_payroll_revision_customer` FOREIGN KEY (`customer_id`) REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_payroll_revision_period`   FOREIGN KEY (`period_id`)   REFERENCES `attendance_payroll_periods` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4) Telegram settings: one row per customer.
--
--    The bot token is stored as a ciphertext produced by the application
--    (never plain, never in a log). `bot_token_hint` keeps only the last 4
--    characters so the panel can show ************1234 without ever
--    reading the secret back to the browser.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_telegram_settings` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36) NOT NULL,
    `customer_id`       BIGINT UNSIGNED NOT NULL,
    `bot_token_cipher`  TEXT DEFAULT NULL,
    `bot_token_hint`    VARCHAR(12) DEFAULT NULL,
    `bot_username`      VARCHAR(120) DEFAULT NULL,
    `chat_id`           VARCHAR(64) DEFAULT NULL,
    `notify_entry`      TINYINT(1) NOT NULL DEFAULT 1,
    `notify_exit`       TINYINT(1) NOT NULL DEFAULT 1,
    `notify_late`       TINYINT(1) NOT NULL DEFAULT 0,
    `notify_overtime`   TINYINT(1) NOT NULL DEFAULT 0,
    `daily_report`      TINYINT(1) NOT NULL DEFAULT 0,
    `daily_report_time` CHAR(5) NOT NULL DEFAULT '23:55',
    `is_active`         TINYINT(1) NOT NULL DEFAULT 0,
    `last_ok_at`        DATETIME DEFAULT NULL,
    `last_error`        VARCHAR(255) DEFAULT NULL,
    `last_error_at`     DATETIME DEFAULT NULL,
    `created_by`        BIGINT UNSIGNED DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_telegram_settings_uuid` (`uuid`),
    UNIQUE KEY `uniq_telegram_settings_customer` (`customer_id`),
    CONSTRAINT `fk_telegram_settings_customer` FOREIGN KEY (`customer_id`) REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5) Telegram queue: the outbox.
--
--    Attendance NEVER waits for Telegram. A notification is written here
--    in its own row and drained by the worker; if Telegram is down the
--    attendance record stays and the row is retried with backoff until
--    max_attempts, then it becomes FAILED (visible in the panel).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_telegram_queue` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `customer_id`   BIGINT UNSIGNED NOT NULL,
    `event`         VARCHAR(60) NOT NULL,
    `dedupe_key`    VARCHAR(120) NOT NULL,
    `chat_id`       VARCHAR(64) DEFAULT NULL,
    `payload`       TEXT DEFAULT NULL,
    `status`        ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `available_at`  DATETIME NOT NULL,
    `sent_at`       DATETIME DEFAULT NULL,
    `message_id`    VARCHAR(64) DEFAULT NULL,
    `last_error`    VARCHAR(255) DEFAULT NULL,
    `error_code`    VARCHAR(60) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_telegram_queue_uuid` (`uuid`),
    UNIQUE KEY `uniq_telegram_queue_dedupe` (`customer_id`, `dedupe_key`),
    KEY `idx_telegram_queue_drain` (`status`, `available_at`),
    KEY `idx_telegram_queue_customer` (`customer_id`, `created_at`),
    CONSTRAINT `fk_telegram_queue_customer` FOREIGN KEY (`customer_id`) REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6) Telegram logs: what was actually sent, and what failed.
--
--    The bot token is NEVER written here (not in `error_message`, not in
--    any payload) - the service redacts it before it reaches this table.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_telegram_logs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `customer_id`   BIGINT UNSIGNED NOT NULL,
    `event`         VARCHAR(60) NOT NULL,
    `chat_id`       VARCHAR(64) DEFAULT NULL,
    `status`        ENUM('sent','failed','skipped') NOT NULL,
    `message_id`    VARCHAR(64) DEFAULT NULL,
    `error_code`    VARCHAR(60) DEFAULT NULL,
    `error_message` VARCHAR(255) DEFAULT NULL,
    `queue_id`      BIGINT UNSIGNED DEFAULT NULL,
    `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `sent_at`       DATETIME DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_telegram_log_uuid` (`uuid`),
    KEY `idx_telegram_log_customer` (`customer_id`, `created_at`),
    KEY `idx_telegram_log_status` (`status`, `created_at`),
    CONSTRAINT `fk_telegram_log_customer` FOREIGN KEY (`customer_id`) REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7) Telegram daily reports: the idempotency ledger of the scheduler.
--
--    The cron may run every minute; the unique key (customer, date, type)
--    makes sure the 23:55 report is sent exactly once per day and that a
--    second cron run can never duplicate it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_telegram_reports` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `customer_id`   BIGINT UNSIGNED NOT NULL,
    `report_date`   DATE NOT NULL,
    `report_type`   VARCHAR(40) NOT NULL DEFAULT 'daily',
    `status`        ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    `payload`       TEXT DEFAULT NULL,
    `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `sent_at`       DATETIME DEFAULT NULL,
    `message_id`    VARCHAR(64) DEFAULT NULL,
    `last_error`    VARCHAR(255) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_telegram_report_uuid` (`uuid`),
    UNIQUE KEY `uniq_telegram_report_day` (`customer_id`, `report_date`, `report_type`),
    KEY `idx_telegram_report_status` (`status`, `report_date`),
    CONSTRAINT `fk_telegram_report_customer` FOREIGN KEY (`customer_id`) REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
