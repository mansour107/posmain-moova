<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/SchemaReadinessGuard.php';
require_once __DIR__ . '/../classes/Financial/FinancialLegacyRepairService.php';

$options = getopt('', ['dry-run', 'apply', 'manifest-hash:', 'backup-file:', 'help']);
$dryRun = isset($options['dry-run']);
$apply = isset($options['apply']);
if (PHP_SAPI !== 'cli' || isset($options['help']) || $dryRun === $apply) {
    fwrite(STDOUT, "Usage: php tools/repair_legacy_financial_data.php --dry-run\nApply: php tools/repair_legacy_financial_data.php --apply --manifest-hash=SHA256 --backup-file=/absolute/backup.sql\n");
    exit(isset($options['help']) ? 0 : 1);
}
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    (new SyncSchemaReadinessGuard())->assertReady($conn);
    $service = new FinancialLegacyRepairService();
    $result = $dryRun
        ? $service->plan($conn)
        : $service->apply($conn, (string) ($options['manifest-hash'] ?? ''), (string) ($options['backup-file'] ?? ''));
    $conn->close();
    $result['ok'] = true;
    $result['mode'] = $dryRun ? 'dry-run' : 'apply';
    $exit = 0;
} catch (Throwable $exception) {
    $result = ['ok' => false, 'error' => $exception->getMessage()];
    $exit = 2;
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($exit);
