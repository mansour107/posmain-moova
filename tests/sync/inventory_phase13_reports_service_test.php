<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryReportsService.php';

inventoryPhase13AssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase13-reports-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase13_reports_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase13CreateLegacyTables($conn);
    inventoryPhase13CreateJournalTables($conn);
    inventoryPhase13SeedFixtures($conn);

    $flags = new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'bridge',
            'legacy_mirror' => '1',
        ],
    ]);
    $ledger = new InventoryLedgerService($flags);
    inventoryPhase13Movement($conn, $ledger, 13001, 'opening_balance', 'manual', '20.000000', '0.000000', '2.500000', '50.000000', 'phase13-open-13001');
    inventoryPhase13Movement($conn, $ledger, 13001, 'waste', 'adjustment', '0.000000', '2.000000', '2.500000', '5.000000', 'phase13-waste-13001');
    inventoryPhase13Movement($conn, $ledger, 13002, 'opening_balance', 'manual', '4.000000', '0.000000', '3.000000', '12.000000', 'phase13-open-13002');
    inventoryPhase13Movement($conn, $ledger, 13002, 'recipe_consumption', 'recipe_order_line_usage', '0.000000', '1.000000', '3.000000', '3.000000', 'phase13-consume-13002', ['recipe_id' => 13001]);

    $reports = new InventoryReportsService();
    $filters = ['store_id' => 3, 'limit' => 50];
    $dashboard = $reports->dashboard($conn, $filters);
    inventoryPhase13Assert((float) $dashboard['item_count'] >= 2, 'dashboard should count stocked items');
    inventoryPhase13Assert((float) $dashboard['low_stock_count'] >= 1, 'dashboard should count low stock items');
    $dashboardDetails = $reports->dashboardDetails($conn, $filters);
    inventoryPhase13Assert(count($dashboardDetails['needs_attention'] ?? []) >= 1, 'dashboard details should include needs-attention stock rows');
    inventoryPhase13Assert(count($dashboardDetails['recent_movements'] ?? []) >= 1, 'dashboard details should include recent movement rows');
    inventoryPhase13Assert(count($dashboardDetails['menu_availability_impact'] ?? []) === 1, 'dashboard details should include unavailable menu impact rows');
    inventoryPhase13Assert((int) $dashboardDetails['menu_availability_impact'][0]['blocking_item_id'] === 13002, 'menu availability impact should expose limiting item');
    inventoryPhase13Assert((string) $dashboardDetails['menu_availability_impact'][0]['sellable_item_name'] === 'Report pizza', 'menu availability impact should expose sellable item name');

    foreach ([
        'inventory_levels',
        'movement_history',
        'low_stock',
        'replenishment_suggestions',
        'purchase_history',
        'supplier_purchase_summary',
        'transfer_history',
        'count_variance',
        'waste_adjustment',
        'production_variance',
        'recipe_consumption',
        'menu_availability',
        'inventory_valuation',
        'cogs_reconciliation',
    ] as $reportKey) {
        $rows = $reports->report($conn, $reportKey, $filters);
        inventoryPhase13Assert(count($rows) > 0, 'report should return fixture rows: ' . $reportKey);
    }

    $categoryRows = $reports->report($conn, 'inventory_levels', ['store_id' => 3, 'category_id' => 77, 'limit' => 50]);
    inventoryPhase13Assert(count($categoryRows) === 1 && (int) $categoryRows[0]['item_id'] === 13001, 'inventory levels should filter by item category');
    $suggestionRows = $reports->report($conn, 'replenishment_suggestions', ['store_id' => 3, 'item_id' => 13001, 'limit' => 50]);
    inventoryPhase13Assert(count($suggestionRows) === 1, 'replenishment suggestions should return preferred purchase unit fixture');
    inventoryPhase13Assert((int) $suggestionRows[0]['preferred_purchase_unit_id'] === 13011, 'replenishment suggestions should expose preferred purchase unit id');
    inventoryPhase13Assert((string) $suggestionRows[0]['preferred_purchase_unit_name'] === 'Report Bag', 'replenishment suggestions should expose preferred purchase unit name');
    inventoryPhase13Assert((int) $suggestionRows[0]['default_supplier_account_id'] === 2101, 'replenishment suggestions should expose default supplier id');
    inventoryPhase13Assert((string) $suggestionRows[0]['default_supplier_name'] === 'Supplier A', 'replenishment suggestions should expose default supplier name');
    inventoryPhase13Assert(inventoryPhase13DecimalEquals($suggestionRows[0]['suggested_qty'], '7.000000'), 'replenishment suggestions should keep base shortage quantity');
    inventoryPhase13Assert(inventoryPhase13DecimalEquals($suggestionRows[0]['suggested_purchase_qty'], '2.000000'), 'replenishment suggestions should round purchase unit quantity up');
    inventoryPhase13Assert(inventoryPhase13DecimalEquals($suggestionRows[0]['suggested_purchase_base_qty'], '10.000000'), 'replenishment suggestions should expose rounded base quantity to receive');
    $conn->query('ALTER TABLE inventory_item_stock_levels DROP COLUMN default_supplier_account_id');
    $legacySuggestionRows = $reports->report($conn, 'replenishment_suggestions', ['store_id' => 3, 'item_id' => 13001, 'limit' => 50]);
    inventoryPhase13Assert(count($legacySuggestionRows) === 1, 'replenishment suggestions should tolerate stock-level schemas without default supplier column');
    inventoryPhase13Assert($legacySuggestionRows[0]['default_supplier_account_id'] === null, 'missing default supplier column should surface null supplier id');
    inventoryPhase13Assert($legacySuggestionRows[0]['default_supplier_name'] === null, 'missing default supplier column should surface null supplier name');
    $supplierSummaryRows = $reports->report($conn, 'supplier_purchase_summary', ['store_id' => 3, 'supplier_account_id' => 2101, 'limit' => 50]);
    inventoryPhase13Assert(count($supplierSummaryRows) === 1, 'supplier purchase summary should group purchase receipts by supplier');
    inventoryPhase13Assert((int) $supplierSummaryRows[0]['supplier_account_id'] === 2101, 'supplier purchase summary should expose supplier id');
    inventoryPhase13Assert((string) $supplierSummaryRows[0]['supplier_name'] === 'Supplier A', 'supplier purchase summary should expose supplier name');
    inventoryPhase13Assert((int) $supplierSummaryRows[0]['receipt_count'] === 2, 'supplier purchase summary should count receipts');
    inventoryPhase13Assert((int) $supplierSummaryRows[0]['line_count'] === 2, 'supplier purchase summary should count lines');
    inventoryPhase13Assert(inventoryPhase13DecimalEquals($supplierSummaryRows[0]['received_qty'], '13.000000'), 'supplier purchase summary should sum received qty');
    inventoryPhase13Assert(inventoryPhase13DecimalEquals($supplierSummaryRows[0]['returned_qty'], '1.000000'), 'supplier purchase summary should sum returned qty');
    inventoryPhase13Assert(inventoryPhase13DecimalEquals($supplierSummaryRows[0]['net_received_qty'], '12.000000'), 'supplier purchase summary should expose net received qty');
    inventoryPhase13Assert(inventoryPhase13DecimalEquals($supplierSummaryRows[0]['total_cost'], '32.500000'), 'supplier purchase summary should sum total cost');
    inventoryPhase13Assert(isset($supplierSummaryRows[0]['drilldown_url']) && strpos($supplierSummaryRows[0]['drilldown_url'], 'supplier_account_id=2101') !== false, 'supplier purchase summary should link to filtered purchase history');
    $supplierCategoryRows = $reports->report($conn, 'supplier_purchase_summary', ['store_id' => 3, 'supplier_account_id' => 2101, 'category_id' => 88, 'limit' => 50]);
    inventoryPhase13Assert(count($supplierCategoryRows) === 1 && inventoryPhase13DecimalEquals($supplierCategoryRows[0]['received_qty'], '3.000000'), 'supplier purchase summary should filter by item category');
    $purchaseCategoryRows = $reports->report($conn, 'purchase_history', ['store_id' => 3, 'supplier_account_id' => 2101, 'category_id' => 88, 'limit' => 50]);
    inventoryPhase13Assert(count($purchaseCategoryRows) === 1 && (int) $purchaseCategoryRows[0]['receipt_id'] === 13002, 'purchase history should filter by supplier and item category');
    $menuAvailabilityRows = $reports->report($conn, 'menu_availability', ['store_id' => 3, 'item_id' => 13003, 'limit' => 50]);
    inventoryPhase13Assert(count($menuAvailabilityRows) === 2, 'menu availability report should include available and unavailable cache rows');
    inventoryPhase13Assert((string) $menuAvailabilityRows[0]['availability_status'] === 'unavailable', 'menu availability report should sort unavailable rows first');
    inventoryPhase13Assert((int) $menuAvailabilityRows[0]['blocking_item_id'] === 13002, 'menu availability report should expose limiting ingredient');
    inventoryPhase13Assert(inventoryPhase13DecimalEquals($menuAvailabilityRows[1]['effective_available_qty'], '8.000000'), 'menu availability report should expose can-make quantity');
    $blockingIngredientRows = $reports->report($conn, 'menu_availability', ['store_id' => 3, 'item_id' => 13002, 'limit' => 50]);
    inventoryPhase13Assert(count($blockingIngredientRows) === 1 && (string) $blockingIngredientRows[0]['availability_status'] === 'unavailable', 'menu availability report should filter by blocking ingredient');
    $valuationRows = $reports->report($conn, 'inventory_valuation', ['store_id' => 3, 'item_id' => 13001, 'limit' => 50]);
    inventoryPhase13Assert(count($valuationRows) === 1, 'inventory valuation report should return selected balance row');
    inventoryPhase13Assert(inventoryPhase13DecimalEquals($valuationRows[0]['qty_on_hand'], '18.000000'), 'inventory valuation report should expose current ledger quantity');
    inventoryPhase13Assert(inventoryPhase13DecimalEquals($valuationRows[0]['moving_average_cost'], '2.500000'), 'inventory valuation report should expose moving average cost');
    inventoryPhase13Assert(inventoryPhase13DecimalEquals($valuationRows[0]['current_stock_value'], '45.000000'), 'inventory valuation report should calculate current stock value');
    inventoryPhase13Assert((string) $valuationRows[0]['last_movement_type'] === 'waste', 'inventory valuation report should expose last movement type');
    inventoryPhase13Assert(isset($valuationRows[0]['drilldown_url']) && strpos($valuationRows[0]['drilldown_url'], 'movement_history') !== false, 'inventory valuation report should link to movement history');

    $movementRows = $reports->report($conn, 'movement_history', ['store_id' => 3, 'item_id' => 13001, 'movement_type' => 'waste', 'limit' => 50]);
    inventoryPhase13Assert(count($movementRows) === 1 && $movementRows[0]['movement_type'] === 'waste', 'movement history should filter by item and movement type');
    inventoryPhase13Assert(isset($movementRows[0]['drilldown_url']), 'movement history should expose drilldown_url');

    echo "inventory-phase13-reports-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase13AssertSourceContracts(string $root): void
{
    $service = inventoryPhase13Source($root . '/classes/Inventory/InventoryReportsService.php');
    foreach ([
        'inventory_levels',
        'movement_history',
        'low_stock',
        'replenishment_suggestions',
        'purchase_history',
        'supplier_purchase_summary',
        'transfer_history',
        'count_variance',
        'waste_adjustment',
        'production_variance',
        'recipe_consumption',
        'menu_availability',
        'inventory_valuation',
        'cogs_reconciliation',
    ] as $reportKey) {
        inventoryPhase13Assert(strpos($service, $reportKey) !== false, 'reports service should preserve report dispatch key: ' . $reportKey);
    }
    foreach ([
        'function dashboard',
        'function dashboardDetails',
        'function supplierPurchaseSummary',
        'function menuAvailability',
        'function inventoryValuation',
        'function cogsReconciliation',
        'estimated_purchase_cost',
        'preferred_purchase_unit_id',
        'default_supplier_account_id',
        'recipe_availability_cache',
        'drilldown_url',
    ] as $needle) {
        inventoryPhase13Assert(strpos($service, $needle) !== false, 'reports service should preserve phase13 behavior: ' . $needle);
    }
    inventoryPhase13Assert(
        !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[^A-Za-z_]|DELETE\s+FROM|DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE)\b/i', $service),
        'inventory reports service must remain read-only'
    );

    $docs = inventoryPhase13Source($root . '/docs/inventory/phase13_reports_contracts.md');
    foreach ([
        'manager-facing Arabic dashboard',
        'Replenishment Suggestions',
        'Supplier Purchase Summary',
        'Menu Availability / Can Make',
        'Inventory Valuation / Cost History',
        'read-only and does not introduce a supplier catalog table',
    ] as $needle) {
        inventoryPhase13Assert(strpos($docs, $needle) !== false, 'phase13 docs should preserve reports contract: ' . $needle);
    }
}

