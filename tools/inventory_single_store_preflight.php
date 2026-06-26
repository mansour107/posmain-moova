<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../includes/pos_default_accounts.php';

/**
 * Phase 0 preflight for single-store cutover.
 *
 * Usage:
 *   php tools/inventory_single_store_preflight.php [--json]
 */

function inventorySingleStorePreflightMain(array $argv): int
{
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = posmain_db_connect();
    } catch (Throwable $exception) {
        fwrite(STDERR, "database connection unavailable: " . $exception->getMessage() . "\n");
        return 1;
    }

    $report = inventorySingleStorePreflightBuildReport($conn);
    $conn->close();
    $json = in_array('--json', $argv, true);

    if ($json) {
        echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        inventorySingleStorePreflightPrintHuman($report);
    }

    return !empty($report['blocking']) ? 2 : 0;
}

function inventorySingleStorePreflightBuildReport(mysqli $conn): array
{
    $operationalId = posmain_operational_store_id($conn);
    $operationalName = '';
    if ($operationalId > 0) {
        $row = $conn->query('SELECT aname FROM acc_head WHERE id = ' . (int) $operationalId . ' LIMIT 1');
        if ($row && ($r = $row->fetch_assoc())) {
            $operationalName = (string) ($r['aname'] ?? '');
        }
    }

    $settings = posmain_settings_row_for_operational_store($conn);
    $report = [
        'single_store_mode' => posmain_single_store_mode_enabled(),
        'operational_store_id' => $operationalId,
        'operational_store_name' => $operationalName,
        'settings_def_pos_store' => (int) ($settings['def_pos_store'] ?? 0),
        'ready' => $operationalId > 0,
        'blocking' => [],
        'warnings' => [],
        'tables' => [],
    ];

    if ($operationalId < 1) {
        $report['blocking'][] = 'def_pos_store is missing or invalid';
        return $report;
    }

    $branchScopes = posmain_inventory_discovered_branch_scopes($conn);
    $report['branch_scopes'] = $branchScopes;

    foreach ($branchScopes as $branchScope) {
        $tenant = (int) ($branchScope['pos_tenant'] ?? 0);
        $branch = (int) ($branchScope['pos_branch'] ?? 0);
        $scopeLabel = "tenant={$tenant}, branch={$branch}";

        $scans = [
            'inventory_item_balances' => [
                'sql' => 'SELECT COUNT(*) AS c FROM inventory_item_balances WHERE pos_tenant = ? AND pos_branch = ? AND store_id <> ? AND (qty_on_hand <> 0 OR qty_reserved <> 0)',
                'types' => 'iii',
                'params' => [$tenant, $branch, $operationalId],
                'label' => 'non-operational balances with stock (' . $scopeLabel . ')',
            ],
            'inventory_item_stock_levels' => [
                'sql' => 'SELECT COUNT(*) AS c FROM inventory_item_stock_levels WHERE pos_tenant = ? AND pos_branch = ? AND store_id <> ? AND COALESCE(is_active, 1) = 1',
                'types' => 'iii',
                'params' => [$tenant, $branch, $operationalId],
                'label' => 'active stock level policies on other stores (' . $scopeLabel . ')',
            ],
            'recipe_availability_cache' => [
                'sql' => 'SELECT COUNT(*) AS c FROM recipe_availability_cache WHERE pos_tenant = ? AND pos_branch = ? AND store_id <> ?',
                'types' => 'iii',
                'params' => [$tenant, $branch, $operationalId],
                'label' => 'availability cache rows on other stores (' . $scopeLabel . ')',
            ],
        ];

        foreach ($scans as $table => $scan) {
            if (!inventorySingleStorePreflightTableExists($conn, $table)) {
                continue;
            }
            $count = inventorySingleStorePreflightScalar($conn, $scan['sql'], $scan['types'], $scan['params']);
            $report['tables'][$table . ':' . $tenant . ':' . $branch] = [
                'count' => $count,
                'label' => $scan['label'],
            ];
            if ($count > 0) {
                $report['warnings'][] = $scan['label'] . " ({$count})";
            }
        }
    }

    $globalScans = [
        'inventory_counts' => [
            'sql' => "SELECT COUNT(*) AS c FROM inventory_counts WHERE store_id <> ? AND status IN ('draft','submitted','approved')",
            'types' => 'i',
            'params' => [$operationalId],
            'label' => 'open inventory counts on other stores',
        ],
        'inventory_transfers' => [
            'sql' => "SELECT COUNT(*) AS c FROM inventory_transfers WHERE (source_store_id <> ? OR destination_store_id <> ?) AND status IN ('draft','submitted','sent','partially_received')",
            'types' => 'ii',
            'params' => [$operationalId, $operationalId],
            'label' => 'open transfers touching other stores',
        ],
        'inventory_purchase_orders' => [
            'sql' => "SELECT COUNT(*) AS c FROM inventory_purchase_orders WHERE destination_store_id <> ? AND status IN ('draft','submitted','approved','partially_received')",
            'types' => 'i',
            'params' => [$operationalId],
            'label' => 'open purchase orders for other stores',
        ],
        'stock_reservations' => [
            'sql' => "SELECT COUNT(*) AS c FROM stock_reservations WHERE store_id <> ? AND status = 'reserved'",
            'types' => 'i',
            'params' => [$operationalId],
            'label' => 'active stock reservations on other stores',
        ],
        'production_batches' => [
            'sql' => "SELECT COUNT(*) AS c FROM production_batches WHERE store_id <> ? AND status = 'draft'",
            'types' => 'i',
            'params' => [$operationalId],
            'label' => 'draft production batches on other stores',
        ],
        'recipe_order_line_usage' => [
            'sql' => "SELECT COUNT(*) AS c FROM recipe_order_line_usage WHERE store_id <> ? AND status IN ('previewed','reserved')",
            'types' => 'i',
            'params' => [$operationalId],
            'label' => 'in-flight recipe usages on other stores',
        ],
    ];

    foreach ($globalScans as $table => $scan) {
        if (!inventorySingleStorePreflightTableExists($conn, $table)) {
            continue;
        }
        $count = inventorySingleStorePreflightScalar($conn, $scan['sql'], $scan['types'], $scan['params']);
        $report['tables'][$table] = [
            'count' => $count,
            'label' => $scan['label'],
        ];
        if ($count > 0 && in_array($table, [
            'inventory_counts',
            'inventory_transfers',
            'inventory_purchase_orders',
            'stock_reservations',
            'production_batches',
            'recipe_order_line_usage',
        ], true)) {
            $report['blocking'][] = $scan['label'] . " ({$count})";
        } elseif ($count > 0) {
            $report['warnings'][] = $scan['label'] . " ({$count})";
        }
    }

    return $report;
}

