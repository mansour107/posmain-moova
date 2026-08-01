<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../api/admin/updates/_bootstrap.php';
require_once __DIR__ . '/../classes/Updates/UpdateOrchestrator.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This recovery worker must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['job-id:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php cli/update_recovery_worker.php --job-id=upd_YYYYMMDD_HHMMSS_abcdef\n");
    exit(0);
}

$jobId = trim((string) ($options['job-id'] ?? ''));
if ($jobId === '') {
    fwrite(STDERR, "--job-id is required.\n");
    exit(1);
}

try {
    $job = (new PosmainUpdateOrchestrator())->recover($jobId);
    fwrite(STDOUT, json_encode([
        'ok' => ($job['recovery_status'] ?? '') !== 'recovery_failed',
        'job_id' => $job['id'] ?? $jobId,
        'status' => $job['status'] ?? 'unknown',
        'recovery_status' => $job['recovery_status'] ?? 'unknown',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(($job['recovery_status'] ?? '') === 'recovery_failed' ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
