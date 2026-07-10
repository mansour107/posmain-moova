<?php

declare(strict_types=1);

/**
 * Close any open drawer sessions so the next POS unlock can start a fresh
 * handover open-count flow. Used by Playwright before production scenarios.
 */

require_once __DIR__ . '/../../../config/app_config.php';
require_once __DIR__ . '/../../../includes/db_bootstrap.php';

if (!function_exists('posmain_drawer_sessions_table_exists')) {
    require_once __DIR__ . '/../../../includes/pos_shift_guard.php';
}

$conn = posmain_db_connect();
if (!posmain_drawer_sessions_table_exists($conn)) {
    fwrite(STDOUT, "prepare-clean-shift-skipped\n");
    exit(0);
}

$conn->query(
    "UPDATE drawer_sessions
        SET status = 'closed',
            closed_at = COALESCE(closed_at, NOW()),
            open_branch_lock = NULL,
            variance_status = CASE
                WHEN variance_status IS NULL OR variance_status = '' THEN 'none'
                ELSE variance_status
            END
      WHERE status = 'open'"
);

$affected = (int) $conn->affected_rows;

// Clear orphaned branch locks left on non-open rows (blocks next open).
$conn->query(
    "UPDATE drawer_sessions
        SET open_branch_lock = NULL
      WHERE status <> 'open'
        AND open_branch_lock IS NOT NULL"
);
$clearedLocks = (int) $conn->affected_rows;

fwrite(STDOUT, "prepare-clean-shift-ok affected={$affected} cleared_locks={$clearedLocks}\n");
