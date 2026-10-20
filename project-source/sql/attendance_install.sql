-- =====================================================================
-- SARMAD Attendance - database schema
-- File: sql/attendance_install.sql
-- =====================================================================
-- Target : MySQL 5.7+/MariaDB 10.3+  (DirectAdmin shared hosting)
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- Engine : InnoDB
--
-- IMPORTANT - SHARED DATABASE RULE
-- ---------------------------------------------------------------------
-- This database also hosts other SARMAD projects. This script ONLY
-- creates tables that start with the project prefix:  attendance_
--
-- It contains NO DROP / TRUNCATE / RENAME / ALTER of any foreign table,
-- never references the other projects (their prefixes are listed in
-- app/Core/Database.php as hard blocked) and every statement passes
-- app/Core/SqlGuard before execution.
--
-- All statements are idempotent (CREATE TABLE IF NOT EXISTS /
-- INSERT ... ON DUPLICATE KEY UPDATE) so the installer can be re-run
-- safely after a partial failure.
-- =====================================================================

SET NAMES utf8mb4;
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------
-- 1) Roles (fixed, predictable set - extensible in later stages)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_roles` (
  `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(50)  NOT NULL,
  `title_fa`      VARCHAR(80)  NOT NULL,
  `description`   VARCHAR(255) DEFAULT NULL,
  `level`         TINYINT UNSIGNED NOT NULL DEFAULT 10,
  `is_system`     TINYINT(1)   NOT NULL DEFAULT 0,
  `capabilities`  LONGTEXT     DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `attendance_roles` (`id`, `name`, `title_fa`, `description`, `level`, `is_system`, `capabilities`) VALUES
  (1, 'owner',   'مالک سامانه',        'دسترسی کامل به کل سامانه و همه مشتریان', 100, 1, '["platform.manage","customers.manage","users.manage","managers.manage","devices.manage","employees.view","settings.manage","audit.view","api.use"]'),
  (2, 'manager', 'مدیر مشتری',          'مدیریت کامل اطلاعات همان مشتری',          50,  1, '["employees.manage","employees.view","branches.manage","devices.manage","attendance.view","attendance.manage","reports.view","settings.customer","api.use"]'),
  (3, 'supervisor', 'سرپرست',           'مشاهده و مدیریت حضور و غیاب شعبه',        30,  1, '["employees.view","attendance.view","attendance.manage","reports.view"]'),
  (4, 'device',  'دستگاه حضور و غیاب',  'دسترسی محدود API برای دستگاه‌ها',          10,  1, '["attendance.punch","attendance.sync","device.heartbeat"]')
ON DUPLICATE KEY UPDATE `title_fa` = VALUES(`title_fa`), `description` = VALUES(`description`), `level` = VALUES(`level`), `capabilities` = VALUES(`capabilities`);

