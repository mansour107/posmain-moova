<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryCountService.php';

inventoryPhase7AssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase7-count-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase7_count_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase7CreateLegacyItemTable($conn);
    $conn->query("
        INSERT INTO myitems (id, iname, itmqty, cost_price, last_price, item_type, track_stock)
        VALUES
            (6401, 'Count increase item', 0.000000, 0.000000, 0.000000, 'ingredient', 1),
            (6402, 'Count decrease item', 0.000000, 0.000000, 0.000000, 'ingredient', 1),
            (6403, 'Count stale item', 0.000000, 0.000000, 0.000000, 'ingredient', 1),
            (6405, 'Count unit item', 0.000000, 0.000000, 0.000000, 'ingredient', 1),
            (6406, 'Count category low item', 0.000000, 0.000000, 0.000000, 'ingredient', 1),
            (6407, 'Count category healthy item', 0.000000, 0.000000, 0.000000, 'ingredient', 1),
            (6404, 'Count service item', 0.000000, 0.000000, 0.000000, 'service', 0)
    ");
    $conn->query('UPDATE myitems SET group1 = 17 WHERE id IN (6406, 6407)');
    $conn->query("INSERT INTO myunits (id, uname) VALUES (903, 'Case')");
    $conn->query("
        INSERT INTO item_units (item_id, unit_id, u_val, cost_price)
        VALUES (6405, 903, 12.000000, 24.000000)
    ");

    $flags = new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'bridge',
            'legacy_mirror' => '1',
        ],
    ]);
    $ledger = new InventoryLedgerService($flags);
    $service = new InventoryCountService($flags, $ledger);

    inventoryPhase7SeedStock($conn, $ledger, 6401, '10.000000', '2.000000', 'seed-6401');
    inventoryPhase7SeedStock($conn, $ledger, 6402, '8.000000', '3.000000', 'seed-6402');
    inventoryPhase7SeedStock($conn, $ledger, 6403, '5.000000', '4.000000', 'seed-6403');
    inventoryPhase7SeedStock($conn, $ledger, 6405, '24.000000', '2.000000', 'seed-6405');
    inventoryPhase7SeedStock($conn, $ledger, 6406, '2.000000', '1.000000', 'seed-6406');
    inventoryPhase7SeedStock($conn, $ledger, 6407, '9.000000', '1.000000', 'seed-6407');
    $conn->query("
        INSERT INTO inventory_item_stock_levels (pos_tenant, pos_branch, store_id, item_id, minimum_level, reorder_level, par_level, maximum_level)
        VALUES
            (0, 0, 3, 6406, 1.000000, 3.000000, 6.000000, 10.000000),
            (0, 0, 3, 6407, 1.000000, 3.000000, 6.000000, 10.000000)
    ");

    $draft = $service->createDraft($conn, [
        'count_uuid' => '99999999-9999-4999-8999-999999999999',
        'store_id' => 3,
        'count_type' => 'selected',
        'lines' => [
            ['item_id' => 6401],
        ],
    ], ['user_id' => 7]);
    inventoryPhase7Assert($draft['status'] === 'draft', 'count should start as draft');
    $line = inventoryPhase7One($conn, 'SELECT * FROM inventory_count_lines WHERE count_id = ' . (int) $draft['count_id'] . ' LIMIT 1');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($line['snapshot_qty'], '10.000000'), 'count line should snapshot on-hand qty');
    inventoryPhase7Assert((int) ($line['snapshot_last_movement_id'] ?? 0) > 0, 'count line should snapshot last movement id');

    $replay = $service->createDraft($conn, [
        'count_uuid' => '99999999-9999-4999-8999-999999999999',
        'store_id' => 3,
        'lines' => [
            ['item_id' => 6401],
        ],
    ], ['user_id' => 7]);
    inventoryPhase7Assert(!empty($replay['idempotent_replay']), 'same count uuid should replay');
    inventoryPhase7Assert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_counts')->fetch_assoc()['c'] === 1, 'count replay should not duplicate header');
    try {
        $service->createDraft($conn, [
            'count_uuid' => '99999999-9999-4999-8999-999999999999',
            'store_id' => 3,
            'lines' => [
                ['item_id' => 6402],
            ],
        ], ['user_id' => 7]);
        inventoryPhase7Assert(false, 'changed count uuid retry should fail');
    } catch (RuntimeException $exception) {
        inventoryPhase7Assert($exception->getMessage() === 'COUNT_IDEMPOTENCY_CONFLICT', 'changed count uuid retry should return expected conflict');
    }

    $categoryCount = $service->createDraft($conn, [
        'count_uuid' => '12121212-1212-4121-8121-121212121212',
        'store_id' => 3,
        'count_type' => 'category',
        'category_id' => 17,
    ], ['user_id' => 7]);
    $categoryItems = inventoryPhase7Column($conn, 'SELECT item_id FROM inventory_count_lines WHERE count_id = ' . (int) $categoryCount['count_id'] . ' ORDER BY item_id');
    inventoryPhase7Assert($categoryItems === [6406, 6407], 'category count should auto-fill stock items from the selected category');

    $lowStockCount = $service->createDraft($conn, [
        'count_uuid' => '13131313-1313-4131-8131-131313131313',
        'store_id' => 3,
        'count_type' => 'full',
        'low_stock_only' => 1,
    ], ['user_id' => 7]);
    $lowStockItems = inventoryPhase7Column($conn, 'SELECT item_id FROM inventory_count_lines WHERE count_id = ' . (int) $lowStockCount['count_id'] . ' ORDER BY item_id');
    inventoryPhase7Assert($lowStockItems === [6406], 'low-stock count should auto-fill only items at or below reorder/minimum level');

    $fullCount = $service->createDraft($conn, [
        'count_uuid' => '14141414-1414-4141-8141-141414141414',
        'store_id' => 3,
        'count_type' => 'full',
        'autofill_limit' => 20,
    ], ['user_id' => 7]);
    $fullItems = inventoryPhase7Column($conn, 'SELECT item_id FROM inventory_count_lines WHERE count_id = ' . (int) $fullCount['count_id'] . ' ORDER BY item_id');
    inventoryPhase7Assert(in_array(6401, $fullItems, true) && in_array(6407, $fullItems, true), 'full count should auto-fill stock items');
    inventoryPhase7Assert(!in_array(6404, $fullItems, true), 'full count should not auto-fill service items');

    $save = $service->saveLines($conn, (int) $draft['count_id'], [
        ['item_id' => 6401, 'counted_qty' => '12.000000'],
    ], ['user_id' => 8]);
    inventoryPhase7Assert($save['saved_lines'] === 1, 'draft count should save counted qty');
    $savedLine = inventoryPhase7One($conn, 'SELECT counted_qty, variance_qty, variance_cost FROM inventory_count_lines WHERE id = ' . (int) $line['id'] . ' LIMIT 1');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($savedLine['variance_qty'], '2.000000'), 'saved count should calculate variance qty');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($savedLine['variance_cost'], '4.000000'), 'saved count should calculate variance cost');

    $service->submit($conn, (int) $draft['count_id'], ['user_id' => 9]);
    $service->approve($conn, (int) $draft['count_id'], ['user_id' => 10]);
    $close = $service->close($conn, (int) $draft['count_id'], ['user_id' => 11]);
    inventoryPhase7Assert($close['status'] === 'closed' && count($close['movement_ids']) === 1, 'closing positive variance should create one movement');
    $balance = inventoryPhase7One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6401 AND store_id = 3 LIMIT 1');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($balance['qty_on_hand'], '12.000000'), 'positive count variance should increase on hand to counted qty');
    $movement = inventoryPhase7One($conn, "SELECT movement_type, source_type, qty_in, qty_out FROM inventory_movements WHERE source_type = 'inventory_count' AND item_id = 6401 LIMIT 1");
    inventoryPhase7Assert($movement['movement_type'] === 'adjustment' && inventoryPhase7DecimalEquals($movement['qty_in'], '2.000000'), 'positive variance should be inbound adjustment');
    $secondClose = $service->close($conn, (int) $draft['count_id'], ['user_id' => 11]);
    inventoryPhase7Assert(!empty($secondClose['idempotent_replay']), 'closed count should replay safely');
    $reverse = $service->reverseClosed($conn, (int) $draft['count_id'], ['user_id' => 12, 'reason' => 'wrong physical count']);
    inventoryPhase7Assert($reverse['status'] === 'cancelled' && count($reverse['movement_ids']) === 1, 'closed count reversal should cancel the count and create one inverse movement');
    $balance = inventoryPhase7One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6401 AND store_id = 3 LIMIT 1');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($balance['qty_on_hand'], '10.000000'), 'count reversal should restore the pre-count balance');
    $reversalMovement = inventoryPhase7One($conn, "SELECT movement_type, source_type, qty_in, qty_out, metadata_json FROM inventory_movements WHERE source_type = 'inventory_count' AND idempotency_key LIKE 'inventory-count-reversal:%' AND item_id = 6401 LIMIT 1");
    inventoryPhase7Assert($reversalMovement['movement_type'] === 'adjustment' && inventoryPhase7DecimalEquals($reversalMovement['qty_out'], '2.000000'), 'positive count reversal should be outbound adjustment');
    inventoryPhase7Assert(strpos((string) $reversalMovement['metadata_json'], 'wrong physical count') !== false, 'count reversal movement should keep correction reason');
    $secondReverse = $service->reverseClosed($conn, (int) $draft['count_id'], ['user_id' => 12]);
    inventoryPhase7Assert(!empty($secondReverse['idempotent_replay']), 'cancelled count reversal should replay safely');
    inventoryPhase7Assert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_movements WHERE source_type = 'inventory_count' AND idempotency_key LIKE 'inventory-count-reversal:%' AND item_id = 6401")->fetch_assoc()['c'] === 1, 'count reversal replay should not duplicate movement');

    $decrease = $service->createDraft($conn, [
        'count_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        'store_id' => 3,
        'lines' => [
            ['item_id' => 6402, 'counted_qty' => '5.000000'],
        ],
    ], ['user_id' => 7]);
    $service->submit($conn, (int) $decrease['count_id'], ['user_id' => 9]);
    $service->approve($conn, (int) $decrease['count_id'], ['user_id' => 10]);
    $service->close($conn, (int) $decrease['count_id'], ['user_id' => 11]);
    $balance = inventoryPhase7One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6402 AND store_id = 3 LIMIT 1');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($balance['qty_on_hand'], '5.000000'), 'negative count variance should decrease on hand to counted qty');
    $movement = inventoryPhase7One($conn, "SELECT qty_out FROM inventory_movements WHERE source_type = 'inventory_count' AND item_id = 6402 LIMIT 1");
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($movement['qty_out'], '3.000000'), 'negative variance should be outbound adjustment');

    $unitCount = $service->createDraft($conn, [
        'count_uuid' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        'store_id' => 3,
        'lines' => [
            ['item_id' => 6405, 'unit_id' => 903, 'counted_qty' => '3.000000'],
        ],
    ], ['user_id' => 7]);
    $unitLine = inventoryPhase7One($conn, 'SELECT unit_id, unit_conversion_to_base, snapshot_qty, counted_qty, variance_qty, variance_cost FROM inventory_count_lines WHERE count_id = ' . (int) $unitCount['count_id'] . ' LIMIT 1');
    inventoryPhase7Assert((int) $unitLine['unit_id'] === 903, 'unit count should preserve selected unit');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($unitLine['unit_conversion_to_base'], '12.000000'), 'unit count should freeze selected unit conversion at count open');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($unitLine['snapshot_qty'], '2.000000'), 'unit count should snapshot on-hand in selected unit');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($unitLine['counted_qty'], '3.000000'), 'unit count should keep counted qty in selected unit');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($unitLine['variance_qty'], '1.000000'), 'unit count should calculate selected-unit variance');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($unitLine['variance_cost'], '24.000000'), 'unit count should value base variance');
    $conn->query('UPDATE item_units SET u_val = 6.000000 WHERE item_id = 6405 AND unit_id = 903');
    $service->submit($conn, (int) $unitCount['count_id'], ['user_id' => 9]);
    $service->approve($conn, (int) $unitCount['count_id'], ['user_id' => 10]);
    $service->close($conn, (int) $unitCount['count_id'], ['user_id' => 11]);
    $unitBalance = inventoryPhase7One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6405 AND store_id = 3 LIMIT 1');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($unitBalance['qty_on_hand'], '36.000000'), 'unit count close should land balance on base counted qty');
    $unitMovement = inventoryPhase7One($conn, "SELECT qty_in, unit_id, unit_conversion_to_base, unit_cost, total_cost, metadata_json FROM inventory_movements WHERE source_type = 'inventory_count' AND item_id = 6405 LIMIT 1");
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($unitMovement['qty_in'], '12.000000'), 'unit count close should post base variance qty');
    inventoryPhase7Assert((int) $unitMovement['unit_id'] === 903, 'unit count movement should store selected unit');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($unitMovement['unit_conversion_to_base'], '12.000000'), 'unit count movement should store conversion');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($unitMovement['unit_cost'], '2.000000'), 'unit count movement should use base moving average cost');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($unitMovement['total_cost'], '24.000000'), 'unit count movement should value base variance qty');
    inventoryPhase7Assert(strpos((string) $unitMovement['metadata_json'], '"base_variance_qty":"12.000000"') !== false, 'unit count metadata should keep base variance qty');
    inventoryPhase7Assert(strpos((string) $unitMovement['metadata_json'], '"unit_conversion_to_base":"12.00000000"') !== false, 'unit count metadata should keep frozen conversion');

    try {
        $service->createDraft($conn, [
            'count_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'store_id' => 3,
            'lines' => [
                ['item_id' => 6405, 'unit_id' => 999999],
            ],
        ], ['user_id' => 7]);
        inventoryPhase7Assert(false, 'unknown count unit should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase7Assert($exception->getMessage() === 'ITEM_UNIT_NOT_FOUND', 'unknown count unit should return expected code');
    }
    inventoryPhase7Assert((int) $conn->query("SELECT COUNT(*) AS c FROM inventory_counts WHERE count_uuid = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee'")->fetch_assoc()['c'] === 0, 'unknown count unit should roll back header');

    $stale = $service->createDraft($conn, [
        'count_uuid' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'store_id' => 3,
        'lines' => [
            ['item_id' => 6403],
        ],
    ], ['user_id' => 7]);
    inventoryPhase7SeedStock($conn, $ledger, 6403, '1.000000', '4.000000', 'after-count-6403');
    $service->saveLines($conn, (int) $stale['count_id'], [
        ['item_id' => 6403, 'counted_qty' => '4.000000'],
    ], ['user_id' => 8]);
    $service->submit($conn, (int) $stale['count_id'], ['user_id' => 9]);
    $service->approve($conn, (int) $stale['count_id'], ['user_id' => 10]);
    try {
        $service->close($conn, (int) $stale['count_id'], ['user_id' => 11]);
        inventoryPhase7Assert(false, 'stale count should fail without confirmation');
    } catch (RuntimeException $exception) {
        inventoryPhase7Assert($exception->getMessage() === 'COUNT_STALE_SNAPSHOT', 'stale count should return expected code');
    }
    $service->close($conn, (int) $stale['count_id'], ['user_id' => 11, 'allow_stale_close' => true]);
    $balance = inventoryPhase7One($conn, 'SELECT qty_on_hand FROM inventory_item_balances WHERE item_id = 6403 AND store_id = 3 LIMIT 1');
    $staleLine = inventoryPhase7One($conn, 'SELECT stale_count_conflict FROM inventory_count_lines WHERE count_id = ' . (int) $stale['count_id'] . ' LIMIT 1');
    inventoryPhase7Assert(inventoryPhase7DecimalEquals($balance['qty_on_hand'], '4.000000'), 'confirmed stale close should still land on counted qty');
    inventoryPhase7Assert((int) $staleLine['stale_count_conflict'] === 1, 'confirmed stale close should mark stale line');

    try {
        $service->createDraft($conn, [
            'count_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'store_id' => 3,
            'lines' => [
                ['item_id' => 6404],
            ],
        ], ['user_id' => 7]);
        inventoryPhase7Assert(false, 'service item should not be countable');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase7Assert($exception->getMessage() === 'NON_STOCK_ITEM_CANNOT_BE_COUNTED', 'service item count should return expected code');
    }

    echo "inventory-phase7-count-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase7SeedStock(mysqli $conn, InventoryLedgerService $ledger, int $itemId, string $qty, string $unitCost, string $key): void
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
        'source_uuid' => 'phase7:' . $key,
        'qty_in' => $qty,
        'unit_cost' => $unitCost,
        'total_cost' => InventoryDecimal::multiply($qty, $unitCost),
        'idempotency_key' => 'phase7-count:' . $key,
        'metadata' => ['source' => 'phase7_count_test'],
        'created_by' => 7,
    ], ['id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1], ['manage_transaction' => false]);
    $conn->commit();
}

