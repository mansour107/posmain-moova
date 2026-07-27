<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryNonStockLedgerNeutralizationService.php';

$options = getopt('', ['plan', 'rehearse', 'apply', 'reviewed-manifest:', 'backup:', 'json', 'help']);
if (isset($options['help'])) {
    echo "Usage: php tools/inventory_neutralize_non_stock_ledger.php [--plan|--rehearse|--apply --reviewed-manifest=<sha256> --backup=/absolute/backup.sql] [--json]\n";
    exit(0);
}
$modes = array_values(array_filter(['plan', 'rehearse', 'apply'], static fn(string $mode): bool => isset($options[$mode])));
if (count($modes) > 1) {
    fwrite(STDERR, "Choose only one mode.\n");
    exit(1);
}
$mode = $modes[0] ?? 'plan';

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $service = new InventoryNonStockLedgerNeutralizationService();
    $result = $mode === 'apply'
        ? $service->apply($conn, (string) ($options['reviewed-manifest'] ?? ''), (string) ($options['backup'] ?? ''))
        : ($mode === 'rehearse' ? $service->rehearse($conn) : $service->plan($conn));
    $conn->close();
} catch (Throwable $exception) {
    $result = ['ok' => false, 'mode' => $mode, 'error' => $exception->getMessage(), 'blockers' => [$exception->getMessage()]];
}

echo isset($options['json'])
    ? json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
    : 'Inventory non-stock ledger neutralization: ' . (!empty($result['ok']) ? 'READY' : 'BLOCKED') . PHP_EOL
        . '- mode: ' . (string) ($result['mode'] ?? $mode) . PHP_EOL
        . '- manifest: ' . (string) ($result['manifest_hash'] ?? '') . PHP_EOL
        . '- entries: ' . (int) ($result['summary']['entry_count'] ?? 0) . PHP_EOL;

exit(!empty($result['ok']) ? 0 : 2);
