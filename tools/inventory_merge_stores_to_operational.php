<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../includes/pos_default_accounts.php';
require_once __DIR__ . '/inventory_single_store_preflight.php';

/**
 * Merge multi-store inventory data into the operational store per tenant/branch scope.
 *
 * Usage:
 *   php tools/inventory_merge_stores_to_operational.php [--dry-run|--apply] [--backup-confirmed] [--workflow-action=abort|cancel-open] [--force-cost-policy=zero|negative-net|ignore-zero-cost] [--json]
 */

function inventoryMergeStoresMain(array $argv): int
{
    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = posmain_db_connect();
    } catch (Throwable $exception) {
        fwrite(STDERR, "database connection unavailable: " . $exception->getMessage() . "\n");
        return 1;
    }

    $apply = in_array('--apply', $argv, true);
    $dryRun = !$apply || in_array('--dry-run', $argv, true);
    $json = in_array('--json', $argv, true);
    $backupConfirmed = in_array('--backup-confirmed', $argv, true);
    $workflowAction = inventoryMergeStoresOption($argv, 'workflow-action') ?: 'abort';
    $costPolicy = inventoryMergeStoresOption($argv, 'force-cost-policy') ?: 'default';
    $skipPreflight = in_array('--skip-preflight', $argv, true);

    if ($apply && !$backupConfirmed) {
        fwrite(STDERR, "Refusing --apply without --backup-confirmed (take a DB backup first).\n");
        $conn->close();
        return 1;
    }

    if (!$skipPreflight) {
        $preflight = inventorySingleStorePreflightBuildReport($conn);
        if (!empty($preflight['blocking']) && $workflowAction === 'abort') {
            fwrite(STDERR, "Preflight blocking issues; use --workflow-action=cancel-open or fix manually.\n");
            if ($json) {
                echo json_encode(['ok' => false, 'preflight' => $preflight], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
            } else {
                inventorySingleStorePreflightPrintHuman($preflight);
            }
            $conn->close();
            return 2;
        }
    }

    $operationalId = posmain_operational_store_id($conn);
    if ($operationalId < 1) {
        fwrite(STDERR, "No operational store configured.\n");
        $conn->close();
        return 1;
    }

    $auditPath = __DIR__ . '/../logs/inventory_store_merge_' . date('Ymd_His') . '.jsonl';
    $branchScopes = posmain_inventory_discovered_branch_scopes($conn);
    $summary = [
        'ok' => true,
        'dry_run' => $dryRun,
        'operational_store_id' => $operationalId,
        'workflow_action' => $workflowAction,
        'cost_policy' => $costPolicy,
        'branch_scopes' => $branchScopes,
        'balances' => [],
        'stock_levels' => [],
        'availability_cache_deleted' => 0,
        'workflow_cancelled' => [],
    ];

    try {
        if ($apply && !$dryRun) {
            $conn->begin_transaction();
        }

        if ($workflowAction === 'cancel-open') {
            $summary['workflow_cancelled'] = inventoryMergeStoresCancelOpenWorkflows($conn, $operationalId, $dryRun, $auditPath);
        }

        foreach ($branchScopes as $branchScope) {
            $summary['balances'] = array_merge(
                $summary['balances'],
                inventoryMergeStoresMergeBalances($conn, $branchScope, $operationalId, $dryRun, $costPolicy, $auditPath)
            );
            $summary['stock_levels'] = array_merge(
                $summary['stock_levels'],
                inventoryMergeStoresMergeStockLevels($conn, $branchScope, $operationalId, $dryRun, $auditPath)
            );
            $summary['availability_cache_deleted'] += inventoryMergeStoresPurgeAvailabilityCache($conn, $branchScope, $operationalId, $dryRun);
        }

        if ($apply && !$dryRun) {
            posmain_sync_operational_store_flags($conn);
            $summary['flags_synced'] = true;
            $conn->commit();
        }
    } catch (Throwable $exception) {
        if ($apply && !$dryRun) {
            try {
                $conn->rollback();
            } catch (Throwable $ignored) {
            }
        }
        fwrite(STDERR, 'merge failed: ' . $exception->getMessage() . "\n");
        $conn->close();
        return 1;
    }

    if ($json) {
        echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        echo ($dryRun ? 'DRY-RUN' : 'APPLIED') . " merge to store {$operationalId}\n";
        echo '  branch scopes: ' . count($branchScopes) . "\n";
        echo '  balance items: ' . count($summary['balances']) . "\n";
        echo '  stock level items: ' . count($summary['stock_levels']) . "\n";
        echo '  availability cache rows removed: ' . $summary['availability_cache_deleted'] . "\n";
    }

    $conn->close();

    return 0;
}

