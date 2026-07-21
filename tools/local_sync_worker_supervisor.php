<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/BranchWorkerDaemon.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', [
    'check',
    'once',
    'loop',
    'strict',
    'only::',
    'batch-size::',
    'sync-batch-size::',
    'moova-batch-size::',
    'sleep::',
    'cycles::',
    'max-runtime::',
    'env-file::',
    'pid-file::',
    'status-file::',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/local_sync_worker_supervisor.php [--check|--once|--loop] [--strict] [--env-file=/etc/posmain/branch-worker.env] [--only=sync_outbox,moova_catalog,moova_poller,cloud_sync_poller,moova_apply,moova_ack] [--sleep=5] [--cycles=0] [--max-runtime=0] [--pid-file=/run/posmain-branch-worker.pid] [--status-file=/var/lib/posmain-branch-worker-status.json]\n");
    fwrite(STDOUT, "--check runs preflight only. --strict turns branch/cloud warnings into blockers. --cycles=0 and --max-runtime=0 mean unlimited while --loop is active.\n");
    exit(0);
}

$envFile = !empty($options['env-file']) ? (string) $options['env-file'] : '';
if ($envFile !== '') {
    loadSupervisorEnvFile($envFile, true);
}

$daemon = new BranchWorkerDaemon();
$config = supervisorLoadConfig();
$strict = isset($options['strict']);
$pidFile = (string) ($options['pid-file'] ?? (sys_get_temp_dir() . '/posmain-branch-worker-supervisor.pid'));
$statusFile = (string) ($options['status-file'] ?? (sys_get_temp_dir() . '/posmain-branch-worker-status.json'));
$runOptions = supervisorRunOptions($options);

$preflight = supervisorPreflight($daemon, $config, $strict);
$preflightOk = supervisorPreflightOk($preflight, $strict);

if (isset($options['check'])) {
    writeSupervisorStatus($statusFile, [
        'supervisor' => 'local_sync_worker_supervisor',
        'mode' => 'check',
        'ok' => $preflightOk,
        'preflight' => $preflight,
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    echo json_encode([
        'ok' => $preflightOk,
        'supervisor' => 'local_sync_worker_supervisor',
        'preflight' => $preflight,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($preflightOk ? 0 : 2);
}

if (!$preflightOk) {
    $status = [
        'supervisor' => 'local_sync_worker_supervisor',
        'mode' => 'startup',
        'ok' => false,
        'preflight' => $preflight,
        'stopped_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    writeSupervisorStatus($statusFile, $status);
    echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

writePidFile($pidFile);
register_shutdown_function(function () use ($pidFile): void {
    if (is_file($pidFile) && trim((string) @file_get_contents($pidFile)) === (string) getmypid()) {
        @unlink($pidFile);
    }
});

$loop = isset($options['loop']) && !isset($options['once']);
$sleep = isset($options['sleep']) ? max(1, (int) $options['sleep']) : 5;
$cycles = isset($options['cycles']) ? max(0, (int) $options['cycles']) : ($loop ? 0 : 1);
$maxRuntime = isset($options['max-runtime']) ? max(0, (int) $options['max-runtime']) : 0;
$started = time();
$cycleNo = 0;
$hadFailure = false;

do {
    $cycleNo++;
    if ($envFile !== '') {
        loadSupervisorEnvFile($envFile, true);
    }
    $config = supervisorLoadConfig($config);
    $preflight = supervisorPreflight($daemon, $config, $strict);
    $metrics = runSupervisorCycle($daemon, $config, $runOptions);
    $hadFailure = $hadFailure || empty($metrics['ok']);

    $status = [
        'supervisor' => 'local_sync_worker_supervisor',
        'mode' => $loop ? 'loop' : 'once',
        'ok' => !empty($metrics['ok']),
        'had_failure_since_start' => $hadFailure,
        'pid' => getmypid(),
        'cycle' => $cycleNo,
        'preflight' => $preflight,
        'last_cycle' => $metrics,
        'updated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    writeSupervisorStatus($statusFile, $status);
    echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    $reachedCycleLimit = $cycles > 0 && $cycleNo >= $cycles;
    $reachedRuntimeLimit = $maxRuntime > 0 && (time() - $started) >= $maxRuntime;
    if (!$loop || $reachedCycleLimit || $reachedRuntimeLimit) {
        break;
    }

    sleep($sleep);
} while (true);

exit($hadFailure ? 1 : 0);

function supervisorLoadConfig(array $fallback = []): array
{
    if (!function_exists('posmain_app_config')) {
        return $fallback;
    }

    try {
        return posmain_app_config();
    } catch (Throwable $e) {
        error_log('POSMAIN supervisor config reload failed: ' . $e->getMessage());
        return $fallback;
    }
}

function supervisorPreflight(BranchWorkerDaemon $daemon, array $config, bool $strict): array
{
    try {
        $conn = posmain_db_connect();
        $preflight = $daemon->preflight($conn, $config);
        $conn->close();
    } catch (Throwable $e) {
        $preflight = [
            'ok' => false,
            'error' => 'db_connect_failed',
            'message' => $e->getMessage(),
            'warnings' => ['db_connect_failed'],
            'schema_pending' => [],
            'jobs' => $daemon->describeJobs(),
        ];
    }

    $strictBlockers = $strict
        ? array_values(array_unique(array_merge($preflight['schema_pending'] ?? [], $preflight['warnings'] ?? [])))
        : [];
    $preflight['strict'] = $strict;
    $preflight['strict_blockers'] = $strictBlockers;

    return $preflight;
}

function supervisorPreflightOk(array $preflight, bool $strict): bool
{
    return !empty($preflight['ok']) && (!$strict || empty($preflight['strict_blockers']));
}

function runSupervisorCycle(BranchWorkerDaemon $daemon, array $config, array $runOptions): array
{
    try {
        $conn = posmain_db_connect();
        $metrics = $daemon->runCycle($conn, $config, $runOptions);
        $conn->close();

        return $metrics;
    } catch (Throwable $e) {
        return [
            'daemon' => 'branch_worker_daemon',
            'ok' => false,
            'failed' => 1,
            'error' => 'db_connect_failed',
            'message' => $e->getMessage(),
        ];
    }
}

function supervisorRunOptions(array $options): array
{
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

    return $runOptions;
}

function writePidFile(string $path): void
{
    ensureSupervisorDirectory(dirname($path));
    file_put_contents($path, (string) getmypid() . PHP_EOL, LOCK_EX);
}

function writeSupervisorStatus(string $path, array $status): void
{
    ensureSupervisorDirectory(dirname($path));
    file_put_contents($path, json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);
}

function ensureSupervisorDirectory(string $directory): void
{
    if ($directory === '' || $directory === '.' || is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create supervisor directory: ' . $directory);
    }
}

function loadSupervisorEnvFile(string $path, bool $override = false): void
{
    if (!is_file($path)) {
        throw new RuntimeException('Environment file does not exist: ' . $path);
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Unable to read environment file: ' . $path);
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $name = trim($parts[0]);
        $value = trim($parts[1]);
        if ($name === '' || (!$override && getenv($name) !== false)) {
            continue;
        }

        putenv($name . '=' . trim($value, "\"'"));
        $_ENV[$name] = trim($value, "\"'");
    }
}
