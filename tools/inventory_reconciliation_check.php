<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../includes/csv_export.php';
require_once __DIR__ . '/../classes/Recipe/RecipeReconciliationService.php';
require_once __DIR__ . '/../classes/Inventory/InventoryReconciliationAcceptanceService.php';
require_once __DIR__ . '/../classes/Items/ErpUnitConversionAuditService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'tenant:',
    'branch:',
    'store:',
    'item:',
    'limit:',
    'acceptance-file:',
    'differences-only',
    'csv',
    'json',
    'help',
]);

if (isset($options['help'])) {
    inventoryReconciliationUsage();
    exit(0);
}

$filters = [
    'pos_tenant' => inventoryReconciliationIntOption($options, 'tenant', 0),
    'pos_branch' => inventoryReconciliationIntOption($options, 'branch', 0),
    'store_id' => inventoryReconciliationIntOption($options, 'store', 0),
    'limit' => max(1, min(5000, inventoryReconciliationIntOption($options, 'limit', 1000))),
    'differences_only' => isset($options['differences-only']),
];
$itemId = inventoryReconciliationIntOption($options, 'item', 0);
if ($itemId > 0) {
    $filters['item_ids'] = [$itemId];
}
$acceptanceFile = trim((string) ($options['acceptance-file'] ?? ''));
$acceptance = ['entries' => [], 'blockers' => []];
if ($acceptanceFile !== '') {
    $acceptance = (new InventoryReconciliationAcceptanceService())->loadFile($acceptanceFile);
}

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if (!empty($acceptance['blockers'])) {
        throw new InvalidArgumentException(implode(',', $acceptance['blockers']));
    }
    $conn = posmain_db_connect();
    $service = new RecipeReconciliationService();
    $rows = $service->report($conn, $filters);
    $conversionMismatches = [];
    $conversionAuditError = '';
    try {
        $conversionAudit = new ErpUnitConversionAuditService();
        $conversionMismatches = $conversionAudit->findRawFactorMovementMismatches($conn, min(100, (int) $filters['limit']));
    } catch (Throwable $conversionException) {
        $conversionAuditError = $conversionException->getMessage();
    }
    $conn->close();

    $result = inventoryReconciliationResult($filters, $rows, $acceptance);
    $result['conversion_factor_mismatches'] = $conversionMismatches;
    $result['conversion_factor_mismatch_count'] = count($conversionMismatches);
    if ($conversionAuditError !== '') {
        $result['ok'] = false;
        $result['conversion_factor_audit_error'] = $conversionAuditError;
        $result['blockers'][] = 'inventory_unit_conversion_audit_unavailable';
    } elseif ($conversionMismatches) {
        $result['ok'] = false;
        $result['blockers'][] = 'inventory_unit_conversion_factor_mismatch';
    }
    $result['blockers'] = array_values(array_unique($result['blockers']));
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'filters' => $filters,
        'summary' => [
            'row_count' => 0,
            'difference_count' => 0,
            'accepted_difference_count' => 0,
            'unaccepted_difference_count' => 0,
            'reason_counts' => [],
        ],
        'rows' => [],
        'blockers' => !empty($acceptance['blockers']) ? $acceptance['blockers'] : ['inventory_reconciliation_database_unreachable'],
        'error' => $exception->getMessage(),
    ];
}

if (isset($options['csv'])) {
    inventoryReconciliationPrintCsv($result);
} elseif (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    inventoryReconciliationPrintHuman($result);
}

exit(empty($result['blockers']) ? 0 : 2);

function inventoryReconciliationUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/inventory_reconciliation_check.php [--tenant=0] [--branch=0] [--store=0] [--item=123] [--limit=1000] [--acceptance-file=/absolute/path/to/accepted.json] [--differences-only] [--csv] [--json]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Read-only comparison of myitems.itmqty, fat_details movement balance, inventory_movements, and inventory_item_balances. Store 0 means all stores; pass a positive --store for a single-store check.\n");
    fwrite(STDOUT, "Accepted reconciliation files must match item, scope, reasons, and quantities exactly; they do not repair data.\n");
}

function inventoryReconciliationIntOption(array $options, string $name, int $default): int
{
    if (!isset($options[$name])) {
        return $default;
    }

    return max(0, (int) $options[$name]);
}

