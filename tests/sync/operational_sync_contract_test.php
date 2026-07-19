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
    'delivery_client',
    'shop_settings',
    'modifier_group',
    'moova_shop_link',
    'push_counter',
    "'password'",
] as $snippet) {
    operationalSyncContractAssert(strpos($domains, $snippet) !== false, 'OperationalSyncDomains missing snippet: ' . $snippet);
}

$push = file_get_contents(__DIR__ . '/../../classes/Sync/BranchCatalogPushService.php');
foreach ([
    'queueOperationalSnapshots',
    'queueShopConfigSnapshots',
    'queueModifierCatalogSnapshots',
    'queueShiftCloseSnapshots',
    'inventory_balances',
    'recipes',
    'employees',
    'pulse_logs',
    'shop_config',
    'modifier_catalog',
    'shift_closes',
    'delivery_clients',
    'moova_shop_links',
    'operational_sync_enabled',
] as $snippet) {
    operationalSyncContractAssert(strpos($push, $snippet) !== false, 'BranchCatalogPushService missing snippet: ' . $snippet);
}

$inbox = file_get_contents(__DIR__ . '/../../classes/Sync/SyncInboxService.php');
operationalSyncContractAssert(strpos($inbox, 'CloudOperationalMirrorService') !== false, 'SyncInboxService should apply operational snapshots');

$tableSnapshot = file_get_contents(__DIR__ . '/../../classes/Sync/CloudTableSnapshotService.php');
operationalSyncContractAssert(strpos($tableSnapshot, 'operational_row') !== false, 'CloudTableSnapshotService should exclude operational sync payloads');
operationalSyncContractAssert(strpos($tableSnapshot, 'is_array($payload[\'table\'])') !== false, 'CloudTableSnapshotService should only treat array table payloads as POS tables');

$mirror = file_get_contents(__DIR__ . '/../../classes/Sync/CloudOperationalMirrorService.php');
operationalSyncContractAssert(strpos($mirror, 'shop_settings') !== false, 'CloudOperationalMirrorService should apply shop settings');
operationalSyncContractAssert(strpos($mirror, 'modifier_group_bundle') !== false, 'CloudOperationalMirrorService should apply modifier bundles');
operationalSyncContractAssert(strpos($mirror, 'moova_shop_link') !== false, 'CloudOperationalMirrorService should apply Moova shop links');
operationalSyncContractAssert(strpos($mirror, "\$link['moova_device_token_hash'] = ''") !== false, 'Moova recovery must force secure re-pairing');

$events = file_get_contents(__DIR__ . '/../../classes/Sync/OperationalSyncEventService.php');
operationalSyncContractAssert(strpos($events, "unset(\$row['moova_device_token_hash'])") !== false, 'Moova snapshots must strip token hashes');

$restoreExport = file_get_contents(__DIR__ . '/../../classes/Sync/CloudBranchRestoreExportService.php');
operationalSyncContractAssert(strpos($restoreExport, 'restorableInboxDecisionSql') !== false, 'restore export must use one inbox eligibility predicate');
operationalSyncContractAssert(substr_count($restoreExport, 'AND {$eligibleDecision}') === 4, 'all inbox restore queries must use the stale-safe predicate');
operationalSyncContractAssert(strpos($restoreExport, "<> 'stale'") !== false, 'restore export must exclude explicit stale decisions');
operationalSyncContractAssert(strpos($restoreExport, "unset(\$row['moova_device_token_hash'])") !== false, 'legacy restore export must strip token hashes');

echo "operational-sync-contract-ok\n";

function operationalSyncContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