function inventoryMergeStoresOption(array $argv, string $name): ?string
{
    foreach ($argv as $arg) {
        if (strpos($arg, '--' . $name . '=') === 0) {
            return substr($arg, strlen('--' . $name . '='));
        }
    }

    return null;
}

function inventoryMergeStoresAudit(string $path, array $entry): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function inventoryMergeStoresCancelOpenWorkflows(mysqli $conn, int $operationalId, bool $dryRun, string $auditPath): array
{
    $cancelled = [];
    $updates = [
        ['inventory_counts', "SELECT COUNT(*) AS c FROM inventory_counts WHERE store_id <> ? AND status IN ('draft','submitted','approved')", "UPDATE inventory_counts SET status = 'cancelled' WHERE store_id <> ? AND status IN ('draft','submitted','approved')", 'i', [$operationalId]],
        ['inventory_transfers', "SELECT COUNT(*) AS c FROM inventory_transfers WHERE (source_store_id <> ? OR destination_store_id <> ?) AND status IN ('draft','submitted','sent','partially_received')", "UPDATE inventory_transfers SET status = 'cancelled' WHERE (source_store_id <> ? OR destination_store_id <> ?) AND status IN ('draft','submitted','sent','partially_received')", 'ii', [$operationalId, $operationalId]],
        ['inventory_purchase_orders', "SELECT COUNT(*) AS c FROM inventory_purchase_orders WHERE destination_store_id <> ? AND status IN ('draft','submitted','approved','partially_received')", "UPDATE inventory_purchase_orders SET status = 'cancelled' WHERE destination_store_id <> ? AND status IN ('draft','submitted','approved','partially_received')", 'i', [$operationalId]],
        ['stock_reservations', "SELECT COUNT(*) AS c FROM stock_reservations WHERE store_id <> ? AND status = 'reserved'", "UPDATE stock_reservations SET status = 'released' WHERE store_id <> ? AND status = 'reserved'", 'i', [$operationalId]],
        ['production_batches', "SELECT COUNT(*) AS c FROM production_batches WHERE store_id <> ? AND status = 'draft'", "UPDATE production_batches SET status = 'cancelled' WHERE store_id <> ? AND status = 'draft'", 'i', [$operationalId]],
        ['recipe_order_line_usage', "SELECT COUNT(*) AS c FROM recipe_order_line_usage WHERE store_id <> ? AND status IN ('previewed','reserved')", "UPDATE recipe_order_line_usage SET status = 'voided' WHERE store_id <> ? AND status IN ('previewed','reserved')", 'i', [$operationalId]],
    ];

    foreach ($updates as [$table, $countSql, $sql, $types, $params]) {
        if (!inventorySingleStorePreflightTableExists($conn, $table)) {
            continue;
        }

        $affected = inventorySingleStorePreflightScalar($conn, $countSql, $types, $params);
        if (!$dryRun && $affected > 0) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
        }

        $cancelled[$table] = $affected;
        inventoryMergeStoresAudit($auditPath, ['action' => 'cancel_open', 'table' => $table, 'dry_run' => $dryRun, 'affected' => $affected]);
    }

    return $cancelled;
}

