<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Recipe/RecipeReconciliationService.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';

inventoryReconciliationAssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-reconciliation-check-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_reconciliation_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryReconciliationCreateLegacyTables($conn);
    $conn->query("
        INSERT INTO myitems (id, code, iname, itmqty, item_type, track_stock)
        VALUES
            (9001, 'R-9001', 'Recon item', 5.000000, 'sellable', 1),
            (9002, 'S-9002', 'Service with stock', 0.000000, 'service', 1),
            (9003, 'N-9003', 'Non-stock sellable with legacy rows', -1.000000, 'sellable', 0),
            (9004, 'A-9004', 'Aggregate clean item', 6.000000, 'sellable', 1),
            (9005, 'D-9005', 'Active draft reserved item', 5.000000, 'sellable', 1)
    ");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, det_store, tenant, branch, isdeleted)
        VALUES
            (1, 100, 9001, 5.000000, 0.000000, 3, 0, 0, 0),
            (2, 101, 9002, 0.000000, 1.000000, 3, 0, 0, 0),
            (3, 102, 9003, 0.000000, 1.000000, 3, 0, 0, 0),
            (4, 103, 9001, 1.000000, 0.000000, 4, 0, 0, 0),
            (5, 104, 9004, 5.000000, 0.000000, 3, 0, 0, 0),
            (6, 105, 9004, 1.000000, 0.000000, 4, 0, 0, 0),
            (7, 106, 9005, 5.000000, 0.000000, 3, 0, 0, 0),
            (8, 200, 9005, 0.000000, 1.000000, 3, 0, 0, 0)
    ");
    $conn->query('UPDATE fat_details SET pro_tybe=9 WHERE id=8');
    $conn->query("INSERT INTO ot_head VALUES (200,'unpaid','draft','active',0,0)");

    $ledger = new InventoryLedgerService(new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]));
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 9001,
        'movement_type' => 'purchase',
        'source_type' => 'manual',
        'source_uuid' => 'recon-ledger-short',
        'qty_in' => '4.000000',
        'idempotency_key' => 'recon:purchase:9001',
    ], ['item_id' => 9001, 'item_type' => 'sellable', 'track_stock' => 1]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 4],
        'item_id' => 9001,
        'movement_type' => 'purchase',
        'source_type' => 'manual',
        'source_uuid' => 'recon-ledger-other-store',
        'qty_in' => '1.000000',
        'idempotency_key' => 'recon:purchase:9001:store4',
    ], ['item_id' => 9001, 'item_type' => 'sellable', 'track_stock' => 1]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 9004,
        'movement_type' => 'purchase',
        'source_type' => 'manual',
        'source_uuid' => 'recon-ledger-aggregate-store3',
        'qty_in' => '5.000000',
        'idempotency_key' => 'recon:purchase:9004:store3',
    ], ['item_id' => 9004, 'item_type' => 'sellable', 'track_stock' => 1]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 9005,
        'movement_type' => 'purchase',
        'source_type' => 'fat_details',
        'source_id' => 7,
        'fat_detail_id' => 7,
        'qty_in' => '5.000000',
        'idempotency_key' => 'recon:purchase:9005',
    ], ['item_id' => 9005, 'item_type' => 'sellable', 'track_stock' => 1]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 9005,
        'movement_type' => 'reservation',
        'source_type' => 'reservation',
        'source_id' => 8,
        'fat_detail_id' => 8,
        'order_id' => 200,
        'qty_reserved' => '1.000000',
        'idempotency_key' => 'recon:reservation:9005',
    ], ['item_id' => 9005, 'item_type' => 'sellable', 'track_stock' => 1]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 9002,
        'movement_type' => 'sale_direct',
        'source_type' => 'manual',
        'source_uuid' => 'recon-invalid-service-ledger',
        'qty_out' => '1.000000',
        'idempotency_key' => 'recon:invalid-service:9002',
    ], ['item_id' => 9002, 'item_type' => 'sellable', 'track_stock' => 1], ['enforce_negative_policy' => false]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 4],
        'item_id' => 9004,
        'movement_type' => 'purchase',
        'source_type' => 'manual',
        'source_uuid' => 'recon-ledger-aggregate-store4',
        'qty_in' => '1.000000',
        'idempotency_key' => 'recon:purchase:9004:store4',
    ], ['item_id' => 9004, 'item_type' => 'sellable', 'track_stock' => 1]);

    $rows = (new RecipeReconciliationService())->report($conn, [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 3,
        'item_ids' => [9001, 9002, 9003],
        'differences_only' => true,
        'limit' => 10,
    ]);
    inventoryReconciliationAssert(count($rows) === 2, 'reconciliation should return material current-stock difference rows');
    inventoryReconciliationAssert(in_array('movement_scope_or_quantity_mismatch', $rows[0]['difference_reasons'], true), 'quantity mismatch should be categorized');
    inventoryReconciliationAssert(in_array('non_stock_item_has_stock_movement', $rows[1]['difference_reasons'], true), 'service stock movement should be categorized');
    inventoryReconciliationAssert(!in_array(9003, array_column($rows, 'item_id'), true), 'commercial fat_details history alone must not be treated as current stock for a non-stock item');
    inventoryReconciliationAssert($rows[0]['difference_reason'] !== '', 'reconciliation should expose a compact reason string');

    $storeScopedCleanRows = (new RecipeReconciliationService())->report($conn, [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 4,
        'item_ids' => [9001],
        'differences_only' => true,
        'limit' => 10,
    ]);
    inventoryReconciliationAssert($storeScopedCleanRows === [], 'positive store reconciliation should not compare global myitems.itmqty against a single store when fat_details and ledger agree');

    $aggregateRows = (new RecipeReconciliationService())->report($conn, [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 0,
        'item_ids' => [9004],
        'differences_only' => true,
        'limit' => 10,
    ]);
    inventoryReconciliationAssert($aggregateRows === [], 'store 0 reconciliation should aggregate all stores instead of pretending store 0 is the only scope');
    $draftRows = (new RecipeReconciliationService())->report($conn, [
        'pos_tenant' => 0,
        'pos_branch' => 0,
        'store_id' => 0,
        'item_ids' => [9005],
        'differences_only' => true,
    ]);
    inventoryReconciliationAssert($draftRows === [], 'active draft commercial lines must not create a false stock divergence when mirror, ledger, and balance agree');

    echo "inventory-reconciliation-check-contract-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryReconciliationAssertSourceContracts(string $root): void
{
    $toolSource = inventoryReconciliationSource($root . '/tools/inventory_reconciliation_check.php');
    foreach ([
        'RecipeReconciliationService.php',
        '--tenant=0',
        '--branch=0',
        '--store=0',
        '--acceptance-file',
        '--differences-only',
        '--csv',
        'accepted_difference_count',
        'unaccepted_difference_count',
        'inventory_reconciliation_unaccepted_differences',
        'inventory_unit_conversion_factor_mismatch',
        'inventory_unit_conversion_audit_unavailable',
        'reason_counts',
        'inventoryReconciliationPrintCsv',
        'posmain_csv_write_row',
        'json_encode($result',
    ] as $needle) {
        inventoryReconciliationAssert(strpos($toolSource, $needle) !== false, 'inventory reconciliation CLI should contain: ' . $needle);
    }
    inventoryReconciliationAssert(
        !preg_match('/\b(INSERT|UPDATE|DELETE|DROP|TRUNCATE)\b/i', $toolSource),
        'inventory reconciliation CLI must remain read-only'
    );

    $serviceSource = inventoryReconciliationSource($root . '/classes/Recipe/RecipeReconciliationService.php');
    foreach ([
        'legacy_qty',
        'fat_details_qty',
        'ledger_qty',
        'balance_qty',
        'difference_reasons',
        'difference_reason',
        'recommended_action',
        'differences_only',
        'candidateItemIds',
        'aggregateBalanceRow',
        'storeId <= 0',
        'non_stock_item_has_stock_movement',
        'legacy_summary_mismatch',
        'missing_balance_row',
        'ledger_balance_mismatch',
        'missing_bridge_movement',
        'deleted_fat_detail_or_ledger_only',
        'movement_scope_or_quantity_mismatch',
    ] as $needle) {
        inventoryReconciliationAssert(strpos($serviceSource, $needle) !== false, 'reconciliation service should preserve stock truth comparison: ' . $needle);
    }
    inventoryReconciliationAssert(
        !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[^A-Za-z_]|DELETE\s+FROM|DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE)\b/i', $serviceSource),
        'reconciliation service must remain read-only'
    );

    $docs = inventoryReconciliationSource($root . '/docs/inventory/phase5_reconciliation_contracts.md');
    foreach ([
        '`myitems.itmqty`',
        'scoped `fat_details` quantity',
        'scoped `inventory_movements` quantity',
        '`inventory_item_balances.qty_on_hand`',
        '`difference_reasons`',
        '`difference_reason`',
        'does not repair data or mutate stock',
    ] as $needle) {
        inventoryReconciliationAssert(strpos($docs, $needle) !== false, 'phase5 docs should preserve reconciliation contract: ' . $needle);
    }
}

function inventoryReconciliationCreateLegacyTables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS myitems (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            code VARCHAR(64) NULL,
            iname VARCHAR(255) NULL,
            itmqty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            item_type VARCHAR(64) NOT NULL DEFAULT 'sellable',
            track_stock TINYINT(1) NOT NULL DEFAULT 1
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
    $conn->query('ALTER TABLE fat_details ADD COLUMN pro_tybe INT NOT NULL DEFAULT 4');
    $conn->query("
        CREATE TABLE ot_head (
            id INT PRIMARY KEY,
            payment_status VARCHAR(20) NOT NULL,
            invoice_status VARCHAR(20) NOT NULL,
            order_status VARCHAR(20) NOT NULL,
            closed INT NOT NULL DEFAULT 0,
            isdeleted TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
    ");
}

function inventoryReconciliationSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryReconciliationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