function inventorySingleStorePreflightTableExists(mysqli $conn, string $table): bool
{
    $safe = preg_replace('/[^a-z0-9_]/i', '', $table);
    $result = $conn->query("SHOW TABLES LIKE '{$safe}'");

    return $result && $result->num_rows > 0;
}

function inventorySingleStorePreflightScalar(mysqli $conn, string $sql, string $types, array $params): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['c'] ?? 0);
}

function inventorySingleStorePreflightPrintHuman(array $report): void
{
    echo "Single-store preflight\n";
    echo "  mode: " . ($report['single_store_mode'] ? 'on' : 'off') . "\n";
    echo "  operational_store: {$report['operational_store_id']} {$report['operational_store_name']}\n";
    echo "  settings.def_pos_store: {$report['settings_def_pos_store']}\n";
    echo "  ready: " . ($report['ready'] ? 'yes' : 'no') . "\n";
    foreach ($report['tables'] as $table => $info) {
        echo "  {$table}: {$info['count']} — {$info['label']}\n";
    }
    if ($report['blocking']) {
        echo "BLOCKING:\n";
        foreach ($report['blocking'] as $line) {
            echo "  - {$line}\n";
        }
    }
    if ($report['warnings']) {
        echo "WARNINGS:\n";
        foreach ($report['warnings'] as $line) {
            echo "  - {$line}\n";
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(inventorySingleStorePreflightMain($argv));
}
