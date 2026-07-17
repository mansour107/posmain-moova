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
        'role' => 'branch',
        'branch' => ['uuid'=>'57575757-5757-4757-8757-575757575757','name'=>'PO Test Branch','pos_tenant'=>0,'pos_branch'=>0],
        'sync' => ['outbox_enabled'=>true,'branch_sync_enabled'=>true,'operational_sync_enabled'=>true],
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
    inventoryPhase6PoAssert((int) inventoryPhase6PoOne($conn, 'SELECT sync_revision FROM inventory_purchase_orders WHERE id = '.(int)$draft['purchase_order_id'])['sync_revision'] === 1, 'draft should start at sync revision 1');
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

    try {
        $orderService->createDraft($conn, [
            'purchase_order_uuid' => '99999999-9999-4999-8999-999999999999',
            'supplier_account_id' => 2101,
            'destination_store_id' => 3,
            'lines' => [
                ['item_id' => 0, 'qty' => '1.000000', 'unit_cost' => '7.500000'],
            ],
        ], ['user_id' => 7]);
        inventoryPhase6PoAssert(false, 'purchase order missing item selection should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase6PoAssert($exception->getMessage() === 'INVENTORY_ITEM_REQUIRED', 'purchase order missing item should return expected code');
    }
    inventoryPhase6PoAssert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_purchase_orders')->fetch_assoc()['c'] === 1, 'missing item should not create purchase order header');

    try {
        $orderService->createDraft($conn, [
            'purchase_order_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'supplier_account_id' => 2101,
            'destination_store_id' => 3,
            'lines' => [
                ['item_id' => 6399, 'qty' => '1.000000', 'unit_cost' => '7.500000'],
            ],
        ], ['user_id' => 7]);
        inventoryPhase6PoAssert(false, 'purchase order unknown item should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase6PoAssert($exception->getMessage() === 'INVENTORY_ITEM_NOT_FOUND', 'purchase order unknown item should return expected code');
    }
    inventoryPhase6PoAssert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_purchase_orders')->fetch_assoc()['c'] === 1, 'unknown item should not create purchase order header');

    $submitted = $orderService->submit($conn, (int) $draft['purchase_order_id'], ['user_id' => 8]);
    inventoryPhase6PoAssert($submitted['status'] === 'submitted', 'draft order should submit');
    inventoryPhase6PoAssert((int) inventoryPhase6PoOne($conn, 'SELECT sync_revision FROM inventory_purchase_orders WHERE id = '.(int)$draft['purchase_order_id'])['sync_revision'] === 2, 'submit should advance sync revision once');
    $submittedRow = inventoryPhase6PoOne($conn, 'SELECT status, submitted_by, submitted_at FROM inventory_purchase_orders WHERE id = ' . (int) $draft['purchase_order_id'] . ' LIMIT 1');
    inventoryPhase6PoAssert($submittedRow['status'] === 'submitted' && (int) $submittedRow['submitted_by'] === 8 && $submittedRow['submitted_at'] !== null, 'submitted order should store reviewer trail');

    $approved = $orderService->approve($conn, (int) $draft['purchase_order_id'], ['user_id' => 9]);
    inventoryPhase6PoAssert($approved['status'] === 'approved', 'submitted order should approve');
    inventoryPhase6PoAssert((int) inventoryPhase6PoOne($conn, 'SELECT sync_revision FROM inventory_purchase_orders WHERE id = '.(int)$draft['purchase_order_id'])['sync_revision'] === 3, 'approve should advance sync revision once');
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
    inventoryPhase6PoAssert((int) inventoryPhase6PoOne($conn, 'SELECT sync_revision FROM inventory_purchase_orders WHERE id = '.(int)$draft['purchase_order_id'])['sync_revision'] === 4, 'partial receipt should advance sync revision once');

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
    inventoryPhase6PoAssert((int) inventoryPhase6PoOne($conn, 'SELECT sync_revision FROM inventory_purchase_orders WHERE id = '.(int)$draft['purchase_order_id'])['sync_revision'] === 5, 'final receipt should advance sync revision once');
    inventoryPhase6PoAssert((int)$conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type='purchase_order' AND aggregate_local_id=".(int)$draft['purchase_order_id'])->fetch_assoc()['c'] === 5, 'each authoritative PO revision should have one deterministic event');

    $failingEvents = new class extends OperationalSyncEventService {
        public function recordPurchaseOrderSnapshot(mysqli $conn, int $orderId, array $options = []): ?array
        {
            throw new RuntimeException('PURCHASE_ORDER_SYNC_CAPTURE_FAILED_FOR_TEST');
        }
    };
    $failingOrderService = new InventoryPurchaseOrderService($flags, null, $failingEvents);
    $ordersBeforeFailure = (int)$conn->query('SELECT COUNT(*) c FROM inventory_purchase_orders')->fetch_assoc()['c'];
    try {
        $failingOrderService->createDraft($conn, ['purchase_order_uuid'=>'58585858-5858-4858-8858-585858585858','supplier_account_id'=>2101,'destination_store_id'=>3,'lines'=>[['item_id'=>6302,'qty'=>'2.000000','unit_cost'=>'4.000000']]], ['user_id'=>7]);
        inventoryPhase6PoAssert(false,'required PO capture failure should fail create');
    } catch (RuntimeException $e) { inventoryPhase6PoAssert($e->getMessage()==='PURCHASE_ORDER_SYNC_CAPTURE_FAILED_FOR_TEST','PO capture failure should propagate'); }
    inventoryPhase6PoAssert((int)$conn->query('SELECT COUNT(*) c FROM inventory_purchase_orders')->fetch_assoc()['c']===$ordersBeforeFailure,'failed PO capture should roll back header and lines');

    $progressOrder=$orderService->createDraft($conn,['purchase_order_uuid'=>'59595959-5959-4959-8959-595959595959','supplier_account_id'=>2101,'destination_store_id'=>3,'lines'=>[['item_id'=>6302,'qty'=>'2.000000','unit_cost'=>'4.000000']]],['user_id'=>7]);
    $progressId=(int)$progressOrder['purchase_order_id'];$progressLine=(int)inventoryPhase6PoOne($conn,'SELECT id FROM inventory_purchase_order_lines WHERE purchase_order_id='.$progressId)['id'];$orderService->submit($conn,$progressId,['user_id'=>8]);$orderService->approve($conn,$progressId,['user_id'=>9]);
    $failingReceiving = new InventoryPurchaseReceivingService($flags,null,null,null,null,$failingEvents);
    $movementsBeforeFailure=(int)$conn->query('SELECT COUNT(*) c FROM inventory_movements')->fetch_assoc()['c'];
    try {
        $failingReceiving->receive($conn,['purchase_receipt_uuid'=>'60606060-6060-4060-8060-606060606060','purchase_order_id'=>$progressId,'supplier_account_id'=>2101,'destination_store_id'=>3,'lines'=>[['purchase_order_line_id'=>$progressLine,'item_id'=>6302,'qty'=>'1.000000','unit_cost'=>'4.000000']]],['user_id'=>10]);
        inventoryPhase6PoAssert(false,'PO progress capture failure should fail receipt');
    } catch (RuntimeException $e) { inventoryPhase6PoAssert($e->getMessage()==='PURCHASE_ORDER_SYNC_CAPTURE_FAILED_FOR_TEST','PO progress capture failure should propagate'); }
    $failedProgress=inventoryPhase6PoOne($conn,'SELECT o.status,o.sync_revision,l.received_qty FROM inventory_purchase_orders o JOIN inventory_purchase_order_lines l ON l.purchase_order_id=o.id WHERE o.id='.$progressId);
    inventoryPhase6PoAssert($failedProgress['status']==='approved'&&(int)$failedProgress['sync_revision']===3&&inventoryPhase6PoDecimalEquals($failedProgress['received_qty'],'0.000000'),'failed progress capture should roll back PO state');
    inventoryPhase6PoAssert((int)$conn->query('SELECT COUNT(*) c FROM inventory_movements')->fetch_assoc()['c']===$movementsBeforeFailure,'failed PO progress capture should roll back stock movement');

    echo "inventory-phase6-purchase-order-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase6PoCreateLegacyItemTable(mysqli $conn): void
{
    $conn->query("CREATE TABLE acc_head (id INT PRIMARY KEY, code VARCHAR(20) NOT NULL, aname VARCHAR(100) NOT NULL, is_stock TINYINT NOT NULL DEFAULT 0, isdeleted TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
    $conn->query("INSERT INTO acc_head (id,code,aname,is_stock,isdeleted) VALUES (3,'1303','PO operational store',1,0)");
    $conn->query("CREATE TABLE settings (id INT PRIMARY KEY, def_pos_store INT NULL) ENGINE=InnoDB");
    $conn->query("INSERT INTO settings (id,def_pos_store) VALUES (1,3)");
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
    foreach (['saveInventoryPurchaseOrder', 'submitInventoryPurchaseOrder', 'approveInventoryPurchaseOrder', 'inventoryPurchaseCanApproveOrders', 'purchase_order_line_id', 'ajax/inventory_purchase_order.php', 'inventoryPurchaseOrderStatusLabel', "'submitted' => 'بانتظار الاعتماد'", "'partially_received' => 'مستلم جزئيا'", 'inventory-purchase-item-search', 'applyInventoryPurchaseItemSearch', 'نتائج مطابقة', 'assertInventoryLinesReady'] as $needle) {
        inventoryPhase6PoAssert(strpos($page, $needle) !== false, 'Arabic purchasing UI should include PO workflow: ' . $needle);
    }

    $docs = inventoryPhase6PoSource($root . '/docs/inventory/phase6_purchase_bridge_contracts.md');
    inventoryPhase6PoAssert(strpos($docs, 'purchase-order statuses in Arabic') !== false, 'Phase 6 docs should describe Arabic PO status labels');
    inventoryPhase6PoAssert(strpos($docs, 'in-row item search') !== false, 'Phase 6 docs should describe purchasing line item search');
    inventoryPhase6PoAssert(strpos($docs, '`inventory.approve` or `accounting.view`') !== false, 'Phase 6 docs should describe PO approval permission boundary');

    $endpoint = inventoryPhase6PoSource($root . '/ajax/inventory_purchase_order.php');
    foreach (['InventoryPurchaseOrderService.php', 'create_submit', 'approve', "require_permission('inventory.edit'", "require_csrf('inventory_receiving'", "auth_guard_has_permission('inventory.approve'", "auth_guard_has_permission('accounting.view'", 'PURCHASE_ORDER_APPROVAL_REQUIRED', 'INVENTORY_ITEM_REQUIRED', 'INVENTORY_ITEM_NOT_FOUND'] as $needle) {
        inventoryPhase6PoAssert(strpos($endpoint, $needle) !== false, 'PO endpoint should include: ' . $needle);
    }

    $receivingSource = inventoryPhase6PoSource($root . '/classes/Inventory/InventoryPurchaseReceivingService.php');
    foreach (['PURCHASE_ORDER_NOT_APPROVED', 'PURCHASE_ORDER_OVER_RECEIPT', 'FOR UPDATE'] as $needle) {
        inventoryPhase6PoAssert(strpos($receivingSource, $needle) !== false, 'receiving should guard PO receipts: ' . $needle);
    }

    $orderSource = inventoryPhase6PoSource($root . '/classes/Inventory/InventoryPurchaseOrderService.php');
    foreach (['assertExistingOrderReplay', 'PURCHASE_ORDER_IDEMPOTENCY_CONFLICT', 'canonicalOrderRequestLines', 'canonicalOrderStoredLines', 'assertRegisteredItem', 'INVENTORY_ITEM_NOT_FOUND'] as $needle) {
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
