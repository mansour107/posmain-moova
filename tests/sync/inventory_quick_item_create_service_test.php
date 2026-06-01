<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryQuickItemCreateService.php';
require_once $root . '/classes/Inventory/InventoryPurchaseReceivingService.php';

inventoryQuickItemAssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-quick-item-create-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_quick_item_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryQuickItemCreateLegacyTables($conn);

    $service = new InventoryQuickItemCreateService();
    $result = $service->create($conn, [
        'iname' => 'Quick created flour',
        'barcode' => 'QIC-FLOUR',
        'item_type' => 'ingredient',
        'unit_id' => 44,
        'cost_price' => '12.500000',
        'group1' => 7,
        'store_id' => 3,
        'supplier_account_id' => 2111,
    ], ['user_id' => 9]);

    inventoryQuickItemAssert($result['success'] === true && (int) $result['item']['id'] > 0, 'quick create should return created item');
    $itemId = (int) $result['item']['id'];
    $item = inventoryQuickItemOne($conn, 'SELECT * FROM myitems WHERE id = ' . $itemId . ' LIMIT 1');
    inventoryQuickItemAssert((string) $item['iname'] === 'Quick created flour', 'quick create should write item name');
    inventoryQuickItemAssert((string) $item['barcode'] === 'QIC-FLOUR', 'quick create should write barcode');
    inventoryQuickItemAssert((string) $item['item_type'] === 'ingredient', 'quick create should mark ingredient type');
    inventoryQuickItemAssert((int) $item['track_stock'] === 1, 'quick create should mark item stock tracked');
    inventoryQuickItemAssert((int) $item['preferred_unit_id'] === 44, 'quick create should save preferred unit');
    inventoryQuickItemAssert(inventoryQuickItemDecimalEquals($item['cost_price'], '12.500000'), 'quick create should save cost');
    inventoryQuickItemAssert(inventoryQuickItemDecimalEquals($item['last_price'], '12.500000'), 'quick create should seed last purchase price');

    $unit = inventoryQuickItemOne($conn, 'SELECT * FROM item_units WHERE item_id = ' . $itemId . ' LIMIT 1');
    inventoryQuickItemAssert((int) $unit['unit_id'] === 44, 'quick create should create base item unit');
    inventoryQuickItemAssert(inventoryQuickItemDecimalEquals($unit['u_val'], '1.000000'), 'quick create unit should convert 1 to base');
    inventoryQuickItemAssert(inventoryQuickItemDecimalEquals($unit['cost_price'], '12.500000'), 'quick create unit should save cost');

    $stockLevel = inventoryQuickItemOne($conn, 'SELECT * FROM inventory_item_stock_levels WHERE item_id = ' . $itemId . ' AND store_id = 3 LIMIT 1');
    inventoryQuickItemAssert((int) $stockLevel['preferred_purchase_unit_id'] === 44, 'quick create should attach preferred purchase unit for selected store');
    inventoryQuickItemAssert((int) $stockLevel['default_supplier_account_id'] === 2111, 'quick create should attach selected supplier as default');

    try {
        $service->create($conn, ['iname' => 'Quick created flour', 'barcode' => 'QIC-FLOUR-2', 'unit_id' => 44], ['user_id' => 9]);
        inventoryQuickItemAssert(false, 'duplicate name should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryQuickItemAssert($exception->getMessage() === 'ITEM_NAME_DUPLICATE', 'duplicate name should return expected code');
    }

    try {
        $service->create($conn, ['iname' => 'Quick created sugar', 'barcode' => 'QIC-FLOUR', 'unit_id' => 44], ['user_id' => 9]);
        inventoryQuickItemAssert(false, 'duplicate barcode should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryQuickItemAssert($exception->getMessage() === 'ITEM_BARCODE_DUPLICATE', 'duplicate barcode should return expected code');
    }

    $receiving = new InventoryPurchaseReceivingService(new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'bridge',
            'legacy_mirror' => '1',
        ],
    ]));
    $receipt = $receiving->receive($conn, [
        'purchase_receipt_uuid' => '99999999-9999-4999-8999-999999999999',
        'supplier_account_id' => 2111,
        'destination_store_id' => 3,
        'supplier_invoice_no' => 'QIC-RECEIPT',
        'lines' => [
            ['item_id' => $itemId, 'unit_id' => 44, 'qty' => '2.000000', 'unit_cost' => '12.500000'],
        ],
    ], ['user_id' => 9]);
    inventoryQuickItemAssert($receipt['success'] === true && $receipt['movement_ids'] !== [], 'quick-created item should be receivable');
    $balance = inventoryQuickItemOne($conn, 'SELECT * FROM inventory_item_balances WHERE item_id = ' . $itemId . ' AND store_id = 3 LIMIT 1');
    inventoryQuickItemAssert(inventoryQuickItemDecimalEquals($balance['qty_on_hand'], '2.000000'), 'receiving quick-created item should update ledger balance');

    echo "inventory-quick-item-create-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryQuickItemCreateLegacyTables(mysqli $conn): void
{
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  iname VARCHAR(200) NOT NULL,
  name2 VARCHAR(200) NULL,
  code INT NULL,
  barcode VARCHAR(100) NULL,
  info TEXT NULL,
  market_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  last_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  price1 DECIMAL(18,6) NOT NULL DEFAULT 0,
  price2 DECIMAL(18,6) NOT NULL DEFAULT 0,
  price3 DECIMAL(18,6) NOT NULL DEFAULT 0,
  group1 BIGINT UNSIGNED NULL,
  group2 BIGINT UNSIGNED NULL,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
  track_stock TINYINT(1) NOT NULL DEFAULT 1,
  preferred_unit_id BIGINT UNSIGNED NULL,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  user BIGINT UNSIGNED NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE myunits (
  id INT NOT NULL PRIMARY KEY,
  uname VARCHAR(80) NOT NULL,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("INSERT INTO myunits (id, uname, isdeleted) VALUES (44, 'كجم', 0)");
    $conn->query("
CREATE TABLE item_units (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  unit_id INT NOT NULL,
  u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  unit_barcode VARCHAR(100) NULL,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  price1 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  price2 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  price3 DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE process (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE item_group (
  id INT NOT NULL PRIMARY KEY,
  gname VARCHAR(120) NOT NULL,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("INSERT INTO item_group (id, gname, isdeleted) VALUES (7, 'Quick create category', 0)");
}

function inventoryQuickItemAssertSourceContracts(string $root): void
{
    $page = inventoryQuickItemSource($root . '/inventory_purchasing.php');
    foreach (['إنشاء صنف مخزني', 'inventoryQuickItemModal', 'ajax/inventory_item_quick_create.php', 'useQuickCreatedInventoryItem', 'inventory-create-missing-item', 'inventoryQuickItemSaving'] as $needle) {
        inventoryQuickItemAssert(strpos($page, $needle) !== false, 'receiving page should include quick item UI contract: ' . $needle);
    }

    $endpoint = inventoryQuickItemSource($root . '/ajax/inventory_item_quick_create.php');
    foreach (['InventoryQuickItemCreateService.php', "require_permission('inventory.edit'", "require_csrf('inventory_receiving'", 'ITEM_BARCODE_DUPLICATE'] as $needle) {
        inventoryQuickItemAssert(strpos($endpoint, $needle) !== false, 'quick item endpoint should include: ' . $needle);
    }

    $service = inventoryQuickItemSource($root . '/classes/Inventory/InventoryQuickItemCreateService.php');
    foreach (['INSERT INTO myitems', 'INSERT INTO item_units', 'inventory_item_stock_levels', 'posmain_record_menu_item_sync', 'ITEM_NAME_DUPLICATE'] as $needle) {
        inventoryQuickItemAssert(strpos($service, $needle) !== false, 'quick item service should include: ' . $needle);
    }
}

function inventoryQuickItemOne(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryQuickItemAssert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryQuickItemDecimalEquals($actual, string $expected): bool
{
    return abs((float) $actual - (float) $expected) < 0.000001;
}

function inventoryQuickItemSource(string $path): string
{
    $source = file_get_contents($path);
    inventoryQuickItemAssert($source !== false, 'Unable to read ' . $path);

    return (string) $source;
}

function inventoryQuickItemAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}
