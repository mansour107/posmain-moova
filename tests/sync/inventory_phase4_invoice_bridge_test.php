<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryInvoiceBridge.php';

inventoryPhase4AssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase4-invoice-bridge-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase4_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    $manager = new SyncSchemaManager();
    $manager->apply($conn);
    inventoryPhase4CreateLegacyItemTable($conn);
    inventoryPhase4SeedItem($conn, 1001, 'Shadow stock item', 'sellable', 1, '20.000000', '2.000000');
    inventoryPhase4SeedItem($conn, 2002, 'Shadow service item', 'service', 1, '0.000000', '0.000000');

    $flags = new InventoryFeatureFlags(['inventory' => [
        'ledger_mode' => 'shadow',
        'strict_stock' => '1',
        'legacy_mirror' => '1',
    ], 'branch' => [
        'pos_tenant' => 3,
        'pos_branch' => 5,
        'uuid' => '00000000-0000-4000-8000-000000000005',
    ]]);
    $bridge = new InventoryInvoiceBridge($flags);

    $conn->begin_transaction();
    $result = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_PURCHASE, 7001, [[
        'id' => 501,
        'item_id' => 1001,
        'qty_in' => '10.000000',
        'qty_out' => '0.000000',
        'u_val' => '1.000000',
        'cost_price' => '2.000000',
        'det_store' => 7,
    ]], ['user_id' => 9]);
    $conn->commit();
    inventoryPhase4Assert($result['success'] === true && $result['movements'] !== [], 'shadow purchase should create a ledger movement result');
    inventoryPhase4Assert($result['movements'][0]['shadow_write'] === true, 'shadow purchase should be marked as shadow write');

    $conn->begin_transaction();
    $sale = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_SALES, 7002, [[
        'id' => 502,
        'item_id' => 1001,
        'qty_in' => '0.000000',
        'qty_out' => '3.000000',
        'u_val' => '1.000000',
        'cost_price' => '2.000000',
        'det_store' => 7,
    ]], ['user_id' => 9]);
    $conn->commit();
    inventoryPhase4Assert($sale['success'] === true && $sale['movements'] !== [], 'shadow sale should create a ledger movement result');

    $balance = inventoryPhase4One($conn, 'SELECT * FROM inventory_item_balances WHERE item_id = 1001 AND store_id = 7 LIMIT 1');
    inventoryPhase4Assert(inventoryPhase4DecimalEquals($balance['qty_on_hand'], '7.000000'), 'shadow purchase and sale should update new ledger balance');
    inventoryPhase4Assert(inventoryPhase4DecimalEquals($balance['qty_available'], '7.000000'), 'shadow sale should update available balance');
    $legacy = inventoryPhase4One($conn, 'SELECT itmqty FROM myitems WHERE id = 1001 LIMIT 1');
    inventoryPhase4Assert(inventoryPhase4DecimalEquals($legacy['itmqty'], '20.000000'), 'shadow bridge must not mirror or mutate legacy myitems.itmqty');

    $conn->begin_transaction();
    $replay = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_SALES, 7002, [[
        'id' => 502,
        'item_id' => 1001,
        'qty_in' => '0.000000',
        'qty_out' => '3.000000',
        'u_val' => '1.000000',
        'cost_price' => '2.000000',
        'det_store' => 7,
    ]], ['user_id' => 9]);
    $conn->commit();
    inventoryPhase4Assert(!empty($replay['movements'][0]['idempotent_replay']), 'same invoice detail should replay idempotently');
    inventoryPhase4Assert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'] === 2, 'idempotent replay should not duplicate shadow movement');

    $conn->begin_transaction();
    $reversal = $bridge->recordInvoiceReversalLines($conn, InventoryInvoiceBridge::TYPE_SALES, 7002, [[
        'id' => 502,
        'item_id' => 1001,
        'qty_in' => '0.000000',
        'qty_out' => '3.000000',
        'u_val' => '1.000000',
        'cost_price' => '2.000000',
        'det_store' => 7,
    ]], 'invoice_deleted', ['user_id' => 9]);
    $conn->commit();
    inventoryPhase4Assert($reversal['success'] === true && $reversal['movements'] !== [], 'invoice deletion should reverse only proven original shadow movement');
    $reversalMovement = inventoryPhase4One($conn, "SELECT movement_type, qty_in, qty_out FROM inventory_movements WHERE idempotency_key LIKE 'inventory-invoice-bridge-reversal:%detail:502%' LIMIT 1");
    inventoryPhase4Assert($reversalMovement['movement_type'] === 'refund_reversal', 'sales deletion reversal should return stock through refund_reversal');
    inventoryPhase4Assert(inventoryPhase4DecimalEquals($reversalMovement['qty_in'], '3.000000'), 'sales deletion reversal should be inbound');
    $balance = inventoryPhase4One($conn, 'SELECT * FROM inventory_item_balances WHERE item_id = 1001 AND store_id = 7 LIMIT 1');
    inventoryPhase4Assert(inventoryPhase4DecimalEquals($balance['qty_on_hand'], '10.000000'), 'sales deletion reversal should restore shadow on-hand');

    $conn->begin_transaction();
    $missingOriginal = $bridge->recordInvoiceReversalLines($conn, InventoryInvoiceBridge::TYPE_SALES, 7999, [[
        'id' => 599,
        'item_id' => 1001,
        'qty_in' => '0.000000',
        'qty_out' => '1.000000',
        'det_store' => 7,
    ]], 'invoice_deleted', ['user_id' => 9]);
    $conn->commit();
    inventoryPhase4Assert($missingOriginal['movements'] === [] && $missingOriginal['skipped'][0]['reason'] === 'original_shadow_movement_missing', 'reversal should skip old invoices without original shadow movement');

    $conn->begin_transaction();
    $editOriginal = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_SALES, 7010, [[
        'id' => 508,
        'item_id' => 1001,
        'qty_in' => '0.000000',
        'qty_out' => '4.000000',
        'u_val' => '1.000000',
        'cost_price' => '2.000000',
        'det_store' => 7,
    ]], ['user_id' => 9]);
    $editReversal = $bridge->recordInvoiceReversalLines($conn, InventoryInvoiceBridge::TYPE_SALES, 7010, [[
        'id' => 508,
        'item_id' => 1001,
        'qty_in' => '0.000000',
        'qty_out' => '4.000000',
        'u_val' => '1.000000',
        'cost_price' => '2.000000',
        'det_store' => 7,
    ]], 'invoice_edited', ['user_id' => 9]);
    $editNew = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_SALES, 7010, [[
        'id' => 509,
        'item_id' => 1001,
        'qty_in' => '0.000000',
        'qty_out' => '1.000000',
        'u_val' => '1.000000',
        'cost_price' => '2.000000',
        'det_store' => 7,
    ]], ['user_id' => 9]);
    $conn->commit();
    inventoryPhase4Assert($editOriginal['movements'] !== [] && $editReversal['movements'] !== [] && $editNew['movements'] !== [], 'edit replacement should reverse old shadow line and add new shadow line');
    $editBalance = inventoryPhase4One($conn, 'SELECT * FROM inventory_item_balances WHERE item_id = 1001 AND store_id = 7 LIMIT 1');
    inventoryPhase4Assert(inventoryPhase4DecimalEquals($editBalance['qty_on_hand'], '9.000000'), 'edit replacement should leave only the replacement sale effect');

    $conn->begin_transaction();
    $offer = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_OFFER, 7003, [[
        'id' => 503,
        'item_id' => 1001,
        'qty_in' => '0.000000',
        'qty_out' => '5.000000',
        'det_store' => 7,
    ]], ['user_id' => 9]);
    $conn->commit();
    inventoryPhase4Assert($offer['movements'] === [] && $offer['skipped'] !== [], 'offer should not create stock movement');

    $conn->begin_transaction();
    $service = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_SALES, 7004, [[
        'id' => 504,
        'item_id' => 2002,
        'qty_in' => '0.000000',
        'qty_out' => '2.000000',
        'det_store' => 7,
    ]], ['user_id' => 9]);
    $conn->commit();
    inventoryPhase4Assert(!empty($service['movements'][0]['noop']), 'service item should produce a no-op ledger result');
    inventoryPhase4Assert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements WHERE item_id = 2002')->fetch_assoc()['c'] === 0, 'service item shadow bridge should not create movement row');

    $conn->begin_transaction();
    $negativeShadow = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_SALES, 7005, [[
        'id' => 505,
        'item_id' => 1001,
        'qty_in' => '0.000000',
        'qty_out' => '99.000000',
        'det_store' => 9,
    ]], ['user_id' => 9]);
    $conn->commit();
    inventoryPhase4Assert($negativeShadow['success'] === true && $negativeShadow['movements'] !== [], 'shadow strict mode should record negative evidence without blocking legacy sale');
    $negativeBalance = inventoryPhase4One($conn, 'SELECT * FROM inventory_item_balances WHERE item_id = 1001 AND store_id = 9 LIMIT 1');
    inventoryPhase4Assert(inventoryPhase4DecimalEquals($negativeBalance['qty_available'], '-99.000000'), 'shadow strict mode should preserve negative variance for reconciliation');

    $conn->begin_transaction();
    $salesReturn = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_SALES_RETURN, 7006, [[
        'id' => 506,
        'item_id' => 1001,
        'qty_in' => '1.000000',
        'qty_out' => '0.000000',
        'det_store' => 7,
    ]], ['user_id' => 9]);
    $conn->commit();
    inventoryPhase4Assert($salesReturn['movements'][0]['movement_id'] > 0, 'sales return should create inbound reversal movement');
    $returnMovement = inventoryPhase4One($conn, "SELECT movement_type FROM inventory_movements WHERE idempotency_key LIKE '%detail:506' LIMIT 1");
    inventoryPhase4Assert($returnMovement['movement_type'] === 'refund_reversal', 'sales return should map to refund_reversal');

    $conn->begin_transaction();
    $purchaseReturn = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_PURCHASE_RETURN, 7007, [[
        'id' => 507,
        'item_id' => 1001,
        'qty_in' => '0.000000',
        'qty_out' => '1.000000',
        'det_store' => 7,
    ]], ['user_id' => 9]);
    $conn->commit();
    inventoryPhase4Assert($purchaseReturn['movements'][0]['movement_id'] > 0, 'purchase return should create outbound purchase return movement');
    $purchaseReturnMovement = inventoryPhase4One($conn, "SELECT movement_type FROM inventory_movements WHERE idempotency_key LIKE '%detail:507' LIMIT 1");
    inventoryPhase4Assert($purchaseReturnMovement['movement_type'] === 'purchase_return', 'purchase return should map to dedicated purchase_return movement type');

    echo "inventory-phase4-invoice-bridge-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase4AssertSourceContracts(string $root): void
{
    $bridgeSource = inventoryPhase4Source($root . '/classes/Inventory/InventoryInvoiceBridge.php');
    foreach (['SAVEPOINT ', 'inventory_invoice_bridge_', 'recordShadowMovement', 'inventory_invoice_bridge_disabled'] as $needle) {
        inventoryPhase4Assert(strpos($bridgeSource, $needle) !== false, 'invoice bridge should keep phase4 guard: ' . $needle);
    }
    inventoryPhase4AssertReversalClassification();

    $invoiceAddSource = inventoryPhase4Source($root . '/do/doadd_invoice.php');
    foreach (['InventoryInvoiceBridge.php', '$edit_id <= 0', '!$is_split_line_payment', 'recordInvoiceLines'] as $needle) {
        inventoryPhase4Assert(strpos($invoiceAddSource, $needle) !== false, 'doadd invoice should contain guarded phase4 shadow hook: ' . $needle);
    }

    $invoiceDeleteSource = inventoryPhase4Source($root . '/do/dodel_invoice.php');
    foreach (['InventoryInvoiceBridge.php', 'recordInvoiceReversalLines', 'invoice_deleted', 'Inventory invoice delete bridge shadow errors'] as $needle) {
        inventoryPhase4Assert(strpos($invoiceDeleteSource, $needle) !== false, 'dodel invoice should contain guarded phase4 reversal hook: ' . $needle);
    }

    $operationDeleteSource = inventoryPhase4Source($root . '/do/dodel_pro.php');
    foreach (['InventoryInvoiceBridge.php', 'recordInvoiceReversalLines', 'operation_deleted', 'Inventory operation delete bridge shadow errors'] as $needle) {
        inventoryPhase4Assert(strpos($operationDeleteSource, $needle) !== false, 'dodel pro should contain guarded phase4 reversal hook: ' . $needle);
    }

    $invoiceEditSource = inventoryPhase4Source($root . '/do/doedit_invoice.php');
    foreach (['InventoryInvoiceBridge.php', 'recordInvoiceReversalLines', 'recordInvoiceLines', 'invoice_edited', 'Inventory invoice edit add bridge shadow errors'] as $needle) {
        inventoryPhase4Assert(strpos($invoiceEditSource, $needle) !== false, 'doedit invoice should contain guarded phase4 replacement hook: ' . $needle);
    }

    $cofeSource = inventoryPhase4Source($root . '/ajax/cofe_create_order.php');
    foreach (['InventoryInvoiceBridge.php', 'recordInvoiceLines', 'source_system', 'cofe_widget', '[Cofe] Inventory invoice bridge shadow errors'] as $needle) {
        inventoryPhase4Assert(strpos($cofeSource, (string) $needle) !== false, 'Cofe endpoint should contain guarded phase4 shadow hook: ' . (string) $needle);
    }

    $posMutationSource = inventoryPhase4Source($root . '/classes/Pos/Service/PosOrderMutationService.php');
    foreach ([
        'InventoryInvoiceBridge.php',
        'recordInventoryInvoiceBridgeLines',
        'recordInventoryInvoiceBridgeReversalLines',
        'loadInventoryInvoiceBridgeLines',
        'POS inventory invoice bridge shadow errors',
        'POS inventory invoice bridge reversal shadow errors',
    ] as $needle) {
        inventoryPhase4Assert(strpos($posMutationSource, $needle) !== false, 'POS mutation service should contain guarded phase4 bridge hook: ' . $needle);
    }

    $posOrderSource = inventoryPhase4Source($root . '/classes/PosOrderService.php');
    foreach ([
        'InventoryInvoiceBridge.php',
        'recordMoovaInventoryBridgeLines',
        'recordMoovaInventoryBridgeReversalLines',
        'inventoryBridgeLinesFromMoovaMappedLines',
        'Moova inventory invoice bridge shadow errors',
        'Moova inventory invoice bridge reversal shadow errors',
    ] as $needle) {
        inventoryPhase4Assert(strpos($posOrderSource, $needle) !== false, 'Moova POS order service should contain guarded phase4 bridge hook: ' . $needle);
    }

    $startBalanceSource = inventoryPhase4Source($root . '/save_start_balance.php');
    foreach ([
        'posmain_start_balance_recipe_movement_integrity',
        'payload_hash',
        'metadata_json',
        'items-start-balance:tenant:',
        'posmain_start_balance_column_exists',
    ] as $needle) {
        inventoryPhase4Assert(strpos($startBalanceSource, $needle) !== false, 'start balance should keep guarded phase4 opening-balance evidence: ' . $needle);
    }
}

