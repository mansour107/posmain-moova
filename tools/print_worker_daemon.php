#!/usr/bin/env php
<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/PrintWorkerService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['once', 'sleep::', 'limit::', 'pid-file::', 'status-file::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/print_worker_daemon.php [--once] [--sleep=2] [--limit=50] [--pid-file=PATH] [--status-file=PATH]\n");
    exit(0);
}

$sleepSeconds = max(1, min(30, (int) ($options['sleep'] ?? 2)));
$limit = max(1, min(500, (int) ($options['limit'] ?? 50)));
$pidFile = (string) ($options['pid-file'] ?? (sys_get_temp_dir() . '/posmain-print-worker.pid'));
$statusFile = (string) ($options['status-file'] ?? (sys_get_temp_dir() . '/posmain-print-worker-status.json'));
$once = isset($options['once']);
$running = true;

$pidHandle = @fopen($pidFile, 'c+');
if (!is_resource($pidHandle) || !@flock($pidHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "يوجد عامل طباعة يعمل بالفعل على هذا الجهاز.\n");
    exit(2);
}
ftruncate($pidHandle, 0);
fwrite($pidHandle, (string) getmypid());
fflush($pidHandle);
@chmod($pidFile, 0600);

if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

$worker = new PrintWorkerService();
$cycles = 0;
$processedTotal = 0;
$failedTotal = 0;

do {
    $cycles++;
    $processed = 0;
    $failed = 0;
    $lastError = null;
    try {
        $conn = posmain_db_connect();
        for ($index = 0; $index < $limit; $index++) {
            $job = $worker->processNext($conn);
            if ($job === null) {
                break;
            }
            $processed++;
            if (($job['status'] ?? '') === 'failed') {
                $failed++;
            }
        }
        $conn->close();
    } catch (Throwable $exception) {
        $failed++;
        $lastError = $exception->getMessage();
        error_log('POSMAIN_PRINT_WORKER ' . $lastError);
    }
    $processedTotal += $processed;
    $failedTotal += $failed;
    printWorkerWriteStatus($statusFile, [
        'service' => 'print_worker',
        'running' => $running,
        'pid' => getmypid(),
        'cycles' => $cycles,
        'last_cycle_processed' => $processed,
        'processed_total' => $processedTotal,
        'failed_total' => $failedTotal,
        'last_error' => $lastError,
        'updated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    if (!$once && $running) {
        sleep($sleepSeconds);
    }
} while (!$once && $running);

@flock($pidHandle, LOCK_UN);
fclose($pidHandle);
@unlink($pidFile);
exit(0);

function printWorkerWriteStatus(string $path, array $status): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }
    $json = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $temporary = $path . '.tmp.' . getmypid();
    if (is_string($json) && @file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) !== false) {
        @chmod($temporary, 0600);
        @rename($temporary, $path);
    }
}
