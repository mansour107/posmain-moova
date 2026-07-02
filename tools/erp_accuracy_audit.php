<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Items/ErpUnitConversionAuditService.php';
require_once __DIR__ . '/../classes/Accounting/TaxRoundingPolicy.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['limit:', 'json', 'dry-run', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/erp_accuracy_audit.php [--limit=500] [--json] [--dry-run]\n");
    exit(0);
}

$limit = max(1, min(5000, (int) ($options['limit'] ?? 500)));
$dryRun = isset($options['dry-run']);

try {
    $conn = posmain_db_connect();
    $audit = new ErpUnitConversionAuditService();
    $unitReport = $audit->classifyItemUnits($conn, $limit);
    $movementMismatches = $audit->findRawFactorMovementMismatches($conn, min(200, $limit));
    $conn->close();

    $result = [
        'ok' => true,
        'dry_run' => $dryRun,
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'tax_enabled' => TaxRoundingPolicy::isEnabled(),
        'unit_classification' => $unitReport,
        'movement_factor_mismatches' => $movementMismatches,
        'movement_mismatch_count' => count($movementMismatches),
    ];
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'error' => $exception->getMessage(),
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    fwrite(STDOUT, 'ERP accuracy audit' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL);
    fwrite(STDOUT, 'Unit rows: ' . json_encode($result['unit_classification']['summary'] ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    fwrite(STDOUT, 'Movement mismatches: ' . (int) ($result['movement_mismatch_count'] ?? 0) . PHP_EOL);
}

exit(($result['ok'] ?? false) ? 0 : 2);
