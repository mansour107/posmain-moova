<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Recipe/RecipeReconciliationService.php';
require_once __DIR__ . '/../classes/Inventory/InventoryAccountingReconciliationService.php';
require_once __DIR__ . '/../classes/Inventory/InventoryAccountingReconciliationAcceptanceService.php';
require_once __DIR__ . '/../classes/Inventory/InventoryReconciliationAcceptanceService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'dry-run',
    'apply',
    'backup-file:',
    'acceptance-file:',
    'accounting-acceptance-file:',
    'allow-accepted-reconciliation',
    'allow-accepted-accounting-reconciliation',
    'tenant:',
    'branch:',
    'store:',
    'limit:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    inventoryRetireLegacyTriggersUsage();
    exit(0);
}

$dryRun = isset($options['dry-run']);
$apply = isset($options['apply']);
if ($dryRun === $apply) {
    inventoryRetireLegacyTriggersUsage(STDERR);
    exit(1);
}

$backupFile = trim((string) ($options['backup-file'] ?? ''));
$acceptanceFile = trim((string) ($options['acceptance-file'] ?? ''));
$accountingAcceptanceFile = trim((string) ($options['accounting-acceptance-file'] ?? ''));
$filters = [
    'pos_tenant' => inventoryRetireLegacyTriggersIntOption($options, 'tenant', 0),
    'pos_branch' => inventoryRetireLegacyTriggersIntOption($options, 'branch', 0),
    'store_id' => inventoryRetireLegacyTriggersIntOption($options, 'store', 0),
    'limit' => max(1, min(5000, inventoryRetireLegacyTriggersIntOption($options, 'limit', 5000))),
    'differences_only' => true,
];

$result = inventoryRetireLegacyTriggersPlan(
    $filters,
    $apply,
    $backupFile,
    $acceptanceFile,
    isset($options['allow-accepted-reconciliation']),
    $accountingAcceptanceFile,
    isset($options['allow-accepted-accounting-reconciliation'])
);
if ($apply && !empty($result['ok'])) {
    inventoryRetireLegacyTriggersApply($result);
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    inventoryRetireLegacyTriggersPrint($result);
}

exit(!empty($result['ok']) ? 0 : 2);

