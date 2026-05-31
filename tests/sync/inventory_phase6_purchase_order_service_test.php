<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryPurchaseOrderService.php';
require_once $root . '/classes/Inventory/InventoryPurchaseReceivingService.php';

inventoryPhase6PoAssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase6-purchase-order-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase6_po_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase6PoCreateLegacyItemTable($conn);
    $conn->query("
        INSERT INTO myitems (id, iname, itmqty, cost_price, last_price, item_type, track_stock)
        VALUES
            (6301, 'PO lifecycle item', 0.000000, 0.000000, 0.000000, 'ingredient', 1),
            (6302, 'PO mismatch item', 0.000000, 0.000000, 0.000000, 'ingredient', 1)
    ");

    $flags = new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'bridge',
            'legacy_mirror' => '1',
        ],
    ]);
    $orderService = new InventoryPurchaseOrderService($flags);
    $receivingService = new InventoryPurchaseReceivingService($flags);

    $draft = $orderService->createDraft($conn, [
        'purchase_order_uuid' => '55555555-5555-4555-8555-555555555555',
        'supplier_account_id' => 2101,
        'destination_store_id' => 3,
        'notes' => 'test order',
        'lines' => [
            ['item_id' => 6301, 'qty' => '10.000000', 'unit_cost' => '7.500000'],
        ],
    ], ['user_id' => 7]);
    inventoryPhase6PoAssert($draft['success'] === true && $draft['status'] === 'draft', 'purchase order should start as draft');
    $line = inventoryPhase6PoOne($conn, 'SELECT * FROM inventory_purchase_order_lines WHERE purchase_order_id = ' . (int) $draft['purchase_order_id'] . ' LIMIT 1');
    inventoryPhase6PoAssert(inventoryPhase6PoDecimalEquals($line['ordered_qty'], '10.000000'), 'draft order line should preserve ordered qty');
    inventoryPhase6PoAssert(inventoryPhase6PoDecimalEquals($line['unit_cost'], '7.500000'), 'draft order line should preserve unit cost');

    $replay = $orderService->createDraft($conn, [
        'purchase_order_uuid' => '55555555-5555-4555-8555-555555555555',
        'supplier_account_id' => 2101,
        'destination_store_id' => 3,
        'lines' => [
            ['item_id' => 6301, 'qty' => '10.000000', 'unit_cost' => '7.500000'],
        ],
    ], ['user_id' => 7]);
    inventoryPhase6PoAssert(!empty($replay['idempotent_replay']), 'same purchase order uuid should replay');
    inventoryPhase6PoAssert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_purchase_orders')->fetch_assoc()['c'] === 1, 'purchase order replay should not duplicate header');
    inventoryPhase6PoAssert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_purchase_order_lines')->fetch_assoc()['c'] === 1, 'purchase order replay should not duplicate lines');

    $submitted = $orderService->submit($conn, (int) $draft['purchase_order_id'], ['user_id' => 8]);
    inventoryPhase6PoAssert($submitted['status'] === 'submitted', 'draft order should submit');
    $submittedRow = inventoryPhase6PoOne($conn, 'SELECT status, submitted_by, submitted_at FROM inventory_purchase_orders WHERE id = ' . (int) $draft['purchase_order_id'] . ' LIMIT 1');
    inventoryPhase6PoAssert($submittedRow['status'] === 'submitted' && (int) $submittedRow['submitted_by'] === 8 && $submittedRow['submitted_at'] !== null, 'submitted order should store reviewer trail');

    $approved = $orderService->approve($conn, (int) $draft['purchase_order_id'], ['user_id' => 9]);
    inventoryPhase6PoAssert($approved['status'] === 'approved', 'submitted order should approve');
    $approvedRow = inventoryPhase6PoOne($conn, 'SELECT status, approved_by, approved_at FROM inventory_purchase_orders WHERE id = ' . (int) $draft['purchase_order_id'] . ' LIMIT 1');
    inventoryPhase6PoAssert($approvedRow['status'] === 'approved' && (int) $approvedRow['approved_by'] === 9 && $approvedRow['approved_at'] !== null, 'approved order should store approval trail');

    $receipt = $receivingService->receive($conn, [
        'purchase_receipt_uuid' => '66666666-6666-4666-8666-666666666666',
        'purchase_order_id' => (int) $draft['purchase_order_id'],
        'supplier_account_id' => 2101,
        'destination_store_id' => 3,
        'lines' => [
            ['purchase_order_line_id' => (int) $line['id'], 'item_id' => 6301, 'qty' => '4.000000', 'unit_cost' => '7.500000'],
        ],
    ], ['user_id' => 10]);
    inventoryPhase6PoAssert($receipt['success'] === true && !empty($receipt['movement_ids']), 'approved PO receipt should post to ledger');
    $partialLine = inventoryPhase6PoOne($conn, 'SELECT received_qty FROM inventory_purchase_order_lines WHERE id = ' . (int) $line['id'] . ' LIMIT 1');
    $partialOrder = inventoryPhase6PoOne($conn, 'SELECT status FROM inventory_purchase_orders WHERE id = ' . (int) $draft['purchase_order_id'] . ' LIMIT 1');
    inventoryPhase6PoAssert(inventoryPhase6PoDecimalEquals($partialLine['received_qty'], '4.000000'), 'partial PO receipt should update line received qty');
    inventoryPhase6PoAssert($partialOrder['status'] === 'partially_received', 'partial PO receipt should update order status');

    try {
        $receivingService->receive($conn, [
            'purchase_receipt_uuid' => '77777777-7777-4777-8777-777777777777',
            'purchase_order_id' => (int) $draft['purchase_order_id'],
            'supplier_account_id' => 2101,
            'destination_store_id' => 3,
            'lines' => [
                ['purchase_order_line_id' => (int) $line['id'], 'item_id' => 6301, 'qty' => '7.000000', 'unit_cost' => '7.500000'],
            ],
        ], ['user_id' => 10]);
        inventoryPhase6PoAssert(false, 'over receipt should fail');
    } catch (RuntimeException $exception) {
        inventoryPhase6PoAssert($exception->getMessage() === 'PURCHASE_ORDER_OVER_RECEIPT', 'over receipt should return expected code');
    }

    $finalReceipt = $receivingService->receive($conn, [
        'purchase_receipt_uuid' => '88888888-8888-4888-8888-888888888888',
        'purchase_order_id' => (int) $draft['purchase_order_id'],
        'supplier_account_id' => 2101,
        'destination_store_id' => 3,
        'lines' => [
            ['purchase_order_line_id' => (int) $line['id'], 'item_id' => 6301, 'qty' => '6.000000', 'unit_cost' => '7.500000'],
        ],
    ], ['user_id' => 10]);
    inventoryPhase6PoAssert($finalReceipt['success'] === true, 'remaining PO quantity should receive');
    $receivedOrder = inventoryPhase6PoOne($conn, 'SELECT status FROM inventory_purchase_orders WHERE id = ' . (int) $draft['purchase_order_id'] . ' LIMIT 1');
    $receivedLine = inventoryPhase6PoOne($conn, 'SELECT received_qty FROM inventory_purchase_order_lines WHERE id = ' . (int) $line['id'] . ' LIMIT 1');
    inventoryPhase6PoAssert($receivedOrder['status'] === 'received', 'fully received PO should move to received');
    inventoryPhase6PoAssert(inventoryPhase6PoDecimalEquals($receivedLine['received_qty'], '10.000000'), 'fully received PO should match ordered quantity');

    echo "inventory-phase6-purchase-order-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase6PoCreateLegacyItemTable(mysqli $conn): void
{
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL PRIMARY KEY,
  iname VARCHAR(200) NOT NULL,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  last_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
  track_stock TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase6PoAssertSourceContracts(string $root): void
{
    $page = inventoryPhase6PoSource($root . '/inventory_purchasing.php');
    foreach (['saveInventoryPurchaseOrder', 'submitInventoryPurchaseOrder', 'approveInventoryPurchaseOrder', 'inventoryPurchaseCanApproveOrders', 'purchase_order_line_id', 'ajax/inventory_purchase_order.php', 'inventoryPurchaseOrderStatusLabel', "'submitted' => 'بانتظار الاعتماد'", "'partially_received' => 'مستلم جزئيا'", 'inventory-purchase-item-search', 'applyInventoryPurchaseItemSearch', 'نتائج مطابقة'] as $needle) {
        inventoryPhase6PoAssert(strpos($page, $needle) !== false, 'Arabic purchasing UI should include PO workflow: ' . $needle);
    }

    $docs = inventoryPhase6PoSource($root . '/docs/inventory/phase6_purchase_bridge_contracts.md');
    inventoryPhase6PoAssert(strpos($docs, 'purchase-order statuses in Arabic') !== false, 'Phase 6 docs should describe Arabic PO status labels');
    inventoryPhase6PoAssert(strpos($docs, 'in-row item search') !== false, 'Phase 6 docs should describe purchasing line item search');
    inventoryPhase6PoAssert(strpos($docs, '`inventory.approve` or `accounting.view`') !== false, 'Phase 6 docs should describe PO approval permission boundary');

    $endpoint = inventoryPhase6PoSource($root . '/ajax/inventory_purchase_order.php');
    foreach (['InventoryPurchaseOrderService.php', 'create_submit', 'approve', "require_permission('inventory.edit'", "require_csrf('inventory_receiving'", "auth_guard_has_permission('inventory.approve'", "auth_guard_has_permission('accounting.view'", 'PURCHASE_ORDER_APPROVAL_REQUIRED'] as $needle) {
        inventoryPhase6PoAssert(strpos($endpoint, $needle) !== false, 'PO endpoint should include: ' . $needle);
    }

    $receivingSource = inventoryPhase6PoSource($root . '/classes/Inventory/InventoryPurchaseReceivingService.php');
    foreach (['PURCHASE_ORDER_NOT_APPROVED', 'PURCHASE_ORDER_OVER_RECEIPT', 'FOR UPDATE'] as $needle) {
        inventoryPhase6PoAssert(strpos($receivingSource, $needle) !== false, 'receiving should guard PO receipts: ' . $needle);
    }

    $orderSource = inventoryPhase6PoSource($root . '/classes/Inventory/InventoryPurchaseOrderService.php');
    foreach (['assertExistingOrderReplay', 'PURCHASE_ORDER_IDEMPOTENCY_CONFLICT', 'canonicalOrderRequestLines', 'canonicalOrderStoredLines'] as $needle) {
        inventoryPhase6PoAssert(strpos($orderSource, $needle) !== false, 'purchase order service should guard idempotent replay conflicts: ' . $needle);
    }
}

function inventoryPhase6PoOne(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase6PoAssert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryPhase6PoDecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === $expected;
}

function inventoryPhase6PoSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase6PoAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
