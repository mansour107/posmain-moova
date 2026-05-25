<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/BranchWorkerDaemon.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', [
    'once',
    'loop',
    'list',
    'preflight',
    'strict',
    'only::',
    'batch-size::',
    'sync-batch-size::',
    'moova-batch-size::',
    'sleep::',
    'max-runtime::',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php cli/branch_worker_daemon.php [--once|--loop] [--list|--preflight] [--strict] [--only=sync_outbox,moova_poller,cloud_sync_poller,moova_apply,moova_ack] [--batch-size=25] [--sync-batch-size=50] [--moova-batch-size=25] [--sleep=5] [--max-runtime=300]\n");
    fwrite(STDOUT, "--strict makes --preflight fail when branch/cloud config warnings are present.\n");
    fwrite(STDOUT, "Use php cli/branch_worker_daemon.php --preflight --strict before service enablement.\n");
    exit(0);
}

$daemon = new BranchWorkerDaemon();

if (isset($options['list'])) {
    echo json_encode([
        'ok' => true,
        'jobs' => $daemon->describeJobs(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$config = posmain_app_config();
foreach ([
    'POSMAIN_BRANCH_UUID' => ['branch', 'uuid'],
    'POSMAIN_CLOUD_BASE_URL' => ['branch', 'cloud_base_url'],
    'POSMAIN_BRANCH_SYNC_SECRET' => ['sync', 'branch_secret'],
] as $envName => $path) {
    $envValue = getenv($envName);
    if ($envValue !== false && trim((string) $envValue) === '') {
        $config[$path[0]][$path[1]] = '';
    }
}

if (isset($options['preflight'])) {
    $strict = isset($options['strict']);
    try {
        $conn = posmain_db_connect();
        $preflight = $daemon->preflight($conn, $config);
        $conn->close();
        $preflight['strict'] = $strict;
        $preflight['strict_blockers'] = $strict
            ? array_values(array_unique(array_merge($preflight['schema_pending'] ?? [], $preflight['warnings'] ?? [])))
            : [];
    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'strict' => $strict,
            'error' => 'db_connect_failed',
            'message' => $e->getMessage(),
            'jobs' => $daemon->describeJobs(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(2);
    }

    echo json_encode($preflight, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(empty($preflight['schema_pending']) && (!$strict || empty($preflight['warnings'])) ? 0 : 2);
}

$loop = isset($options['loop']);
$once = isset($options['once']) || !$loop;
$sleep = isset($options['sleep']) ? max(1, (int) $options['sleep']) : 5;
$maxRuntime = isset($options['max-runtime']) ? max(1, (int) $options['max-runtime']) : 300;
$started = time();

$runOptions = [
    'only' => $options['only'] ?? null,
];
if (isset($options['batch-size'])) {
    $runOptions['batch_size'] = max(1, (int) $options['batch-size']);
}
if (isset($options['sync-batch-size'])) {
    $runOptions['sync_batch_size'] = max(1, (int) $options['sync-batch-size']);
}
if (isset($options['moova-batch-size'])) {
    $runOptions['moova_batch_size'] = max(1, (int) $options['moova-batch-size']);
}

do {
    try {
        $conn = posmain_db_connect();
        $metrics = $daemon->runCycle($conn, $config, $runOptions);
        $conn->close();
    } catch (Throwable $e) {
        $metrics = [
            'daemon' => 'branch_worker_daemon',
            'ok' => false,
            'failed' => 1,
            'error' => 'db_connect_failed',
            'message' => $e->getMessage(),
        ];
    }

    echo json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if ($once || time() - $started >= $maxRuntime) {
        break;
    }

    sleep($sleep);
} while (true);