function inventoryRetireLegacyTriggersPlan(
    array $filters,
    bool $apply,
    string $backupFile,
    string $acceptanceFile = '',
    bool $allowAcceptedReconciliation = false,
    string $accountingAcceptanceFile = '',
    bool $allowAcceptedAccountingReconciliation = false
): array
{
    $dropStatements = [
        'DROP TRIGGER IF EXISTS update_after_update',
        'DROP TRIGGER IF EXISTS update_balance_trigger',
    ];
    $result = [
        'ok' => false,
        'mode' => $apply ? 'apply' : 'dry_run',
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'filters' => $filters,
        'trigger_names' => [],
        'drop_statements' => $dropStatements,
        'reconciliation' => [
            'difference_count' => 0,
            'accepted_difference_count' => 0,
            'unaccepted_difference_count' => 0,
            'sample_differences' => [],
            'sample_unaccepted_differences' => [],
        ],
        'accounting_reconciliation' => [
            'ok' => false,
            'status' => 'not_checked',
            'problem_count' => 0,
            'accepted_problem_count' => 0,
            'unaccepted_problem_count' => 0,
            'sample_problems' => [],
            'sample_unaccepted_problems' => [],
        ],
        'acceptance_file' => $acceptanceFile,
        'accounting_acceptance_file' => $accountingAcceptanceFile,
        'allow_accepted_reconciliation' => $allowAcceptedReconciliation,
        'allow_accepted_accounting_reconciliation' => $allowAcceptedAccountingReconciliation,
        'required_before_apply' => [
            'database_backup',
            'clean_or_accepted_reconciliation',
            'clean_or_accepted_inventory_accounting_reconciliation',
            'live_inventory_cutover_signoff',
            'browser_operator_qa',
        ],
        'blockers' => [],
    ];

    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $acceptance = ['entries' => [], 'blockers' => []];
        if ($acceptanceFile !== '') {
            $acceptance = (new InventoryReconciliationAcceptanceService())->loadFile($acceptanceFile);
            foreach (($acceptance['blockers'] ?? []) as $blocker) {
                $result['blockers'][] = (string) $blocker;
            }
        }
        $conn = posmain_db_connect();
        $result['trigger_names'] = inventoryRetireLegacyTriggersExisting($conn);

        $rows = (new RecipeReconciliationService())->report($conn, $filters);
        $differenceRows = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['has_difference'])));
        if ($acceptanceFile !== '' && empty($acceptance['blockers'])) {
            $evaluation = (new InventoryReconciliationAcceptanceService())->evaluate($differenceRows, $acceptance['entries'] ?? []);
            $differenceRows = array_values($evaluation['rows']);
            foreach (($evaluation['blockers'] ?? []) as $blocker) {
                $result['blockers'][] = (string) $blocker;
            }
        }
        $unacceptedRows = array_values(array_filter($differenceRows, static fn(array $row): bool => empty($row['accepted_reconciliation'])));
        $acceptedRows = array_values(array_filter($differenceRows, static fn(array $row): bool => !empty($row['accepted_reconciliation'])));

        $result['reconciliation']['difference_count'] = count($differenceRows);
        $result['reconciliation']['accepted_difference_count'] = count($acceptedRows);
        $result['reconciliation']['unaccepted_difference_count'] = count($unacceptedRows);
        $result['reconciliation']['sample_differences'] = array_slice($differenceRows, 0, 5);
        $result['reconciliation']['sample_unaccepted_differences'] = array_slice($unacceptedRows, 0, 5);

        if ($unacceptedRows) {
            $result['blockers'][] = 'inventory_reconciliation_has_differences';
        }
        if ($acceptedRows && !$allowAcceptedReconciliation) {
            $result['blockers'][] = 'accepted_reconciliation_requires_explicit_allow_flag';
        }

        $accountingAcceptance = ['entries' => [], 'blockers' => []];
        $accountingAcceptanceBlockers = [];
        if ($accountingAcceptanceFile !== '') {
            $accountingAcceptance = (new InventoryAccountingReconciliationAcceptanceService())->loadFile($accountingAcceptanceFile);
            foreach (($accountingAcceptance['blockers'] ?? []) as $blocker) {
                $accountingAcceptanceBlockers[] = (string) $blocker;
                $result['blockers'][] = (string) $blocker;
            }
        }
        $accountingReview = (new InventoryAccountingReconciliationService())->review($conn, inventoryRetireLegacyTriggersAccountingFilters($filters));
        $accountingRows = $accountingReview['rows'] ?? [];
        if ($accountingAcceptanceFile !== '' && empty($accountingAcceptance['blockers'])) {
            $accountingEvaluation = (new InventoryAccountingReconciliationAcceptanceService())->evaluate($accountingRows, $accountingAcceptance['entries'] ?? []);
            $accountingRows = $accountingEvaluation['rows'] ?? $accountingRows;
            foreach (($accountingEvaluation['blockers'] ?? []) as $blocker) {
                $accountingAcceptanceBlockers[] = (string) $blocker;
                $result['blockers'][] = (string) $blocker;
            }
        }
        $accountingProblemRows = array_values(array_filter($accountingRows, static function (array $row): bool {
            return (string) ($row['reconciliation_status'] ?? '') !== 'balanced';
        }));
        $acceptedAccountingProblemRows = array_values(array_filter($accountingProblemRows, static fn(array $row): bool => !empty($row['accepted_accounting_reconciliation'])));
        $unacceptedAccountingProblemRows = array_values(array_filter($accountingProblemRows, static fn(array $row): bool => empty($row['accepted_accounting_reconciliation'])));
        $result['accounting_reconciliation'] = [
            'ok' => !empty($accountingReview['ok']) || (!$unacceptedAccountingProblemRows && !$accountingAcceptanceBlockers),
            'status' => (string) ($accountingReview['status'] ?? ''),
            'problem_count' => (int) ($accountingReview['problem_count'] ?? count($accountingProblemRows)),
            'accepted_problem_count' => count($acceptedAccountingProblemRows),
            'unaccepted_problem_count' => count($unacceptedAccountingProblemRows),
            'sample_problems' => array_slice($accountingProblemRows, 0, 5),
            'sample_unaccepted_problems' => array_slice($unacceptedAccountingProblemRows, 0, 5),
        ];
        if (!$unacceptedAccountingProblemRows && $acceptedAccountingProblemRows) {
            $result['accounting_reconciliation']['status'] = 'accepted_problems';
        }
        if ($unacceptedAccountingProblemRows) {
            $result['blockers'][] = 'inventory_accounting_reconciliation_not_ready';
        }
        if ($acceptedAccountingProblemRows && !$allowAcceptedAccountingReconciliation) {
            $result['blockers'][] = 'accepted_inventory_accounting_reconciliation_requires_explicit_allow_flag';
        }

        if ($apply && ($backupFile === '' || !is_file($backupFile) || !is_readable($backupFile) || filesize($backupFile) < 1)) {
            $result['blockers'][] = 'readable_database_backup_file_required_for_trigger_retirement';
        }
        if (!$result['trigger_names']) {
            $result['already_retired'] = true;
        }

        $conn->close();
    } catch (Throwable $exception) {
        $result['blockers'][] = 'inventory_trigger_retirement_database_unreachable';
        $result['error'] = $exception->getMessage();
    }

    $result['blockers'] = array_values(array_unique($result['blockers']));
    $result['ok'] = empty($result['blockers']);

    return $result;
}

