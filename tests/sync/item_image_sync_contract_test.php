<?php

$root = dirname(__DIR__, 2);

$sources = [
    'schema' => file_get_contents($root . '/classes/Sync/SchemaManager.php'),
    'queue' => file_get_contents($root . '/classes/Sync/ItemImageSyncQueueService.php'),
    'upload' => file_get_contents($root . '/classes/Sync/BranchImageSyncService.php'),
    'receive' => file_get_contents($root . '/classes/Sync/CloudBranchImageReceiveService.php'),
    'export' => file_get_contents($root . '/classes/Sync/CloudBranchImageExportService.php'),
    'domains' => file_get_contents($root . '/classes/Sync/OperationalSyncDomains.php'),
    'worker' => file_get_contents($root . '/cli/sync_image_worker.php'),
    'config' => file_get_contents($root . '/config/app_config.php'),
];

foreach ($sources as $name => $contents) {
    itemImageSyncAssert(is_string($contents) && $contents !== '', 'unable to read source: ' . $name);
}

itemImageSyncAssert(strpos($sources['schema'], 'sync_image_queue') !== false, 'schema should define sync_image_queue');
itemImageSyncAssert(strpos($sources['domains'], "'item_image'") !== false, 'operational domains should include item_image');
itemImageSyncAssert(strpos($sources['upload'], 'image_sync_max_bytes_per_run') !== false, 'upload service should throttle bytes per run');
itemImageSyncAssert(strpos($sources['upload'], 'image_sync_max_files_per_run') !== false, 'upload service should throttle files per run');
itemImageSyncAssert(strpos($sources['upload'], 'spawnBackgroundWorker') !== false, 'upload service should spawn resumable background worker');
itemImageSyncAssert(strpos($sources['receive'], 'hash_equals($fileSha256, $uploadedHash)') !== false, 'receive service should verify sha256');
itemImageSyncAssert(strpos($sources['export'], 'exportSignatureBody') !== false, 'export service should sign download requests');
itemImageSyncAssert(strpos($sources['worker'], '--loop') !== false, 'image worker cli should support loop/resume');
itemImageSyncAssert(strpos($sources['config'], 'image_sync_enabled') !== false, 'app config should expose image_sync_enabled');

echo "item_image_sync_contract_test: OK\n";

function itemImageSyncAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
