-- =====================================================================
-- SARMAD Attendance - stage 2 upgrade (Owner panel)
-- File: sql/attendance_upgrade_2.0.0.sql
-- =====================================================================
-- Target : MySQL 5.7+ / MariaDB 10.3+  (DirectAdmin shared hosting)
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- Engine : InnoDB
--
-- SHARED DATABASE RULE
-- ---------------------------------------------------------------------
-- This file ONLY creates / touches tables that start with  attendance_
-- It contains NO DROP / TRUNCATE / RENAME and never references another
-- project (barber_*, cafe_*, clinic_*, zibaei_*, sarmadpf_*).
--
-- Every statement is idempotent, so this file can be executed on top of
-- an existing stage 1 installation as many times as needed.
--
-- Column level additions (ALTER TABLE) are deliberately NOT in this file
-- because "ADD COLUMN IF NOT EXISTS" is MariaDB only. They are applied in
-- PHP by App\Services\MigrationService::addColumnIfMissing() which checks
-- information_schema first and refuses any table outside attendance_*.
-- =====================================================================

SET NAMES utf8mb4;
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ---------------------------------------------------------------------
-- 16) Feature catalogue (what the platform is able to switch on/off)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_features` (
  `id`              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `feature_key`     VARCHAR(50)  NOT NULL,
  `title_fa`        VARCHAR(120) NOT NULL,
  `description`     VARCHAR(255) DEFAULT NULL,
  `category`        ENUM('basic','optional') NOT NULL DEFAULT 'basic',
  `is_core`         TINYINT(1)   NOT NULL DEFAULT 0,
  `default_enabled` TINYINT(1)   NOT NULL DEFAULT 0,
  `module_ready`    TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`      SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_features_key` (`feature_key`),
  KEY `idx_features_category` (`category`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `attendance_features`
  (`feature_key`, `title_fa`, `description`, `category`, `is_core`, `default_enabled`, `module_ready`, `sort_order`) VALUES
  ('employees',        'مدیریت پرسنل',        'ثبت و نگهداری اطلاعات کارکنان مشتری',                'basic',    1, 1, 1, 10),
  ('attendance',       'حضور و غیاب',         'ثبت تردد کارکنان (ورود و خروج)',                     'basic',    1, 1, 0, 20),
  ('shifts',           'شیفت‌ها',             'تعریف شیفت کاری و برنامه هفتگی',                     'basic',    1, 0, 0, 30),
  ('reports',          'گزارش‌ها',            'گزارش‌های پایه حضور و غیاب',                         'basic',    1, 1, 1, 40),
  ('excel_reports',    'خروجی اکسل',          'دریافت خروجی Excel از گزارش‌ها',                     'basic',    1, 0, 0, 50),
  ('branches',         'شعب و واحدها',        'تعریف چند شعبه برای یک مشتری',                      'optional', 0, 0, 1, 60),
  ('payroll',          'حقوق و دستمزد',       'محاسبه کارکرد و حقوق',                              'optional', 0, 0, 0, 70),
  ('telegram',         'ربات تلگرام',         'اعلان‌ها و گزارش‌ها از طریق تلگرام',                 'optional', 0, 0, 0, 80),
  ('advanced_reports', 'گزارش‌های پیشرفته',   'تحلیل‌های پیشرفته و گزارش‌های سفارشی',               'optional', 0, 0, 0, 90)
ON DUPLICATE KEY UPDATE
  `title_fa`     = VALUES(`title_fa`),
  `description`  = VALUES(`description`),
  `category`     = VALUES(`category`),
  `is_core`      = VALUES(`is_core`),
  `default_enabled` = VALUES(`default_enabled`),
  `module_ready` = VALUES(`module_ready`),
  `sort_order`   = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- 17) Feature flags - one row per customer + feature
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_feature_flags` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` BIGINT UNSIGNED NOT NULL,
  `feature_key` VARCHAR(50)  NOT NULL,
  `is_enabled`  TINYINT(1)   NOT NULL DEFAULT 0,
  `enabled_at`  DATETIME     DEFAULT NULL,
  `disabled_at` DATETIME     DEFAULT NULL,
  `changed_by`  BIGINT UNSIGNED DEFAULT NULL,
  `notes`       VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_feature_flags_customer_key` (`customer_id`, `feature_key`),
  KEY `idx_feature_flags_key` (`feature_key`),
  KEY `idx_feature_flags_enabled` (`is_enabled`),
  CONSTRAINT `fk_feature_flags_customer` FOREIGN KEY (`customer_id`)
      REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18) Subscriptions (current plan + history of every change)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_subscriptions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`          CHAR(36)     NOT NULL,
  `customer_id`   BIGINT UNSIGNED NOT NULL,
  `plan_name`     VARCHAR(40)  NOT NULL DEFAULT 'trial',
  `status`        ENUM('trial','active','expired','suspended','canceled') NOT NULL DEFAULT 'trial',
  `start_date`    DATE         DEFAULT NULL,
  `expire_date`   DATE         DEFAULT NULL,
  `price`         DECIMAL(12,0) DEFAULT NULL,
  `currency`      CHAR(3)      NOT NULL DEFAULT 'IRT',
  `max_employees` INT UNSIGNED NOT NULL DEFAULT 50,
  `max_devices`   INT UNSIGNED NOT NULL DEFAULT 5,
  `notes`         VARCHAR(255) DEFAULT NULL,
  `created_by`    BIGINT UNSIGNED DEFAULT NULL,
  `is_current`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_subscriptions_uuid` (`uuid`),
  KEY `idx_subscriptions_customer` (`customer_id`, `is_current`),
  KEY `idx_subscriptions_status` (`status`),
  KEY `idx_subscriptions_expire` (`expire_date`),
  CONSTRAINT `fk_subscriptions_customer` FOREIGN KEY (`customer_id`)
      REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 19) Emergency access (owner entering a customer manager panel)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_owner_impersonations` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`     BIGINT UNSIGNED NOT NULL,
  `owner_user_id`   BIGINT UNSIGNED NOT NULL,
  `manager_user_id` BIGINT UNSIGNED DEFAULT NULL,
  `reason`          VARCHAR(255) NOT NULL,
  `ip`              VARCHAR(45)  DEFAULT NULL,
  `user_agent`      VARCHAR(255) DEFAULT NULL,
  `session_id`      VARCHAR(128) DEFAULT NULL,
  `actions_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `started_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_action_at`  DATETIME     DEFAULT NULL,
  `ended_at`        DATETIME     DEFAULT NULL,
  `ended_reason`    VARCHAR(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_impersonations_customer` (`customer_id`, `started_at`),
  KEY `idx_impersonations_owner` (`owner_user_id`, `started_at`),
  KEY `idx_impersonations_open` (`ended_at`),
  CONSTRAINT `fk_impersonations_customer` FOREIGN KEY (`customer_id`)
      REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 20) Global settings added in stage 2 (owner settings page)
