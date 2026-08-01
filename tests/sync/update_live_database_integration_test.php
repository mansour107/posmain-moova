<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../classes/Updates/UpdateDatabaseCoordinator.php';

if ((string) getenv('POSMAIN_ALLOW_UPDATE_INTEGRATION') !== '1') {
    fwrite(STDERR, "Set POSMAIN_ALLOW_UPDATE_INTEGRATION=1 to mutate the configured test databases.\n");
    exit(2);
}

$jobId = 'upd_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
$coordinator = new PosmainUpdateDatabaseCoordinator();
$backupSet = null;

try {
    $preflight = $coordinator->preflight();
    $before = $coordinator->plan();
    $backupSet = $coordinator->backupAll($jobId);
    $applied = $coordinator->applyAll($backupSet);
    $verification = $coordinator->verifyAllFresh();
    if (empty($verification['ok'])) {
        throw new RuntimeException('LIVE_UPDATE_DATABASE_VERIFICATION_FAILED');
    }
    if (!$coordinator->deleteBackupSet($backupSet)) {
        throw new RuntimeException('LIVE_UPDATE_BACKUP_CLEANUP_FAILED');
    }

    echo json_encode([
        'ok' => true,
        'job_id' => $jobId,
        'preflight_target_count' => $preflight['target_count'] ?? null,
        'pending_before' => $before['pending_count'] ?? null,
        'applied_target_count' => count($applied['targets'] ?? []),
        'verified_target_count' => $verification['target_count'] ?? null,
        'backup_deleted' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    $restore = null;
    if (is_array($backupSet)) {
        try {
            $restore = $coordinator->restoreAll($backupSet);
        } catch (Throwable $restoreException) {
            $restore = ['ok' => false, 'error' => $restoreException->getMessage()];
        }
    }
    fwrite(STDERR, json_encode([
        'ok' => false,
        'job_id' => $jobId,
        'error' => $exception->getMessage(),
        'restore' => $restore,
        'backup_directory' => is_array($backupSet) ? ($backupSet['directory'] ?? null) : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
