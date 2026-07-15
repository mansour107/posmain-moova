<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/LegacyClosedOrdersRetirementService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', ['dry-run', 'archive', 'drop', 'backup-file:', 'approved-manifest:', 'manifest-hash:', 'json', 'help']);
$modes = (int) isset($options['dry-run']) + (int) isset($options['archive']) + (int) isset($options['drop']);
if (isset($options['help']) || $modes !== 1) {
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php tools/retire_closed_orders.php --dry-run [--json]\n");
    fwrite(STDOUT, "  php tools/retire_closed_orders.php --archive --backup-file=/absolute/backup.sql [--approved-manifest=/absolute/approved.json] [--json]\n");
    fwrite(STDOUT, "  php tools/retire_closed_orders.php --drop --backup-file=/absolute/backup.sql --manifest-hash=SHA256 [--json]\n");
    exit(isset($options['help']) ? 0 : 1);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $service = new LegacyClosedOrdersRetirementService();
    if (isset($options['dry-run'])) {
        $result = $service->inspect($conn);
        $result['mode'] = 'dry-run';
    } elseif (isset($options['archive'])) {
        $approved = [];
        $manifestFile = trim((string) ($options['approved-manifest'] ?? ''));
        if ($manifestFile !== '') {
            if (!is_file($manifestFile) || !is_readable($manifestFile)) {
                throw new RuntimeException('APPROVED_MANIFEST_NOT_READABLE');
            }
            $manifest = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
            $approved = is_array($manifest['approved_row_hashes'] ?? null) ? $manifest['approved_row_hashes'] : [];
            if (trim((string) ($manifest['approved_by'] ?? '')) === '' || trim((string) ($manifest['approved_at'] ?? '')) === '') {
                throw new RuntimeException('APPROVED_MANIFEST_APPROVER_AND_TIMESTAMP_REQUIRED');
            }
        }
        $result = $service->archive($conn, trim((string) ($options['backup-file'] ?? '')), $approved);
        $result['mode'] = 'archive';
    } else {
        $result = $service->drop(
            $conn,
            trim((string) ($options['backup-file'] ?? '')),
            strtolower(trim((string) ($options['manifest-hash'] ?? '')))
        );
        $result['mode'] = 'drop';
    }
    $conn->close();
    $result['ok'] = true;
    $exitCode = 0;
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    $result = ['ok' => false, 'error' => $exception->getMessage()];
    $exitCode = 2;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($exitCode);