function inventoryPhase7CreateLegacyItemTable(mysqli $conn): void
{
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL PRIMARY KEY,
  iname VARCHAR(200) NOT NULL,
  barcode VARCHAR(100) NULL,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  last_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  group1 INT NULL,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable',
  track_stock TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE item_group (
  id INT NOT NULL PRIMARY KEY,
  gname VARCHAR(120) NOT NULL,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("INSERT INTO item_group (id, gname) VALUES (17, 'Count category')");
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

function inventoryPhase7AssertSourceContracts(string $root): void
{
    $page = inventoryPhase7Source($root . '/inventory_counts.php');
    foreach (['جرد المخزون', 'ajax/inventory_count_create.php', 'inventory-count-csrf', 'inventoryCountUnitOptions', 'الوحدة', 'تصنيف كامل', 'inventoryCountLowStockOnly', 'الأصناف تحت حد إعادة الطلب فقط', 'inventoryCountStatusLabel', "'submitted' => 'بانتظار الاعتماد'", "'closed' => 'مغلق'", 'inventoryCountItemSearch', 'applyInventoryCountItemSearch', 'inventoryCountSelectedTotal', 'ابحث باسم الصنف أو الباركود', 'مخزن غير مسمى'] as $needle) {
        inventoryPhase7Assert(strpos($page, $needle) !== false, 'count list UI should include: ' . $needle);
    }
    inventoryPhase7Assert(strpos($page, "تصنيف ' .") === false && strpos($page, 'تصنيف غير مسمى') !== false, 'count list UI should avoid raw id category fallbacks');
    inventoryPhase7Assert(strpos($page, "(string) \$count['store_id']") === false, 'count list UI should avoid raw store id fallbacks');

    $detail = inventoryPhase7Source($root . '/inventory_count_detail.php');
    foreach (['ajax/inventory_count_save.php', 'ajax/inventory_count_submit.php', 'ajax/inventory_count_approve.php', 'ajax/inventory_count_close.php', 'ajax/inventory_count_reverse.php', 'inventoryCountCanApprove', 'allow_stale_close', 'unit_conversion', 'reverseInventoryCount', 'inventoryCountBarcodeScan', 'applyInventoryCountScan', 'مسح الباركود', 'كمية كل مسحة', 'عكس الأثر', 'inventoryCountDetailStatusLabel', 'inventoryCountDetailTypeLabel', "'full' => 'جرد كامل'", "'cancelled' => 'ملغي'", 'مخزن غير مسمى', 'صنف غير مسمى'] as $needle) {
        inventoryPhase7Assert(strpos($detail, $needle) !== false, 'count detail UI should include: ' . $needle);
    }
    inventoryPhase7Assert(strpos($detail, "(string) \$inventoryCount['store_id']") === false, 'count detail UI should avoid raw store id fallback');
    inventoryPhase7Assert(strpos($detail, "\$line['iname'] ?? \$line['item_id']") === false, 'count detail UI should avoid raw item id fallback');

    $approveEndpoint = inventoryPhase7Source($root . '/ajax/inventory_count_approve.php');
    foreach (["auth_guard_has_permission('inventory.approve'", "auth_guard_has_permission('accounting.view'", 'COUNT_APPROVAL_REQUIRED'] as $needle) {
        inventoryPhase7Assert(strpos($approveEndpoint, $needle) !== false, 'count approve endpoint should require approval context: ' . $needle);
    }

    $endpoint = inventoryPhase7Source($root . '/ajax/inventory_count_close.php');
    foreach (['InventoryCountService.php', "require_permission('inventory.edit'", "require_csrf('inventory_count'", "auth_guard_has_permission('inventory.approve'", 'STALE_CLOSE_APPROVAL_REQUIRED'] as $needle) {
        inventoryPhase7Assert(strpos($endpoint, $needle) !== false, 'count close endpoint should include: ' . $needle);
    }

    $common = inventoryPhase7Source($root . '/ajax/inventory_count_common.php');
    inventoryPhase7Assert(strpos($common, 'اعتماد الجرد يحتاج صلاحية اعتماد المخزون') !== false, 'count common errors should translate count approval errors');
    inventoryPhase7Assert(strpos($common, 'إغلاق جرد تغير مخزونه يحتاج اعتماد مدير') !== false, 'count common errors should translate stale-close approval errors');
    inventoryPhase7Assert(strpos($common, 'عكس أثر الجرد المغلق يحتاج صلاحية اعتماد أو محاسبة') !== false, 'count common errors should translate reversal approval errors');
    inventoryPhase7Assert(strpos($common, 'ITEM_UNIT_NOT_FOUND') !== false && strpos($common, 'INVALID_UNIT_CONVERSION') !== false, 'count common errors should translate unit errors');

    $docs = inventoryPhase7Source($root . '/docs/inventory/phase7_count_contracts.md');
    foreach (['Count approval requires `inventory.approve` or `accounting.view`', 'ordinary `inventory.edit` users cannot unlock it', 'In-app browser smoke verified', 'full create/save/submit/approve/close count workflow', 'selected count units', 'Full and category counts can auto-fill lines', 'Closed count corrections are reversal-only', 'COUNT_IDEMPOTENCY_CONFLICT', 'barcode count-entry mode', 'UI-only', 'Arabic status and count-type labels', 'item search by name/barcode', 'selected-item counter', 'unnamed-category labels', 'unnamed-store and unnamed-item labels', 'Count list store labels'] as $needle) {
        inventoryPhase7Assert(strpos($docs, $needle) !== false, 'phase7 docs should include: ' . $needle);
    }

    $sidebar = inventoryPhase7Source($root . '/includes/sidebar.php');
    inventoryPhase7Assert(strpos($sidebar, 'inventory_counts.php') !== false && strpos($sidebar, 'جرد المخزون') !== false, 'sidebar should link Arabic count page');
}

function inventoryPhase7One(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase7Assert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryPhase7Column(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    $values = [];
    while ($row = $result->fetch_row()) {
        $values[] = (int) $row[0];
    }

    return $values;
}

function inventoryPhase7DecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === $expected;
}

function inventoryPhase7Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase7Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