function inventoryMergeStoresMergeBalances(mysqli $conn, array $branchScope, int $operationalId, bool $dryRun, string $costPolicy, string $auditPath): array
{
    if (!inventorySingleStorePreflightTableExists($conn, 'inventory_item_balances')) {
        return [];
    }

    $tenant = (int) ($branchScope['pos_tenant'] ?? 0);
    $branch = (int) ($branchScope['pos_branch'] ?? 0);
    $stmt = $conn->prepare(
        'SELECT DISTINCT item_id
         FROM inventory_item_balances
         WHERE pos_tenant = ?
           AND pos_branch = ?
           AND store_id <> ?
           AND (qty_on_hand <> 0 OR qty_reserved <> 0)'
    );
    $stmt->bind_param('iii', $tenant, $branch, $operationalId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = (int) $row['item_id'];
    }
    $stmt->close();

    $merged = [];
    foreach ($items as $itemId) {
        $rows = inventoryMergeStoresBalanceRows($conn, $tenant, $branch, $itemId, $operationalId);
        if (!$rows['sources']) {
            continue;
        }

        $operational = $rows['operational'];
        $sumOnHand = (float) ($operational['qty_on_hand'] ?? 0);
        $sumReserved = (float) ($operational['qty_reserved'] ?? 0);
        foreach ($rows['sources'] as $src) {
            $sumOnHand += (float) $src['qty_on_hand'];
            $sumReserved += (float) $src['qty_reserved'];
        }

        $newCost = inventoryMergeStoresWeightedCost(
            array_merge($operational ? [$operational] : [], $rows['sources']),
            $costPolicy,
            (float) ($operational['moving_average_cost'] ?? 0)
        );

        $entry = [
            'pos_tenant' => $tenant,
            'pos_branch' => $branch,
            'item_id' => $itemId,
            'before_operational' => $operational,
            'sources' => $rows['sources'],
            'after' => [
                'qty_on_hand' => $sumOnHand,
                'qty_reserved' => $sumReserved,
                'qty_available' => $sumOnHand - $sumReserved,
                'moving_average_cost' => $newCost,
            ],
        ];
        $merged[] = $entry;
        inventoryMergeStoresAudit($auditPath, ['action' => 'merge_balance', 'entry' => $entry, 'dry_run' => $dryRun]);

        if ($dryRun) {
            continue;
        }

        inventoryMergeStoresUpsertOperationalBalance($conn, $tenant, $branch, $operationalId, $itemId, $entry['after']);
        foreach ($rows['sources'] as $src) {
            $zero = $conn->prepare(
                'UPDATE inventory_item_balances
                 SET qty_on_hand = 0, qty_reserved = 0, qty_available = 0
                 WHERE pos_tenant = ? AND pos_branch = ? AND store_id = ? AND item_id = ?'
            );
            $sourceStoreId = (int) $src['store_id'];
            $zero->bind_param('iiii', $tenant, $branch, $sourceStoreId, $itemId);
            $zero->execute();
            $zero->close();
        }
    }

    return $merged;
}

function inventoryMergeStoresBalanceRows(mysqli $conn, int $tenant, int $branch, int $itemId, int $operationalId): array
{
    $stmt = $conn->prepare(
        'SELECT store_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost
         FROM inventory_item_balances
         WHERE pos_tenant = ? AND pos_branch = ? AND item_id = ?'
    );
    $stmt->bind_param('iii', $tenant, $branch, $itemId);
    $stmt->execute();
    $result = $stmt->get_result();
    $operational = null;
    $sources = [];
    while ($row = $result->fetch_assoc()) {
        if ((int) $row['store_id'] === $operationalId) {
            $operational = $row;
        } else {
            $sources[] = $row;
        }
    }
    $stmt->close();

    return ['operational' => $operational, 'sources' => $sources];
}

function inventoryMergeStoresWeightedCost(array $rows, string $policy, float $existingCost): string
{
    $totalQty = 0.0;
    $totalValue = 0.0;
    foreach ($rows as $row) {
        $qty = (float) ($row['qty_on_hand'] ?? 0);
        $cost = (float) ($row['moving_average_cost'] ?? 0);
        if ($qty > 0 && ($cost > 0 || $policy === 'ignore-zero-cost')) {
            if ($cost <= 0) {
                continue;
            }
            $totalQty += $qty;
            $totalValue += $qty * $cost;
        }
    }
    if ($totalQty > 0) {
        return number_format($totalValue / $totalQty, 6, '.', '');
    }

    foreach ($rows as $row) {
        $cost = (float) ($row['moving_average_cost'] ?? 0);
        if ($cost > 0) {
            return number_format($cost, 6, '.', '');
        }
    }

    if ($existingCost > 0) {
        return number_format($existingCost, 6, '.', '');
    }

    return '0.000000';
}

