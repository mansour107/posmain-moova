<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryAdjustmentService.php';

inventoryPhase9AssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase9-adjustment-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase9_adjustment_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase9CreateLegacyItemTable($conn);
    inventoryPhase9CreateOperationalStore($conn, 3);
    $conn->query("
        INSERT INTO myitems (id, iname, barcode, itmqty, cost_price, last_price, item_type, track_stock)
        VALUES
            (6601, 'Adjustment item', 'A-6601', 0.000000, 0.000000, 0.000000, 'ingredient', 1),
            (6603, 'Adjustment unit item', 'A-6603', 0.000000, 0.000000, 0.000000, 'ingredient', 1),
            (6602, 'Adjustment service', 'A-6602', 0.000000, 0.000000, 0.000000, 'service', 0)
    ");
    $conn->query("INSERT INTO myunits (id, uname) VALUES (902, 'Case')");
    $conn->query("
        INSERT INTO item_units (item_id, unit_id, u_val, cost_price)
        VALUES (6603, 902, 12.000000, 60.000000)
    ");
    $conn->query("
        INSERT INTO inventory_reason_codes (id, reason_code, reason_name, reason_group, direction, requires_approval, is_system, is_active)
        VALUES
            (9101, 'WASTE_EXPIRED', 'Expired batch', 'waste', 'out', 0, 1, 1),
            (9102, 'ADJ_COUNT_IN', 'Count increase', 'adjustment', 'in', 0, 1, 1),
            (9103, 'ADJ_COUNT_OUT', 'Count decrease', 'adjustment', 'out', 0, 1, 1),
            (9104, 'MANAGER_WRITE_OFF', 'Manager write-off', 'waste', 'out', 1, 1, 1),
            (9105, 'INCREASE_ONLY', 'Increase only', 'adjustment', 'in', 0, 1, 1)
    ");

    $flags = new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'bridge',
            'legacy_mirror' => '1',
        ],
    ]);
    $ledger = new InventoryLedgerService($flags);
    $service = new InventoryAdjustmentService($flags, $ledger);
    $reasonService = new InventoryReasonCodeService();
    $wasteReasons = $reasonService->listForOperation($conn, ['pos_tenant' => 0, 'pos_branch' => 0], 'waste', 'decrease');
    $increaseReasons = $reasonService->listForOperation($conn, ['pos_tenant' => 0, 'pos_branch' => 0], 'adjustment', 'increase');
    inventoryPhase9Assert(in_array('WASTE_EXPIRED', array_column($wasteReasons, 'reason_code'), true), 'waste reason list should include waste reasons');
    inventoryPhase9Assert(!in_array('INCREASE_ONLY', array_column($wasteReasons, 'reason_code'), true), 'waste reason list should exclude adjustment-only reasons');
    inventoryPhase9Assert(in_array('INCREASE_ONLY', array_column($increaseReasons, 'reason_code'), true), 'increase reason list should include inbound adjustment reasons');
    inventoryPhase9SeedStock($conn, $ledger, 6601, '10.000000', '3.000000', 'seed-6601');

    $waste = $service->recordWaste($conn, [
        'operation_uuid' => '11111111-aaaa-4aaa-8aaa-111111111111',
        'store_id' => 3,
        'item_id' => 6601,
        'qty' => '2.000000',
        'reason_code_id' => 9101,
        'occurred_at' => date('Y-m-d'),
    ], ['user_id' => 7]);
    inventoryPhase9Assert($waste['success'] === true && $waste['movement_type'] === 'waste', 'waste should record through inventory adjustment service');
    $movement = inventoryPhase9One($conn, "SELECT movement_type, source_type, qty_out, total_cost, metadata_json FROM inventory_movements WHERE source_uuid = 'waste:11111111-aaaa-4aaa-8aaa-111111111111' LIMIT 1");
    inventoryPhase9Assert($movement['movement_type'] === 'waste' && $movement['source_type'] === 'adjustment', 'waste movement should use inventory adjustment source');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($movement['qty_out'], '2.000000'), 'waste should write qty_out');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($movement['total_cost'], '6.000000'), 'waste should default cost from moving average');
    inventoryPhase9Assert(strpos((string) $movement['metadata_json'], '"reason_code_id":9101') !== false, 'waste metadata should keep selected reason code id');
    inventoryPhase9Assert(strpos((string) $movement['metadata_json'], '"reason_code":"WASTE_EXPIRED"') !== false, 'waste metadata should keep selected reason code');
    inventoryPhase9Assert(strpos((string) $movement['metadata_json'], '"reason_name":"Expired batch"') !== false, 'waste metadata should keep selected reason name');
    $balance = inventoryPhase9One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6601 AND store_id = 3 LIMIT 1');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($balance['qty_on_hand'], '8.000000'), 'waste should decrease on hand');

    $wasteReplay = $service->recordWaste($conn, [
        'operation_uuid' => '11111111-aaaa-4aaa-8aaa-111111111111',
        'store_id' => 3,
        'item_id' => 6601,
        'qty' => '2.000000',
        'reason_code_id' => 9101,
        'occurred_at' => date('Y-m-d'),
    ], ['user_id' => 7]);
    inventoryPhase9Assert(!empty($wasteReplay['idempotent_replay']), 'waste replay should reuse movement');
    inventoryPhase9Assert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE movement_type = 'waste'")->fetch_assoc()['c'] === 1, 'waste replay should not duplicate movement');

    $increase = $service->recordAdjustment($conn, [
        'operation_uuid' => '22222222-bbbb-4bbb-8bbb-222222222222',
        'store_id' => 3,
        'item_id' => 6601,
        'direction' => 'increase',
        'qty' => '5.000000',
        'unit_cost' => '4.000000',
        'reason_code_id' => 9102,
        'reason' => 'count correction',
        'occurred_at' => date('Y-m-d'),
    ], ['user_id' => 7]);
    inventoryPhase9Assert($increase['success'] === true && $increase['movement_type'] === 'adjustment', 'increase adjustment should record');
    $movement = inventoryPhase9One($conn, "SELECT qty_in, qty_out, total_cost FROM inventory_movements WHERE source_uuid = 'adjustment:22222222-bbbb-4bbb-8bbb-222222222222' LIMIT 1");
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($movement['qty_in'], '5.000000'), 'increase adjustment should write qty_in');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($movement['qty_out'], '0.000000'), 'increase adjustment should not write qty_out');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($movement['total_cost'], '20.000000'), 'increase adjustment should use supplied cost');
    $balance = inventoryPhase9One($conn, 'SELECT qty_on_hand, moving_average_cost FROM inventory_item_balances WHERE item_id = 6601 AND store_id = 3 LIMIT 1');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($balance['qty_on_hand'], '13.000000'), 'increase adjustment should increase on hand');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($balance['moving_average_cost'], '3.384615'), 'increase adjustment should update moving average');

    try {
        $service->recordAdjustment($conn, [
            'operation_uuid' => '13131313-bbbb-4bbb-8bbb-131313131313',
            'store_id' => 3,
            'item_id' => 6601,
            'direction' => 'increase',
            'qty' => '1.000000',
            'reason' => 'wrong photo context',
            'photo_attachment' => [
                'path' => 'uploads/inventory_waste/inventory_waste_20260530_120000_abcdefabcdefabcd.jpg',
                'mime' => 'image/jpeg',
                'size_bytes' => 12345,
                'sha256' => str_repeat('b', 64),
            ],
            'occurred_at' => date('Y-m-d'),
        ], ['user_id' => 7]);
        inventoryPhase9Assert(false, 'non-waste photo attachment should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase9Assert($exception->getMessage() === 'WASTE_PHOTO_WASTE_ONLY', 'non-waste photo attachment should return expected code');
    }

    $decrease = $service->recordAdjustment($conn, [
        'operation_uuid' => '33333333-cccc-4ccc-8ccc-333333333333',
        'store_id' => 3,
        'item_id' => 6601,
        'direction' => 'decrease',
        'qty' => '3.000000',
        'reason_code_id' => 9103,
        'reason' => 'manual correction',
        'occurred_at' => date('Y-m-d'),
    ], ['user_id' => 7]);
    inventoryPhase9Assert($decrease['success'] === true, 'decrease adjustment should record');
    $movement = inventoryPhase9One($conn, "SELECT qty_in, qty_out FROM inventory_movements WHERE source_uuid = 'adjustment:33333333-cccc-4ccc-8ccc-333333333333' LIMIT 1");
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($movement['qty_out'], '3.000000'), 'decrease adjustment should write qty_out');
    $balance = inventoryPhase9One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6601 AND store_id = 3 LIMIT 1');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($balance['qty_on_hand'], '10.000000'), 'decrease adjustment should decrease on hand');

    $unitIncrease = $service->recordAdjustment($conn, [
        'operation_uuid' => '88888888-bbbb-4bbb-8bbb-888888888888',
        'store_id' => 3,
        'item_id' => 6603,
        'unit_id' => 902,
        'direction' => 'increase',
        'qty' => '2.000000',
        'unit_cost' => '60.000000',
        'reason' => 'case count correction',
        'occurred_at' => date('Y-m-d'),
    ], ['user_id' => 7]);
    inventoryPhase9Assert($unitIncrease['success'] === true, 'unit increase adjustment should record');
    $unitMovement = inventoryPhase9One($conn, "SELECT qty_in, unit_id, unit_conversion_to_base, unit_cost, total_cost, metadata_json FROM inventory_movements WHERE source_uuid = 'adjustment:88888888-bbbb-4bbb-8bbb-888888888888' LIMIT 1");
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($unitMovement['qty_in'], '24.000000'), 'unit increase should write base qty_in');
    inventoryPhase9Assert((int) $unitMovement['unit_id'] === 902, 'unit increase should store selected unit');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($unitMovement['unit_conversion_to_base'], '12.000000'), 'unit increase should store conversion');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($unitMovement['unit_cost'], '5.000000'), 'unit increase should store base unit cost');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($unitMovement['total_cost'], '120.000000'), 'unit increase should preserve entered-unit total');
    inventoryPhase9Assert(strpos((string) $unitMovement['metadata_json'], '"entered_qty":"2.000000"') !== false, 'unit increase metadata should keep entered qty');
    $unitBalance = inventoryPhase9One($conn, 'SELECT qty_on_hand, moving_average_cost FROM inventory_item_balances WHERE item_id = 6603 AND store_id = 3 LIMIT 1');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($unitBalance['qty_on_hand'], '24.000000'), 'unit increase should increase on hand by base qty');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($unitBalance['moving_average_cost'], '5.000000'), 'unit increase should set base moving average cost');

    $unitWaste = $service->recordWaste($conn, [
        'operation_uuid' => '99999999-cccc-4ccc-8ccc-999999999999',
        'store_id' => 3,
        'item_id' => 6603,
        'unit_id' => 902,
        'qty' => '1.000000',
        'reason' => 'case waste',
        'occurred_at' => date('Y-m-d'),
    ], ['user_id' => 7]);
    inventoryPhase9Assert($unitWaste['success'] === true, 'unit waste should record');
    $unitWasteMovement = inventoryPhase9One($conn, "SELECT qty_out, unit_id, unit_conversion_to_base, unit_cost, total_cost FROM inventory_movements WHERE source_uuid = 'waste:99999999-cccc-4ccc-8ccc-999999999999' LIMIT 1");
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($unitWasteMovement['qty_out'], '12.000000'), 'unit waste should write base qty_out');
    inventoryPhase9Assert((int) $unitWasteMovement['unit_id'] === 902, 'unit waste should store selected unit');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($unitWasteMovement['unit_cost'], '5.000000'), 'unit waste should use base moving average cost');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($unitWasteMovement['total_cost'], '60.000000'), 'unit waste should value base qty');
    $unitBalance = inventoryPhase9One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6603 AND store_id = 3 LIMIT 1');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($unitBalance['qty_on_hand'], '12.000000'), 'unit waste should decrease on hand by base qty');

    try {
        $service->recordAdjustment($conn, [
            'operation_uuid' => 'aaaaaaaa-bbbb-4bbb-8bbb-aaaaaaaaaaaa',
            'store_id' => 3,
            'item_id' => 6603,
            'unit_id' => 999999,
            'direction' => 'increase',
            'qty' => '1.000000',
            'reason' => 'bad unit adjustment',
            'occurred_at' => date('Y-m-d'),
        ], ['user_id' => 7]);
        inventoryPhase9Assert(false, 'unknown adjustment unit should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase9Assert($exception->getMessage() === 'ITEM_UNIT_NOT_FOUND', 'unknown adjustment unit should return expected code');
    }

    try {
        $service->recordWaste($conn, [
            'operation_uuid' => 'abababab-aaaa-4aaa-8aaa-abababababab',
            'store_id' => 3,
            'item_id' => 6601,
            'qty' => '1.000000',
            'reason_code_id' => 999999,
            'reason' => 'unknown reason code',
            'occurred_at' => date('Y-m-d'),
        ], ['user_id' => 7]);
        inventoryPhase9Assert(false, 'unknown reason code should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase9Assert($exception->getMessage() === 'REASON_CODE_NOT_FOUND', 'unknown reason code should return expected code');
    }

    try {
        $service->recordWaste($conn, [
            'operation_uuid' => 'bcbcbcbc-aaaa-4aaa-8aaa-bcbcbcbcbcbc',
            'store_id' => 3,
            'item_id' => 6601,
            'qty' => '1.000000',
            'reason_code_id' => 9105,
            'reason' => 'wrong group reason code',
            'occurred_at' => date('Y-m-d'),
        ], ['user_id' => 7]);
        inventoryPhase9Assert(false, 'wrong reason-code group should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase9Assert($exception->getMessage() === 'REASON_CODE_GROUP_INVALID', 'wrong reason-code group should return expected code');
    }

    try {
        $service->recordWaste($conn, [
            'operation_uuid' => 'cdcdcdcd-aaaa-4aaa-8aaa-cdcdcdcdcdcd',
            'store_id' => 3,
            'item_id' => 6601,
            'qty' => '1.000000',
            'reason_code_id' => 9104,
            'occurred_at' => date('Y-m-d'),
        ], ['user_id' => 7]);
        inventoryPhase9Assert(false, 'approval-only reason code should fail without approval');
    } catch (RuntimeException $exception) {
        inventoryPhase9Assert($exception->getMessage() === 'REASON_CODE_APPROVAL_REQUIRED', 'approval-only reason code should return expected code');
    }

    $approvedReasonCode = $service->recordWaste($conn, [
        'operation_uuid' => 'dededede-aaaa-4aaa-8aaa-dededededede',
        'store_id' => 3,
        'item_id' => 6603,
        'qty' => '1.000000',
        'reason_code_id' => 9104,
        'occurred_at' => date('Y-m-d'),
    ], ['user_id' => 7, 'allow_reason_code_approval' => true]);
    inventoryPhase9Assert($approvedReasonCode['success'] === true, 'approval-only reason code should record with approval context');

    try {
        $service->recordAdjustment($conn, [
            'operation_uuid' => '66666666-ffff-4fff-8fff-666666666666',
            'store_id' => 3,
            'item_id' => 6601,
            'direction' => 'decrease',
            'qty' => '11.000000',
            'reason' => 'negative correction without approval',
            'occurred_at' => date('Y-m-d'),
        ], ['user_id' => 7]);
        inventoryPhase9Assert(false, 'negative-result adjustment should fail without approval');
    } catch (RuntimeException $exception) {
        inventoryPhase9Assert($exception->getMessage() === 'NEGATIVE_RESULT_APPROVAL_REQUIRED', 'negative-result adjustment should return expected code');
    }

    $approvedNegative = $service->recordAdjustment($conn, [
        'operation_uuid' => '77777777-aaaa-4aaa-8aaa-777777777777',
        'store_id' => 3,
        'item_id' => 6601,
        'direction' => 'decrease',
        'qty' => '11.000000',
        'reason' => 'manager-approved negative correction',
        'occurred_at' => date('Y-m-d'),
    ], ['user_id' => 7, 'allow_negative_result' => true]);
    inventoryPhase9Assert($approvedNegative['success'] === true, 'manager-approved negative adjustment should record');
    $balance = inventoryPhase9One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6601 AND store_id = 3 LIMIT 1');
    inventoryPhase9Assert(inventoryPhase9DecimalEquals($balance['qty_on_hand'], '-1.000000'), 'approved negative adjustment should make on hand negative');

    try {
        $service->recordAdjustment($conn, [
            'operation_uuid' => '44444444-dddd-4ddd-8ddd-444444444444',
            'store_id' => 3,
            'item_id' => 6602,
            'direction' => 'increase',
            'qty' => '1.000000',
            'reason' => 'bad service adjustment',
        ], ['user_id' => 7]);
        inventoryPhase9Assert(false, 'service item adjustment should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase9Assert($exception->getMessage() === 'NON_STOCK_ITEM_CANNOT_BE_ADJUSTED', 'service item adjustment should return expected code');
    }

    try {
        $service->recordWaste($conn, [
            'operation_uuid' => '55555555-eeee-4eee-8eee-555555555555',
            'store_id' => 3,
            'item_id' => 6601,
            'qty' => '1.000000',
            'reason' => 'old spoilage',
            'occurred_at' => date('Y-m-d', strtotime('-1 day')),
        ], ['user_id' => 7]);
        inventoryPhase9Assert(false, 'backdated waste should fail without approval');
    } catch (RuntimeException $exception) {
        inventoryPhase9Assert($exception->getMessage() === 'BACKDATE_PERMISSION_REQUIRED', 'backdated waste should return expected code');
    }

    $photoWaste = $service->recordWaste($conn, [
        'operation_uuid' => '12121212-aaaa-4aaa-8aaa-121212121212',
        'store_id' => 3,
        'item_id' => 6603,
        'qty' => '1.000000',
        'reason' => 'photo evidence waste',
        'photo_attachment' => [
            'path' => 'uploads/inventory_waste/inventory_waste_20260530_120000_abcdefabcdefabcd.jpg',
            'file_name' => 'inventory_waste_20260530_120000_abcdefabcdefabcd.jpg',
            'original_name' => 'expired milk.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 12345,
            'sha256' => str_repeat('a', 64),
            'uploaded_at' => '2026-05-30T12:00:00+03:00',
            'storage' => 'local_uploads',
        ],
        'occurred_at' => date('Y-m-d'),
    ], ['user_id' => 7]);
    inventoryPhase9Assert($photoWaste['success'] === true, 'waste with photo attachment should record');
    $photoMovement = inventoryPhase9One($conn, "SELECT metadata_json FROM inventory_movements WHERE source_uuid = 'waste:12121212-aaaa-4aaa-8aaa-121212121212' LIMIT 1");
    inventoryPhase9Assert(strpos((string) $photoMovement['metadata_json'], '"photo_attachment"') !== false, 'waste metadata should keep photo attachment');
    inventoryPhase9Assert(strpos((string) $photoMovement['metadata_json'], '"path":"uploads/inventory_waste/') !== false, 'waste photo metadata should keep safe relative path');
    inventoryPhase9Assert(strpos((string) $photoMovement['metadata_json'], '"sha256":"' . str_repeat('a', 64) . '"') !== false, 'waste photo metadata should keep file hash');

    echo "inventory-phase9-adjustment-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase9SeedStock(mysqli $conn, InventoryLedgerService $ledger, int $itemId, string $qty, string $unitCost, string $key): void
{
    $conn->begin_transaction();
    $ledger->recordMovement($conn, [
        'scope' => [
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'store_id' => 3,
        ],
        'item_id' => $itemId,
        'movement_type' => 'opening_balance',
        'source_type' => 'manual',
        'source_uuid' => 'phase9:' . $key,
        'qty_in' => $qty,
        'unit_cost' => $unitCost,
        'total_cost' => InventoryDecimal::multiply($qty, $unitCost),
        'idempotency_key' => 'phase9-adjustment:' . $key,
        'metadata' => ['source' => 'phase9_adjustment_test'],
        'created_by' => 7,
    ], ['id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1], ['manage_transaction' => false]);
    $conn->commit();
}

function inventoryPhase9CreateLegacyItemTable(mysqli $conn): void
{
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL PRIMARY KEY,
  iname VARCHAR(200) NOT NULL,
  barcode VARCHAR(100) NULL,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  last_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
  track_stock TINYINT(1) NOT NULL DEFAULT 1
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
  u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function inventoryPhase9CreateOperationalStore(mysqli $conn, int $storeId): void
{
    $conn->query('CREATE TABLE settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        def_pos_store INT UNSIGNED NULL
    ) ENGINE=InnoDB');
    $conn->query('CREATE TABLE acc_head (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        code VARCHAR(32) NOT NULL,
        aname VARCHAR(191) NOT NULL,
        is_stock TINYINT(1) NOT NULL DEFAULT 0,
        isdeleted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB');
    $conn->query("INSERT INTO acc_head (id, code, aname, is_stock, isdeleted)
        VALUES ({$storeId}, 'STORE-{$storeId}', 'Operational store', 1, 0)");
    $conn->query("INSERT INTO settings (def_pos_store) VALUES ({$storeId})");
}

function inventoryPhase9AssertSourceContracts(string $root): void
{
    $page = inventoryPhase9Source($root . '/inventory_adjustments.php');
    foreach (['الهالك والتسويات', 'ajax/inventory_adjustment.php', 'inventory-adjustment-csrf', 'inventoryAdjustmentItemSearch', 'inventoryAdjustmentItemResults', 'inventoryAdjustmentItemCatalog', 'selectInventoryAdjustmentItem', 'role="combobox"', 'ابحث باسم الصنف أو الباركود', 'inventoryAdjustmentAvailable', 'inventoryAdjustmentOnHand', 'inventoryAdjustmentUnitOptions', 'inventoryAdjustmentPreferredUnits', 'inventoryAdjustmentPreferredUnit', 'inventoryAdjustmentReasonCode', 'inventoryAdjustmentReasonCodes', 'inventoryAdjustmentPhoto', 'waste_photo', 'FormData', 'سبب جاهز', 'الوحدة', 'html,body{overflow-x:hidden}', '.inventory-adjustment-panel-body .row{margin-left:0;margin-right:0}'] as $needle) {
        inventoryPhase9Assert(strpos($page, $needle) !== false, 'adjustment UI should include: ' . $needle);
    }

    $endpoint = inventoryPhase9Source($root . '/ajax/inventory_adjustment.php');
    foreach (['InventoryAdjustmentService.php', "require_permission('inventory.edit'", "require_csrf('inventory_adjustment'", 'posmain_store_image_upload_with_details', 'inventoryAdjustmentStoreWastePhoto', 'WASTE_PHOTO_INVALID'] as $needle) {
        inventoryPhase9Assert(strpos($endpoint, $needle) !== false, 'adjustment endpoint should include: ' . $needle);
    }
    inventoryPhase9Assert(strpos($endpoint, 'allow_negative_result') !== false, 'adjustment endpoint should pass negative-result approval context');
    inventoryPhase9Assert(strpos($endpoint, 'NEGATIVE_RESULT_APPROVAL_REQUIRED') !== false, 'adjustment endpoint should translate negative-result approval errors');
    inventoryPhase9Assert(strpos($endpoint, 'ITEM_UNIT_NOT_FOUND') !== false && strpos($endpoint, 'INVALID_UNIT_CONVERSION') !== false, 'adjustment endpoint should translate unit errors');
    inventoryPhase9Assert(strpos($endpoint, 'allow_reason_code_approval') !== false, 'adjustment endpoint should pass reason-code approval context');
    inventoryPhase9Assert(strpos($endpoint, 'REASON_CODE_APPROVAL_REQUIRED') !== false, 'adjustment endpoint should translate reason-code approval errors');

    $sidebar = inventoryPhase9Source($root . '/includes/sidebar.php');
    inventoryPhase9Assert(strpos($sidebar, 'inventory_adjustments.php') !== false && strpos($sidebar, 'الهالك والتسويات') !== false, 'sidebar should link Arabic adjustment page');
    inventoryPhase9Assert(!is_file($root . '/recipe_waste.php'), 'legacy recipe waste page should be removed after Inventory adjustment cutover');
    inventoryPhase9Assert(!is_file($root . '/classes/Recipe/RecipeWasteAdjustmentService.php'), 'legacy recipe waste service should be removed after Inventory adjustment cutover');

    $reports = inventoryPhase9Source($root . '/reports.php');
    inventoryPhase9Assert(strpos($reports, 'inventory_adjustments.php?from=recipe_reports') !== false, 'reports should link inventory adjustment screen instead of legacy waste UI');

    $docs = inventoryPhase9Source($root . '/docs/inventory/phase9_adjustment_contracts.md');
    foreach (['defaults the unit selector from existing stock-level preferences', 'preferred_count_unit_id', 'still validates the submitted unit', '`inventory.approve` or `accounting.view`', 'ordinary `inventory.edit` users', 'Photo attachment for waste', 'metadata_json', 'uploads/inventory_waste', 'recipe_waste.php` and `RecipeWasteAdjustmentService` have been deleted'] as $needle) {
        inventoryPhase9Assert(strpos($docs, $needle) !== false, 'phase9 docs should include: ' . $needle);
    }
}

function inventoryPhase9One(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase9Assert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryPhase9DecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === $expected;
}

function inventoryPhase9Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase9Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
