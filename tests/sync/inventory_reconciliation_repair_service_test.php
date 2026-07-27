<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryReconciliationRepairService.php';

inventoryReconciliationRepairAssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-reconciliation-repair-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_reconciliation_repair_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryReconciliationRepairCreateLegacyTables($conn);
    $conn->query("
        INSERT INTO myitems (id, code, iname, itmqty, item_type, track_stock)
        VALUES
            (9101, 'MR-9101', 'Mirror stale item', 4.000000, 'sellable', 1),
            (9102, 'MR-9102', 'Non stock with legacy movement', -1.000000, 'sellable', 0),
            (9103, 'MR-9103', 'Ledger mismatch item', 3.000000, 'ingredient', 1)
    ");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, det_store, tenant, branch, isdeleted)
        VALUES
            (1, 101, 9101, 5.000000, 0.000000, 3, 0, 0, 0),
            (2, 102, 9102, 0.000000, 1.000000, 3, 0, 0, 0),
            (3, 103, 9103, 3.000000, 0.000000, 3, 0, 0, 0)
    ");

    $ledger = new InventoryLedgerService(new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]));
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 9101,
        'movement_type' => 'purchase',
        'source_type' => 'manual',
        'source_uuid' => 'mirror-repair-9101',
        'qty_in' => '5.000000',
        'idempotency_key' => 'mirror-repair:9101',
    ], ['item_id' => 9101, 'item_type' => 'sellable', 'track_stock' => 1]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 9103,
        'movement_type' => 'purchase',
        'source_type' => 'manual',
        'source_uuid' => 'mirror-repair-9103',
        'qty_in' => '2.000000',
        'idempotency_key' => 'mirror-repair:9103',
    ], ['item_id' => 9103, 'item_type' => 'ingredient', 'track_stock' => 1]);
    $conn->query('UPDATE myitems SET itmqty = 4.000000 WHERE id = 9101');

    $service = new InventoryReconciliationRepairService();
    $filters = ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3, 'limit' => 10];
    $plan = $service->mirrorRepairPlan($conn, $filters);
    inventoryReconciliationRepairAssert((int) $plan['summary']['repair_candidate_count'] === 1, 'only stale mirror rows with agreeing stock sources should be repair candidates: ' . json_encode($plan));
    inventoryReconciliationRepairAssert((int) $plan['repair_candidates'][0]['item_id'] === 9101, 'stale mirror item should be selected');
    inventoryReconciliationRepairAssert((int) $plan['summary']['unhandled_difference_count'] === 1, 'only the real stock-ledger mismatch should remain unhandled');
    inventoryReconciliationRepairAssert((int) $plan['unhandled_differences'][0]['item_id'] === 9103, 'ordinary non-stock sale history must not be treated as an inventory divergence');
    inventoryReconciliationRepairAssert((bool) preg_match('/^[a-f0-9]{64}$/', (string) $plan['manifest_hash']), 'dry-run should produce a review manifest hash');

    $rehearsal = $service->rehearseMirrorRepair($conn, $filters);
    inventoryReconciliationRepairAssert((int) $rehearsal['summary']['rehearsed_count'] === 1, 'rehearsal should count safe mirror repair');
    $qtyAfterRehearsal = $conn->query('SELECT itmqty FROM myitems WHERE id = 9101')->fetch_assoc()['itmqty'];
    inventoryReconciliationRepairAssert((string) $qtyAfterRehearsal === '4.000000', 'rehearsal should roll back myitems mirror update');

    $manifestMismatchRejected = false;
    try {
        $service->applyMirrorRepair($conn, $filters, str_repeat('0', 64));
    } catch (RuntimeException $exception) {
        $manifestMismatchRejected = $exception->getMessage() === 'INVENTORY_REPAIR_MANIFEST_CHANGED';
    }
    inventoryReconciliationRepairAssert($manifestMismatchRejected, 'apply should reject a stale or mismatched reviewed manifest');

    $apply = $service->applyMirrorRepair($conn, $filters, (string) $plan['manifest_hash']);
    inventoryReconciliationRepairAssert((int) $apply['summary']['repaired_count'] === 1, 'apply should update safe mirror repair');
    $qtyAfterApply = $conn->query('SELECT itmqty FROM myitems WHERE id = 9101')->fetch_assoc()['itmqty'];
    inventoryReconciliationRepairAssert((string) $qtyAfterApply === '5.000000', 'apply should refresh legacy mirror quantity');
    $replayed = $service->applyMirrorRepair($conn, $filters, (string) $plan['manifest_hash']);
    inventoryReconciliationRepairAssert(($replayed['replayed'] ?? false) === true, 'repeating an applied manifest must return its recorded result without mutating again');

    echo "inventory-reconciliation-repair-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryReconciliationRepairAssertSourceContracts(string $root): void
{
    $tool = inventoryReconciliationRepairSource($root . '/tools/inventory_reconciliation_repair.php');
    foreach ([
        'InventoryReconciliationRepairService.php',
        'readable_database_backup_file_required_for_reconciliation_repair_apply',
        '--dry-run',
        '--rehearse',
        '--apply --backup-file',
        '--manifest-hash',
        'Plans or repairs only safe myitems.itmqty compatibility-mirror rows',
        'posmain_db_connect',
    ] as $needle) {
        inventoryReconciliationRepairAssert(strpos($tool, $needle) !== false, 'repair tool should contain: ' . $needle);
    }

    $service = inventoryReconciliationRepairSource($root . '/classes/Inventory/InventoryReconciliationRepairService.php');
    foreach ([
        'mirrorRepairPlan',
        'rehearseMirrorRepair',
        'applyMirrorRepair',
        'isSafeLegacyMirrorRepair',
        'InventoryLegacyMirrorService',
        'RecipeReconciliationService',
        'refreshItemQtySummary',
        'legacy_mirror_qty_refresh',
        'unhandled_differences',
        'legacy_summary_mismatch',
        'movement_scope_or_quantity_mismatch',
        'fat_details_qty',
        'ledger_qty',
        'balance_qty',
    ] as $needle) {
        inventoryReconciliationRepairAssert(strpos($service, $needle) !== false, 'repair service should preserve narrow mirror repair contract: ' . $needle);
    }
    inventoryReconciliationRepairAssert(
        strpos($service, 'refreshItemQtySummary') !== false && strpos($service, 'recordMovement') === false,
        'repair service should only refresh legacy mirror quantity, not write ledger movements'
    );

    $docs = inventoryReconciliationRepairSource($root . '/docs/inventory/phase14_migration_contracts.md');
    foreach ([
        '## Reconciliation Repair',
        'intentionally narrow',
        '`myitems.itmqty` compatibility mirror rows',
        '`fat_details`, `inventory_movements`, and `inventory_item_balances` already agree',
        'Apply requires backup evidence',
    ] as $needle) {
        inventoryReconciliationRepairAssert(strpos($docs, $needle) !== false, 'phase14 docs should preserve reconciliation repair guardrail: ' . $needle);
    }
}

function inventoryReconciliationRepairCreateLegacyTables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS acc_head (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            is_stock TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("INSERT INTO acc_head (id, is_stock) VALUES (3, 1)");
    $conn->query("
        CREATE TABLE IF NOT EXISTS settings (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            def_pos_store BIGINT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("INSERT INTO settings (id, def_pos_store) VALUES (1, 3)");
    $conn->query("
        CREATE TABLE IF NOT EXISTS myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            code VARCHAR(64) NULL,
            iname VARCHAR(255) NULL,
            itmqty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            item_type VARCHAR(64) NOT NULL DEFAULT 'sellable',
            track_stock TINYINT(1) NOT NULL DEFAULT 1,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS fat_details (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fatid INT NOT NULL DEFAULT 0,
            item_id BIGINT UNSIGNED NULL,
            qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            det_store INT NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function inventoryReconciliationRepairAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inventoryReconciliationRepairSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}
