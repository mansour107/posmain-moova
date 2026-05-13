<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../classes/Sync/MoovaInboundQueueService.php';
require_once __DIR__ . '/../classes/Moova/MoovaLocalIngestService.php';
require_once __DIR__ . '/../classes/MoovaPosIntegration.php';
require_once __DIR__ . '/../classes/PosOrderService.php';
require_once __DIR__ . '/../classes/Sync/BranchMoovaApplyWorker.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['once', 'loop', 'batch-size::', 'sleep::', 'max-runtime::']);
$loop = isset($options['loop']);
$once = isset($options['once']) || !$loop;
$batchSize = isset($options['batch-size']) ? max(1, (int) $options['batch-size']) : 25;
$sleep = isset($options['sleep']) ? max(1, (int) $options['sleep']) : 5;
$maxRuntime = isset($options['max-runtime']) ? max(1, (int) $options['max-runtime']) : 300;

if (!$once && !$loop) {
    fwrite(STDERR, "Usage: php cli/moova_apply_worker.php --once|--loop [--batch-size=25] [--sleep=5] [--max-runtime=300]\n");
    exit(1);
}

$started = time();
$worker = new BranchMoovaApplyWorker();

do {
    $conn = posmain_db_connect();
    $metrics = $worker->runOnce($conn, posmain_app_config(), [
        'batch_size' => $batchSize,
    ]);
    $conn->close();

    echo json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if ($once || time() - $started >= $maxRuntime) {
        break;
    }

    sleep($sleep);
} while (true);
