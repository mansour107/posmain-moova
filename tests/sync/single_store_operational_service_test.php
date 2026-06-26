<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Sync/SchemaManager.php';
require_once $root . '/includes/pos_default_accounts.php';
require_once $root . '/includes/pos_operational_store.php';
require_once $root . '/classes/Recipe/RecipeScopeResolver.php';
require_once $root . '/tools/inventory_merge_stores_to_operational.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "single-store-operational-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_single_store_ops_' . getmypid();
$conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
$conn->query('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    (new SyncSchemaManager())->apply($conn);
    singleStoreOpsSeed($conn);

    $operational = posmain_operational_store_id($conn);
    singleStoreOpsAssert($operational === 27, 'operational store should follow settings.def_pos_store, not stale flag');

    try {
        posmain_assert_operational_store_id($conn, 275);
        singleStoreOpsAssert(false, 'non-operational store should be rejected');
    } catch (InvalidArgumentException $exception) {
        singleStoreOpsAssert($exception->getMessage() === 'NON_OPERATIONAL_STORE', 'expected NON_OPERATIONAL_STORE');
    }

    $summary = inventoryMergeStoresMergeBalances(
        $conn,
        ['pos_tenant' => 0, 'pos_branch' => 0],
        27,
        true,
        'default',
        sys_get_temp_dir() . '/single_store_merge_audit.jsonl'
    );
    singleStoreOpsAssert(count($summary) === 1, 'scoped merge should find one item to merge');
    singleStoreOpsAssert((float) ($summary[0]['after']['qty_on_hand'] ?? 0) === 15.0, 'merge should sum branch-scoped balances only');

    $otherBranch = inventoryMergeStoresMergeBalances(
        $conn,
        ['pos_tenant' => 0, 'pos_branch' => 2],
        27,
        true,
        'default',
        sys_get_temp_dir() . '/single_store_merge_audit.jsonl'
    );
    singleStoreOpsAssert(count($otherBranch) === 1, 'other branch scope should merge independently');
    singleStoreOpsAssert((float) ($otherBranch[0]['after']['qty_on_hand'] ?? 0) === 4.0, 'branch 2 balance should remain isolated');

    $recipeScope = (new RecipeScopeResolver())->resolveForConn($conn, ['store_id' => 0], 'write');
    singleStoreOpsAssert($recipeScope->storeId === 27, 'recipe scope should coerce store_id=0 to operational store');
    singleStoreOpsAssert(posmain_apply_read_store_filter($conn, 0) === 27, 'read filter should coerce store_id=0 to operational store');
    singleStoreOpsAssert(posmain_apply_read_store_filter($conn, 27) === 27, 'read filter should keep operational store');
    singleStoreOpsAssert(posmain_apply_read_store_filter($conn, 275) === 27, 'read filter should coerce non-operational store to operational store');
    try {
        (new RecipeScopeResolver())->resolveForConn($conn, ['store_id' => 275], 'write');
        singleStoreOpsAssert(false, 'recipe scope should reject non-operational store');
    } catch (InvalidArgumentException $exception) {
        singleStoreOpsAssert($exception->getMessage() === 'NON_OPERATIONAL_STORE', 'recipe scope expected NON_OPERATIONAL_STORE');
    }

    posmain_sync_operational_store_flags($conn);
    $operationalFlag = $conn->query('SELECT is_operational_store FROM acc_head WHERE id = 27')->fetch_assoc();
    $staleFlag = $conn->query('SELECT is_operational_store FROM acc_head WHERE id = 275')->fetch_assoc();
    singleStoreOpsAssert((int) ($operationalFlag['is_operational_store'] ?? 0) === 1, 'operational store flag should follow def_pos_store');
    singleStoreOpsAssert((int) ($staleFlag['is_operational_store'] ?? 0) === 0, 'stale operational flag should be cleared');

    inventoryMergeStoresMergeBalances(
        $conn,
        ['pos_tenant' => 0, 'pos_branch' => 0],
        27,
        false,
        'default',
        sys_get_temp_dir() . '/single_store_merge_apply_audit.jsonl'
    );
    $appliedQty = $conn->query('SELECT qty_on_hand FROM inventory_item_balances WHERE pos_tenant = 0 AND pos_branch = 0 AND store_id = 27 AND item_id = 9001')->fetch_assoc();
    singleStoreOpsAssert((float) ($appliedQty['qty_on_hand'] ?? 0) === 15.0, 'merge apply should persist operational balance');
    $zeroedQty = $conn->query('SELECT qty_on_hand FROM inventory_item_balances WHERE pos_tenant = 0 AND pos_branch = 0 AND store_id = 275 AND item_id = 9001')->fetch_assoc();
    singleStoreOpsAssert((float) ($zeroedQty['qty_on_hand'] ?? 0) === 0.0, 'merge apply should zero non-operational source balance');

    echo "single-store-operational-service-ok\n";
} finally {
    $conn->query('DROP DATABASE IF EXISTS `' . $dbName . '`');
    $conn->close();
}

function singleStoreOpsSeed(mysqli $conn): void
{
    $conn->query('
        CREATE TABLE IF NOT EXISTS acc_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            aname VARCHAR(120) NOT NULL,
            is_stock TINYINT NOT NULL DEFAULT 0,
            is_operational_store TINYINT NOT NULL DEFAULT 0,
            isdeleted TINYINT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $conn->query('
        CREATE TABLE IF NOT EXISTS settings (
            id INT PRIMARY KEY,
            def_pos_store INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $conn->query('
        CREATE TABLE IF NOT EXISTS myitems (
            id INT PRIMARY KEY,
            iname VARCHAR(120) NOT NULL,
            isdeleted TINYINT NOT NULL DEFAULT 0,
            track_stock TINYINT NOT NULL DEFAULT 1,
            item_type VARCHAR(32) NOT NULL DEFAULT \'ingredient\'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $conn->query("
        INSERT INTO acc_head (id, aname, is_stock, is_operational_store, isdeleted)
        VALUES
            (27, 'Main Store', 1, 0, 0),
            (275, 'Stale Flag Store', 1, 1, 0)
    ");
    $conn->query("INSERT INTO settings (id, def_pos_store) VALUES (1, 27)");
    $conn->query("
        INSERT INTO myitems (id, iname, isdeleted, track_stock, item_type)
        VALUES (9001, 'Merge Test Item', 0, 1, 'ingredient')
    ");
    $conn->query("
        INSERT INTO inventory_item_balances (pos_tenant, pos_branch, store_id, item_id, qty_on_hand, qty_reserved, qty_available, moving_average_cost)
        VALUES
            (0, 0, 27, 9001, 10.000000, 0.000000, 10.000000, 2.000000),
            (0, 0, 275, 9001, 5.000000, 0.000000, 5.000000, 3.000000),
            (0, 2, 275, 9001, 4.000000, 0.000000, 4.000000, 1.000000)
    ");
}

function singleStoreOpsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
