<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryStoreScopeReclassificationService.php';
require_once $root . '/classes/Inventory/InventoryNonStockLedgerNeutralizationService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-store-scope-reclassification-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_scope_reclass_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');
$backupPath = sys_get_temp_dir() . '/posmain_scope_reclass_test_' . getmypid() . '.sql';

try {
    (new SyncSchemaManager())->apply($conn);
    scopeReclassCreateBaseTables($conn);
    file_put_contents($backupPath, 'isolated test backup');

    $flags = new InventoryFeatureFlags([
        'inventory' => ['ledger_mode' => 'bridge', 'legacy_mirror' => false],
        'sync' => ['operational_sync_enabled' => false],
    ]);
    $ledger = new InventoryLedgerService($flags);
    scopeReclassMovement($conn, $ledger, 9001, 274, 'opening_balance', '5.000000', '0', '3.000000', 'seed-positive-source');
    scopeReclassMovement($conn, $ledger, 9001, 27, 'opening_balance', '10.000000', '0', '2.000000', 'seed-positive-target');
    scopeReclassMovement($conn, $ledger, 9002, 274, 'transfer_out', '0', '4.000000', '0.000000', 'seed-negative-source');
    scopeReclassMovement($conn, $ledger, 9002, 27, 'opening_balance', '2.000000', '0', '4.000000', 'seed-negative-target');

    $service = new InventoryStoreScopeReclassificationService($ledger);
    $options = ['source_store_ids' => [274], 'operational_store_id' => 27];
    $plan = $service->plan($conn, $options);
    scopeReclassAssert($plan['ok'] === true, 'plan should be runnable');
    scopeReclassAssert((int) $plan['summary']['entry_count'] === 2, 'plan should include positive and negative source balances');
    scopeReclassAssert((int) $plan['summary']['movement_count'] === 4, 'plan should create two atomic movement pairs');

    $beforeCount = scopeReclassScalar($conn, 'SELECT COUNT(*) FROM inventory_movements');
    $rehearsal = $service->rehearse($conn, $options);
    scopeReclassAssert($rehearsal['ok'] === true && $rehearsal['mode'] === 'rehearse', 'rehearsal should run');
    scopeReclassAssert(scopeReclassScalar($conn, 'SELECT COUNT(*) FROM inventory_movements') === $beforeCount, 'rehearsal must rollback movements');
    scopeReclassAssert(scopeReclassQty($conn, 274, 9001) === '5.000000', 'rehearsal must rollback source balance');

    try {
        $service->apply($conn, $options, str_repeat('a', 64), $backupPath);
        scopeReclassAssert(false, 'changed manifest must be rejected');
    } catch (RuntimeException $exception) {
        scopeReclassAssert($exception->getMessage() === 'INVENTORY_SCOPE_RECLASSIFICATION_MANIFEST_CHANGED', 'expected manifest mismatch');
    }

    $beforeGlobalQty = scopeReclassGlobalLedgerQty($conn);
    $beforeGlobalValue = scopeReclassGlobalSignedValue($conn);
    $applied = $service->apply($conn, $options, (string) $plan['manifest_hash'], $backupPath);
    scopeReclassAssert($applied['ok'] === true && $applied['mode'] === 'apply', 'reviewed manifest should apply');
    scopeReclassAssert(scopeReclassQty($conn, 274, 9001) === '0.000000', 'positive source balance should become zero');
    scopeReclassAssert(scopeReclassQty($conn, 274, 9002) === '0.000000', 'negative source balance should become zero');
    scopeReclassAssert(scopeReclassQty($conn, 27, 9001) === '15.000000', 'positive quantity should move to target');
    scopeReclassAssert(scopeReclassQty($conn, 27, 9002) === '-2.000000', 'negative quantity should move to target');
    scopeReclassAssert(scopeReclassGlobalLedgerQty($conn) === $beforeGlobalQty, 'paired repair must preserve global ledger quantity');
    scopeReclassAssert(scopeReclassGlobalSignedValue($conn) === $beforeGlobalValue, 'paired repair must preserve signed ledger value');

    $replay = $service->apply($conn, $options, (string) $plan['manifest_hash'], $backupPath);
    scopeReclassAssert(!empty($replay['replayed']), 'same manifest should replay recorded result');
    scopeReclassAssert(scopeReclassScalar($conn, 'SELECT COUNT(*) FROM inventory_movements') === $beforeCount + 4, 'replay must not duplicate movements');

    $conn->query("INSERT INTO myitems (id, iname, item_type, track_stock) VALUES (9003, 'Historical non-stock anomaly', 'service', 1)");
    scopeReclassMovement($conn, $ledger, 9003, 274, 'transfer_out', '0', '3.000000', '0.000000', 'seed-invalid-non-stock-source');
    scopeReclassMovement($conn, $ledger, 9003, 27, 'transfer_out', '0', '1.000000', '0.000000', 'seed-invalid-non-stock-target');
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 27],
        'item_id' => 9003,
        'movement_type' => 'reservation',
        'source_type' => 'reservation',
        'qty_reserved' => '0.500000',
        'idempotency_key' => 'seed-invalid-non-stock-reservation',
    ], ['id' => 9003, 'item_type' => 'ingredient', 'track_stock' => 1], ['enforce_negative_policy' => false]);
    $conn->query('UPDATE myitems SET track_stock = 0, itmqty = 9 WHERE id = 9003');
    $nonStockService = new InventoryNonStockLedgerNeutralizationService($ledger);
    $nonStockPlan = $nonStockService->plan($conn);
    scopeReclassAssert($nonStockPlan['ok'] === true && (int) $nonStockPlan['summary']['entry_count'] === 2, 'non-stock neutralization should plan each invalid scoped balance');
    $nonStockBefore = scopeReclassScalar($conn, 'SELECT COUNT(*) FROM inventory_movements');
    $nonStockService->rehearse($conn);
    scopeReclassAssert(scopeReclassScalar($conn, 'SELECT COUNT(*) FROM inventory_movements') === $nonStockBefore, 'non-stock rehearsal must rollback');
    $nonStockApplied = $nonStockService->apply($conn, (string) $nonStockPlan['manifest_hash'], $backupPath);
    scopeReclassAssert($nonStockApplied['ok'] === true, 'reviewed non-stock manifest should apply');
    scopeReclassAssert(scopeReclassQty($conn, 274, 9003) === '0.000000' && scopeReclassQty($conn, 27, 9003) === '0.000000', 'invalid non-stock balances should become zero');
    scopeReclassAssert(scopeReclassReserved($conn, 27, 9003) === '0.000000', 'invalid non-stock reservation should be released');
    scopeReclassAssert((string) $conn->query('SELECT itmqty FROM myitems WHERE id = 9003')->fetch_assoc()['itmqty'] === '0.000000', 'non-stock legacy mirror should reset to zero');
    $nonStockReplay = $nonStockService->apply($conn, (string) $nonStockPlan['manifest_hash'], $backupPath);
    scopeReclassAssert(!empty($nonStockReplay['replayed']), 'non-stock repair replay must be idempotent');

    echo "inventory-store-scope-reclassification-service-ok\n";
} finally {
    if (is_file($backupPath)) {
        unlink($backupPath);
    }
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function scopeReclassCreateBaseTables(mysqli $conn): void
{
    $conn->query('CREATE TABLE IF NOT EXISTS settings (id INT PRIMARY KEY, def_pos_store INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $conn->query('CREATE TABLE IF NOT EXISTS acc_head (id INT PRIMARY KEY, aname VARCHAR(120) NOT NULL, is_stock TINYINT NOT NULL DEFAULT 1, is_operational_store TINYINT NOT NULL DEFAULT 0, isdeleted TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $conn->query("CREATE TABLE IF NOT EXISTS myitems (id INT PRIMARY KEY, iname VARCHAR(120) NOT NULL, item_type VARCHAR(32) NOT NULL DEFAULT 'ingredient', track_stock TINYINT NOT NULL DEFAULT 1, itmqty DECIMAL(18,6) NOT NULL DEFAULT 0) ENGINE=InnoDB");
    $conn->query('INSERT INTO settings (id, def_pos_store) VALUES (1, 27)');
    $conn->query("INSERT INTO acc_head (id, aname, is_operational_store) VALUES (27, 'Operational', 1), (274, 'Wrong historical scope', 0)");
    $conn->query("INSERT INTO myitems (id, iname) VALUES (9001, 'Positive source'), (9002, 'Negative source')");
}

function scopeReclassMovement(mysqli $conn, InventoryLedgerService $ledger, int $itemId, int $storeId, string $type, string $qtyIn, string $qtyOut, string $unitCost, string $key): void
{
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => $storeId],
        'item_id' => $itemId,
        'movement_type' => $type,
        'source_type' => 'manual',
        'qty_in' => $qtyIn,
        'qty_out' => $qtyOut,
        'unit_cost' => $unitCost,
        'idempotency_key' => $key,
    ], ['id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1]);
}

function scopeReclassQty(mysqli $conn, int $storeId, int $itemId): string
{
    $row = $conn->query('SELECT qty_on_hand FROM inventory_item_balances WHERE pos_tenant = 0 AND pos_branch = 0 AND store_id = ' . $storeId . ' AND item_id = ' . $itemId)->fetch_assoc();
    return InventoryDecimal::normalize($row['qty_on_hand'] ?? '0');
}

function scopeReclassReserved(mysqli $conn, int $storeId, int $itemId): string
{
    $row = $conn->query('SELECT qty_reserved FROM inventory_item_balances WHERE pos_tenant = 0 AND pos_branch = 0 AND store_id = ' . $storeId . ' AND item_id = ' . $itemId)->fetch_assoc();
    return InventoryDecimal::normalize($row['qty_reserved'] ?? '0');
}

function scopeReclassGlobalLedgerQty(mysqli $conn): string
{
    $row = $conn->query('SELECT COALESCE(SUM(qty_in - qty_out), 0) AS value FROM inventory_movements')->fetch_assoc();
    return InventoryDecimal::normalize($row['value'] ?? '0');
}

function scopeReclassGlobalSignedValue(mysqli $conn): string
{
    $row = $conn->query('SELECT COALESCE(SUM(CASE WHEN qty_in > 0 THEN total_cost ELSE -total_cost END), 0) AS value FROM inventory_movements')->fetch_assoc();
    return InventoryDecimal::normalize($row['value'] ?? '0');
}

function scopeReclassScalar(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_row();
    return (int) ($row[0] ?? 0);
}

function scopeReclassAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
