-- =====================================================================
-- SARMAD Attendance - stage 5 upgrade (Face Recognition)
-- =====================================================================
-- Face Embeddings table.
--
--   * Everything here is prefixed with the project prefix (attendance_).
--   * This file only ever contains CREATE TABLE IF NOT EXISTS statements:
--     columns for tables that already exist are added by
--     App\Services\MigrationService::COLUMN_ADDITIONS (hard coded, guarded
--     by SqlGuard / Database::table()).
--   * It is idempotent: running it twice is a no-op.
--
-- About the data itself:
--   `embedding` holds a JSON array of 128 floats produced by the LOCAL
--   browser face recognition model. No raw camera image is needed for
--   matching and no image is stored in this table.
--
--   sample_index is limited to exactly 1..3 by a CHECK constraint AND by a
--   UNIQUE key on (employee_id, sample_index), so the database itself
--   enforces "exactly three face samples per employee".
--
--   Liveness / anti-spoofing is NOT part of this schema on purpose: the
--   project explicitly does not implement it (no column, no flag).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `attendance_face_embeddings` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `customer_id`   BIGINT UNSIGNED NOT NULL,
    `employee_id`   BIGINT UNSIGNED NOT NULL,
    `sample_index`  TINYINT UNSIGNED NOT NULL,
    `embedding`     LONGTEXT NOT NULL,
    `dimensions`    SMALLINT UNSIGNED NOT NULL DEFAULT 128,
    `model_version` VARCHAR(60) NOT NULL,
    `engine`        VARCHAR(30) NOT NULL DEFAULT 'face-api.js',
    `detector`      VARCHAR(30) NOT NULL DEFAULT 'tiny_face_detector',
    `metric`        VARCHAR(16) NOT NULL DEFAULT 'euclidean',
    `quality`       DECIMAL(6,5) DEFAULT NULL,
    `image_id`      BIGINT UNSIGNED DEFAULT NULL,
    `source`        ENUM('enrollment','reimport') NOT NULL DEFAULT 'enrollment',
    `created_by`    BIGINT UNSIGNED DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `revoked_at`    DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_face_embedding_sample` (`employee_id`, `sample_index`),
    KEY `idx_face_embeddings_dataset` (`customer_id`, `employee_id`, `revoked_at`),
    KEY `idx_face_embeddings_model` (`model_version`),
    CONSTRAINT `chk_face_embedding_sample_index` CHECK (`sample_index` BETWEEN 1 AND 3),
    CONSTRAINT `chk_face_embedding_dimensions` CHECK (`dimensions` = 128),
    CONSTRAINT `fk_face_embeddings_customer` FOREIGN KEY (`customer_id`)
        REFERENCES `attendance_customers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_face_embeddings_employee` FOREIGN KEY (`employee_id`)
        REFERENCES `attendance_employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