-- ---------------------------------------------------------------------
-- 2) Customers (tenants) - the root of every tenant boundary
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_customers` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`              CHAR(36)     NOT NULL,
  `name`              VARCHAR(150) NOT NULL,
  `slug`              VARCHAR(120) NOT NULL,
  `company_name`      VARCHAR(180) DEFAULT NULL,
  `contact_email`     VARCHAR(190) DEFAULT NULL,
  `contact_phone`     VARCHAR(25)  DEFAULT NULL,
  `address`           VARCHAR(255) DEFAULT NULL,
  `logo_path`         VARCHAR(255) DEFAULT NULL,
  `timezone`          VARCHAR(64)  NOT NULL DEFAULT 'Asia/Tehran',
  `locale`            VARCHAR(10)  NOT NULL DEFAULT 'fa',
  `date_system`       ENUM('jalali','gregorian') NOT NULL DEFAULT 'jalali',
  `subscription_plan` VARCHAR(30)  NOT NULL DEFAULT 'trial',
  `max_employees`     INT UNSIGNED NOT NULL DEFAULT 50,
  `max_devices`       INT UNSIGNED NOT NULL DEFAULT 5,
  `status`            ENUM('active','suspended','archived') NOT NULL DEFAULT 'active',
  `settings`          LONGTEXT     DEFAULT NULL,
  `trial_ends_at`     DATETIME     DEFAULT NULL,
  `created_by`        BIGINT UNSIGNED DEFAULT NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`        DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customers_uuid` (`uuid`),
  UNIQUE KEY `uniq_customers_slug` (`slug`),
  KEY `idx_customers_status` (`status`),
  KEY `idx_customers_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) Managers (customer side administration entity)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_managers` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`          CHAR(36)     NOT NULL,
  `customer_id`   BIGINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED DEFAULT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `title`         VARCHAR(120) DEFAULT NULL,
  `mobile`        VARCHAR(25)  DEFAULT NULL,
  `email`         VARCHAR(190) DEFAULT NULL,
  `is_primary`    TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`    BIGINT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_managers_uuid` (`uuid`),
  KEY `idx_managers_customer` (`customer_id`),
  KEY `idx_managers_user` (`user_id`),
  CONSTRAINT `fk_managers_customer` FOREIGN KEY (`customer_id`)
      REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4) Users (owner / manager / supervisor logins)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_users` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`                 CHAR(36)     NOT NULL,
  `role_id`              SMALLINT UNSIGNED NOT NULL DEFAULT 2,
  `role`                 VARCHAR(20)  NOT NULL DEFAULT 'manager',
  `customer_id`          BIGINT UNSIGNED DEFAULT NULL,
  `manager_id`           BIGINT UNSIGNED DEFAULT NULL,
  `name`                 VARCHAR(150) NOT NULL,
  `username`             VARCHAR(60)  NOT NULL,
  `email`                VARCHAR(190) DEFAULT NULL,
  `mobile`               VARCHAR(25)  DEFAULT NULL,
  `password_hash`        VARCHAR(255) NOT NULL,
  `must_change_password` TINYINT(1)   NOT NULL DEFAULT 0,
  `password_changed_at`  DATETIME     DEFAULT NULL,
  `last_login_at`        DATETIME     DEFAULT NULL,
  `last_login_ip`        VARCHAR(45)  DEFAULT NULL,
  `failed_attempts`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`         DATETIME     DEFAULT NULL,
  `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,
  `api_enabled`          TINYINT(1)   NOT NULL DEFAULT 0,
  `timezone`             VARCHAR(64)  DEFAULT NULL,
  `locale`               VARCHAR(10)  DEFAULT NULL,
  `created_by`           BIGINT UNSIGNED DEFAULT NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`           DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_uuid` (`uuid`),
  UNIQUE KEY `uniq_users_username` (`username`),
  UNIQUE KEY `uniq_users_email` (`email`),
  KEY `idx_users_customer` (`customer_id`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_manager` (`manager_id`),
  KEY `idx_users_active` (`is_active`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`)
      REFERENCES `attendance_roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_users_customer` FOREIGN KEY (`customer_id`)
      REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_users_manager` FOREIGN KEY (`manager_id`)
      REFERENCES `attendance_managers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5) Branches
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_branches` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`             CHAR(36)     NOT NULL,
  `customer_id`      BIGINT UNSIGNED NOT NULL,
  `manager_id`       BIGINT UNSIGNED DEFAULT NULL,
  `name`             VARCHAR(150) NOT NULL,
  `code`             VARCHAR(40)  NOT NULL,
  `address`          VARCHAR(255) DEFAULT NULL,
  `city`             VARCHAR(80)  DEFAULT NULL,
  `timezone`         VARCHAR(64)  NOT NULL DEFAULT 'Asia/Tehran',
  `geo_lat`          DECIMAL(10,7) DEFAULT NULL,
  `geo_lng`          DECIMAL(10,7) DEFAULT NULL,
  `geofence_radius`  INT UNSIGNED NOT NULL DEFAULT 150,
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`       BIGINT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`       DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_branches_uuid` (`uuid`),
  UNIQUE KEY `uniq_branches_customer_code` (`customer_id`, `code`),
  KEY `idx_branches_customer` (`customer_id`),
  KEY `idx_branches_manager` (`manager_id`),
  CONSTRAINT `fk_branches_customer` FOREIGN KEY (`customer_id`)
      REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_branches_manager` FOREIGN KEY (`manager_id`)
      REFERENCES `attendance_managers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6) Employees (identity only in this stage - face data comes later)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_employees` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`            CHAR(36)     NOT NULL,
  `customer_id`     BIGINT UNSIGNED NOT NULL,
  `branch_id`       BIGINT UNSIGNED DEFAULT NULL,
  `user_id`         BIGINT UNSIGNED DEFAULT NULL,
  `employee_code`   VARCHAR(40)  DEFAULT NULL,
  `first_name`      VARCHAR(80)  NOT NULL,
  `last_name`       VARCHAR(80)  NOT NULL,
  `national_id`     VARCHAR(20)  DEFAULT NULL,
  `mobile`          VARCHAR(25)  DEFAULT NULL,
  `email`           VARCHAR(190) DEFAULT NULL,
  `position`        VARCHAR(120) DEFAULT NULL,
  `department`      VARCHAR(120) DEFAULT NULL,
  `employment_type` VARCHAR(30)  NOT NULL DEFAULT 'full_time',
  `hire_date`       DATE         DEFAULT NULL,
  `status`          ENUM('active','suspended','terminated') NOT NULL DEFAULT 'active',
  `photo_path`      VARCHAR(255) DEFAULT NULL,
  `face_enrolled`   TINYINT(1)   NOT NULL DEFAULT 0,
  `meta`            LONGTEXT     DEFAULT NULL,
  `created_by`      BIGINT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`      DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_employees_uuid` (`uuid`),
  UNIQUE KEY `uniq_employees_customer_code` (`customer_id`, `employee_code`),
  KEY `idx_employees_customer` (`customer_id`),
  KEY `idx_employees_branch` (`branch_id`),
  KEY `idx_employees_status` (`status`),
  KEY `idx_employees_national` (`customer_id`, `national_id`),
  CONSTRAINT `fk_employees_customer` FOREIGN KEY (`customer_id`)
      REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_employees_branch` FOREIGN KEY (`branch_id`)
      REFERENCES `attendance_branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7) Devices (kiosk / PWA clients)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_devices` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`                  CHAR(36)     NOT NULL,
  `device_uid`            VARCHAR(64)  NOT NULL,
  `customer_id`           BIGINT UNSIGNED NOT NULL,
  `branch_id`             BIGINT UNSIGNED DEFAULT NULL,
  `manager_id`            BIGINT UNSIGNED DEFAULT NULL,
  `name`                  VARCHAR(120) NOT NULL,
  `type`                  ENUM('kiosk','tablet','mobile','desktop') NOT NULL DEFAULT 'kiosk',
  `platform`              VARCHAR(60)  DEFAULT NULL,
  `app_version`           VARCHAR(30)  DEFAULT NULL,
  `serial_number`         VARCHAR(80)  DEFAULT NULL,
  `token_prefix`          VARCHAR(16)  DEFAULT NULL,
  `api_token_hash`        CHAR(64)     DEFAULT NULL,
  `token_expires_at`      DATETIME     DEFAULT NULL,
  `token_rotated_at`      DATETIME     DEFAULT NULL,
  `pairing_code`          VARCHAR(20)  DEFAULT NULL,
  `pairing_expires_at`    DATETIME     DEFAULT NULL,
  `status`                ENUM('pending','active','disabled','revoked') NOT NULL DEFAULT 'pending',
  `capabilities`          LONGTEXT     DEFAULT NULL,
  `clock_offset_seconds`  INT          NOT NULL DEFAULT 0,
  `last_seen_at`          DATETIME     DEFAULT NULL,
  `last_seen_ip`          VARCHAR(45)  DEFAULT NULL,
  `last_sync_at`          DATETIME     DEFAULT NULL,
  `activated_at`          DATETIME     DEFAULT NULL,
  `registered_by`         BIGINT UNSIGNED DEFAULT NULL,
  `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`            DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_devices_uuid` (`uuid`),
  UNIQUE KEY `uniq_devices_uid` (`device_uid`),
  UNIQUE KEY `uniq_devices_token` (`api_token_hash`),
  KEY `idx_devices_customer` (`customer_id`),
  KEY `idx_devices_branch` (`branch_id`),
  KEY `idx_devices_status` (`status`),
  KEY `idx_devices_pairing` (`pairing_code`),
  KEY `idx_devices_last_seen` (`last_seen_at`),
  CONSTRAINT `fk_devices_customer` FOREIGN KEY (`customer_id`)
      REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_devices_branch` FOREIGN KEY (`branch_id`)
      REFERENCES `attendance_branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_devices_manager` FOREIGN KEY (`manager_id`)
      REFERENCES `attendance_managers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8) Device sessions (rotation / revocation history)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_device_sessions` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`         BIGINT UNSIGNED NOT NULL,
  `customer_id`       BIGINT UNSIGNED NOT NULL,
  `session_token_hash` CHAR(64) NOT NULL,
  `ip`                VARCHAR(45)  DEFAULT NULL,
  `user_agent`        VARCHAR(255) DEFAULT NULL,
  `started_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at`  DATETIME     DEFAULT NULL,
  `expires_at`        DATETIME     DEFAULT NULL,
  `revoked_at`        DATETIME     DEFAULT NULL,
  `revoke_reason`     VARCHAR(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device_sessions_token` (`session_token_hash`),
  KEY `idx_device_sessions_device` (`device_id`),
  KEY `idx_device_sessions_customer` (`customer_id`),
  CONSTRAINT `fk_device_sessions_device` FOREIGN KEY (`device_id`)
      REFERENCES `attendance_devices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9) Settings (global / customer / branch / device - hierarchical)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_settings` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope`         ENUM('global','customer','branch','device') NOT NULL DEFAULT 'global',
  `customer_id`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `branch_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `device_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` LONGTEXT     DEFAULT NULL,
  `setting_type`  ENUM('string','int','bool','json','text') NOT NULL DEFAULT 'string',
  `is_encrypted`  TINYINT(1)   NOT NULL DEFAULT 0,
  `updated_by`    BIGINT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_settings_scope` (`scope`, `customer_id`, `branch_id`, `device_id`, `setting_key`),
  KEY `idx_settings_customer` (`customer_id`),
  KEY `idx_settings_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `attendance_settings` (`scope`, `customer_id`, `branch_id`, `device_id`, `setting_key`, `setting_value`, `setting_type`) VALUES
  ('global', 0, 0, 0, 'app.version',              '1.0.0',      'string'),
  ('global', 0, 0, 0, 'app.timezone',             'Asia/Tehran','string'),
  ('global', 0, 0, 0, 'app.date_system',          'jalali',     'string'),
  ('global', 0, 0, 0, 'security.max_login_attempts','5',         'int'),
  ('global', 0, 0, 0, 'security.lockout_minutes',  '15',         'int'),
  ('global', 0, 0, 0, 'device.offline_after',      '300',        'int'),
  ('global', 0, 0, 0, 'attendance.allow_manual',   '0',          'bool'),
  ('global', 0, 0, 0, 'attendance.round_minutes',  '1',          'int'),
  ('global', 0, 0, 0, 'installer.locked',          '1',          'bool')
