-- =====================================================================
-- SARMAD Attendance - upgrade 6.0.0 (attendance ENGINE)
-- ---------------------------------------------------------------------
-- Stage 6 adds the write side of attendance. Two NEW tables are created
-- here; every change to an existing table travels through
-- MigrationService::DDL_STEPS / COLUMN_ADDITIONS (project rule: stage SQL
-- files only CREATE TABLE).
--
-- NOTHING outside attendance_* is touched. No foreign project table
-- (barber_*, cafe_*, clinic_*, zibaei_*, sarmadpf_*) is referenced anywhere.
--
-- Liveness detection is NOT part of this design and there is no column,
-- table or flag for it anywhere.
-- =====================================================================

-- ---------------------------------------------------------------------
-- One recognition proof per accepted local face match.
--
-- The device performs recognition locally and reports the outcome. The
-- backend never trusts a bare employee_id coming from a client: it issues
-- a short lived, single use, device+customer+employee bound proof, and the
-- attendance entry/exit call must present it. Replaying it is impossible
-- because it is consumed inside the same transaction as the record.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_recognition_proofs` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36) NOT NULL,
    `customer_id`       BIGINT UNSIGNED NOT NULL,
    `device_id`         BIGINT UNSIGNED NOT NULL,
    `employee_id`       BIGINT UNSIGNED NOT NULL,
    `branch_id`         BIGINT UNSIGNED DEFAULT NULL,
    `action`            ENUM('entry','exit','any') NOT NULL DEFAULT 'any',
    `request_id`        VARCHAR(64) NOT NULL,
    `confidence`        DECIMAL(6,5) DEFAULT NULL,
    `distance`          DECIMAL(6,5) DEFAULT NULL,
    `model_version`     VARCHAR(60) DEFAULT NULL,
    `issued_at`         DATETIME NOT NULL,
    `expires_at`        DATETIME NOT NULL,
    `consumed_at`       DATETIME DEFAULT NULL,
    `consumed_record_id` BIGINT UNSIGNED DEFAULT NULL,
    `ip`                VARCHAR(45) DEFAULT NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_proof_uuid` (`uuid`),
    KEY `idx_proofs_lookup` (`device_id`, `employee_id`, `consumed_at`),
    KEY `idx_proofs_expiry` (`expires_at`),
    KEY `idx_proofs_scope` (`customer_id`, `issued_at`),
    CONSTRAINT `fk_proofs_customer` FOREIGN KEY (`customer_id`) REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_proofs_device`   FOREIGN KEY (`device_id`)   REFERENCES `attendance_devices` (`id`)   ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_proofs_employee` FOREIGN KEY (`employee_id`) REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Attendance events: the integration seam for the next stage.
--
-- The engine writes a durable event row for every attendance fact
-- (attendance.entry.created, attendance.exit.created,
-- attendance.late.detected, attendance.overtime.detected, ...). THIS STAGE
-- DOES NOT SEND ANYTHING: no Telegram call exists here. The table is the
-- queue a later stage will drain, so notifications can never be lost when
-- they are added.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_events` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `customer_id`   BIGINT UNSIGNED NOT NULL,
    `employee_id`   BIGINT UNSIGNED DEFAULT NULL,
    `device_id`     BIGINT UNSIGNED DEFAULT NULL,
    `record_id`     BIGINT UNSIGNED DEFAULT NULL,
    `event`         VARCHAR(60) NOT NULL,
    `level`         ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    `payload`       TEXT DEFAULT NULL,
    `status`        ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `available_at`  DATETIME NOT NULL,
    `sent_at`       DATETIME DEFAULT NULL,
    `last_error`    VARCHAR(255) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_event_uuid` (`uuid`),
    KEY `idx_events_employee` (`employee_id`, `created_at`),
    KEY `idx_events_record` (`record_id`),
    CONSTRAINT `fk_events_customer` FOREIGN KEY (`customer_id`) REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_events_employee` FOREIGN KEY (`employee_id`) REFERENCES `attendance_employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_events_device`   FOREIGN KEY (`device_id`)   REFERENCES `attendance_devices` (`id`)   ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
