<?php

$files = [
    'classes/Sync/OperationalSyncDomains.php',
    'classes/Sync/OperationalSyncEventService.php',
    'classes/Sync/OperationalSyncRecorder.php',
    'classes/Sync/CloudOperationalMirrorService.php',
    'classes/Sync/BranchCatalogPushService.php',
    'classes/Sync/SyncInboxService.php',
    'config/app_config.php',
];

foreach ($files as $file) {
    operationalSyncContractAssert(is_string(file_get_contents(__DIR__ . '/../../' . $file)), $file . ' should be readable');
}

$domains = file_get_contents(__DIR__ . '/../../classes/Sync/OperationalSyncDomains.php');
foreach ([
    'item_category',
    'inventory_balance',
    'inventory_stock_level',
    'inventory_movement',
    'recipe',
    'employee',
    'pulse_log',
    'pulse_type',
    "'password'",
] as $snippet) {
    operationalSyncContractAssert(strpos($domains, $snippet) !== false, 'OperationalSyncDomains missing snippet: ' . $snippet);
}

$push = file_get_contents(__DIR__ . '/../../classes/Sync/BranchCatalogPushService.php');
foreach ([
    'queueOperationalSnapshots',
    'inventory_balances',
    'recipes',
    'employees',
    'pulse_logs',
    'operational_sync_enabled',
] as $snippet) {
    operationalSyncContractAssert(strpos($push, $snippet) !== false, 'BranchCatalogPushService missing snippet: ' . $snippet);
}

$inbox = file_get_contents(__DIR__ . '/../../classes/Sync/SyncInboxService.php');
operationalSyncContractAssert(strpos($inbox, 'CloudOperationalMirrorService') !== false, 'SyncInboxService should apply operational snapshots');

echo "operational-sync-contract-ok\n";

function operationalSyncContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
