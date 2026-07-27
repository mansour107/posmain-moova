<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryDeletedFatMovementNeutralizationService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "inventory-deleted-fat-movement-neutralization-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = 'posmain_inventory_deleted_fat_' . getmypid();
$backup = sys_get_temp_dir() . '/posmain_deleted_fat_' . getmypid() . '.sql';
$conn->query("DROP DATABASE IF EXISTS `{$db}`");
$conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn->select_db($db);

try {
    (new SyncSchemaManager())->apply($conn);
    $conn->query('CREATE TABLE settings (id INT PRIMARY KEY, def_pos_store INT NOT NULL) ENGINE=InnoDB');
    $conn->query('CREATE TABLE acc_head (id INT PRIMARY KEY, aname VARCHAR(120), is_stock TINYINT DEFAULT 1, is_operational_store TINYINT DEFAULT 0, isdeleted TINYINT DEFAULT 0) ENGINE=InnoDB');
    $conn->query('CREATE TABLE myitems (id INT PRIMARY KEY, iname VARCHAR(120), item_type VARCHAR(32), track_stock TINYINT DEFAULT 1, itmqty DECIMAL(18,6) DEFAULT 0) ENGINE=InnoDB');
    $conn->query('CREATE TABLE fat_details (id INT PRIMARY KEY, item_id INT, tenant INT DEFAULT 0, branch INT DEFAULT 0, det_store INT, isdeleted TINYINT DEFAULT 0) ENGINE=InnoDB');
    $conn->query("INSERT INTO settings VALUES (1,27)");
    $conn->query("INSERT INTO acc_head (id,aname,is_operational_store) VALUES (27,'Operational',1),(274,'Legacy',0)");
    $conn->query("INSERT INTO myitems VALUES (7101,'Deleted sale','ingredient',1,0),(7102,'Active sale','ingredient',1,0)");
    $conn->query("INSERT INTO fat_details VALUES (1,7101,0,0,274,1),(2,7102,0,0,27,0)");
    file_put_contents($backup, 'isolated deleted-fat repair backup');

    $ledger = new InventoryLedgerService(new InventoryFeatureFlags([
        'inventory' => ['ledger_mode' => 'bridge', 'legacy_mirror' => false],
        'sync' => ['operational_sync_enabled' => false],
    ]));
    deletedFatMovement($conn, $ledger, 7101, 1, 274, 'migration:fat_details:1:v1');
    deletedFatMovement($conn, $ledger, 7102, 2, 27, 'inventory-invoice-bridge:v1:detail:2');
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 27],
        'item_id' => 7101,
        'movement_type' => 'transfer_out',
        'source_type' => 'manual',
        'qty_out' => '2.000000',
        'unit_cost' => '5.000000',
        'total_cost' => '10.000000',
        'idempotency_key' => 'scope-reclass:test:0:0:274:7101:target-out',
    ], ['id' => 7101, 'item_type' => 'ingredient', 'track_stock' => 1], ['enforce_negative_policy' => false]);
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => 274],
        'item_id' => 7101,
        'movement_type' => 'transfer_in',
        'source_type' => 'manual',
        'qty_in' => '2.000000',
        'unit_cost' => '5.000000',
        'total_cost' => '10.000000',
        'idempotency_key' => 'scope-reclass:test:0:0:274:7101:source-in',
    ], ['id' => 7101, 'item_type' => 'ingredient', 'track_stock' => 1]);

    $service = new InventoryDeletedFatMovementNeutralizationService($ledger);
    $plan = $service->plan($conn, ['operational_store_id' => 27]);
    deletedFatAssert($plan['ok'] && (int) $plan['summary']['entry_count'] === 1, 'only deleted residue should be planned');
    deletedFatAssert((int) $plan['entries'][0]['correction_store_id'] === 27, 'reclassified residue must be corrected in operational store');
    deletedFatAssert($plan['entries'][0]['quantity'] === '2.000000', 'exact deleted residue must be planned');

    $before = deletedFatScalar($conn, 'SELECT COUNT(*) FROM inventory_movements');
    $rehearsal = $service->rehearse($conn, ['operational_store_id' => 27]);
    deletedFatAssert($rehearsal['ok'] && deletedFatScalar($conn, 'SELECT COUNT(*) FROM inventory_movements') === $before, 'rehearsal must roll back');
    try {
        $service->apply($conn, ['operational_store_id' => 27], str_repeat('a', 64), $backup);
        deletedFatAssert(false, 'manifest mismatch must block');
    } catch (RuntimeException $exception) {
        deletedFatAssert($exception->getMessage() === 'INVENTORY_DELETED_FAT_REPAIR_MANIFEST_CHANGED', 'expected manifest mismatch');
    }
    $applied = $service->apply($conn, ['operational_store_id' => 27], (string) $plan['manifest_hash'], $backup);
    deletedFatAssert($applied['ok'] && (int) $applied['summary']['applied_movement_count'] === 1, 'reviewed repair should apply once');
    deletedFatAssert(deletedFatQty($conn, 27, 7101) === '0.000000', 'deleted sale residue must be removed from operational balance');
    deletedFatAssert(deletedFatScalar($conn, "SELECT COUNT(*) FROM inventory_movements WHERE fat_detail_id=1 AND idempotency_key='migration:fat_details:1:v1'") === 1, 'source movement must remain immutable');
    $replay = $service->apply($conn, ['operational_store_id' => 27], (string) $plan['manifest_hash'], $backup);
    deletedFatAssert(!empty($replay['replayed']) && deletedFatScalar($conn, 'SELECT COUNT(*) FROM inventory_movements') === $before + 1, 'repair replay must be idempotent');
    deletedFatAssert((int) $service->plan($conn, ['operational_store_id' => 27])['summary']['entry_count'] === 0, 'applied residue must no longer be planned');

    echo "inventory-deleted-fat-movement-neutralization-service-ok\n";
} finally {
    if (is_file($backup)) {
        unlink($backup);
    }
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function deletedFatMovement(mysqli $conn, InventoryLedgerService $ledger, int $itemId, int $fatId, int $storeId, string $key): void
{
    $ledger->recordMovement($conn, [
        'scope' => ['pos_tenant' => 0, 'pos_branch' => 0, 'store_id' => $storeId],
        'item_id' => $itemId,
        'movement_type' => 'sale_direct',
        'source_type' => 'fat_details',
        'source_id' => $fatId,
        'fat_detail_id' => $fatId,
        'qty_out' => '2.000000',
        'unit_cost' => '5.000000',
        'total_cost' => '10.000000',
        'idempotency_key' => $key,
    ], ['id' => $itemId, 'item_type' => 'ingredient', 'track_stock' => 1], ['enforce_negative_policy' => false]);
}

function deletedFatQty(mysqli $conn, int $storeId, int $itemId): string
{
    $row = $conn->query("SELECT qty_on_hand FROM inventory_item_balances WHERE pos_tenant=0 AND pos_branch=0 AND store_id={$storeId} AND item_id={$itemId}")->fetch_assoc();

    return InventoryDecimal::normalize($row['qty_on_hand'] ?? '0');
}

function deletedFatScalar(mysqli $conn, string $sql): int
{
    return (int) ($conn->query($sql)->fetch_row()[0] ?? 0);
}

function deletedFatAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
