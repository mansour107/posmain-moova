-- Harmless update-pipeline test migration.
-- Creates a marker table only; safe to skip if already present.

CREATE TABLE IF NOT EXISTS update_pipeline_test_marker (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    note VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO update_pipeline_test_marker (id, note)
VALUES (1, 'update pipeline test');