function inventoryPhase4AssertReversalClassification(): void
{
    $bridge = new InventoryInvoiceBridge(new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'shadow']]));
    $method = new ReflectionMethod($bridge, 'reversalMovementTypeForOriginal');

    $cases = [
        ['purchase', '0.000000', '2.000000', 'purchase_return'],
        ['purchase_return', '2.000000', '0.000000', 'purchase'],
        ['sale_direct', '2.000000', '0.000000', 'refund_reversal'],
        ['refund_reversal', '0.000000', '2.000000', 'adjustment'],
    ];
    foreach ($cases as [$originalType, $qtyIn, $qtyOut, $expected]) {
        inventoryPhase4Assert(
            $method->invoke($bridge, $originalType, $qtyIn, $qtyOut) === $expected,
            'invoice bridge reversal should classify ' . $originalType . ' as ' . $expected
        );
    }
}

function inventoryPhase4CreateLegacyItemTable(mysqli $conn): void
{
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL PRIMARY KEY,
  iname VARCHAR(200) NOT NULL,
  price3 DECIMAL(18,6) NOT NULL DEFAULT 0,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
  track_stock TINYINT(1) NOT NULL DEFAULT 1,
  base_unit_id BIGINT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase4SeedItem(mysqli $conn, int $id, string $name, string $itemType, int $trackStock, string $qty, string $cost): void
{
    $stmt = $conn->prepare("
INSERT INTO myitems
  (id, iname, price3, itmqty, cost_price, item_type, track_stock)
VALUES (?, ?, 0, ?, ?, ?, ?)");
    $stmt->bind_param('issssi', $id, $name, $qty, $cost, $itemType, $trackStock);
    $stmt->execute();
    $stmt->close();
}

function inventoryPhase4One(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase4Assert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryPhase4DecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === $expected;
}

function inventoryPhase4Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase4Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
