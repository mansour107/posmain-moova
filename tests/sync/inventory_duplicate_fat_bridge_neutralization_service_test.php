<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryStoreScopeReclassificationService.php';
require_once $root . '/classes/Inventory/InventoryDuplicateFatBridgeNeutralizationService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-duplicate-fat-bridge-neutralization-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_inventory_duplicate_fat_' . getmypid();
$backupPath = sys_get_temp_dir() . '/posmain_duplicate_fat_test_' . getmypid() . '.sql';
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    duplicateFatCreateBaseTables($conn);
    file_put_contents($backupPath, 'isolated duplicate-fat repair test backup');

    $flags = new InventoryFeatureFlags([
        'inventory' => ['ledger_mode' => 'bridge', 'legacy_mirror' => false],
        'sync' => ['operational_sync_enabled' => false],
    ]);
    $ledger = new InventoryLedgerService($flags);

    duplicateFatMovement($conn, $ledger, 7001, 1, 27, '2.000000', 'inventory-invoice-bridge:v1:detail:1');
    duplicateFatMovement($conn, $ledger, 7001, 1, 274, '2.000000', 'migration:fat_details:1:v1');
    duplicateFatMovement($conn, $ledger, 7002, 2, 27, '3.000000', 'inventory-invoice-bridge:v1:detail:2');
    duplicateFatMovement($conn, $ledger, 7002, 2, 27, '3.000000', 'migration:fat_details:2:v1');
    duplicateFatMovement($conn, $ledger, 7004, 4, 27, '1.000000', 'inventory-invoice-bridge:v1:detail:4');
    duplicateFatMovement($conn, $ledger, 7004, 4, 27, '1.000000', 'migration:fat_details:4:v1');
    $conn->query('UPDATE myitems SET track_stock = 0 WHERE id = 7004');

    $scopeService = new InventoryStoreScopeReclassificationService($ledger);
    $scopeOptions = ['source_store_ids' => [274], 'operational_store_id' => 27];
    $scopePlan = $scopeService->plan($conn, $scopeOptions);
    duplicateFatAssert($scopePlan['ok'] === true && (int) $scopePlan['summary']['entry_count'] === 1, 'wrong-store duplicate should be eligible for audited scope reclassification');
    $scopeService->apply($conn, $scopeOptions, (string) $scopePlan['manifest_hash'], $backupPath);
    duplicateFatAssert(duplicateFatQty($conn, 274, 7001) === '0.000000', 'scope repair should clear wrong store before duplicate neutralization');
    duplicateFatAssert(duplicateFatQty($conn, 27, 7001) === '4.000000', 'scope repair should place the duplicated quantity in the operational store');
    duplicateFatAssert(duplicateFatQty($conn, 27, 7002) === '6.000000', 'same-store duplicate should remain visible before repair');

    $service = new InventoryDuplicateFatBridgeNeutralizationService($ledger);
    $options = ['operational_store_id' => 27];
    $plan = $service->plan($conn, $options);
    duplicateFatAssert($plan['ok'] === true, 'unique canonical overlaps should produce a runnable plan');
    duplicateFatAssert((int) $plan['summary']['entry_count'] === 2, 'both cross-store and same-store duplicate overlaps should be planned');
    duplicateFatAssert((int) $plan['summary']['skipped_count'] === 1, 'non-stock overlaps should be delegated to the non-stock ledger neutralization repair');
    $entries = [];
    foreach ($plan['entries'] as $entry) {
        $entries[(int) $entry['fat_detail_id']] = $entry;
    }
    duplicateFatAssert((int) $entries[1]['correction_store_id'] === 27 && !empty($entries[1]['scope_reclassified']), 'reclassified duplicate must be neutralized where its quantity now lives');
    duplicateFatAssert((int) $entries[2]['correction_store_id'] === 27 && empty($entries[2]['scope_reclassified']), 'same-store duplicate must be neutralized in place');

    $beforeCount = duplicateFatScalar($conn, 'SELECT COUNT(*) FROM inventory_movements');
    $beforeGlobalQty = duplicateFatGlobalQty($conn);
    $rehearsal = $service->rehearse($conn, $options);
    duplicateFatAssert($rehearsal['ok'] === true && $rehearsal['mode'] === 'rehearse', 'repair rehearsal should execute');
    duplicateFatAssert(duplicateFatScalar($conn, 'SELECT COUNT(*) FROM inventory_movements') === $beforeCount, 'rehearsal must roll back compensating movements');
    duplicateFatAssert(duplicateFatQty($conn, 27, 7001) === '4.000000' && duplicateFatQty($conn, 27, 7002) === '6.000000', 'rehearsal must roll back balances');

    try {
        $service->apply($conn, $options, str_repeat('a', 64), $backupPath);
        duplicateFatAssert(false, 'changed manifest must be rejected');
    } catch (RuntimeException $exception) {
        duplicateFatAssert($exception->getMessage() === 'INVENTORY_DUPLICATE_FAT_REPAIR_MANIFEST_CHANGED', 'expected duplicate repair manifest mismatch');
    }

    $applied = $service->apply($conn, $options, (string) $plan['manifest_hash'], $backupPath);
    duplicateFatAssert($applied['ok'] === true && $applied['mode'] === 'apply', 'reviewed duplicate repair should apply');
    duplicateFatAssert(duplicateFatQty($conn, 27, 7001) === '2.000000', 'cross-store overlap should retain only canonical quantity');
    duplicateFatAssert(duplicateFatQty($conn, 27, 7002) === '3.000000', 'same-store overlap should retain only canonical quantity');
    duplicateFatAssert(
        InventoryDecimal::subtract($beforeGlobalQty, duplicateFatGlobalQty($conn)) === '5.000000',
        'global ledger quantity should decrease by exactly the duplicated historical quantity'
    );
    duplicateFatAssert(
        duplicateFatScalar($conn, "SELECT COUNT(*) FROM inventory_movements WHERE fat_detail_id IN (1,2) AND (idempotency_key LIKE 'inventory-invoice-bridge:%' OR idempotency_key IN ('migration:fat_details:1:v1','migration:fat_details:2:v1'))") === 4,
        'repair must preserve original migration and canonical evidence'
    );
    duplicateFatAssert(
        duplicateFatScalar($conn, "SELECT COUNT(*) FROM inventory_movements WHERE idempotency_key LIKE 'migration:fat_details:duplicate-neutralization:%'") === 2,
        'repair should add one immutable compensating movement per duplicate'
    );

    $replay = $service->apply($conn, $options, (string) $plan['manifest_hash'], $backupPath);
    duplicateFatAssert(!empty($replay['replayed']), 'same reviewed manifest should replay the recorded result');
    duplicateFatAssert(duplicateFatScalar($conn, 'SELECT COUNT(*) FROM inventory_movements') === $beforeCount + 2, 'replay must not create duplicate compensation');
    duplicateFatAssert((int) $service->plan($conn, $options)['summary']['entry_count'] === 0, 'applied overlaps should no longer be planned');

    duplicateFatMovement($conn, $ledger, 7003, 3, 27, '1.000000', 'migration:fat_details:3:v1');
    duplicateFatMovement($conn, $ledger, 7003, 3, 27, '1.000000', 'inventory-invoice-bridge:v1:detail:3:a');
    duplicateFatMovement($conn, $ledger, 7003, 3, 27, '1.000000', 'inventory-invoice-bridge:v1:detail:3:b');
    $blocked = $service->plan($conn, $options);
    duplicateFatAssert($blocked['ok'] === false && (int) $blocked['summary']['blocker_count'] === 1, 'multiple canonical matches must block automatic neutralization');
    duplicateFatAssert(($blocked['blockers'][0]['code'] ?? '') === 'duplicate_fat_bridge_canonical_match_not_unique', 'non-unique canonical blocker should be explicit');

    echo "inventory-duplicate-fat-bridge-neutralization-service-ok\n";
} finally {
    if (is_file($backupPath)) {
        unlink($backupPath);
    }
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function duplicateFatCreateBaseTables(mysqli $conn): void
{
    $conn->query('CREATE TABLE settings (id INT PRIMARY KEY, def_pos_store INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $conn->query('CREATE TABLE acc_head (id INT PRIMARY KEY, aname VARCHAR(120) NOT NULL, is_stock TINYINT NOT NULL DEFAULT 1, is_operational_store TINYINT NOT NULL DEFAULT 0, isdeleted TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $conn->query("CREATE TABLE myitems (id INT PRIMARY KEY, iname VARCHAR(120) NOT NULL, item_type VARCHAR(32) NOT NULL DEFAULT 'ingredient', track_stock TINYINT NOT NULL DEFAULT 1, itmqty DECIMAL(18,6) NOT NULL DEFAULT 0) ENGINE=InnoDB");
    $conn->query('INSERT INTO settings (id, def_pos_store) VALUES (1, 27)');
    $conn->query("INSERT INTO acc_head (id, aname, is_operational_store) VALUES (27, 'Operational', 1), (274, 'Wrong historical scope', 0)");
    $conn->query("INSERT INTO myitems (id, iname) VALUES (7001, 'Cross-store duplicate'), (7002, 'Same-store duplicate'), (7003, 'Ambiguous duplicate'), (7004, 'Non-stock duplicate')");
}

function duplicateFatMovement(
    mysqli $conn,
    InventoryLedgerService $ledger,
    int $itemId,
    int $fatDetailId,
    int $storeId,
    string $qtyIn,
    string $key
): void {
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => $storeId],
        'item_id' => $itemId,
        'movement_type' => 'purchase',
        'source_type' => 'fat_details',
        'source_id' => $fatDetailId,
        'source_uuid' => 'fat-detail:' . $fatDetailId,
        'fat_detail_id' => $fatDetailId,
        'qty_in' => $qtyIn,
        'unit_cost' => '4.000000',
        'total_cost' => InventoryDecimal::multiply($qtyIn, '4.000000'),
        'idempotency_key' => $key,
    ], ['id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1]);
}

function duplicateFatQty(mysqli $conn, int $storeId, int $itemId): string
{
    $row = $conn->query('SELECT qty_on_hand FROM inventory_item_balances WHERE pos_tenant = 0 AND pos_branch = 0 AND store_id = ' . $storeId . ' AND item_id = ' . $itemId)->fetch_assoc();

    return InventoryDecimal::normalize($row['qty_on_hand'] ?? '0');
}

function duplicateFatGlobalQty(mysqli $conn): string
{
    $row = $conn->query('SELECT COALESCE(SUM(qty_in - qty_out), 0) AS value FROM inventory_movements')->fetch_assoc();

    return InventoryDecimal::normalize($row['value'] ?? '0');
}

function duplicateFatScalar(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_row();

    return (int) ($row[0] ?? 0);
}

function duplicateFatAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
