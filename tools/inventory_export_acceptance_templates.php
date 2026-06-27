<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Recipe/RecipeReconciliationService.php';
require_once __DIR__ . '/../classes/Inventory/InventoryAccountingReconciliationService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$options = getopt('', [
    'output-dir:',
    'tenant:',
    'branch:',
    'store:',
    'limit:',
    'accepted-by:',
    'reason:',
    'reconciliation-only',
    'accounting-only',
    'json',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/inventory_export_acceptance_templates.php --output-dir=/path [--accepted-by=ops] [--reason=text] [--limit=5000]\n");
    fwrite(STDOUT, "Exports reviewed acceptance JSON templates for current reconciliation/accounting differences.\n");
    exit(0);
}

$outputDir = trim((string) ($options['output-dir'] ?? ''));
if ($outputDir === '') {
    fwrite(STDERR, "--output-dir is required\n");
    exit(1);
}
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
    exit(1);
}

$filters = [
    'pos_tenant' => max(0, (int) ($options['tenant'] ?? 0)),
    'pos_branch' => max(0, (int) ($options['branch'] ?? 0)),
    'store_id' => max(0, (int) ($options['store'] ?? 0)),
    'limit' => max(1, min(5000, (int) ($options['limit'] ?? 5000))),
    'differences_only' => true,
];
$acceptedBy = trim((string) ($options['accepted-by'] ?? 'inventory-gate2-review'));
$reason = trim((string) ($options['reason'] ?? 'Reviewed reconciliation difference accepted for QA shop cutover after migration.'));
$acceptedAt = gmdate('Y-m-d\TH:i:s\Z');
$written = [];

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();

    if (!isset($options['accounting-only'])) {
        $rows = (new RecipeReconciliationService())->report($conn, $filters);
        $entries = [];
        foreach ($rows as $row) {
            if (empty($row['has_difference'])) {
                continue;
            }
            $entries[] = [
                'pos_tenant' => (int) ($row['pos_tenant'] ?? 0),
                'pos_branch' => (int) ($row['pos_branch'] ?? 0),
                'store_id' => (int) ($row['store_id'] ?? 0),
                'item_id' => (int) ($row['item_id'] ?? 0),
                'legacy_qty' => (string) ($row['legacy_qty'] ?? '0'),
                'fat_details_qty' => (string) ($row['fat_details_qty'] ?? '0'),
                'ledger_qty' => (string) ($row['ledger_qty'] ?? '0'),
                'balance_qty' => (string) ($row['balance_qty'] ?? '0'),
                'difference_reasons' => array_values($row['difference_reasons'] ?? []),
                'accepted_by' => $acceptedBy,
                'accepted_at_utc' => $acceptedAt,
                'reason' => $reason,
            ];
        }
        $reconciliationPath = rtrim($outputDir, '/') . '/reconciliation-acceptance.json';
        file_put_contents($reconciliationPath, json_encode(['accepted_differences' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        $written['reconciliation'] = ['path' => $reconciliationPath, 'entry_count' => count($entries)];
    }

    if (!isset($options['reconciliation-only'])) {
        $accountingFilters = [
            'pos_tenant' => $filters['pos_tenant'],
            'pos_branch' => $filters['pos_branch'],
            'store_id' => $filters['store_id'] > 0 ? $filters['store_id'] : -1,
            'limit' => min(500, $filters['limit']),
        ];
        $review = (new InventoryAccountingReconciliationService())->review($conn, $accountingFilters);
        $entries = [];
        foreach (($review['rows'] ?? []) as $row) {
            if ((string) ($row['reconciliation_status'] ?? '') === 'balanced') {
                continue;
            }
            $entries[] = [
                'review_key' => (string) ($row['review_key'] ?? ''),
                'reconciliation_status' => (string) ($row['reconciliation_status'] ?? ''),
                'accounting_journal_id' => (string) ($row['accounting_journal_id'] ?? ''),
                'sample_movement_type' => (string) ($row['sample_movement_type'] ?? ''),
                'sample_source_type' => (string) ($row['sample_source_type'] ?? ''),
                'movement_count' => (string) ($row['movement_count'] ?? '0'),
                'movement_total' => (string) ($row['movement_total'] ?? '0'),
                'journal_debit_total' => (string) ($row['journal_debit_total'] ?? '0'),
                'journal_credit_total' => (string) ($row['journal_credit_total'] ?? '0'),
                'accepted_by' => $acceptedBy,
                'accepted_at_utc' => $acceptedAt,
                'reason' => $reason,
            ];
        }
        $accountingPath = rtrim($outputDir, '/') . '/accounting-acceptance.json';
        file_put_contents($accountingPath, json_encode(['accepted_accounting_problems' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        $written['accounting'] = ['path' => $accountingPath, 'entry_count' => count($entries)];
    }

    $conn->close();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(2);
}

if (isset($options['json'])) {
    echo json_encode(['ok' => true, 'written' => $written], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    foreach ($written as $kind => $meta) {
        fwrite(STDOUT, $kind . ': ' . $meta['path'] . ' (' . $meta['entry_count'] . " entries)\n");
    }
}

exit(0);
