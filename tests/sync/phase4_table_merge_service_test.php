<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/TableMergeService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_table_merge_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    phase4TableMergeCreateLegacyTables($conn);
    (new SyncSchemaManager())->apply($conn);
    phase4TableMergeSeed($conn);

    $service = new TableMergeService();
    $conn->query("UPDATE acc_head SET is_stock = 0 WHERE id = 3");
    phase4TableMergeExpectException(function () use ($service, $conn) {
        $service->mergeOrders($conn, [
            'source_table_id' => 1,
            'destination_table_id' => 2,
            'source_order_id' => 100,
            'destination_order_id' => 200,
        ], ['user_id' => 77, 'tenant' => 5, 'branch' => 6]);
    }, 'OPERATIONAL_STORE_NOT_CONFIGURED');
    phase4TableMergeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = 100 AND isdeleted = 0")->fetch_assoc()['c'] === 2,
        'missing-store failure must not move source lines'
    );
    phase4TableMergeAssert(
        (int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 1,
        'missing-store failure must not release source table'
    );
    $conn->query("UPDATE acc_head SET is_stock = 1 WHERE id = 3");

    $merged = $service->mergeOrders($conn, [
        'source_table_id' => 1,
        'destination_table_id' => 2,
        'source_order_id' => 100,
        'destination_order_id' => 200,
    ], [
        'user_id' => 77,
        'tenant' => 5,
        'branch' => 6,
    ]);

    phase4TableMergeAssert($merged['success'] === true, 'merge should succeed');
    phase4TableMergeAssert($merged['source_order_id'] === 100, 'source order id expected');
    phase4TableMergeAssert($merged['destination_order_id'] === 200, 'destination order id expected');
    phase4TableMergeAssert($merged['merged_detail_count'] === 2, 'source details should be merged');
    phase4TableMergeAssert($merged['source_freed'] === true, 'source table should be freed');
    phase4TableMergeAssert($merged['payment_status'] === 'partial', 'destination should remain partial');
    phase4TableMergeAssert(abs((float) $merged['paid_amount'] - 30.0) < 0.0001, 'combined paid amount expected');
    phase4TableMergeAssert(abs((float) $merged['remaining_amount'] - 45.0) < 0.0001, 'remaining amount expected');
    phase4TableMergeAssert(abs((float) $merged['net'] - 75.0) < 0.0001, 'merged net expected');

    $source = $conn->query("SELECT table_id, isdeleted, order_status, invoice_status, payment_status, remaining_amount FROM ot_head WHERE id = 100")->fetch_assoc();
    phase4TableMergeAssert((int) $source['isdeleted'] === 1, 'source order should be deleted from active truth');
    phase4TableMergeAssert($source['order_status'] === 'cancelled', 'source order status should use a real DB enum value');
    phase4TableMergeAssert($source['invoice_status'] === 'cancelled', 'source invoice status should use a real DB enum value');
    phase4TableMergeAssert($source['payment_status'] === 'voided', 'source payment status should be voided');
    phase4TableMergeAssert(abs((float) $source['remaining_amount']) < 0.0001, 'source remaining should be zeroed');

    $destination = $conn->query("SELECT fat_total, fat_net, paid_amount, remaining_amount, payment_status, order_status FROM ot_head WHERE id = 200")->fetch_assoc();
    phase4TableMergeAssert(abs((float) $destination['fat_total'] - 75.0) < 0.0001, 'destination total expected');
    phase4TableMergeAssert(abs((float) $destination['fat_net'] - 75.0) < 0.0001, 'destination net expected');
    phase4TableMergeAssert(abs((float) $destination['paid_amount'] - 30.0) < 0.0001, 'destination paid expected');
    phase4TableMergeAssert(abs((float) $destination['remaining_amount'] - 45.0) < 0.0001, 'destination remaining expected');
    phase4TableMergeAssert($destination['payment_status'] === 'partial', 'destination payment status expected');
    phase4TableMergeAssert($destination['order_status'] === 'active', 'destination stays active');

    phase4TableMergeAssert((int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = 200 AND isdeleted = 0")->fetch_assoc()['c'] === 3, 'destination should own all active details');
    phase4TableMergeAssert((float) $conn->query("SELECT SUM(det_value) AS s FROM fat_details WHERE fatid = 200 AND isdeleted = 0")->fetch_assoc()['s'] === 75.0, 'detail total should merge');
    phase4TableMergeAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 0, 'source table cache should be free');
    phase4TableMergeAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 2")->fetch_assoc()['table_case'] === 1, 'destination table cache should be occupied');

    $events = $conn->query("SELECT event_type, event_source, actor_user_id, tenant, branch FROM order_events ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    phase4TableMergeAssert(count($events) === 2, 'two merge events expected');
    phase4TableMergeAssert($events[0]['event_type'] === 'table_merged', 'destination event expected');
    phase4TableMergeAssert($events[1]['event_type'] === 'order_merged_into', 'source event expected');
    phase4TableMergeAssert((int) $events[0]['actor_user_id'] === 77, 'event actor expected');
    phase4TableMergeAssert((int) $events[0]['tenant'] === 5 && (int) $events[0]['branch'] === 6, 'event scope expected');

    phase4TableMergeExpectException(function () use ($service, $conn) {
        $service->mergeOrders($conn, ['source_table_id' => 2, 'destination_table_id' => 2]);
    }, 'TABLE_MERGE_SAME_TABLE');
    phase4TableMergeExpectException(function () use ($service, $conn) {
        $service->mergeOrders($conn, ['source_table_id' => 3, 'destination_table_id' => 2]);
    }, 'SOURCE_ORDER_NOT_ACTIVE');
    phase4TableMergeExpectException(function () use ($service, $conn) {
        $service->mergeOrders($conn, ['source_table_id' => 2, 'destination_table_id' => 4]);
    }, 'DESTINATION_ORDER_NOT_ACTIVE');

    echo "phase4-table-merge-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4TableMergeCreateLegacyTables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_store INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(120) NULL,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(120) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            pro_tybe INT NULL,
            table_id INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            order_status ENUM('draft','active','completed','cancelled') NULL,
            payment_status ENUM('unpaid','partial','paid','refunded','voided') NULL,
            invoice_status ENUM('draft','completed','cancelled') NULL,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL PRIMARY KEY,
            fatid INT NOT NULL,
            pro_id INT NULL,
            item_id INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            det_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function phase4TableMergeSeed(mysqli $conn): void
{
    $conn->query("INSERT INTO settings (id, def_pos_store, isdeleted) VALUES (1, 3, 0)");
    $conn->query("INSERT INTO acc_head (id, code, aname, is_stock, isdeleted) VALUES (3, '123001', 'Operational store', 1, 0)");
    $conn->query("
        INSERT INTO tables (id, tname, table_case, isdeleted) VALUES
        (1, 'T1', 1, 0),
        (2, 'T2', 1, 0),
        (3, 'T3', 0, 0),
        (4, 'T4', 0, 0)
    ");
    $conn->query("
        INSERT INTO ot_head (
            id, pro_tybe, table_id, isdeleted, order_status, payment_status,
            invoice_status, fat_total, fat_disc, fat_net, pro_value, profit,
            paid_amount, remaining_amount, tenant, branch
        ) VALUES
        (100, 9, 1, 0, 'active', 'partial', 'draft', 45, 0, 45, 45, 20, 10, 35, 5, 6),
        (200, 9, 2, 0, 'active', 'partial', 'draft', 30, 0, 30, 30, 10, 20, 10, 5, 6),
        (300, 9, 3, 0, 'completed', 'paid', 'completed', 25, 0, 25, 25, 5, 25, 0, 5, 6)
    ");
    $conn->query("
        INSERT INTO fat_details (id, fatid, pro_id, item_id, isdeleted, det_value, profit) VALUES
        (1001, 100, 100, 10, 0, 20, 8),
        (1002, 100, 100, 11, 0, 25, 12),
        (2001, 200, 200, 12, 0, 30, 10)
    ");
}

function phase4TableMergeExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4TableMergeAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4TableMergeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
