<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryAccountingReconciliationService.php';
require_once __DIR__ . '/../classes/Inventory/InventoryAccountingReconciliationAcceptanceService.php';

$options = getopt('', [
    'tenant::',
    'branch::',
    'store::',
    'limit::',
    'acceptance-file:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/inventory_accounting_reconciliation.php [--tenant=0] [--branch=0] [--store=3] [--limit=100] [--acceptance-file=/absolute/path/to/accepted-accounting.json] [--json]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Read-only accountant review for inventory movements vs linked journal entries.\n");
    fwrite(STDOUT, "Accepted accounting reconciliation files must match the current problem row exactly; they do not repair data or create journals.\n");
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = posmain_db_connect();
try {
    $filters = [
        'pos_tenant' => isset($options['tenant']) ? (int) $options['tenant'] : -1,
        'pos_branch' => isset($options['branch']) ? (int) $options['branch'] : -1,
        'store_id' => isset($options['store']) ? (int) $options['store'] : -1,
        'limit' => isset($options['limit']) ? (int) $options['limit'] : 100,
    ];
    $result = (new InventoryAccountingReconciliationService())->review($conn, $filters);
    $acceptanceFile = trim((string) ($options['acceptance-file'] ?? ''));
    if ($acceptanceFile !== '') {
        $result = inventoryAccountingReconciliationApplyAcceptance($result, $acceptanceFile);
    }
} finally {
    $conn->close();
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 2);
}

fwrite(STDOUT, 'Inventory accounting reconciliation: ' . (!empty($result['ok']) ? 'READY' : 'NOT READY') . PHP_EOL);
fwrite(STDOUT, 'Problem count: ' . (int) ($result['problem_count'] ?? 0) . PHP_EOL);
fwrite(STDOUT, 'Accepted problems: ' . (int) ($result['accepted_problem_count'] ?? 0) . PHP_EOL);
fwrite(STDOUT, 'Unaccepted problems: ' . (int) ($result['unaccepted_problem_count'] ?? ($result['problem_count'] ?? 0)) . PHP_EOL);
foreach (($result['rows'] ?? []) as $row) {
    fwrite(STDOUT, sprintf(
        "- %s journal=%s movements=%s movement_total=%s debit=%s credit=%s accepted=%s\n",
        (string) ($row['reconciliation_status'] ?? ''),
        (string) ($row['accounting_journal_id'] ?? ''),
        (string) ($row['movement_count'] ?? ''),
        (string) ($row['movement_total'] ?? ''),
        (string) ($row['journal_debit_total'] ?? ''),
        (string) ($row['journal_credit_total'] ?? ''),
        !empty($row['accepted_accounting_reconciliation']) ? 'yes' : 'no'
    ));
}
if (!empty($result['blockers'])) {
    fwrite(STDOUT, "- blockers:\n");
    foreach ($result['blockers'] as $blocker) {
        fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
    }
}

exit(!empty($result['ok']) ? 0 : 2);

function inventoryAccountingReconciliationApplyAcceptance(array $result, string $acceptanceFile): array
{
    $acceptance = (new InventoryAccountingReconciliationAcceptanceService())->loadFile($acceptanceFile);
    $result['acceptance_file'] = $acceptance['file'] ?? $acceptanceFile;

    $blockers = $acceptance['blockers'] ?? [];
    if (!$blockers) {
        $evaluation = (new InventoryAccountingReconciliationAcceptanceService())->evaluate($result['rows'] ?? [], $acceptance['entries'] ?? []);
        $result['rows'] = $evaluation['rows'] ?? ($result['rows'] ?? []);
        $summary = $evaluation['summary'] ?? [];
        $result['accepted_problem_count'] = (int) ($summary['accepted_problem_count'] ?? 0);
        $result['unaccepted_problem_count'] = (int) ($summary['unaccepted_problem_count'] ?? ($result['problem_count'] ?? 0));
        $result['unused_acceptance_count'] = (int) ($summary['unused_acceptance_count'] ?? 0);
        $result['acceptance_entry_count'] = (int) ($summary['acceptance_entry_count'] ?? 0);
        $blockers = $evaluation['blockers'] ?? [];
    }

    $result['blockers'] = array_values(array_unique(array_merge($result['blockers'] ?? [], $blockers)));
    $unaccepted = (int) ($result['unaccepted_problem_count'] ?? ($result['problem_count'] ?? 0));
    $accepted = (int) ($result['accepted_problem_count'] ?? 0);
    $result['ok'] = $unaccepted === 0 && empty($result['blockers']);
    if ($result['ok'] && $accepted > 0) {
        $result['status'] = 'accepted_problems';
    } elseif (!$result['ok'] && $unaccepted > 0) {
        $result['status'] = 'problems_found';
    }

    return $result;
}
