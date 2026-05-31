<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Inventory/InventoryHistoricalMigrationService.php';

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
    inventoryRebuildBalancesUsage();
    exit(isset($options['help']) ? 0 : 1);
}

$filters = inventoryRebuildBalancesFilters($options);
$backupFile = trim((string) ($options['backup-file'] ?? ''));
$conn = null;
$connected = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = posmain_db_connect();
    $connected = true;
    $service = new InventoryHistoricalMigrationService();
    if ($apply) {
        if ($backupFile === '' || !is_file($backupFile) || !is_readable($backupFile) || filesize($backupFile) < 1) {
            $result = [
                'ok' => false,
                'mode' => 'apply',
                'summary' => [
                    'derived_balance_rows' => 0,
                    'difference_count' => 0,
                    'cost_difference_count' => 0,
                    'last_movement_difference_count' => 0,
                    'missing_balance_count' => 0,
                    'rebuild_candidate_count' => 0,
                    'dry_run_only' => false,
                ],
                'rebuilt_balances' => [],
                'blockers' => ['readable_database_backup_file_required_for_balance_rebuild_apply'],
            ];
        } else {
            $result = $service->applyBalanceRebuild($conn, $filters);
        }
    } elseif ($rehearse) {
        $result = $service->rehearseBalanceRebuild($conn, $filters);
    } else {
        $result = $service->rebuildBalancesPlan($conn, $filters);
        $result['mode'] = 'dry_run';
    }
    $result['generated_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    $result['filters'] = $filters;
    $conn->close();
} catch (Throwable $exception) {
    if ($conn instanceof mysqli) {
        $conn->close();
    }
    $result = [
        'mode' => $apply ? 'apply' : ($rehearse ? 'rehearse' : 'dry_run'),
        'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'filters' => $filters,
        'summary' => [
            'derived_balance_rows' => 0,
            'difference_count' => 0,
            'cost_difference_count' => 0,
            'last_movement_difference_count' => 0,
            'missing_balance_count' => 0,
            'rebuild_candidate_count' => 0,
            'dry_run_only' => !$apply,
        ],
        'rows' => [],
        'rebuilt_balances' => [],
        'rehearsed_balances' => [],
        'blockers' => [$connected ? 'inventory_rebuild_execution_failed' : 'inventory_rebuild_database_unreachable'],
        'error' => $exception->getMessage(),
    ];
}

if (isset($options['json'])) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    inventoryRebuildBalancesPrintHuman($result);
}

exit(empty($result['blockers']) ? 0 : 2);

function inventoryRebuildBalancesUsage(): void
{
    fwrite(STDOUT, "Usage: php tools/inventory_rebuild_balances.php --dry-run [--tenant=0] [--branch=0] [--store=0] [--item=123] [--limit=1000] [--json]\n");
    fwrite(STDOUT, "Rehearse: php tools/inventory_rebuild_balances.php --rehearse [--tenant=0] [--branch=0] [--store=0] [--item=123] [--limit=1000] [--json]\n");
    fwrite(STDOUT, "Apply: php tools/inventory_rebuild_balances.php --apply --backup-file=/absolute/path/to/recent.sql [--tenant=0] [--branch=0] [--store=0] [--item=123] [--limit=1000] [--json]\n");
    fwrite(STDOUT, "\n");
    fwrite(STDOUT, "Compares, rehearses, or rebuilds inventory_item_balances from inventory_movements. Rehearse runs inside a rolled-back transaction. Apply requires a readable backup file.\n");
}

function inventoryRebuildBalancesFilters(array $options): array
{
    return [
        'pos_tenant' => inventoryRebuildBalancesIntOption($options, 'tenant', 0),
        'pos_branch' => inventoryRebuildBalancesIntOption($options, 'branch', 0),
        'store_id' => inventoryRebuildBalancesIntOption($options, 'store', 0),
        'item_id' => inventoryRebuildBalancesIntOption($options, 'item', 0),
        'limit' => max(1, min(5000, inventoryRebuildBalancesIntOption($options, 'limit', 1000))),
    ];
}

function inventoryRebuildBalancesIntOption(array $options, string $name, int $default): int
{
    if (!isset($options[$name])) {
        return $default;
    }

    return max(0, (int) $options[$name]);
}

function inventoryRebuildBalancesPrintHuman(array $result): void
{
    $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
    fwrite(STDOUT, "Inventory balance rebuild " . (string) ($result['mode'] ?? 'dry_run') . "\n");
    fwrite(STDOUT, '- derived balance rows: ' . (int) ($summary['derived_balance_rows'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- quantity differences: ' . (int) ($summary['difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- cost differences: ' . (int) ($summary['cost_difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- last movement differences: ' . (int) ($summary['last_movement_difference_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- missing balances: ' . (int) ($summary['missing_balance_count'] ?? 0) . PHP_EOL);
    fwrite(STDOUT, '- rebuild candidates: ' . (int) ($summary['rebuild_candidate_count'] ?? 0) . PHP_EOL);
    if (($result['mode'] ?? '') === 'rehearse') {
        fwrite(STDOUT, '- rehearsed balances: ' . (int) ($summary['rehearsed_count'] ?? 0) . PHP_EOL);
    } elseif (($result['mode'] ?? '') === 'apply') {
        fwrite(STDOUT, '- rebuilt balances: ' . (int) ($summary['rebuilt_count'] ?? 0) . PHP_EOL);
    }

    if (!empty($result['blockers'])) {
        fwrite(STDOUT, "- blockers:\n");
        foreach ($result['blockers'] as $blocker) {
            fwrite(STDOUT, '  - ' . (string) $blocker . PHP_EOL);
        }
    }
}
