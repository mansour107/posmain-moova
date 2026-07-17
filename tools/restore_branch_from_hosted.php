<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/BranchRestoreFromHostedService.php';
require_once __DIR__ . '/../classes/Sync/RestoreEventPhase.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'apply',
    'menu',
    'tables',
    'orders',
    'operational',
    'all',
    'limit:',
    'scope:',
    'backup-file:',
    'max-backup-age-hours::',
    'workers-stopped',
    'worker-pid-file::',
    'dry-run-manifest:',
    'expected-events:',
    'confirm:',
    'receipt-file:',
    'help',
]);

if (isset($options['help'])) {
    restoreBranchFromHostedUsage();
    exit(0);
}

$apply = isset($options['apply']);
$all = isset($options['all']);
$phases = [];
if ($all || isset($options['menu'])) {
    $phases[] = RestoreEventPhase::MENU;
}
if ($all || isset($options['tables'])) {
    $phases[] = RestoreEventPhase::TABLES;
}
if ($all || isset($options['orders'])) {
    $phases[] = RestoreEventPhase::ORDERS;
}
if ($all || isset($options['operational'])) {
    $phases[] = RestoreEventPhase::OPERATIONAL;
}
if ($phases === []) {
    $phases = RestoreEventPhase::all();
}

$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 50;
$scope = trim((string) ($options['scope'] ?? BranchRestoreSafetyGuard::SCOPE_EMPTY));
$receiptFile = trim((string) ($options['receipt-file'] ?? ''));

if ($apply) {
    try {
        restoreBranchAssertReceiptTarget($receiptFile);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = posmain_db_connect();
$config = posmain_app_config();
$service = new BranchRestoreFromHostedService();

try {
    $summary = $service->restore($conn, $config, [
        'apply' => $apply,
        'limit' => $limit,
        'phases' => $phases,
        'scope' => $scope,
        'backup_file' => (string) ($options['backup-file'] ?? ''),
        'max_backup_age_hours' => isset($options['max-backup-age-hours'])
            ? max(0, (int) $options['max-backup-age-hours'])
            : BranchRestoreSafetyGuard::DEFAULT_MAX_BACKUP_AGE_HOURS,
        'workers_stopped' => isset($options['workers-stopped']),
        'worker_pid_file' => (string) ($options['worker-pid-file'] ?? '/run/posmain-branch-worker.pid'),
        'dry_run_manifest' => (string) ($options['dry-run-manifest'] ?? ''),
        'expected_events' => $options['expected-events'] ?? null,
        'confirmation_token' => (string) ($options['confirm'] ?? ''),
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

if ($apply) {
    try {
        restoreBranchWriteReceipt($receiptFile, $summary);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    $summary['receipt_file'] = $receiptFile;
}

echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
$failed = (int) ($summary['failed'] ?? 0) > 0;
$notReconciled = $apply && empty($summary['reconciliation']['ok']);
exit($failed || $notReconciled ? 2 : 0);

function restoreBranchFromHostedUsage($stream = STDOUT): void
{
    fwrite($stream, "Dry run:\n");
    fwrite($stream, "  php tools/restore_branch_from_hosted.php --all [--limit=N]\n\n");
    fwrite($stream, "Guarded empty-branch apply:\n");
    fwrite($stream, "  php tools/restore_branch_from_hosted.php --apply --all --scope=empty --backup-file=/absolute/path/to/fresh.sql --workers-stopped --worker-pid-file=/run/posmain-branch-worker.pid --dry-run-manifest=HASH --expected-events=N --confirm=TOKEN --receipt-file=/absolute/path/to/new-receipt.json\n\n");
    fwrite($stream, "Dry-run is the default and never applies hosted events. Apply supports only all phases into an empty business database.\n");
    fwrite($stream, "The apply command reruns the dry-run, requires generic cloud pull disabled, validates a fresh backup (24 hours by default), refuses an active worker PID, verifies the exact manifest/count/token, and writes a new receipt file.\n");
    fwrite($stream, "Use --max-backup-age-hours=0 only as an explicit operator override. Selected-scope repair is intentionally unavailable until entity-scoped conflict handling exists.\n");
}

function restoreBranchAssertReceiptTarget(string $path): void
{
    if ($path === '') {
        throw new InvalidArgumentException('Restore apply requires --receipt-file=/absolute/path/to/new-receipt.json.');
    }
    if ($path[0] !== DIRECTORY_SEPARATOR) {
        throw new InvalidArgumentException('Restore receipt path must be absolute.');
    }
    if (file_exists($path)) {
        throw new InvalidArgumentException('Restore receipt path already exists; receipts are append-only.');
    }
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new InvalidArgumentException('Restore receipt directory must already exist and be writable.');
    }
}

function restoreBranchWriteReceipt(string $path, array $summary): void
{
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException('Unable to create the restore receipt without overwriting an existing file.');
    }

    try {
        $receipt = [
            'contract' => 'posmain_empty_branch_restore_receipt_v1',
            'recorded_at_utc' => gmdate('c'),
            'branch_uuid' => (string) ($summary['branch_uuid'] ?? ''),
            'cloud_base_url' => (string) ($summary['cloud_base_url'] ?? ''),
            'dry_run' => $summary['dry_run'] ?? null,
            'backup' => $summary['safety']['backup'] ?? null,
            'reconciliation' => $summary['reconciliation'] ?? null,
            'phases' => $summary['phases'] ?? [],
        ];
        $json = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($json) || fwrite($handle, $json . "\n") === false) {
            throw new RuntimeException('Unable to write the restore receipt.');
        }
        fflush($handle);
    } finally {
        fclose($handle);
    }
}