-- ---------------------------------------------------------------------
INSERT INTO `attendance_settings`
  (`scope`, `customer_id`, `branch_id`, `device_id`, `setting_key`, `setting_value`, `setting_type`) VALUES
  ('global', 0, 0, 0, 'platform.name',                 'SARMAD Attendance', 'string'),
  ('global', 0, 0, 0, 'platform.support_email',        '',                  'string'),
  ('global', 0, 0, 0, 'platform.support_phone',        '',                  'string'),
  ('global', 0, 0, 0, 'platform.default_plan',         'trial',             'string'),
  ('global', 0, 0, 0, 'platform.default_trial_days',   '14',                'int'),
  ('global', 0, 0, 0, 'security.password_min_length',  '10',                'int'),
  ('global', 0, 0, 0, 'security.audit_retention_days', '365',               'int'),
  ('global', 0, 0, 0, 'owner.impersonation_enabled',   '1',                 'bool')
ON DUPLICATE KEY UPDATE `setting_type` = VALUES(`setting_type`);

-- ---------------------------------------------------------------------
-- 21) Migration bookkeeping
-- ---------------------------------------------------------------------
INSERT INTO `attendance_schema_migrations` (`migration`, `batch`) VALUES ('2026_02_01_000000_owner_panel', 2)
ON DUPLICATE KEY UPDATE `migration` = VALUES(`migration`);
