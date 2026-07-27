<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryDeletedFatMovementNeutralizationService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'operational-store:',
    'plan',
    'rehearse',
    'apply',
    'reviewed-manifest:',
    'backup:',
    'json',
    'help',
]);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/inventory_neutralize_deleted_fat_movements.php [--operational-store=27] [--plan|--rehearse|--apply --reviewed-manifest=<sha256> --backup=/absolute/backup.sql] [--json]\n");
    exit(0);
}

$modes = array_values(array_filter(['plan', 'rehearse', 'apply'], static fn(string $mode): bool => isset($options[$mode])));
if (count($modes) > 1) {
    fwrite(STDERR, "Choose only one of --plan, --rehearse, or --apply.\n");
    exit(1);
}
$mode = $modes[0] ?? 'plan';
$serviceOptions = [];
if (isset($options['operational-store'])) {
    $serviceOptions['operational_store_id'] = max(0, (int) $options['operational-store']);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $service = new InventoryDeletedFatMovementNeutralizationService();
    $result = $mode === 'apply'
        ? $service->apply(
            $conn,
            $serviceOptions,
            (string) ($options['reviewed-manifest'] ?? ''),
            (string) ($options['backup'] ?? '')
        )
        : ($mode === 'rehearse'
            ? $service->rehearse($conn, $serviceOptions)
            : $service->plan($conn, $serviceOptions));
    $conn->close();
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'mode' => $mode,
        'error' => $exception->getMessage(),
        'blockers' => [$exception->getMessage()],
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'Deleted legacy movement neutralization: ' . (!empty($result['ok']) ? 'READY' : 'BLOCKED') . PHP_EOL;
    echo '- mode: ' . (string) ($result['mode'] ?? $mode) . PHP_EOL;
    echo '- manifest: ' . (string) ($result['manifest_hash'] ?? '') . PHP_EOL;
    echo '- entries: ' . (int) ($result['summary']['entry_count'] ?? 0) . PHP_EOL;
    foreach (($result['blockers'] ?? []) as $blocker) {
        echo '- blocker: ' . (is_array($blocker) ? json_encode($blocker, JSON_UNESCAPED_SLASHES) : (string) $blocker) . PHP_EOL;
    }
}

exit(!empty($result['ok']) ? 0 : 2);