function inventoryReconciliationResult(array $filters, array $rows, array $acceptance = []): array
{
    $differenceCount = 0;
    $reasonCounts = [];
    foreach ($rows as $row) {
        if (!empty($row['has_difference'])) {
            $differenceCount++;
        }
        foreach (($row['difference_reasons'] ?? []) as $reason) {
            $reason = (string) $reason;
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
        }
    }
    ksort($reasonCounts);

    $acceptanceSummary = [
        'accepted_difference_count' => 0,
        'unaccepted_difference_count' => $differenceCount,
        'unused_acceptance_count' => 0,
        'acceptance_entry_count' => 0,
    ];
    $acceptanceBlockers = [];
    $acceptanceFile = (string) ($acceptance['file'] ?? '');
    if ($acceptanceFile !== '') {
        $evaluation = (new InventoryReconciliationAcceptanceService())->evaluate($rows, $acceptance['entries'] ?? []);
        $rows = $evaluation['rows'];
        $acceptanceSummary = array_merge($acceptanceSummary, $evaluation['summary'] ?? []);
        $acceptanceBlockers = $evaluation['blockers'] ?? [];
    }
    $unacceptedCount = (int) ($acceptanceSummary['unaccepted_difference_count'] ?? $differenceCount);

    $blockers = $acceptanceBlockers;
    if ($unacceptedCount > 0) {
        $blockers[] = 'inventory_reconciliation_unaccepted_differences';
    }

    return [
        'ok' => $unacceptedCount === 0 && empty($acceptanceBlockers),
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'filters' => $filters,
        'acceptance_file' => $acceptanceFile,
        'summary' => [
            'row_count' => count($rows),
            'difference_count' => $differenceCount,
            'accepted_difference_count' => (int) ($acceptanceSummary['accepted_difference_count'] ?? 0),
            'unaccepted_difference_count' => $unacceptedCount,
            'unused_acceptance_count' => (int) ($acceptanceSummary['unused_acceptance_count'] ?? 0),
            'acceptance_entry_count' => (int) ($acceptanceSummary['acceptance_entry_count'] ?? 0),
            'reason_counts' => $reasonCounts,
        ],
        'rows' => $rows,
        'blockers' => array_values(array_unique($blockers)),
    ];
}

function inventoryReconciliationPrintHuman(array $result): void
{
    $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
    fwrite(STDOUT, 'Inventory reconciliation: ' . (!empty($result['ok']) ? 'CLEAN' : 'DIFFERENCES FOUND') . PHP_EOL);
    fwrite(STDOUT, '- rows: ' . (int) ($summary['row_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- differences: ' . (int) ($summary['difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- accepted differences: ' . (int) ($summary['accepted_difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- unaccepted differences: ' . (int) ($summary['unaccepted_difference_count'] ?? 0) . PHP_EOL);

    $reasonCounts = is_array($summary['reason_counts'] ?? null) ? $summary['reason_counts'] : [];
    if ($reasonCounts) {
        fwrite(STDOUT, "- reasons:\n");
        foreach ($reasonCounts as $reason => $count) {
            fwrite(STDOUT, '  - ' . $reason . ': ' . (int) $count . PHP_EOL);
        }
    }

    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }
}

function inventoryReconciliationPrintCsv(array $result): void
{
    $out = fopen('php://output', 'w');
    posmain_csv_write_row($out, [
        'pos_tenant',
        'pos_branch',
        'store_id',
        'item_id',
        'item_barcode',
        'item_name',
        'item_type',
        'track_stock',
        'legacy_qty',
        'fat_details_qty',
        'ledger_qty',
        'balance_qty',
        'legacy_vs_fat_difference',
        'ledger_vs_balance_difference',
        'legacy_vs_ledger_difference',
        'difference_reason',
        'accepted',
        'recommended_action',
        'last_movement_id',
    ]);

    foreach (($result['rows'] ?? []) as $row) {
        posmain_csv_write_row($out, posmain_csv_safe_row([
            $row['pos_tenant'] ?? '',
            $row['pos_branch'] ?? '',
            $row['store_id'] ?? '',
            $row['item_id'] ?? '',
            $row['item_barcode'] ?? '',
            $row['item_name'] ?? '',
            $row['item_type'] ?? '',
            $row['track_stock'] ?? '',
            $row['legacy_qty'] ?? '',
            $row['fat_details_qty'] ?? '',
            $row['ledger_qty'] ?? '',
            $row['balance_qty'] ?? '',
            $row['legacy_vs_fat_difference'] ?? '',
            $row['ledger_vs_balance_difference'] ?? '',
            $row['legacy_vs_ledger_difference'] ?? '',
            $row['difference_reason'] ?? '',
            !empty($row['accepted']) ? '1' : '0',
            $row['recommended_action'] ?? '',
            $row['last_movement_id'] ?? '',
        ]));
    }
}
