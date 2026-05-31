<?php

$root = dirname(__DIR__, 2);
$guardPath = $root . '/classes/Inventory/InventoryLegacyStockEndpointGuard.php';

$live = inventoryLegacyStockEndpointGuardRun($guardPath, 'live');
inventoryLegacyStockEndpointGuardAssert($live['exit_code'] === 0, 'live guard subprocess should exit normally after blocking');
inventoryLegacyStockEndpointGuardAssert(strpos($live['output'], 'legacy_guard_test') !== false, 'live guard should emit stable block code');
inventoryLegacyStockEndpointGuardAssert(strpos($live['output'], 'تم إيقاف مسار المخزون القديم') !== false, 'live guard should emit Arabic operator message');
inventoryLegacyStockEndpointGuardAssert(strpos($live['output'], 'after-guard') === false, 'live guard should stop endpoint execution');

$off = inventoryLegacyStockEndpointGuardRun($guardPath, 'off');
inventoryLegacyStockEndpointGuardAssert($off['exit_code'] === 0, 'off guard subprocess should exit normally');
inventoryLegacyStockEndpointGuardAssert(trim($off['output']) === 'after-guard', 'off guard should preserve old endpoint execution');

echo "inventory-legacy-stock-endpoint-guard-ok\n";

function inventoryLegacyStockEndpointGuardRun(string $guardPath, string $mode): array
{
    $code = 'require ' . var_export($guardPath, true) . '; InventoryLegacyStockEndpointGuard::blockIfLive("legacy_guard_test"); echo "after-guard";';
    $cmd = 'POSMAIN_INVENTORY_LEDGER_MODE=' . escapeshellarg($mode) . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);

    return [
        'exit_code' => $exitCode,
        'output' => implode("\n", $output),
    ];
}

function inventoryLegacyStockEndpointGuardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