function inventoryMergeStoresUpsertOperationalBalance(mysqli $conn, int $tenant, int $branch, int $storeId, int $itemId, array $after): void
{
    $stmt = $conn->prepare(
        'INSERT INTO inventory_item_balances (pos_tenant, pos_branch, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            qty_on_hand = VALUES(qty_on_hand),
            qty_reserved = VALUES(qty_reserved),
            qty_available = VALUES(qty_available),
            moving_average_cost = VALUES(moving_average_cost)'
    );
    $onHand = (string) $after['qty_on_hand'];
    $reserved = (string) $after['qty_reserved'];
    $available = (string) $after['qty_available'];
    $cost = (string) $after['moving_average_cost'];
    $stmt->bind_param('iiiissss', $tenant, $branch, $storeId, $itemId, $onHand, $reserved, $available, $cost);
    $stmt->execute();
    $stmt->close();
}

function inventoryMergeStoresMergeStockLevels(mysqli $conn, array $branchScope, int $operationalId, bool $dryRun, string $auditPath): array
{
    if (!inventorySingleStorePreflightTableExists($conn, 'inventory_item_stock_levels')) {
        return [];
    }

    $tenant = (int) ($branchScope['pos_tenant'] ?? 0);
    $branch = (int) ($branchScope['pos_branch'] ?? 0);
    $stmt = $conn->prepare(
        'SELECT DISTINCT item_id
         FROM inventory_item_stock_levels
         WHERE pos_tenant = ? AND pos_branch = ? AND store_id <> ?'
    );
    $stmt->bind_param('iii', $tenant, $branch, $operationalId);
    $stmt->execute();
    $result = $stmt->get_result();

    $merged = [];
    while ($row = $result->fetch_assoc()) {
        $itemId = (int) $row['item_id'];
        $levels = inventoryMergeStoresStockLevelRows($conn, $tenant, $branch, $itemId, $operationalId);
        if (!$levels['sources']) {
            continue;
        }

        $target = $levels['operational'] ?: ['item_id' => $itemId, 'store_id' => $operationalId];
        foreach (['minimum_level', 'reorder_level', 'par_level', 'maximum_level', 'safety_stock_qty'] as $field) {
            $max = (float) ($target[$field] ?? 0);
            foreach ($levels['sources'] as $src) {
                $max = max($max, (float) ($src[$field] ?? 0));
            }
            $target[$field] = $max;
        }
        foreach (['preferred_purchase_unit_id', 'preferred_count_unit_id', 'default_supplier_account_id'] as $field) {
            if (empty($target[$field])) {
                foreach ($levels['sources'] as $src) {
                    if (!empty($src[$field])) {
                        $target[$field] = $src[$field];
                        break;
                    }
                }
            }
        }
        $target['is_active'] = 1;
        $merged[] = ['pos_tenant' => $tenant, 'pos_branch' => $branch, 'item_id' => $itemId, 'merged' => $target];
        inventoryMergeStoresAudit($auditPath, ['action' => 'merge_stock_level', 'item_id' => $itemId, 'dry_run' => $dryRun, 'pos_tenant' => $tenant, 'pos_branch' => $branch]);

        if ($dryRun) {
            continue;
        }

        inventoryMergeStoresUpsertStockLevel($conn, $tenant, $branch, $operationalId, $target);
        $deactivate = $conn->prepare(
            'UPDATE inventory_item_stock_levels SET is_active = 0
             WHERE pos_tenant = ? AND pos_branch = ? AND item_id = ? AND store_id <> ?'
        );
        $deactivate->bind_param('iiii', $tenant, $branch, $itemId, $operationalId);
        $deactivate->execute();
        $deactivate->close();
    }
    $stmt->close();

    return $merged;
}

