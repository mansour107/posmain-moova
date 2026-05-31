<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryReconciliationRepairService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', [
    'dry-run',
    'rehearse',
    'apply',
    'backup-file:',
    'tenant:',
    'branch:',
    'store:',
    'item:',
    'limit:',
    'json',
    'help',
]);

$dryRun = isset($options['dry-run']);
$rehearse = isset($options['rehearse']);
$apply = isset($options['apply']);
if (isset($options['help']) || (($dryRun ? 1 : 0) + ($rehearse ? 1 : 0) + ($apply ? 1 : 0)) !== 1) {
    inventoryReconciliationRepairUsage();
    exit(isset($options['help']) ? 0 : 1);
}

$filters = [
    'pos_tenant' => inventoryReconciliationRepairIntOption($options, 'tenant', 0),
    'pos_branch' => inventoryReconciliationRepairIntOption($options, 'branch', 0),
    'store_id' => inventoryReconciliationRepairIntOption($options, 'store', 0),
    'limit' => max(1, min(5000, inventoryReconciliationRepairIntOption($options, 'limit', 1000))),
];
$itemId = inventoryReconciliationRepairIntOption($options, 'item', 0);
if ($itemId > 0) {
    $filters['item_ids'] = [$itemId];
}

$backupFile = trim((string) ($options['backup-file'] ?? ''));
$conn = null;
$connected = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $connected = true;
    $service = new InventoryReconciliationRepairService();
    if ($apply) {
        if ($backupFile === '' || !is_file($backupFile) || !is_readable($backupFile) || filesize($backupFile) < 1) {
            $result = [
                'ok' => false,
                'mode' => 'apply',
                'summary' => [
                    'difference_count' => 0,
                    'repair_candidate_count' => 0,
                    'unhandled_difference_count' => 0,
                    'repaired_count' => 0,
                    'dry_run_only' => false,
                ],
                'repaired_items' => [],
                'blockers' => ['readable_database_backup_file_required_for_reconciliation_repair_apply'],
            ];
        } else {
            $result = $service->applyMirrorRepair($conn, $filters);
        }
    } elseif ($rehearse) {
        $result = $service->rehearseMirrorRepair($conn, $filters);
    } else {
        $result = $service->mirrorRepairPlan($conn, $filters);
    }
    $result['checked_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    $result['filters'] = $filters;
    $conn->close();
} catch (Throwable $exception) {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
    $result = [
        'ok' => false,
        'mode' => $apply ? 'apply' : ($rehearse ? 'rehearse' : 'dry_run'),
        'checked_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'filters' => $filters,
        'summary' => [
            'difference_count' => 0,
            'repair_candidate_count' => 0,
            'unhandled_difference_count' => 0,
            'dry_run_only' => !$apply,
        ],
        'repair_candidates' => [],
        'repaired_items' => [],
        'rehearsed_items' => [],
        'unhandled_differences' => [],
        'blockers' => [$connected ? 'inventory_reconciliation_repair_execution_failed' : 'inventory_reconciliation_repair_database_unreachable'],
        'error' => $exception->getMessage(),
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    inventoryReconciliationRepairPrint($result);
}

exit(empty($result['blockers']) ? 0 : 2);

function inventoryReconciliationRepairUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/inventory_reconciliation_repair.php --dry-run [--tenant=0] [--branch=0] [--store=0] [--item=123] [--limit=1000] [--json]\n");
    fwrite(STDOUT, "Rehearse: php tools/inventory_reconciliation_repair.php --rehearse [--tenant=0] [--branch=0] [--store=0] [--item=123] [--limit=1000] [--json]\n");
    fwrite(STDOUT, "Apply: php tools/inventory_reconciliation_repair.php --apply --backup-file=/absolute/path/to/recent.sql [--tenant=0] [--branch=0] [--store=0] [--item=123] [--limit=1000] [--json]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Plans or repairs only safe myitems.itmqty compatibility-mirror rows where fat_details, inventory_movements, and inventory_item_balances already agree.\n");
}

function inventoryReconciliationRepairIntOption(array $options, string $name, int $default): int
{
    if (!isset($options[$name])) {
        return $default;
    }

    return max(0, (int) $options[$name]);
}

function inventoryReconciliationRepairPrint(array $result): void
{
    $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
    fwrite(STDOUT, 'Inventory reconciliation repair ' . (string) ($result['mode'] ?? 'dry_run') . PHP_EOL);
    fwrite(STDOUT, '- differences: ' . (int) ($summary['difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- repair candidates: ' . (int) ($summary['repair_candidate_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- unhandled differences: ' . (int) ($summary['unhandled_difference_count'] ?? 0) . PHP_EOL);
    if (($result['mode'] ?? '') === 'rehearse') {
        fwrite(STDOUT, '- rehearsed repairs: ' . (int) ($summary['rehearsed_count'] ?? 0) . PHP_EOL);
    } elseif (($result['mode'] ?? '') === 'apply') {
        fwrite(STDOUT, '- applied repairs: ' . (int) ($summary['repaired_count'] ?? 0) . PHP_EOL);
    }
    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }
}
