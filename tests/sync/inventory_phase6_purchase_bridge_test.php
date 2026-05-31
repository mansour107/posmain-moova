<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryInvoiceBridge.php';

inventoryPhase6AssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase6-purchase-bridge-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase6_purchase_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase6CreateLegacyItemTable($conn);
    $conn->query("
        INSERT INTO myitems (id, iname, itmqty, cost_price, last_price, item_type, track_stock)
        VALUES (6101, 'Purchase bridge item', 10.000000, 2.000000, 2.000000, 'sellable', 1)
    ");

    $bridge = new InventoryInvoiceBridge(new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'bridge',
            'legacy_mirror' => '1',
        ],
        'branch' => [
            'pos_tenant' => 0,
            'pos_branch' => 0,
        ],
    ]));
    $conn->begin_transaction();
    $firstPurchase = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_PURCHASE, 8001, [[
        'id' => 8101,
        'item_id' => 6101,
        'qty_in' => '10.000000',
        'qty_out' => '0.000000',
        'u_val' => '1.000000',
        'cost_price' => '4.000000',
        'det_store' => 3,
    ]], ['user_id' => 7]);
    $conn->commit();

    inventoryPhase6Assert($firstPurchase['success'] === true && $firstPurchase['movements'] !== [], 'purchase bridge should record purchase movement in bridge mode');
    $balance = inventoryPhase6One($conn, 'SELECT * FROM inventory_item_balances WHERE item_id = 6101 AND store_id = 3 LIMIT 1');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($balance['qty_on_hand'], '10.000000'), 'purchase bridge balance should reflect received quantity');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($balance['moving_average_cost'], '4.000000'), 'first purchase should set moving average cost');
    $legacy = inventoryPhase6One($conn, 'SELECT itmqty, cost_price, last_price FROM myitems WHERE id = 6101 LIMIT 1');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($legacy['itmqty'], '10.000000'), 'bridge legacy mirror should refresh item quantity');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($legacy['cost_price'], '4.000000'), 'bridge legacy mirror should refresh item average cost');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($legacy['last_price'], '4.000000'), 'bridge legacy mirror should refresh item last purchase price');

    $conn->begin_transaction();
    $replay = $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_PURCHASE, 8001, [[
        'id' => 8101,
        'item_id' => 6101,
        'qty_in' => '10.000000',
        'qty_out' => '0.000000',
        'u_val' => '1.000000',
        'cost_price' => '4.000000',
        'det_store' => 3,
    ]], ['user_id' => 7]);
    $conn->commit();
    inventoryPhase6Assert(!empty($replay['movements'][0]['idempotent_replay']), 'duplicate purchase receipt should replay idempotently');
    inventoryPhase6Assert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE item_id = 6101 AND movement_type = 'purchase'")->fetch_assoc()['c'] === 1, 'duplicate purchase receipt should not duplicate movement');

    $conn->begin_transaction();
    $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_PURCHASE, 8002, [[
        'id' => 8102,
        'item_id' => 6101,
        'qty_in' => '10.000000',
        'qty_out' => '0.000000',
        'u_val' => '1.000000',
        'cost_price' => '2.000000',
        'det_store' => 3,
    ]], ['user_id' => 7]);
    $conn->commit();
    $balance = inventoryPhase6One($conn, 'SELECT * FROM inventory_item_balances WHERE item_id = 6101 AND store_id = 3 LIMIT 1');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($balance['qty_on_hand'], '20.000000'), 'second purchase should increase on hand');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($balance['moving_average_cost'], '3.000000'), 'second purchase should update moving average cost');

    $conn->begin_transaction();
    $bridge->recordInvoiceLines($conn, InventoryInvoiceBridge::TYPE_PURCHASE_RETURN, 8003, [[
        'id' => 8103,
        'item_id' => 6101,
        'qty_in' => '0.000000',
        'qty_out' => '5.000000',
        'u_val' => '1.000000',
        'cost_price' => '3.000000',
        'det_store' => 3,
    ]], ['user_id' => 7]);
    $conn->commit();
    $balance = inventoryPhase6One($conn, 'SELECT * FROM inventory_item_balances WHERE item_id = 6101 AND store_id = 3 LIMIT 1');
    $legacy = inventoryPhase6One($conn, 'SELECT itmqty, cost_price, last_price FROM myitems WHERE id = 6101 LIMIT 1');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($balance['qty_on_hand'], '15.000000'), 'purchase return should decrease stock');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($balance['moving_average_cost'], '3.000000'), 'purchase return should keep moving average cost stable');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($legacy['itmqty'], '15.000000'), 'bridge legacy mirror should refresh returned quantity');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($legacy['cost_price'], '3.000000'), 'bridge legacy mirror should keep average cost after return');
    inventoryPhase6Assert(inventoryPhase6DecimalEquals($legacy['last_price'], '2.000000'), 'purchase return should not replace last purchase price');
    $purchaseReturnMovement = inventoryPhase6One($conn, "SELECT movement_type FROM inventory_movements WHERE idempotency_key LIKE '%detail:8103' LIMIT 1");
    inventoryPhase6Assert($purchaseReturnMovement['movement_type'] === 'purchase_return', 'purchase return bridge should use dedicated purchase_return movement type');

    echo "inventory-phase6-purchase-bridge-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase6AssertSourceContracts(string $root): void
{
    $bridgeSource = inventoryPhase6Source($root . '/classes/Inventory/InventoryInvoiceBridge.php');
    foreach (['public const TYPE_PURCHASE = 4', 'public const TYPE_PURCHASE_RETURN = 10', "return 'purchase_return'", "return \$invoiceType === self::TYPE_SALES_RETURN ? 'refund_reversal' : 'purchase'"] as $needle) {
        inventoryPhase6Assert(strpos($bridgeSource, $needle) !== false, 'purchase invoice bridge source should contain: ' . $needle);
    }

    $ledgerSource = inventoryPhase6Source($root . '/classes/Inventory/InventoryLedgerService.php');
    foreach (["'purchase'", "'purchase_return'", "movementType === 'purchase'", 'last_price'] as $needle) {
        inventoryPhase6Assert(strpos($ledgerSource, $needle) !== false, 'ledger service should preserve phase6 purchase behavior: ' . $needle);
    }

    $schemaSource = inventoryPhase6Source($root . '/classes/Sync/SchemaManager.php');
    foreach (['inventory_purchase_receipts', 'inventory_purchase_receipt_lines', "'purchase_return'", 'inventory_movements.modify_movement_type_purchase_return_enum'] as $needle) {
        inventoryPhase6Assert(strpos($schemaSource, $needle) !== false, 'schema manager should preserve phase6 purchase schema contract: ' . $needle);
    }

    $docs = inventoryPhase6Source($root . '/docs/inventory/phase6_purchase_bridge_contracts.md');
    foreach (['Purchase returns now create dedicated outbound `purchase_return` movements', 'myitems.last_price', 'Duplicate purchase receipt rows replay through the existing idempotency key and payload hash'] as $needle) {
        inventoryPhase6Assert(strpos($docs, $needle) !== false, 'phase6 docs should describe purchase bridge behavior: ' . $needle);
    }
}

function inventoryPhase6CreateLegacyItemTable(mysqli $conn): void
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

function inventoryPhase6One(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase6Assert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryPhase6DecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === $expected;
}

function inventoryPhase6Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase6Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
