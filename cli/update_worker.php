<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../api/admin/updates/_bootstrap.php';
require_once __DIR__ . '/../classes/Updates/UpdateOrchestrator.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['job-id:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php cli/update_worker.php --job-id=upd_YYYYMMDD_HHMMSS_abcdef\n");
    exit(0);
}

$jobId = trim((string) ($options['job-id'] ?? ''));
if ($jobId === '') {
    fwrite(STDERR, "--job-id is required.\n");
    exit(1);
}

try {
    $orchestrator = new PosmainUpdateOrchestrator();
    $job = $orchestrator->run($jobId);
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'job_id' => $job['id'] ?? $jobId,
        'status' => $job['status'] ?? 'unknown',
        'phase' => $job['phase'] ?? 'unknown',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
