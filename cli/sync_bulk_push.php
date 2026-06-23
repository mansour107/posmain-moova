<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/BranchBulkPushJobService.php';
require_once __DIR__ . '/../classes/Router/ShopRouter.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['job-uuid:', 'shop-id::', 'shop-db::', 'help']);
if (isset($options['help']) || empty($options['job-uuid'])) {
    fwrite(STDOUT, "Usage: php cli/sync_bulk_push.php --job-uuid=<uuid> [--shop-id=<id>] [--shop-db=<database>]\n");
    exit(empty($options['job-uuid']) ? 1 : 0);
}

if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

$jobUuid = trim((string) $options['job-uuid']);
$shopId = isset($options['shop-id']) ? max(0, (int) $options['shop-id']) : 0;
$shopDb = trim((string) ($options['shop-db'] ?? ''));

try {
    $conn = syncBulkPushConnect($shopId, $shopDb);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => 'db_connect_failed',
        'message' => $e->getMessage(),
        'job_uuid' => $jobUuid,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(2);
}

$config = posmain_app_config();
$service = new BranchBulkPushJobService();

try {
    $service->runToCompletion($conn, $config, $jobUuid);
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'job_uuid' => $jobUuid,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => 'bulk_push_failed',
        'message' => $e->getMessage(),
        'job_uuid' => $jobUuid,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
} finally {
    $conn->close();
}

function syncBulkPushConnect(int $shopId, string $shopDb): mysqli
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
