<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryReportsService.php';
require_once $root . '/classes/Inventory/InventoryStockLevelService.php';

inventoryPhase18AssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase18-stock-level-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase18_stock_level_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase18CreateLegacyTables($conn);
    $conn->query("
        INSERT INTO acc_head (id, aname, is_stock, isdeleted)
        VALUES (3, 'Main Store', 1, 0)
    ");
    $conn->query("
        INSERT INTO acc_head (id, aname, is_stock, isdeleted)
        VALUES (18021, 'Stock Supplier', 0, 0)
    ");
    $conn->query("
        INSERT INTO myitems (id, iname, barcode, group1, itmqty, cost_price, item_type, track_stock, isdeleted)
        VALUES
            (18001, 'Stock level flour', 'SL-18001', 77, 0.000000, 2.000000, 'ingredient', 1, 0),
            (18002, 'Stock level service', 'SL-18002', 77, 0.000000, 0.000000, 'service', 0, 0),
            (18003, 'Stock level sugar', 'SL-18003', 77, 0.000000, 3.000000, 'ingredient', 1, 0)
    ");
    $conn->query("INSERT INTO item_group (id, gname, isdeleted) VALUES (77, 'Dry Goods', 0), (78, 'Empty Category', 0)");
    $conn->query("INSERT INTO myunits (id, uname) VALUES (18011, 'Bag'), (18012, 'Carton')");
    $conn->query("
        INSERT INTO item_units (item_id, unit_id, u_val)
        VALUES (18001, 18011, 25.000000), (18001, 18012, 50.000000)
    ");

    $flags = new InventoryFeatureFlags([
        'inventory' => [
            'ledger_mode' => 'bridge',
            'legacy_mirror' => '1',
        ],
    ]);
    $ledger = new InventoryLedgerService($flags);
    inventoryPhase18SeedStock($conn, $ledger, 18001, '4.000000', '2.000000');

    $service = new InventoryStockLevelService($flags);
    $saved = $service->save($conn, [
        'store_id' => 3,
        'item_id' => 18001,
        'minimum_level' => '5.000000',
        'reorder_level' => '8.000000',
        'par_level' => '12.000000',
        'maximum_level' => '20.000000',
        'safety_stock_qty' => '2.000000',
        'preferred_count_unit_id' => 18011,
        'preferred_purchase_unit_id' => 18012,
        'default_supplier_account_id' => 18021,
        'is_active' => 1,
    ], ['user_id' => 7]);
    inventoryPhase18Assert($saved['success'] === true, 'stock level save should succeed');
    inventoryPhase18Assert(!empty($saved['writes']['recipe_audit_log']), 'stock level save should write an audit event when audit table exists');
    $row = inventoryPhase18One($conn, 'SELECT * FROM inventory_item_stock_levels WHERE item_id = 18001 AND store_id = 3 LIMIT 1');
    inventoryPhase18Assert(inventoryPhase18DecimalEquals($row['minimum_level'], '5.000000'), 'minimum should persist');
    inventoryPhase18Assert(inventoryPhase18DecimalEquals($row['reorder_level'], '8.000000'), 'reorder should persist');
    inventoryPhase18Assert(inventoryPhase18DecimalEquals($row['par_level'], '12.000000'), 'par should persist');
    inventoryPhase18Assert(inventoryPhase18DecimalEquals($row['maximum_level'], '20.000000'), 'maximum should persist');
    inventoryPhase18Assert((int) $row['preferred_count_unit_id'] === 18011 && (int) $row['preferred_purchase_unit_id'] === 18012, 'preferred units should persist');
    inventoryPhase18Assert((int) $row['default_supplier_account_id'] === 18021, 'default supplier should persist on stock level policy');
    inventoryPhase18Assert((int) $row['created_by'] === 7 && (int) $row['updated_by'] === 7, 'stock level should audit user ids');
    $createdAudit = inventoryPhase18One($conn, "SELECT * FROM recipe_audit_log WHERE entity_type = 'inventory_stock_level' ORDER BY id ASC LIMIT 1");
    inventoryPhase18Assert($createdAudit['action'] === 'create_inventory_stock_level', 'new stock level policy should write create audit action');
    inventoryPhase18Assert($createdAudit['before_json'] === null, 'create audit should not invent a before state');
    inventoryPhase18Assert(strpos((string) $createdAudit['after_json'], '"item_id":"18001"') !== false || strpos((string) $createdAudit['after_json'], '"item_id":18001') !== false, 'create audit should include stock level item id');
    inventoryPhase18Assert((int) $createdAudit['actor_user_id'] === 7, 'create audit should stamp actor user');

    try {
        $service->save($conn, [
            'store_id' => 3,
            'item_id' => 18001,
            'minimum_level' => '4.000000',
            'reorder_level' => '6.000000',
            'par_level' => '10.000000',
        ], ['user_id' => 8]);
        inventoryPhase18Assert(false, 'existing policy update should require approval context');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase18Assert($exception->getMessage() === 'STOCK_LEVEL_APPROVAL_REQUIRED', 'existing policy update should return approval-required code');
    }

    $service->save($conn, [
        'store_id' => 3,
        'item_id' => 18001,
        'minimum_level' => '4.000000',
        'reorder_level' => '6.000000',
        'par_level' => '10.000000',
        'maximum_level' => '0.000000',
        'safety_stock_qty' => '1.000000',
        'is_active' => 0,
    ], ['user_id' => 8, 'allow_policy_approval' => true]);
    inventoryPhase18Assert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_item_stock_levels')->fetch_assoc()['c'] === 1, 'stock level upsert should not duplicate item/store row');
    $row = inventoryPhase18One($conn, 'SELECT minimum_level, reorder_level, par_level, maximum_level, safety_stock_qty, is_active, updated_by FROM inventory_item_stock_levels WHERE item_id = 18001 AND store_id = 3 LIMIT 1');
    inventoryPhase18Assert(inventoryPhase18DecimalEquals($row['minimum_level'], '4.000000'), 'upsert should update minimum');
    inventoryPhase18Assert((int) $row['is_active'] === 0 && (int) $row['updated_by'] === 8, 'upsert should update active flag and user');
    $updatedAudit = inventoryPhase18One($conn, "SELECT * FROM recipe_audit_log WHERE entity_type = 'inventory_stock_level' ORDER BY id DESC LIMIT 1");
    inventoryPhase18Assert($updatedAudit['action'] === 'update_inventory_stock_level', 'existing stock level policy should write update audit action');
    inventoryPhase18Assert(strpos((string) $updatedAudit['before_json'], '"minimum_level":"5.000000"') !== false, 'update audit should preserve previous policy values');
    inventoryPhase18Assert(strpos((string) $updatedAudit['after_json'], '"minimum_level":"4.000000"') !== false, 'update audit should preserve new policy values');
    inventoryPhase18Assert((int) $updatedAudit['actor_user_id'] === 8, 'update audit should stamp actor user');

    $service->save($conn, [
        'store_id' => 3,
        'item_id' => 18001,
        'minimum_level' => '5.000000',
        'reorder_level' => '8.000000',
        'par_level' => '12.000000',
        'maximum_level' => '20.000000',
        'safety_stock_qty' => '2.000000',
        'preferred_purchase_unit_id' => 18012,
        'default_supplier_account_id' => 18021,
        'is_active' => 1,
    ], ['user_id' => 9, 'allow_policy_approval' => true]);
    $reports = new InventoryReportsService();
    $lowStock = $reports->report($conn, 'low_stock', ['store_id' => 3, 'item_id' => 18001, 'limit' => 20]);
    inventoryPhase18Assert(count($lowStock) === 1 && inventoryPhase18DecimalEquals($lowStock[0]['reorder_level'], '8.000000'), 'saved stock levels should drive low-stock report');
    $suggestions = $reports->report($conn, 'replenishment_suggestions', ['store_id' => 3, 'item_id' => 18001, 'limit' => 20]);
    inventoryPhase18Assert(count($suggestions) === 1 && inventoryPhase18DecimalEquals($suggestions[0]['suggested_qty'], '8.000000'), 'saved par should drive replenishment suggestion');
    inventoryPhase18Assert((int) $suggestions[0]['preferred_purchase_unit_id'] === 18012, 'replenishment suggestion should expose preferred purchase unit');
    inventoryPhase18Assert((int) $suggestions[0]['default_supplier_account_id'] === 18021 && (string) $suggestions[0]['default_supplier_name'] === 'Stock Supplier', 'replenishment suggestion should expose default supplier');
    inventoryPhase18Assert(inventoryPhase18DecimalEquals($suggestions[0]['suggested_purchase_qty'], '1.000000') && inventoryPhase18DecimalEquals($suggestions[0]['suggested_purchase_base_qty'], '50.000000'), 'replenishment suggestion should round to preferred purchase pack');

    $movementCountBeforeImport = (int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'];
    $import = $service->importCsv($conn, implode("\n", [
        'store_id,store_name,item_id,item_name,minimum_level,reorder_level,par_level,maximum_level,safety_stock_qty,preferred_count_unit_id,preferred_purchase_unit_id,default_supplier_account_id,is_active',
        '3,Main Store,18001,Stock level flour,6.000000,9.000000,14.000000,25.000000,3.000000,18011,18012,18021,1',
    ]), ['user_id' => 10, 'allow_policy_approval' => true]);
    inventoryPhase18Assert($import['success'] === true && (int) $import['imported_count'] === 1, 'CSV import should import one stock level row');
    $row = inventoryPhase18One($conn, 'SELECT minimum_level, reorder_level, par_level, maximum_level, safety_stock_qty, preferred_count_unit_id, preferred_purchase_unit_id, default_supplier_account_id, updated_by FROM inventory_item_stock_levels WHERE item_id = 18001 AND store_id = 3 LIMIT 1');
    inventoryPhase18Assert(inventoryPhase18DecimalEquals($row['minimum_level'], '6.000000'), 'CSV import should update minimum');
    inventoryPhase18Assert(inventoryPhase18DecimalEquals($row['par_level'], '14.000000'), 'CSV import should update par');
    inventoryPhase18Assert((int) $row['preferred_count_unit_id'] === 18011 && (int) $row['preferred_purchase_unit_id'] === 18012, 'CSV import should preserve preferred units');
    inventoryPhase18Assert((int) $row['default_supplier_account_id'] === 18021, 'CSV import should preserve default supplier');
    inventoryPhase18Assert((int) $row['updated_by'] === 10, 'CSV import should audit user id');
    inventoryPhase18Assert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'] === $movementCountBeforeImport, 'CSV import should not create inventory movements');

    try {
        $service->importCsv($conn, "store_id,item_id,minimum_level,reorder_level\n3,18001,5.000000,4.000000", ['user_id' => 7]);
        inventoryPhase18Assert(false, 'invalid CSV row should fail with row number');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase18Assert($exception->getMessage() === 'CSV_ROW_2_REORDER_BELOW_MINIMUM', 'invalid CSV row should include row number and validation code');
    }

    $categoryUpdate = $service->updateCategory($conn, [
        'store_id' => 3,
        'category_id' => 77,
        'minimum_level' => '7.000000',
        'reorder_level' => '9.000000',
        'par_level' => '15.000000',
        'maximum_level' => '26.000000',
        'safety_stock_qty' => '4.000000',
        'is_active' => 1,
    ], ['user_id' => 11, 'allow_policy_approval' => true]);
    inventoryPhase18Assert($categoryUpdate['success'] === true && (int) $categoryUpdate['updated_count'] === 2, 'category update should apply to tracked non-service items only');
    inventoryPhase18Assert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_item_stock_levels WHERE store_id = 3')->fetch_assoc()['c'] === 2, 'category update should create one row per tracked category item');
    $categoryRows = $conn->query('SELECT item_id, minimum_level, par_level, default_supplier_account_id, updated_by FROM inventory_item_stock_levels WHERE store_id = 3 ORDER BY item_id')->fetch_all(MYSQLI_ASSOC);
    inventoryPhase18Assert((int) $categoryRows[0]['item_id'] === 18001 && (int) $categoryRows[1]['item_id'] === 18003, 'category update should exclude service/non-stock item');
    inventoryPhase18Assert(inventoryPhase18DecimalEquals($categoryRows[0]['minimum_level'], '7.000000') && inventoryPhase18DecimalEquals($categoryRows[1]['par_level'], '15.000000'), 'category update should apply policy values');
    inventoryPhase18Assert((int) ($categoryRows[0]['default_supplier_account_id'] ?? 0) === 18021 && empty($categoryRows[1]['default_supplier_account_id']), 'category update should preserve existing supplier defaults without mass-assigning new ones');
    inventoryPhase18Assert((int) $categoryRows[0]['updated_by'] === 11 && (int) $categoryRows[1]['updated_by'] === 11, 'category update should audit user id');
    inventoryPhase18Assert((int) $conn->query('SELECT COUNT(*) AS c FROM inventory_movements')->fetch_assoc()['c'] === $movementCountBeforeImport, 'category update should not create inventory movements');

    try {
        $service->updateCategory($conn, [
            'store_id' => 3,
            'category_id' => 78,
            'minimum_level' => '1.000000',
        ], ['user_id' => 7]);
        inventoryPhase18Assert(false, 'empty category update should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase18Assert($exception->getMessage() === 'CATEGORY_ITEMS_NOT_FOUND', 'empty category should return expected code');
    }

    try {
        $service->save($conn, [
            'store_id' => 3,
            'item_id' => 18001,
            'minimum_level' => '5.000000',
            'reorder_level' => '4.000000',
            'par_level' => '10.000000',
        ], ['user_id' => 7]);
        inventoryPhase18Assert(false, 'reorder below minimum should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase18Assert($exception->getMessage() === 'REORDER_BELOW_MINIMUM', 'reorder below minimum should return expected code');
    }

    try {
        $service->save($conn, [
            'store_id' => 3,
            'item_id' => 18001,
            'preferred_count_unit_id' => 999999,
        ], ['user_id' => 7]);
        inventoryPhase18Assert(false, 'unknown preferred unit should fail');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase18Assert($exception->getMessage() === 'ITEM_UNIT_NOT_FOUND', 'unknown preferred unit should return expected code');
    }

    try {
        $service->save($conn, [
            'store_id' => 3,
            'item_id' => 18001,
            'default_supplier_account_id' => 3,
        ], ['user_id' => 7]);
        inventoryPhase18Assert(false, 'stock store should not be accepted as default supplier');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase18Assert($exception->getMessage() === 'SUPPLIER_NOT_FOUND', 'invalid default supplier should return expected code');
    }

    try {
        $service->save($conn, [
            'store_id' => 3,
            'item_id' => 18002,
            'minimum_level' => '1.000000',
        ], ['user_id' => 7]);
        inventoryPhase18Assert(false, 'service item should reject stock levels');
    } catch (InvalidArgumentException $exception) {
        inventoryPhase18Assert($exception->getMessage() === 'NON_STOCK_ITEM_CANNOT_HAVE_LEVELS', 'service item stock levels should return expected code');
    }

    echo "inventory-phase18-stock-level-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase18CreateLegacyTables(mysqli $conn): void
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
  is_stock TINYINT(1) NOT NULL DEFAULT 0,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $conn->query("
CREATE TABLE item_group (
  id INT NOT NULL PRIMARY KEY,
  gname VARCHAR(120) NOT NULL,
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

function inventoryPhase18AssertSourceContracts(string $root): void
{
    $page = inventoryPhase18Source($root . '/inventory_stock_levels.php');
    foreach (['مستويات المخزون', 'ajax/inventory_stock_level_save.php', 'ajax/inventory_stock_level_bulk.php', 'stock_level_export=template', 'stock_level_export=current', 'استيراد تقني CSV', 'inventoryStockLevelCanTechnicalImport', "auth_guard_has_permission('system.tools.run'", 'FORBIDDEN', 'تطبيق على تصنيف كامل', 'applyInventoryStockLevelCategory', 'category_update', 'inventory-stock-level-csrf', 'نقطة الطلب', 'المستهدف', 'وحدة العد المفضلة', 'وحدة الشراء المفضلة', 'المورد الافتراضي', 'default_supplier_account_id', 'inventoryStockLevelItemSearch', 'data-stock-level-load', 'inventoryStockLevelLoadRow', 'inventoryStockLevelApplyItemSearch', 'تحميل للتعديل'] as $needle) {
        inventoryPhase18Assert(strpos($page, $needle) !== false, 'stock level UI should include: ' . $needle);
    }
    inventoryPhase18Assert(strpos($page, 'const inventoryStockLevelImportButton') !== false, 'stock level UI should not bind CSV import events when the technical import panel is hidden');
    inventoryPhase18Assert(strpos($page, "('#' .") === false && strpos($page, "(string) \$row['store_id']") === false && strpos($page, 'صنف غير مسمى') !== false && strpos($page, 'تصنيف غير مسمى') !== false && strpos($page, 'مخزن غير مسمى') !== false, 'stock level UI should avoid raw id fallback labels in normal tables');
    foreach (['inventoryStockLevelColumnExists', 'inventoryStockLevelHasDefaultSupplierColumn', 'سيظهر اختيار المورد الافتراضي بعد تطبيق تحديث قاعدة البيانات', 'AS default_supplier_account_id'] as $needle) {
        inventoryPhase18Assert(strpos($page, $needle) !== false, 'stock level page should tolerate legacy schemas without default supplier column: ' . $needle);
    }

    $endpoint = inventoryPhase18Source($root . '/ajax/inventory_stock_level_save.php');
    foreach (['InventoryStockLevelService.php', "require_permission('inventory.edit'", "require_csrf('inventory_stock_level'", 'allow_policy_approval', 'STOCK_LEVEL_APPROVAL_REQUIRED', 'REORDER_BELOW_MINIMUM', 'ITEM_UNIT_NOT_FOUND', 'SUPPLIER_NOT_FOUND', 'المورد الافتراضي غير صحيح أو غير متاح'] as $needle) {
        inventoryPhase18Assert(strpos($endpoint, $needle) !== false, 'stock level endpoint should include: ' . $needle);
    }

    $bulkEndpoint = inventoryPhase18Source($root . '/ajax/inventory_stock_level_bulk.php');
    foreach (['InventoryStockLevelService.php', 'importCsv', 'updateCategory', "require_permission('inventory.edit'", "require_csrf('inventory_stock_level'", "auth_guard_has_permission('system.tools.run'", 'TECHNICAL_IMPORT_FORBIDDEN', 'allow_policy_approval', 'STOCK_LEVEL_APPROVAL_REQUIRED', 'CSV_ROW_', 'CATEGORY_ITEMS_NOT_FOUND', 'SUPPLIER_NOT_FOUND', 'المورد الافتراضي غير صحيح أو غير متاح'] as $needle) {
        inventoryPhase18Assert(strpos($bulkEndpoint, $needle) !== false, 'stock level bulk endpoint should include: ' . $needle);
    }

    $sidebar = inventoryPhase18Source($root . '/includes/sidebar.php');
    inventoryPhase18Assert(strpos($sidebar, 'inventory_stock_levels.php') !== false && strpos($sidebar, 'مستويات المخزون') !== false, 'sidebar should link Arabic stock level page');

    $docs = inventoryPhase18Source($root . '/docs/inventory/phase18_stock_level_contracts.md');
    foreach (['existing `inventory_item_stock_levels` table', 'does not write inventory movements', 'low-stock and replenishment reports', 'preferred count and purchase units', 'default supplier', 'Technical admins can download a CSV template', 'Normal inventory editors do not see the raw-ID CSV import/export controls', 'up to 500 CSV rows', 'InventoryStockLevelService::save()', 'Category-wide mass update', 'rounded purchase-unit quantities', 'STOCK_LEVEL_APPROVAL_REQUIRED', 'before/after audit', 'item search by name/barcode', 'load-for-edit action'] as $needle) {
        inventoryPhase18Assert(strpos($docs, $needle) !== false, 'phase18 docs should include: ' . $needle);
    }

    $service = inventoryPhase18Source($root . '/classes/Inventory/InventoryStockLevelService.php');
    foreach (['recordStockLevelAudit', 'inventory_stock_level', 'create_inventory_stock_level', 'update_inventory_stock_level', 'before_json', 'after_json'] as $needle) {
        inventoryPhase18Assert(strpos($service, $needle) !== false, 'stock level service should preserve audit behavior: ' . $needle);
    }
}

function inventoryPhase18SeedStock(mysqli $conn, InventoryLedgerService $ledger, int $itemId, string $qty, string $unitCost): void
{
    $conn->begin_transaction();
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => $itemId,
        'movement_type' => 'opening_balance',
        'source_type' => 'manual',
        'source_uuid' => 'phase18:seed',
        'qty_in' => $qty,
        'unit_cost' => $unitCost,
        'total_cost' => InventoryDecimal::multiply($qty, $unitCost),
        'idempotency_key' => 'phase18-stock-level-seed',
        'metadata' => ['source' => 'phase18_stock_level_test'],
        'created_by' => 7,
    ], ['id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1], ['manage_transaction' => false]);
    $conn->commit();
}

function inventoryPhase18One(mysqli $conn, string $sql): array
{
    $row = $conn->query($sql)->fetch_assoc();
    inventoryPhase18Assert(is_array($row), 'Expected row for query: ' . $sql);

    return $row;
}

function inventoryPhase18DecimalEquals($actual, string $expected): bool
{
    return number_format((float) $actual, 6, '.', '') === $expected;
}

function inventoryPhase18Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase18Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
