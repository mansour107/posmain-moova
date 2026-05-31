<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryStockReadService.php';

inventoryPhase15AssertSourceContracts($root);

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-phase15-cutover-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_phase15_cutover_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    inventoryPhase15CreateLegacyTables($conn);
    inventoryPhase15SeedFixtures($conn);

    $ledger = new InventoryLedgerService(new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]));
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 3],
        'item_id' => 15001,
        'movement_type' => 'purchase',
        'source_type' => 'manual',
        'source_uuid' => 'phase15-purchase-15001',
        'qty_in' => '7.000000',
        'unit_cost' => '2.500000',
        'total_cost' => '17.500000',
        'idempotency_key' => 'phase15:purchase:15001',
    ], ['id' => 15001, 'item_type' => 'ingredient', 'track_stock' => 1]);

    $legacyService = new InventoryStockReadService(new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]));
    inventoryPhase15Assert($legacyService->stockSource($conn) === 'legacy', 'bridge mode should keep legacy read source');
    $legacyRows = $legacyService->decorateItems($conn, [['id' => 15001, 'itmqty' => '99.000000', 'cost_price' => '1.000000']]);
    inventoryPhase15Assert($legacyRows[0]['itmqty'] === '99.000000', 'bridge mode should not replace legacy item quantity');

    $liveService = new InventoryStockReadService(new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'live']]));
    inventoryPhase15Assert($liveService->stockSource($conn) === 'ledger', 'live mode should read ledger when tables exist');
    $liveRows = $liveService->decorateItems($conn, [['id' => 15001, 'itmqty' => '99.000000', 'cost_price' => '1.000000']]);
    inventoryPhase15Assert($liveRows[0]['itmqty'] === '7.000000', 'live mode should replace displayed quantity with ledger balance');
    inventoryPhase15Assert($liveRows[0]['legacy_itmqty'] === '99.000000', 'live mode should preserve legacy mirror value for diagnostics');
    inventoryPhase15Assert($liveRows[0]['stock_qty_source'] === 'ledger', 'live item row should expose ledger source');
    $legacyApiPayload = $legacyService->decoratePublicItemPayload($conn, [['id' => 15001, 'quantity' => 99.0]]);
    inventoryPhase15Assert($legacyApiPayload[0]['quantity'] === 99.0, 'bridge mode public item API payload should preserve legacy quantity');
    inventoryPhase15Assert($legacyApiPayload[0]['stock_quantity_source'] === 'legacy', 'bridge mode public item API payload should name legacy source');
    $liveApiPayload = $liveService->decoratePublicItemPayload($conn, [['id' => 15001, 'quantity' => 99.0]]);
    inventoryPhase15Assert($liveApiPayload[0]['quantity'] === 7.0, 'live mode public item API payload should expose ledger quantity');
    inventoryPhase15Assert($liveApiPayload[0]['stock_quantity_source'] === 'ledger', 'live mode public item API payload should name ledger source');
    inventoryPhase15Assert($liveApiPayload[0]['available_quantity'] === 7.0, 'live mode public item API payload should expose cashier-safe available quantity');
    inventoryPhase15Assert($liveApiPayload[0]['reserved_quantity'] === 0.0, 'live mode public item API payload should expose reserved quantity without cost fields');

    $history = $liveService->movementHistoryForItem($conn, 15001, [], ['limit' => 20]);
    inventoryPhase15Assert($history['source'] === 'ledger', 'live item history should come from ledger');
    inventoryPhase15Assert((int) $history['total_count'] === 1, 'live item history should count ledger movements');
    inventoryPhase15Assert($history['total_in'] === '7.000000', 'live item history should summarize inbound quantity');
    inventoryPhase15Assert($history['rows'][0]['movement_type'] === 'purchase', 'live item history should expose movement type');

    $join = $liveService->itemListLedgerJoin($conn, 'mi');
    $rows = inventoryPhase15Rows($conn, "
        SELECT mi.id, {$join['qty_expr']} AS current_qty, {$join['cost_expr']} AS current_cost
        FROM myitems mi
        {$join['join_sql']}
        WHERE mi.id = 15001
    ");
    inventoryPhase15Assert($rows[0]['current_qty'] === '7.000000', 'ledger join should expose current ledger quantity');

    echo "inventory-phase15-cutover-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function inventoryPhase15AssertSourceContracts(string $root): void
{
    $serviceSource = inventoryPhase15Source($root . '/classes/Inventory/InventoryStockReadService.php');
    foreach ([
        'shouldReadLedger',
        "\$this->flags->mode() === 'live'",
        'inventory_item_balances',
        'inventory_movements',
        'decorateItems',
        'decoratePublicItemPayload',
        'movementHistoryForItem',
        'itemListLedgerJoin',
        'legacy_itmqty',
        'stock_quantity_source',
        'qty_available',
        'qty_reserved',
    ] as $needle) {
        inventoryPhase15Assert(strpos($serviceSource, $needle) !== false, 'cutover stock read service should preserve live-read behavior: ' . $needle);
    }

    $docs = inventoryPhase15Source($root . '/docs/inventory/phase15_cutover_contracts.md');
    foreach (['`POSMAIN_INVENTORY_LEDGER_MODE=live`', '`myitems.itmqty` remains a compatibility mirror', 'no feature flag default is flipped'] as $needle) {
        inventoryPhase15Assert(strpos($docs, $needle) !== false, 'cutover docs should preserve read-side guardrail: ' . $needle);
    }
}

function inventoryPhase15CreateLegacyTables(mysqli $conn): void
{
    $conn->query("
CREATE TABLE myitems (
  id INT NOT NULL PRIMARY KEY,
  code VARCHAR(64) NULL,
  barcode VARCHAR(64) NULL,
  iname VARCHAR(200) NOT NULL,
  itmqty DECIMAL(18,6) NOT NULL DEFAULT 0,
  cost_price DECIMAL(18,6) NOT NULL DEFAULT 0,
  item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'ingredient',
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
}

function inventoryPhase15SeedFixtures(mysqli $conn): void
{
    $conn->query("INSERT INTO acc_head (id, aname, is_stock) VALUES (3, 'Main Store', 1)");
    $conn->query("
        INSERT INTO myitems (id, code, barcode, iname, itmqty, cost_price, item_type, track_stock)
        VALUES (15001, 'C-15001', 'B-15001', 'Cutover flour', 99.000000, 1.000000, 'ingredient', 1)
    ");
}

function inventoryPhase15Rows(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function inventoryPhase15Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inventoryPhase15Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}