function inventoryRetireLegacyTriggersApply(array &$result): void
{
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = posmain_db_connect();
        foreach ($result['drop_statements'] as $statement) {
            $conn->query($statement);
        }
        $result['applied'] = true;
        $result['trigger_names_after_apply'] = inventoryRetireLegacyTriggersExisting($conn);
        $conn->close();
    } catch (Throwable $exception) {
        $result['ok'] = false;
        $result['applied'] = false;
        $result['blockers'][] = 'inventory_trigger_retirement_apply_failed';
        $result['error'] = $exception->getMessage();
    }
}

function inventoryRetireLegacyTriggersExisting(mysqli $conn): array
{
    $stmt = $conn->prepare("
SELECT TRIGGER_NAME
FROM INFORMATION_SCHEMA.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND EVENT_OBJECT_TABLE = 'fat_details'
  AND TRIGGER_NAME IN ('update_after_update', 'update_balance_trigger')
ORDER BY TRIGGER_NAME");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return array_map(static fn(array $row): string => (string) $row['TRIGGER_NAME'], $rows);
}

function inventoryRetireLegacyTriggersAccountingFilters(array $filters): array
{
    $accountingFilters = [
        'pos_tenant' => (int) ($filters['pos_tenant'] ?? 0),
        'pos_branch' => (int) ($filters['pos_branch'] ?? 0),
        'limit' => (int) ($filters['limit'] ?? 5000),
    ];
    $storeId = (int) ($filters['store_id'] ?? 0);
    $accountingFilters['store_id'] = $storeId > 0 ? $storeId : -1;

    return $accountingFilters;
}

function inventoryRetireLegacyTriggersIntOption(array $options, string $name, int $default): int
{
    if (!isset($options[$name])) {
        return $default;
    }

    return max(0, (int) $options[$name]);
}

function inventoryRetireLegacyTriggersUsage($stream = null): void
{
    $stream = $stream ?: STDOUT;
    fwrite($stream, "Usage: php tools/inventory_retire_legacy_triggers.php --dry-run [--tenant=0] [--branch=0] [--store=0] [--limit=5000] [--acceptance-file=/absolute/path/to/accepted.json] [--allow-accepted-reconciliation] [--accounting-acceptance-file=/absolute/path/to/accepted-accounting.json] [--allow-accepted-accounting-reconciliation] [--json]\n");
    fwrite($stream, "Apply: php tools/inventory_retire_legacy_triggers.php --apply --backup-file=/absolute/path/to/recent.sql [--acceptance-file=/absolute/path/to/accepted.json] [--allow-accepted-reconciliation] [--accounting-acceptance-file=/absolute/path/to/accepted-accounting.json] [--allow-accepted-accounting-reconciliation] [--json]\n");
    fwrite($stream, "Drops legacy fat_details stock triggers only after clean or exactly accepted inventory reconciliation, clean or exactly accepted inventory accounting reconciliation with explicit allow flags, and a readable backup file.\n");
    fwrite($stream, "Store 0 means all stores for inventory and accounting reconciliation gates; pass a positive --store only for a scoped rehearsal.\n");
}

function inventoryRetireLegacyTriggersPrint(array $result): void
{
    fwrite(STDOUT, 'Inventory legacy trigger retirement: ' . (!empty($result['ok']) ? 'READY' : 'BLOCKED') . PHP_EOL);
    fwrite(STDOUT, '- mode: ' . (string) ($result['mode'] ?? '') . PHP_EOL);
    fwrite(STDOUT, '- triggers: ' . implode(', ', $result['trigger_names'] ?? []) . PHP_EOL);
    fwrite(STDOUT, '- reconciliation differences: ' . (int) ($result['reconciliation']['difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- accepted differences: ' . (int) ($result['reconciliation']['accepted_difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- unaccepted differences: ' . (int) ($result['reconciliation']['unaccepted_difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- accounting reconciliation: ' . (string) ($result['accounting_reconciliation']['status'] ?? '') . ', problems=' . (int) ($result['accounting_reconciliation']['problem_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- accepted accounting problems: ' . (int) ($result['accounting_reconciliation']['accepted_problem_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- unaccepted accounting problems: ' . (int) ($result['accounting_reconciliation']['unaccepted_problem_count'] ?? 0) . PHP_EOL);
    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }
    fwrite(STDOUT, "- planned SQL:\n");
    foreach (($result['drop_statements'] ?? []) as $statement) {
        fwrite(STDOUT, '  - ' . (string) $statement . ';' . PHP_EOL);
    }
}
