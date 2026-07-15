<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Financial/FinancialCertificationBaselineService.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$options = getopt('', ['dry-run', 'apply', 'cutoff:', 'exceptions-file:', 'approver:', 'manifest-hash:', 'backup-file:', 'help']);
$dryRun = isset($options['dry-run']);
$apply = isset($options['apply']);
if (isset($options['help']) || $dryRun === $apply) {
    fwrite(STDOUT, "Usage: php tools/financial_certification_baseline.php --dry-run --cutoff='YYYY-MM-DD HH:MM:SS' --exceptions-file=/absolute/exceptions.json\n");
    fwrite(STDOUT, "Apply: php tools/financial_certification_baseline.php --apply --cutoff='...' --exceptions-file=/absolute/exceptions.json --approver=name --manifest-hash=SHA256 --backup-file=/absolute/backup.sql\n");
    exit(isset($options['help']) ? 0 : 1);
}

try {
    $file = trim((string) ($options['exceptions-file'] ?? ''));
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException('HISTORICAL_EXCEPTIONS_FILE_REQUIRED');
    }
    $exceptions = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($exceptions)) {
        throw new RuntimeException('HISTORICAL_EXCEPTIONS_INVALID');
    }
    $cutoff = trim((string) ($options['cutoff'] ?? ''));
    $cutoffTimestamp = strtotime($cutoff);
    if ($cutoffTimestamp === false) {
        throw new RuntimeException('FINANCIAL_BASELINE_CUTOFF_INVALID');
    }
    $normalizedCutoff = date('Y-m-d H:i:s', $cutoffTimestamp);
    $service = new FinancialCertificationBaselineService();
    $hash = $service->manifestHash($normalizedCutoff, $exceptions);
    if ($dryRun) {
        $result = ['ok' => true, 'mode' => 'dry-run', 'cutoff_time' => $normalizedCutoff, 'manifest_hash' => $hash, 'historical_exceptions' => $exceptions];
    } else {
        $backup = trim((string) ($options['backup-file'] ?? ''));
        if ($backup === '' || !is_file($backup) || !is_readable($backup) || filesize($backup) < 1) {
            throw new RuntimeException('READABLE_DATABASE_BACKUP_REQUIRED');
        }
        $reviewedHash = strtolower(trim((string) ($options['manifest-hash'] ?? '')));
        if (!hash_equals($hash, $reviewedHash)) {
            throw new RuntimeException('FINANCIAL_BASELINE_MANIFEST_CHANGED');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = posmain_db_connect();
        $result = $service->create($conn, $cutoff, $exceptions, trim((string) ($options['approver'] ?? '')));
        $conn->close();
        $result['ok'] = true;
        $result['mode'] = 'apply';
    }
    $exit = 0;
} catch (Throwable $exception) {
    $result = ['ok' => false, 'error' => $exception->getMessage()];
    $exit = 2;
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($exit);
