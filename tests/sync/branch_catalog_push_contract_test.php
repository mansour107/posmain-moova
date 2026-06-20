<?php

$setting = file_get_contents(__DIR__ . '/../../setting.php');
$ajax = file_get_contents(__DIR__ . '/../../ajax/sync_credentials.php');
$service = file_get_contents(__DIR__ . '/../../classes/Sync/BranchCatalogPushService.php');

branchCatalogPushContractAssert(is_string($setting), 'setting.php should be readable');
branchCatalogPushContractAssert(is_string($ajax), 'ajax/sync_credentials.php should be readable');
branchCatalogPushContractAssert(is_string($service), 'BranchCatalogPushService.php should be readable');

foreach ([
    'js-sync-push-data',
    'push_supported_data_to_hosted',
    'Sync all data to hosted',
    'Syncing supported local data to hosted...',
    'loadSyncStatusPanel();',
] as $snippet) {
    branchCatalogPushContractAssert(strpos($setting, $snippet) !== false, 'setting.php missing data sync snippet: ' . $snippet);
}

foreach ([
    'push_supported_data_to_hosted',
    'BranchCatalogPushService',
    'sync_supported_data_pushed_to_hosted',
    'Supported data sync finished.',
] as $snippet) {
    branchCatalogPushContractAssert(strpos($ajax, $snippet) !== false, 'ajax/sync_credentials.php missing data sync snippet: ' . $snippet);
}

foreach ([
    'class BranchCatalogPushService',
    'pushToHosted',
    'recordMenuItemSnapshot',
    'recordTableSnapshot',
    'recordOrderSnapshot',
    'settings_supported_data_push',
    'supported_domains',
    'unsupported_domains',
    'dispatchOutbox',
    'countPendingOutbox',
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
