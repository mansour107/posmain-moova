-- Harmless update-pipeline test migration for release 1.0.3.
-- Creates a separate marker table only; safe to skip if already present.

CREATE TABLE IF NOT EXISTS update_pipeline_test_v2 (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    release_label VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO update_pipeline_test_v2 (id, release_label)
VALUES (1, '1.0.3');
