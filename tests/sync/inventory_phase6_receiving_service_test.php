<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryPurchaseReceivingService.php';

inventoryPhase6ReceivingAssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase6-receiving-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase6_receiving_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase6ReceivingCreateLegacyTables($conn);
    $conn->query("
        INSERT INTO myitems (id, iname, itmqty, cost_price, last_price, item_type, track_stock)
        VALUES
            (6201, 'Receiving item', 0.000000, 0.000000, 0.000000, 'sellable', 1),
            (6202, 'PO receiving item', 0.000000, 0.000000, 0.000000, 'ingredient', 1),
            (6203, 'Case receiving item', 0.000000, 0.000000, 0.000000, 'ingredient', 1)
    ");
    $conn->query("INSERT INTO myunits (id, uname) VALUES (900, 'Case')");
    $conn->query("
        INSERT INTO item_units (item_id, unit_id, u_val, cost_price)
        VALUES (6203, 900, 12.000000, 60.000000)
    ");
    $conn->query("
        INSERT INTO inventory_purchase_orders
          (id, purchase_order_uuid, pos_tenant, pos_branch, supplier_account_id, destination_store_id, status, created_by)
        VALUES
          (7001, '11111111-1111-4111-8111-111111111111', 0, 0, 2101, 3, 'approved', 7)
    ");
    $conn->query("
        INSERT INTO inventory_purchase_order_lines
          (id, purchase_order_id, item_id, ordered_qty, received_qty, unit_cost, total_cost)
        VALUES
          (7101, 7001, 6202, 10.000000, 0.000000, 5.000000, 50.000000)
    ");

    $receivingConfig = [
        'role' => 'branch',
        'branch' => [
            'uuid' => 'abababab-abab-4bab-8bab-abababababab',
            'name' => 'Receiving Test Branch',
            'pos_tenant' => 0,
            'pos_branch' => 0,
        ],
        'sync' => [
            'outbox_enabled' => true,
            'branch_sync_enabled' => true,
            'operational_sync_enabled' => true,
        ],
        'inventory' => [
            'ledger_mode' => 'bridge',
            'legacy_mirror' => '1',
        ],
    ];
    $service = new InventoryPurchaseReceivingService(new InventoryFeatureFlags($receivingConfig));

    $receipt = $service->receive($conn, [
        'purchase_receipt_uuid' => '22222222-2222-4222-8222-222222222222',
        'supplier_account_id' => 2101,
        'destination_store_id' => 3,
        'supplier_invoice_no' => 'SUP-100',
        'lines' => [
            ['item_id' => 6201, 'qty' => '4.000000', 'unit_cost' => '6.000000'],
        ],
    ], ['user_id' => 7]);
    inventoryPhase6ReceivingAssert($receipt['success'] === true && $receipt['movement_ids'] !== [], 'direct receipt should create movement');
    inventoryPhase6ReceivingAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'purchase_receipt' AND aggregate_local_id = " . (int) $receipt['receipt_id'])->fetch_assoc()['c'] === 1,
        'posted receipt should capture one immutable sync bundle in the same transaction'
    );
    $receiptEventId = (int) $conn->query("SELECT id FROM sync_outbox WHERE aggregate_type = 'purchase_receipt' AND aggregate_local_id = " . (int) $receipt['receipt_id'])->fetch_assoc()['id'];
    $effectEventId = (int) $conn->query("SELECT COALESCE(MAX(id), 0) AS id FROM sync_outbox WHERE aggregate_type IN ('inventory_movement', 'inventory_balance', 'inventory_journal')")->fetch_assoc()['id'];
    inventoryPhase6ReceivingAssert($receiptEventId > $effectEventId, 'final receipt event should follow its stock, balance and optional accounting events');
    $balance = inventoryPhase6ReceivingOne($conn, 'SELECT * FROM inventory_item_balances WHERE item_id = 6201 AND store_id = 3 LIMIT 1');
    inventoryPhase6ReceivingAssert(inventoryPhase6ReceivingDecimalEquals($balance['qty_on_hand'], '4.000000'), 'direct receipt should increase stock');
    inventoryPhase6ReceivingAssert(inventoryPhase6ReceivingDecimalEquals($balance['moving_average_cost'], '6.000000'), 'direct receipt should set average cost');

    $replay = $service->receive($conn, [
        'purchase_receipt_uuid' => '22222222-2222-4222-8222-222222222222',
        'supplier_account_id' => 2101,
        'destination_store_id' => 3,
        'lines' => [
            ['item_id' => 6201, 'qty' => '4.000000', 'unit_cost' => '6.000000'],
        ],
    ], ['user_id' => 7]);
    inventoryPhase6ReceivingAssert(!empty($replay['idempotent_replay']), 'same receipt uuid should replay');
    inventoryPhase6ReceivingAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'purchase_receipt' AND aggregate_local_id = " . (int) $receipt['receipt_id'])->fetch_assoc()['c'] === 1,
        'exact receipt replay should heal but never duplicate its deterministic sync event'
    );
    inventoryPhase6ReceivingAssert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE item_id = 6201 AND movement_type = 'purchase'")->fetch_assoc()['c'] === 1, 'receipt replay should not duplicate purchase movement');

    try {
        $service->receive($conn, [
            'purchase_receipt_uuid' => '55555555-5555-4555-8555-555555555555',
            'supplier_account_id' => 2101,
            'destination_store_id' => 3,
            'supplier_invoice_no' => 'SUP-100',
            'lines' => [
                ['item_id' => 6201, 'qty' => '1.000000', 'unit_cost' => '6.000000'],
            ],
        ], ['user_id' => 7]);
        inventoryPhase6ReceivingAssert(false, 'duplicate supplier invoice should fail');
    } catch (RuntimeException $exception) {
        inventoryPhase6ReceivingAssert($exception->getMessage() === 'SUPPLIER_INVOICE_DUPLICATE', 'duplicate supplier invoice should return expected code');
    }
    inventoryPhase6ReceivingAssert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE item_id = 6201 AND movement_type = 'purchase'")->fetch_assoc()['c'] === 1, 'duplicate supplier invoice should not create a second purchase movement');

    try {
        $service->receive($conn, [
            'purchase_receipt_uuid' => '88888888-8888-4888-8888-888888888888',
            'supplier_account_id' => 2101,
            'destination_store_id' => 3,
            'supplier_invoice_no' => 'SUP-150',
            'lines' => [
                ['item_id' => 0, 'qty' => '1.000000', 'unit_cost' => '6.000000'],
            ],
        ], ['user_id' => 7]);
        inventoryPhase6ReceivingAssert(false, 'missing item selection should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase6ReceivingAssert($exception->getMessage() === 'INVENTORY_ITEM_REQUIRED', 'missing item selection should return expected code');
    }
    inventoryPhase6ReceivingAssert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_purchase_receipts WHERE supplier_invoice_no = 'SUP-150'")->fetch_assoc()['c'] === 0, 'missing item selection should not create receipt header');

    try {
        $service->receive($conn, [
            'purchase_receipt_uuid' => '99999999-9999-4999-8999-999999999999',
            'supplier_account_id' => 2101,
            'destination_store_id' => 3,
            'supplier_invoice_no' => 'SUP-151',
            'lines' => [
                ['item_id' => 6299, 'qty' => '1.000000', 'unit_cost' => '6.000000'],
            ],
        ], ['user_id' => 7]);
        inventoryPhase6ReceivingAssert(false, 'unknown item should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase6ReceivingAssert($exception->getMessage() === 'INVENTORY_ITEM_NOT_FOUND', 'unknown item should return expected code');
    }
    inventoryPhase6ReceivingAssert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_purchase_receipts WHERE supplier_invoice_no = 'SUP-151'")->fetch_assoc()['c'] === 0, 'unknown item failure should roll back receipt header');

    $unitReceipt = $service->receive($conn, [
        'purchase_receipt_uuid' => '66666666-6666-4666-8666-666666666666',
        'supplier_account_id' => 2101,
        'destination_store_id' => 3,
        'supplier_invoice_no' => 'SUP-200',
        'lines' => [
            ['item_id' => 6203, 'unit_id' => 900, 'qty' => '2.000000', 'unit_cost' => '60.000000'],
        ],
    ], ['user_id' => 7]);
    inventoryPhase6ReceivingAssert($unitReceipt['success'] === true && $unitReceipt['movement_ids'] !== [], 'unit receipt should create movement');
    $unitBalance = inventoryPhase6ReceivingOne($conn, 'SELECT * FROM inventory_item_balances WHERE item_id = 6203 AND store_id = 3 LIMIT 1');
    inventoryPhase6ReceivingAssert(inventoryPhase6ReceivingDecimalEquals($unitBalance['qty_on_hand'], '24.000000'), 'unit receipt should convert received quantity to base units');
    inventoryPhase6ReceivingAssert(inventoryPhase6ReceivingDecimalEquals($unitBalance['moving_average_cost'], '5.000000'), 'unit receipt should convert entered unit cost to base unit cost');
    $unitMovement = inventoryPhase6ReceivingOne($conn, 'SELECT unit_id, unit_conversion_to_base, qty_in, unit_cost, total_cost, metadata_json FROM inventory_movements WHERE id = ' . (int) $unitReceipt['movement_ids'][0]);
    inventoryPhase6ReceivingAssert((int) $unitMovement['unit_id'] === 900, 'unit receipt movement should preserve selected unit id');
    inventoryPhase6ReceivingAssert(inventoryPhase6ReceivingDecimalEquals($unitMovement['unit_conversion_to_base'], '12.000000'), 'unit receipt movement should preserve selected unit conversion');
    inventoryPhase6ReceivingAssert(inventoryPhase6ReceivingDecimalEquals($unitMovement['qty_in'], '24.000000'), 'unit receipt movement should write base qty_in');
    inventoryPhase6ReceivingAssert(inventoryPhase6ReceivingDecimalEquals($unitMovement['unit_cost'], '5.000000'), 'unit receipt movement should write base unit cost');
    inventoryPhase6ReceivingAssert(inventoryPhase6ReceivingDecimalEquals($unitMovement['total_cost'], '120.000000'), 'unit receipt movement should preserve entered total cost');
    inventoryPhase6ReceivingAssert(strpos((string) $unitMovement['metadata_json'], '"entered_qty":"2.000000"') !== false, 'unit receipt movement should retain entered unit quantity in metadata');
    try {
        $service->receive($conn, [
            'purchase_receipt_uuid' => '77777777-7777-4777-8777-777777777777',
            'supplier_account_id' => 2101,
            'destination_store_id' => 3,
            'supplier_invoice_no' => 'SUP-201',
            'lines' => [
                ['item_id' => 6203, 'unit_id' => 999, 'qty' => '1.000000', 'unit_cost' => '60.000000'],
            ],
        ], ['user_id' => 7]);
        inventoryPhase6ReceivingAssert(false, 'unknown item unit should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase6ReceivingAssert($exception->getMessage() === 'ITEM_UNIT_NOT_FOUND', 'unknown item unit should return expected code');
    }
    inventoryPhase6ReceivingAssert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_purchase_receipts WHERE supplier_invoice_no = 'SUP-201'")->fetch_assoc()['c'] === 0, 'unknown unit failure should roll back receipt header');

    $poReceipt = $service->receive($conn, [
        'purchase_receipt_uuid' => '33333333-3333-4333-8333-333333333333',
        'purchase_order_id' => 7001,
        'supplier_account_id' => 2101,
        'destination_store_id' => 3,
        'lines' => [
            ['purchase_order_line_id' => 7101, 'item_id' => 6202, 'qty' => '4.000000', 'unit_cost' => '5.000000'],
        ],
    ], ['user_id' => 7]);
    inventoryPhase6ReceivingAssert($poReceipt['success'] === true, 'PO receipt should be accepted');
    $poLine = inventoryPhase6ReceivingOne($conn, 'SELECT received_qty FROM inventory_purchase_order_lines WHERE id = 7101 LIMIT 1');
    $po = inventoryPhase6ReceivingOne($conn, 'SELECT status FROM inventory_purchase_orders WHERE id = 7001 LIMIT 1');
    inventoryPhase6ReceivingAssert(inventoryPhase6ReceivingDecimalEquals($poLine['received_qty'], '4.000000'), 'PO line should track partial received qty');
    inventoryPhase6ReceivingAssert($po['status'] === 'partially_received', 'PO should become partially received');

    $return = $service->returnItems($conn, [
        'purchase_receipt_uuid' => '44444444-4444-4444-8444-444444444444',
        'supplier_account_id' => 2101,
        'destination_store_id' => 3,
        'lines' => [
            ['item_id' => 6201, 'qty' => '1.000000', 'unit_cost' => '6.000000'],
        ],
    ], ['user_id' => 7]);
    inventoryPhase6ReceivingAssert($return['success'] === true && $return['movement_ids'] !== [], 'purchase return should create movement');
    inventoryPhase6ReceivingAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'purchase_receipt' AND aggregate_local_id = " . (int) $return['receipt_id'])->fetch_assoc()['c'] === 1,
        'purchase return should capture one immutable sync bundle in the same transaction'
    );
    $balance = inventoryPhase6ReceivingOne($conn, 'SELECT * FROM inventory_item_balances WHERE item_id = 6201 AND store_id = 3 LIMIT 1');
    inventoryPhase6ReceivingAssert(inventoryPhase6ReceivingDecimalEquals($balance['qty_on_hand'], '3.000000'), 'purchase return should decrease stock');
    $returnMovement = inventoryPhase6ReceivingOne($conn, "SELECT movement_type, qty_out FROM inventory_movements WHERE source_uuid LIKE 'purchase-return:%' LIMIT 1");
    inventoryPhase6ReceivingAssert($returnMovement['movement_type'] === 'purchase_return', 'purchase return should use dedicated outbound purchase_return movement type');

    $receiptCountBeforeFailedCapture = (int) $conn->query('SELECT COUNT(*) AS c FROM inventory_purchase_receipts')->fetch_assoc()['c'];
    $movementCountBeforeFailedCapture = (int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'];
    $failingSyncEvents = new class extends OperationalSyncEventService {
        public function recordPurchaseReceiptSnapshot(mysqli $conn, int $receiptId, array $options = []): ?array
        {
            throw new RuntimeException('PURCHASE_RECEIPT_SYNC_CAPTURE_FAILED_FOR_TEST');
        }
    };
    $atomicService = new InventoryPurchaseReceivingService(
        new InventoryFeatureFlags($receivingConfig),
        null,
        null,
        null,
        null,
        $failingSyncEvents
    );
    try {
        $atomicService->receive($conn, [
            'purchase_receipt_uuid' => '85858585-8585-4585-8585-858585858585',
            'supplier_account_id' => 2101,
            'destination_store_id' => 3,
            'supplier_invoice_no' => 'SUP-ATOMIC-FAIL',
            'lines' => [
                ['item_id' => 6201, 'qty' => '1.000000', 'unit_cost' => '6.000000'],
            ],
        ], ['user_id' => 7]);
        inventoryPhase6ReceivingAssert(false, 'required sync capture failure should fail the receipt transaction');
    } catch (RuntimeException $exception) {
        inventoryPhase6ReceivingAssert($exception->getMessage() === 'PURCHASE_RECEIPT_SYNC_CAPTURE_FAILED_FOR_TEST', 'capture failure should remain visible to the caller');
    }
    inventoryPhase6ReceivingAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM inventory_purchase_receipts')->fetch_assoc()['c'] === $receiptCountBeforeFailedCapture,
        'capture failure must roll back the receipt header and lines'
    );
    inventoryPhase6ReceivingAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'] === $movementCountBeforeFailedCapture,
        'capture failure must roll back its stock movement'
    );

    $conn->begin_transaction();
    try {
        $atomicService->receive($conn, [
            'purchase_receipt_uuid' => '86868686-8686-4686-8686-868686868686',
            'supplier_account_id' => 2101,
            'destination_store_id' => 3,
            'supplier_invoice_no' => 'SUP-CALLER-TX-FAIL',
            'lines' => [
                ['item_id' => 6201, 'qty' => '1.000000', 'unit_cost' => '6.000000'],
            ],
        ], ['user_id' => 7, 'in_transaction' => true]);
        inventoryPhase6ReceivingAssert(false, 'caller-owned transaction should still see required capture failure');
    } catch (RuntimeException $exception) {
        inventoryPhase6ReceivingAssert($exception->getMessage() === 'PURCHASE_RECEIPT_SYNC_CAPTURE_FAILED_FOR_TEST', 'caller-owned capture failure should propagate');
        inventoryPhase6ReceivingAssert(
            (int) $conn->query("SELECT COUNT(*) AS c FROM inventory_purchase_receipts WHERE supplier_invoice_no = 'SUP-CALLER-TX-FAIL'")->fetch_assoc()['c'] === 1,
            'service must leave the caller-owned transaction open for the caller to roll back'
        );
        $conn->rollback();
    }
    inventoryPhase6ReceivingAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM inventory_purchase_receipts')->fetch_assoc()['c'] === $receiptCountBeforeFailedCapture,
        'caller rollback must remove the failed receipt transaction'
    );
    inventoryPhase6ReceivingAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'] === $movementCountBeforeFailedCapture,
        'caller rollback must remove the failed receipt movement'
    );

    echo "inventory-phase6-receiving-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase6ReceivingCreateLegacyTables(mysqli $conn): void
{
    $conn->query("CREATE TABLE acc_head (
        id INT NOT NULL PRIMARY KEY,
        code VARCHAR(20) NOT NULL,
        aname VARCHAR(100) NOT NULL,
        is_stock TINYINT(1) NOT NULL DEFAULT 0,
        isdeleted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO acc_head (id, code, aname, is_stock, isdeleted) VALUES
        (3, '1303', 'Receiving operational store', 1, 0)");
    $conn->query("CREATE TABLE settings (
        id INT NOT NULL PRIMARY KEY,
        def_pos_store INT NULL
    ) ENGINE=InnoDB");
    $conn->query('INSERT INTO settings (id, def_pos_store) VALUES (1, 3)');
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
    $conn->query("
CREATE TABLE myunits (
  id INT NOT NULL PRIMARY KEY,
  uname VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE item_units (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  unit_id INT NOT NULL,
  u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase6ReceivingAssertSourceContracts(string $root): void
{
    $page = inventoryPhase6ReceivingSource($root . '/inventory_purchasing.php');
    foreach (['استلام المشتريات', 'مردود', 'الوحدة', 'ajax/inventory_purchase_receive.php', 'inventory-receiving-csrf', 'inventoryPurchasePreferredUnits', 'inventoryPreferredPurchaseUnit', 'inventoryDefaultSupplierAccount', 'applyInventoryDefaultSupplier', 'inventoryPurchaseSupplierDefaults', 'inventorySupplierPurchaseDefault', 'inventoryDefaultPurchaseUnit', 'inventoryPurchaseBarcodeScan', 'applyInventoryPurchaseScan', 'findInventoryBlankLine', 'مسح باركود الاستلام', 'كمية كل مسحة', 'assertInventoryLinesReady', 'اختر صنفاً مسجلاً'] as $needle) {
        inventoryPhase6ReceivingAssert(strpos($page, $needle) !== false, 'Arabic receiving UI should include: ' . $needle);
    }
    foreach (['inventoryPurchasingColumnExists', 'inventoryPurchaseHasDefaultSupplierColumn', 'AS default_supplier_account_id'] as $needle) {
        inventoryPhase6ReceivingAssert(strpos($page, $needle) !== false, 'receiving UI should tolerate legacy stock-level schema without default supplier column: ' . $needle);
    }

    $endpoint = inventoryPhase6ReceivingSource($root . '/ajax/inventory_purchase_receive.php');
    foreach (['InventoryPurchaseReceivingService.php', "require_permission('inventory.edit'", "require_csrf('inventory_receiving'", 'returnItems', 'receive($conn', 'SUPPLIER_INVOICE_DUPLICATE', 'ITEM_UNIT_NOT_FOUND', 'INVENTORY_ITEM_REQUIRED', 'INVENTORY_ITEM_NOT_FOUND'] as $needle) {
        inventoryPhase6ReceivingAssert(strpos($endpoint, $needle) !== false, 'receiving endpoint should include: ' . $needle);
    }

    $serviceSource = inventoryPhase6ReceivingSource($root . '/classes/Inventory/InventoryPurchaseReceivingService.php');
    foreach (['assertExistingReceiptReplay', 'PURCHASE_RECEIPT_IDEMPOTENCY_CONFLICT', 'canonicalReceiptRequestLines', 'canonicalReceiptStoredLines', 'INVENTORY_ITEM_REQUIRED', 'INVENTORY_ITEM_NOT_FOUND'] as $needle) {
        inventoryPhase6ReceivingAssert(strpos($serviceSource, $needle) !== false, 'receiving service should guard idempotent replay conflicts: ' . $needle);
    }

    $sidebar = inventoryPhase6ReceivingSource($root . '/includes/sidebar.php');
    inventoryPhase6ReceivingAssert(strpos($sidebar, 'inventory_purchasing.php') !== false && strpos($sidebar, 'استلام المخزون') !== false, 'sidebar should link Arabic receiving page');

    $docs = inventoryPhase6ReceivingSource($root . '/docs/inventory/phase6_purchase_bridge_contracts.md');
    foreach (['block duplicate supplier invoice numbers', 'Purchase returns are not blocked', 'Purchase returns now create dedicated', 'unit_conversion_to_base', 'preferred_purchase_unit_id', 'barcode entry', 'supplier purchase history', 'default_supplier_account_id', 'no supplier catalog table', 'Explicit supplier item catalogs'] as $needle) {
        inventoryPhase6ReceivingAssert(strpos($docs, $needle) !== false, 'phase6 docs should include: ' . $needle);
    }
}

function inventoryPhase6ReceivingOne(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase6ReceivingAssert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryPhase6ReceivingDecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === $expected;
}

function inventoryPhase6ReceivingSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase6ReceivingAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
