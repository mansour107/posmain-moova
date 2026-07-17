<?php

$setting = file_get_contents(__DIR__ . '/../../setting.php');
$ajax = file_get_contents(__DIR__ . '/../../ajax/sync_credentials.php');
$service = file_get_contents(__DIR__ . '/../../classes/Sync/BranchCatalogPushService.php');
$bulkPush = file_get_contents(__DIR__ . '/../../classes/Sync/BranchBulkPushJobService.php');
$bulkPushCli = file_get_contents(__DIR__ . '/../../cli/sync_bulk_push.php');

branchCatalogPushContractAssert(is_string($setting), 'setting.php should be readable');
branchCatalogPushContractAssert(is_string($ajax), 'ajax/sync_credentials.php should be readable');
branchCatalogPushContractAssert(is_string($service), 'BranchCatalogPushService.php should be readable');
branchCatalogPushContractAssert(is_string($bulkPush), 'BranchBulkPushJobService.php should be readable');
branchCatalogPushContractAssert(is_string($bulkPushCli), 'cli/sync_bulk_push.php should be readable');
branchCatalogPushContractAssert(strpos($bulkPush, 'cli/sync_bulk_push.php') !== false, 'bulk push service should spawn cli/sync_bulk_push.php');
branchCatalogPushContractAssert(strpos($bulkPush, 'sync_bulk_push_jobs') !== false, 'bulk push service should persist jobs in sync_bulk_push_jobs');
branchCatalogPushContractAssert(strpos($bulkPush, 'FINISHED_JOB_MESSAGE_TTL_SECONDS') !== false, 'bulk push service should expire finished job messages');

foreach ([
    'js-sync-push-data',
    'push_supported_data_plan',
    'push_supported_data_phase',
    'push_supported_data_dispatch',
    'push_supported_data_start',
    'push_supported_data_status',
    'runSupportedDataPushWithProgress',
    'startBulkPushBackground',
    'pollBulkPushStatus',
    'applyBulkPushJobToUi',
    'bulkPushJobMessageIsFresh',
    'formatSyncProgressMessage',
    'Sync all data to hosted',
    'loadSyncStatusPanel();',
    'background sync',
] as $snippet) {
    branchCatalogPushContractAssert(strpos($setting, $snippet) !== false, 'setting.php missing data sync snippet: ' . $snippet);
}

foreach ([
    'push_supported_data_to_hosted',
    'push_supported_data_plan',
    'push_supported_data_phase',
    'push_supported_data_dispatch',
    'push_supported_data_start',
    'push_supported_data_status',
    'BranchCatalogPushService',
    'BranchBulkPushJobService',
    'planPushToHosted',
    'runPushPhase',
    'runPushDispatchBatch',
    'sync_supported_data_pushed_to_hosted',
    'sync_supported_data_background_started',
    'Supported data sync finished.',
] as $snippet) {
    branchCatalogPushContractAssert(strpos($ajax, $snippet) !== false, 'ajax/sync_credentials.php missing data sync snippet: ' . $snippet);
}

foreach ([
    'class BranchCatalogPushService',
    'planPushToHosted',
    'runPushPhase',
    'runPushDispatchBatch',
    'pushToHosted',
    'recordMenuItemSnapshot',
    'recordTableSnapshot',
    'recordOrderSnapshot',
    'settings_supported_data_push',
    'supported_domains',
    'unsupported_domains',
    'dispatchOutbox',
    'drain_outbox',
    'drained_outbox',
    'countPendingOutbox',
    'columnExists',
    'queueInventoryReferencedMenuItems',
    'queueShopConfigSnapshots',
    'queueModifierCatalogSnapshots',
    'queueShiftCloseSnapshots',
    'shop_config',
    'modifier_catalog',
    'shift_closes',
    'delivery_clients',
    'catalog_inventory_refs',
    'if ($domain === \'drawer_session\')',
    'recordDrawerMovementSnapshot($conn, $rowId, $options)',
] as $snippet) {
    branchCatalogPushContractAssert(strpos($service, $snippet) !== false, 'BranchCatalogPushService missing snippet: ' . $snippet);
}

echo "branch-catalog-push-contract-ok\n";

function branchCatalogPushContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
