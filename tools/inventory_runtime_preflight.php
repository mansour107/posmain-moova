<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/../classes/Inventory/InventoryRuntimePreflightService.php';

$options = getopt('', ['json', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/inventory_runtime_preflight.php [--json]\n");
    fwrite(STDOUT, "Read-only fail-closed check for live inventory schema, lifecycle flags, and accounting mappings.\n");
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn = posmain_db_connect();
    $flags = new InventoryFeatureFlags(posmain_app_config());
    $result = (new InventoryRuntimePreflightService())->check($conn, $flags);
    $conn->close();
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'blockers' => ['inventory_runtime_preflight_database_unreachable'],
        'error' => $exception->getMessage(),
    ];
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $ignored) {
        }
    }
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    fwrite(STDOUT, 'Inventory runtime preflight: ' . (!empty($result['ok']) ? 'READY' : 'BLOCKED') . PHP_EOL);
    fwrite(STDOUT, '- mode: ' . (string) ($result['mode'] ?? 'off') . PHP_EOL);
    fwrite(STDOUT, '- pending migrations: ' . (int) ($result['pending_migration_count'] ?? 0) . PHP_EOL);
    foreach (($result['blockers'] ?? []) as $blocker) {
        fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
    }
}

exit(!empty($result['ok']) ? 0 : 2);
