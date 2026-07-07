<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config/app_config.php';
require_once __DIR__ . '/../../../includes/db_bootstrap.php';

if (!function_exists('posmain_drawer_sessions_table_exists')) {
    require_once __DIR__ . '/../../../includes/pos_shift_guard.php';
}

$conn = posmain_db_connect();
if (!posmain_drawer_sessions_table_exists($conn)) {
    fwrite(STDOUT, "close-open-drawers-skipped\n");
    exit(0);
}

$conn->query(
    "UPDATE drawer_sessions
        SET status = 'closed', closed_at = COALESCE(closed_at, NOW())
      WHERE status = 'open' AND closed_at IS NULL"
);
fwrite(STDOUT, 'close-open-drawers-ok affected=' . $conn->affected_rows . "\n");
