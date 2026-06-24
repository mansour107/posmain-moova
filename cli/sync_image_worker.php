<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/BranchImageSyncService.php';
require_once __DIR__ . '/../classes/Sync/BranchImageSyncWorker.php';
require_once __DIR__ . '/../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../classes/Sync/ItemImageSyncQueueService.php';
require_once __DIR__ . '/../classes/Router/ShopRouter.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['once', 'loop', 'sleep::', 'max-runtime::', 'shop-id::', 'shop-db::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php cli/sync_image_worker.php --once|--loop [--sleep=8] [--max-runtime=1800] [--shop-id=<id>] [--shop-db=<database>]\n");
    exit(0);
}

$loop = isset($options['loop']);
$once = isset($options['once']) || !$loop;
$sleep = isset($options['sleep']) ? max(3, (int) $options['sleep']) : 8;
$maxRuntime = isset($options['max-runtime']) ? max(30, (int) $options['max-runtime']) : 1800;
$shopId = isset($options['shop-id']) ? max(0, (int) $options['shop-id']) : 0;
$shopDb = trim((string) ($options['shop-db'] ?? ''));

if (!$once && !$loop) {
    fwrite(STDERR, "Usage: php cli/sync_image_worker.php --once|--loop [--sleep=8] [--max-runtime=1800]\n");
    exit(1);
}

if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

$started = time();
$worker = new BranchImageSyncWorker();
$imageSync = new BranchImageSyncService();

do {
    try {
        $conn = syncImageWorkerConnect($shopId, $shopDb);
        $config = posmain_app_config();
        $identity = (new SyncBranchIdentity())->ensure($conn, $config);
        $branchUuid = strtolower(trim((string) ($identity['branch_uuid'] ?? '')));
        if ($branchUuid !== '') {
            $queue = new ItemImageSyncQueueService();
            $queue->scanBranchUploadQueue($conn, $branchUuid);
            $queue->scanBranchDownloadQueue($conn, $branchUuid);
        }

        $metrics = $worker->runOnce($conn, $config);
        $conn->close();
        fwrite(STDOUT, json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $pendingUpload = (int) ($metrics['pending_upload'] ?? 0);
        $pendingDownload = (int) ($metrics['pending_download'] ?? 0);
        if ($once || ($pendingUpload <= 0 && $pendingDownload <= 0)) {
            break;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, json_encode([
            'ok' => false,
            'error' => 'image_worker_failed',
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    if (time() - $started >= $maxRuntime) {
        break;
    }

    sleep($sleep);
} while (true);

exit(0);

function syncImageWorkerConnect(int $shopId, string $shopDb): mysqli
{
    if ($shopId > 0 && posmain_router_enabled()) {
        return posmain_shop_db_connect($shopId);
    }

    if ($shopDb !== '') {
        $config = posmain_app_config();
        $config['database']['name'] = $shopDb;

        return posmain_raw_db_connect($config['database']);
    }

    return posmain_db_connect();
}