ON DUPLICATE KEY UPDATE `setting_type` = VALUES(`setting_type`);

-- ---------------------------------------------------------------------
-- 10) Audit log (tenant aware)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_audit_logs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel`        VARCHAR(30)  NOT NULL DEFAULT 'app',
  `event`          VARCHAR(60)  NOT NULL,
  `level`          VARCHAR(20)  NOT NULL DEFAULT 'info',
  `result`         ENUM('success','failure','denied','error') NOT NULL DEFAULT 'success',
  `actor_type`     ENUM('owner','manager','supervisor','device','system','guest') NOT NULL DEFAULT 'guest',
  `actor_id`       BIGINT UNSIGNED DEFAULT NULL,
  `actor_label`    VARCHAR(80)  DEFAULT NULL,
  `role`           VARCHAR(20)  DEFAULT NULL,
  `customer_id`    BIGINT UNSIGNED DEFAULT NULL,
  `entity_type`    VARCHAR(60)  DEFAULT NULL,
  `entity_id`      BIGINT UNSIGNED DEFAULT NULL,
  `description`    VARCHAR(500) DEFAULT NULL,
  `ip`             VARCHAR(45)  DEFAULT NULL,
  `user_agent`     VARCHAR(255) DEFAULT NULL,
  `request_method` VARCHAR(10)  DEFAULT NULL,
  `request_path`   VARCHAR(255) DEFAULT NULL,
  `meta`           LONGTEXT     DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_customer_time` (`customer_id`, `created_at`),
  KEY `idx_audit_actor` (`actor_type`, `actor_id`),
  KEY `idx_audit_event` (`event`),
  KEY `idx_audit_result` (`result`),
  KEY `idx_audit_ip` (`ip`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11) Login attempts (throttling source of truth)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_login_attempts` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier`  VARCHAR(190) NOT NULL,
  `user_id`     BIGINT UNSIGNED DEFAULT NULL,
  `customer_id` BIGINT UNSIGNED DEFAULT NULL,
  `ip`          VARCHAR(45)  NOT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `successful`  TINYINT(1)   NOT NULL DEFAULT 0,
  `reason`      VARCHAR(120) DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_identifier` (`identifier`, `created_at`),
  KEY `idx_login_ip` (`ip`, `created_at`),
  KEY `idx_login_success` (`successful`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12) API tokens (owner / manager integrations)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_api_tokens` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`         CHAR(36)     NOT NULL,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `customer_id`  BIGINT UNSIGNED DEFAULT NULL,
  `name`         VARCHAR(120) NOT NULL,
  `token_prefix` VARCHAR(16)  NOT NULL,
  `token_hash`   CHAR(64)     NOT NULL,
  `abilities`    LONGTEXT     DEFAULT NULL,
  `expires_at`   DATETIME     DEFAULT NULL,
  `last_used_at` DATETIME     DEFAULT NULL,
  `last_used_ip` VARCHAR(45)  DEFAULT NULL,
  `revoked_at`   DATETIME     DEFAULT NULL,
  `created_by`   BIGINT UNSIGNED DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_api_tokens_uuid` (`uuid`),
  UNIQUE KEY `uniq_api_tokens_hash` (`token_hash`),
  KEY `idx_api_tokens_user` (`user_id`),
  KEY `idx_api_tokens_customer` (`customer_id`),
  CONSTRAINT `fk_api_tokens_user` FOREIGN KEY (`user_id`)
      REFERENCES `attendance_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13) API request log (observability, purgeable)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_api_request_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id`  VARCHAR(32)  DEFAULT NULL,
  `customer_id` BIGINT UNSIGNED DEFAULT NULL,
  `user_id`     BIGINT UNSIGNED DEFAULT NULL,
  `device_id`   BIGINT UNSIGNED DEFAULT NULL,
  `method`      VARCHAR(10)  NOT NULL,
  `path`        VARCHAR(255) NOT NULL,
  `status`      SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  `duration_ms` INT UNSIGNED DEFAULT NULL,
  `ip`          VARCHAR(45)  DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_logs_customer` (`customer_id`, `created_at`),
  KEY `idx_api_logs_device` (`device_id`),
  KEY `idx_api_logs_path` (`path`),
  KEY `idx_api_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14) Password resets
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_password_resets` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64)     NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     DEFAULT NULL,
  `ip`         VARCHAR(45)  DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_password_resets_token` (`token_hash`),
  KEY `idx_password_resets_user` (`user_id`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`)
      REFERENCES `attendance_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15) Schema migrations (tracking only - never touches foreign tables)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_schema_migrations` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration`  VARCHAR(150) NOT NULL,
  `batch`      INT UNSIGNED NOT NULL DEFAULT 1,
  `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_migrations_name` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `attendance_schema_migrations` (`migration`, `batch`) VALUES ('2026_01_01_000000_initial_schema', 1)
ON DUPLICATE KEY UPDATE `migration` = VALUES(`migration`);