function inventoryMergeStoresStockLevelRows(mysqli $conn, int $tenant, int $branch, int $itemId, int $operationalId): array
{
    $stmt = $conn->prepare(
        'SELECT * FROM inventory_item_stock_levels WHERE pos_tenant = ? AND pos_branch = ? AND item_id = ?'
    );
    $stmt->bind_param('iii', $tenant, $branch, $itemId);
    $stmt->execute();
    $result = $stmt->get_result();
    $operational = null;
    $sources = [];
    while ($row = $result->fetch_assoc()) {
        if ((int) $row['store_id'] === $operationalId) {
            $operational = $row;
        } else {
            $sources[] = $row;
        }
    }
    $stmt->close();

    return ['operational' => $operational, 'sources' => $sources];
}

function inventoryMergeStoresUpsertStockLevel(mysqli $conn, int $tenant, int $branch, int $storeId, array $row): void
{
    $itemId = (int) $row['item_id'];
    $stmt = $conn->prepare(
        'INSERT INTO inventory_item_stock_levels (
            pos_tenant, pos_branch, store_id, item_id,
            minimum_level, reorder_level, par_level, maximum_level, safety_stock_qty,
            preferred_count_unit_id, preferred_purchase_unit_id, default_supplier_account_id, is_active
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            minimum_level = VALUES(minimum_level),
            reorder_level = VALUES(reorder_level),
            par_level = VALUES(par_level),
            maximum_level = VALUES(maximum_level),
            safety_stock_qty = VALUES(safety_stock_qty),
            preferred_count_unit_id = VALUES(preferred_count_unit_id),
            preferred_purchase_unit_id = VALUES(preferred_purchase_unit_id),
            default_supplier_account_id = VALUES(default_supplier_account_id),
            is_active = VALUES(is_active)'
    );
    $minimum = (string) ($row['minimum_level'] ?? 0);
    $reorder = (string) ($row['reorder_level'] ?? 0);
    $par = (string) ($row['par_level'] ?? 0);
    $maximum = (string) ($row['maximum_level'] ?? 0);
    $safety = (string) ($row['safety_stock_qty'] ?? 0);
    $countUnit = !empty($row['preferred_count_unit_id']) ? (int) $row['preferred_count_unit_id'] : 0;
    $purchaseUnit = !empty($row['preferred_purchase_unit_id']) ? (int) $row['preferred_purchase_unit_id'] : 0;
    $supplier = !empty($row['default_supplier_account_id']) ? (int) $row['default_supplier_account_id'] : 0;
    $isActive = (int) ($row['is_active'] ?? 1);
    $stmt->bind_param(
        'iiiisssssiiii',
        $tenant,
        $branch,
        $storeId,
        $itemId,
        $minimum,
        $reorder,
        $par,
        $maximum,
        $safety,
        $countUnit,
        $purchaseUnit,
        $supplier,
        $isActive
    );
    $stmt->execute();
    $stmt->close();
}

function inventoryMergeStoresPurgeAvailabilityCache(mysqli $conn, array $branchScope, int $operationalId, bool $dryRun): int
{
    if (!inventorySingleStorePreflightTableExists($conn, 'recipe_availability_cache')) {
        return 0;
    }

    $tenant = (int) ($branchScope['pos_tenant'] ?? 0);
    $branch = (int) ($branchScope['pos_branch'] ?? 0);
    $count = inventorySingleStorePreflightScalar(
        $conn,
        'SELECT COUNT(*) AS c FROM recipe_availability_cache WHERE pos_tenant = ? AND pos_branch = ? AND store_id <> ?',
        'iii',
        [$tenant, $branch, $operationalId]
    );
    if (!$dryRun && $count > 0) {
        $stmt = $conn->prepare('DELETE FROM recipe_availability_cache WHERE pos_tenant = ? AND pos_branch = ? AND store_id <> ?');
        $stmt->bind_param('iii', $tenant, $branch, $operationalId);
        $stmt->execute();
        $stmt->close();
    }

    return $count;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(inventoryMergeStoresMain($argv));
}