function inventoryPhase13CreateLegacyTables(mysqli $conn): void
{
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL PRIMARY KEY,
  iname VARCHAR(200) NOT NULL,
  barcode VARCHAR(100) NULL,
  group1 BIGINT UNSIGNED NULL,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
  track_stock TINYINT(1) NOT NULL DEFAULT 1,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE acc_head (
  id INT NOT NULL PRIMARY KEY,
  aname VARCHAR(200) NOT NULL,
  code VARCHAR(64) NULL,
  is_stock TINYINT(1) NOT NULL DEFAULT 0,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE myunits (
  id INT NOT NULL PRIMARY KEY,
  uname VARCHAR(80) NOT NULL,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE item_units (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  unit_id INT NOT NULL,
  u_val DECIMAL(18,6) NOT NULL DEFAULT 1,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_item_units_item_unit (item_id, unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase13CreateJournalTables(mysqli $conn): void
{
    $conn->query("
CREATE TABLE journal_heads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  journal_id INT NOT NULL,
  total DECIMAL(18,6) NOT NULL DEFAULT 0,
  details VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE journal_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  journal_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  debit DECIMAL(18,6) NOT NULL DEFAULT 0,
  credit DECIMAL(18,6) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase13SeedFixtures(mysqli $conn): void
{
    $conn->query("
        INSERT INTO acc_head (id, aname, code, is_stock)
        VALUES (3, 'Main Store', '131001', 1), (4, 'Second Store', '131002', 1), (2101, 'Supplier A', '211001', 0)
    ");
    $conn->query("
        INSERT INTO myitems (id, iname, barcode, group1, cost_price, item_type, track_stock)
        VALUES
            (13001, 'Report flour', 'R-13001', 77, 2.500000, 'ingredient', 1),
            (13002, 'Report cheese', 'R-13002', 88, 3.000000, 'ingredient', 1),
            (13003, 'Report pizza', 'R-13003', 90, 0.000000, 'sellable', 1)
    ");
    $conn->query("INSERT INTO myunits (id, uname) VALUES (13011, 'Report Bag')");
    $conn->query("INSERT INTO item_units (item_id, unit_id, u_val) VALUES (13001, 13011, 5.000000)");
    $conn->query("
        INSERT INTO recipe_headers (id, recipe_uuid, sellable_item_id, recipe_name, status)
        VALUES (13001, '13131313-1313-4313-8313-131313131313', 13003, 'Report pizza recipe', 'active')
    ");
    $conn->query("
        INSERT INTO recipe_availability_cache (
            pos_tenant,
            pos_branch,
            store_id,
            sellable_item_id,
            recipe_id,
            order_type,
            channel,
            computed_available_qty,
            effective_available_qty,
            effective_is_available,
            blocking_item_id,
            unavailable_reason,
            calculated_at
        )
        VALUES
            (
                0,
                0,
                3,
                13003,
                13001,
                'takeaway',
                'pos',
                0.000000,
                0.000000,
                0,
                13002,
                'Report cheese stock is 0',
                NOW()
            ),
            (
                0,
                0,
                3,
                13003,
                13001,
                'dine_in',
                'table',
                8.000000,
                8.000000,
                1,
                NULL,
                NULL,
                NOW()
            )
    ");
    $conn->query("
        INSERT INTO inventory_item_stock_levels (pos_tenant, pos_branch, store_id, item_id, minimum_level, reorder_level, par_level, preferred_purchase_unit_id, default_supplier_account_id, is_active)
        VALUES
            (0, 0, 3, 13001, 5.000000, 8.000000, 25.000000, 13011, 2101, 1),
            (0, 0, 3, 13002, 5.000000, 6.000000, 12.000000, NULL, NULL, 1)
    ");
    $conn->query("
        INSERT INTO inventory_purchase_receipts (id, purchase_receipt_uuid, supplier_account_id, destination_store_id, status, received_at, posted_at)
        VALUES
            (13001, '14141414-1414-4414-8414-141414141414', 2101, 3, 'posted', NOW(), NOW()),
            (13002, '14141414-1414-4414-8414-141414141415', 2101, 3, 'posted', NOW(), NOW())
    ");
    $conn->query("
        INSERT INTO inventory_purchase_receipt_lines (purchase_receipt_id, item_id, received_qty, returned_qty, unit_cost, total_cost)
        VALUES
            (13001, 13001, 10.000000, 0.000000, 2.500000, 25.000000),
            (13002, 13002, 3.000000, 1.000000, 2.500000, 7.500000)
    ");
    $conn->query("
        INSERT INTO inventory_transfers (id, transfer_uuid, source_store_id, destination_store_id, status, sent_at, received_at)
        VALUES (13001, '15151515-1515-4515-8515-151515151515', 3, 4, 'received', NOW(), NOW())
    ");
    $conn->query("
        INSERT INTO inventory_transfer_lines (transfer_id, item_id, requested_qty, sent_qty, received_qty, variance_qty, unit_cost)
        VALUES (13001, 13001, 2.000000, 2.000000, 2.000000, 0.000000, 2.500000)
    ");
    $conn->query("
        INSERT INTO inventory_counts (id, count_uuid, store_id, status, count_type, closed_at)
        VALUES (13001, '16161616-1616-4616-8616-161616161616', 3, 'closed', 'selected', NOW())
    ");
    $conn->query("
        INSERT INTO inventory_count_lines (count_id, item_id, snapshot_qty, counted_qty, variance_qty, variance_percent, variance_cost)
        VALUES (13001, 13002, 4.000000, 3.000000, -1.000000, -25.000000, 3.000000)
    ");
    $conn->query("
        INSERT INTO production_batches (id, batch_uuid, store_id, recipe_id, output_item_id, planned_output_qty, actual_output_qty, status, committed_at, variance_reason)
        VALUES (13001, '17171717-1717-4717-8717-171717171717', 3, 13001, 13003, 5.000000, 4.000000, 'committed', NOW(), 'test variance')
    ");
    $conn->query("
        INSERT INTO production_batch_lines (batch_id, line_type, item_id, planned_qty, actual_qty, unit_cost, total_cost)
        VALUES
            (13001, 'input', 13001, 10.000000, 10.000000, 2.500000, 25.000000),
            (13001, 'output', 13003, 5.000000, 4.000000, 6.250000, 25.000000)
    ");
}

function inventoryPhase13Movement(mysqli $conn, InventoryLedgerService $ledger, int $itemId, string $type, string $sourceType, string $qtyIn, string $qtyOut, string $unitCost, string $totalCost, string $key, array $extra = []): void
{
    $conn->begin_transaction();
    $ledger->recordMovement($conn, array_merge([
        'scope' => [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 3,
        ],
        'item_id' => $itemId,
        'movement_type' => $type,
        'source_type' => $sourceType,
        'source_uuid' => $key,
        'qty_in' => $qtyIn,
        'qty_out' => $qtyOut,
        'unit_cost' => $unitCost,
        'total_cost' => $totalCost,
        'idempotency_key' => 'phase13-report:' . $key,
        'metadata' => ['source' => 'phase13_report_test'],
        'created_by' => 7,
    ], $extra), ['id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1], ['manage_transaction' => false]);
    $conn->commit();
}

function inventoryPhase13DecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === $expected;
}

function inventoryPhase13Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inventoryPhase13Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}
