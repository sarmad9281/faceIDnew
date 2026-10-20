-- =============================================================================
-- SARMAD Attendance - stage 4 upgrade (ATTENDANCE DEVICE / PWA / KIOSK)
-- -----------------------------------------------------------------------------
-- Everything here is created under the attendance_ prefix only. The file is
-- idempotent (CREATE TABLE IF NOT EXISTS) and is executed statement by
-- statement through App\Core\SqlGuard, which refuses anything that is not an
-- attendance_* object.
--
-- Stage 1 already created:
--   * attendance_devices          (device identity, status, token hash, pairing)
--   * attendance_device_sessions  (short lived device sessions)
--   * attendance_api_request_logs (API request log)
-- so this file only ADDS what the kiosk needs:
--   1. attendance_device_activation_codes - one-time, time limited, HASHED
--      activation codes (the plain code is never stored, only shown once).
--   2. attendance_device_settings         - non sensitive, device local
--      preferences (success sound, message duration, clock, camera test).
--   3. attendance_device_logs             - device event log (activation,
--      auth, heartbeat, token reset, connection/recognition errors).
--   4. attendance_device_requests         - idempotency keys so a double tap /
--      retried request can never be processed twice (double submit guard).
--
-- Deliberately NOT part of this stage (structure only, no fake functionality):
--   * face recognition engine            -> /api/v1/device/recognition exists as
--     a validated contract and answers FACE_ENGINE_UNAVAILABLE until stage 5.
--   * attendance calculation (late / overtime / work minutes)
--     -> /api/v1/device/attendance validates and rejects with
--        ATTENDANCE_ENGINE_NOT_READY until the attendance engine stage.
--   * liveness detection                 -> not used anywhere, no column.
-- =============================================================================

-- ---------------------------------------------------------------------
-- 1) One-time activation codes (hashed, time limited, attempt limited)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_device_activation_codes` (
  `id`           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`         CHAR(36)            NOT NULL,
  `device_id`    BIGINT(20) UNSIGNED NOT NULL,
  `customer_id`  BIGINT(20) UNSIGNED NOT NULL,
  `code_hash`    CHAR(64)            NOT NULL,
  `code_prefix`  CHAR(4)             NOT NULL,
  `attempts`     SMALLINT UNSIGNED   NOT NULL DEFAULT 0,
  `max_attempts` SMALLINT UNSIGNED   NOT NULL DEFAULT 10,
  `expires_at`   DATETIME            NOT NULL,
  `used_at`      DATETIME            DEFAULT NULL,
  `used_ip`      VARCHAR(45)         DEFAULT NULL,
  `revoked_at`   DATETIME            DEFAULT NULL,
  `created_by`   BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device_activation_uuid` (`uuid`),
  UNIQUE KEY `uniq_device_activation_hash` (`code_hash`),
  KEY `idx_device_activation_device` (`device_id`, `used_at`, `expires_at`),
  KEY `idx_device_activation_customer` (`customer_id`),
  CONSTRAINT `fk_device_activation_device` FOREIGN KEY (`device_id`)
    REFERENCES `attendance_devices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_device_activation_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) Per device, non sensitive settings (device local preferences)
--    Security relevant switches stay with the OWNER panel; nothing here
--    can enable/disable attendance, change the branch or the token.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_device_settings` (
  `id`            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`     BIGINT(20) UNSIGNED NOT NULL,
  `customer_id`   BIGINT(20) UNSIGNED NOT NULL,
  `setting_key`   VARCHAR(60)         NOT NULL,
  `setting_value` VARCHAR(190)        NOT NULL DEFAULT '',
  `updated_by`    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `created_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device_setting` (`device_id`, `setting_key`),
  KEY `idx_device_settings_customer` (`customer_id`),
  CONSTRAINT `fk_device_settings_device` FOREIGN KEY (`device_id`)
    REFERENCES `attendance_devices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_device_settings_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) Device event log (activation, auth, heartbeat, reset, errors)
--    device_id is nullable so a failed activation attempt (unknown code)
--    can be logged without inventing a device.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_device_logs` (
  `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`   BIGINT(20) UNSIGNED DEFAULT NULL,
  `customer_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  `event`       VARCHAR(60)         NOT NULL,
  `level`       ENUM('info','warning','error') NOT NULL DEFAULT 'info',
  `message`     VARCHAR(255)        NOT NULL DEFAULT '',
  `meta`        LONGTEXT            DEFAULT NULL,
  `ip`          VARCHAR(45)         DEFAULT NULL,
  `user_agent`  VARCHAR(255)        DEFAULT NULL,
  `created_at`  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_device_logs_device` (`device_id`, `id`),
  KEY `idx_device_logs_customer` (`customer_id`, `id`),
  KEY `idx_device_logs_event` (`event`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4) Idempotency keys (double submit guard + abuse tracking)
--    UNIQUE (device_id, request_key) is what physically stops a duplicate
--    request: the engine endpoint inserts first and acts second.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_device_requests` (
  `id`          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`   BIGINT(20) UNSIGNED NOT NULL,
  `customer_id` BIGINT(20) UNSIGNED NOT NULL,
  `endpoint`    VARCHAR(60)         NOT NULL,
  `request_key` VARCHAR(64)         NOT NULL,
  `status`      ENUM('accepted','rejected') NOT NULL DEFAULT 'accepted',
  `code`        VARCHAR(40)         NOT NULL DEFAULT '',
  `created_at`  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_device_request_key` (`device_id`, `request_key`),
  KEY `idx_device_requests_created` (`created_at`),
  KEY `idx_device_requests_customer` (`customer_id`),
  CONSTRAINT `fk_device_requests_device` FOREIGN KEY (`device_id`)
    REFERENCES `attendance_devices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5) Device row: last error marker (owner visible)
--    The two columns are added through App\Services\MigrationService
--    (COLUMN_ADDITIONS), which checks information_schema first, so this file
--    stays purely declarative and every statement here is a CREATE TABLE.
-- ---------------------------------------------------------------------
