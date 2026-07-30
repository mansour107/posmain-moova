<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/TableTransferService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_table_transfer_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    phase4TableTransferCreateLegacyTables($conn);
    (new SyncSchemaManager())->apply($conn);

    $service = new TableTransferService();
    phase4TableTransferSeed($conn);

    $moved = $service->moveOrder($conn, [
        'source_table_id' => 1,
        'destination_table_id' => 2,
        'order_id' => 100,
        'mutation_version' => 1,
    ], [
        'user_id' => 77,
        'tenant' => 5,
        'branch' => 6,
    ]);
    phase4TableTransferAssert($moved['success'] === true, 'move should succeed');
    phase4TableTransferAssert($moved['order_id'] === 100, 'moved order id expected');
    phase4TableTransferAssert($moved['source_freed'] === true, 'source table should be freed');
    phase4TableTransferAssert($moved['payment_status'] === 'partial', 'payment status should be preserved');
    phase4TableTransferAssert($moved['order_status'] === 'active', 'order status should be preserved');
    phase4TableTransferAssert($moved['event_id'] !== null, 'table_moved event should be recorded');

    $order = $conn->query("SELECT table_id, payment_status, order_status, fat_total, fat_net, paid_amount, remaining_amount FROM ot_head WHERE id = 100")->fetch_assoc();
    phase4TableTransferAssert((int) $order['table_id'] === 2, 'order should move to destination table');
    phase4TableTransferAssert((string) $order['payment_status'] === 'partial', 'stored payment status preserved');
    phase4TableTransferAssert((string) $order['order_status'] === 'active', 'stored order status preserved');
    phase4TableTransferAssert((float) $order['fat_total'] === 50.0 && (float) $order['fat_net'] === 45.0, 'totals should be preserved');
    phase4TableTransferAssert((float) $order['paid_amount'] === 20.0 && (float) $order['remaining_amount'] === 25.0, 'payment amounts should be preserved');
    phase4TableTransferAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 0, 'source table cache should be free');
    phase4TableTransferAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 2")->fetch_assoc()['table_case'] === 1, 'destination table cache should be occupied');
    phase4TableTransferAssert((int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = 100 AND isdeleted = 0")->fetch_assoc()['c'] === 2, 'order detail rows should remain');
    phase4TableTransferAssert((float) $conn->query("SELECT SUM(det_value) AS s FROM fat_details WHERE fatid = 100 AND isdeleted = 0")->fetch_assoc()['s'] === 50.0, 'detail totals should remain');

    $event = $conn->query("SELECT event_type, event_source, actor_user_id, tenant, branch, before_state_json, after_state_json, metadata_json FROM order_events WHERE order_id = 100")->fetch_assoc();
    phase4TableTransferAssert($event['event_type'] === 'table_moved', 'event type should be table_moved');
    phase4TableTransferAssert($event['event_source'] === 'pos_table_transfer', 'event source expected');
    phase4TableTransferAssert((int) $event['actor_user_id'] === 77, 'event actor expected');
    phase4TableTransferAssert((int) $event['tenant'] === 5 && (int) $event['branch'] === 6, 'event scope expected');
    $before = json_decode($event['before_state_json'], true);
    $after = json_decode($event['after_state_json'], true);
    $metadata = json_decode($event['metadata_json'], true);
    phase4TableTransferAssert($before['source_table_id'] === 1 && $before['destination_table_id'] === 2, 'event before state expected');
    phase4TableTransferAssert($after['source_table_case'] === 0 && $after['destination_table_case'] === 1, 'event after state expected');
    phase4TableTransferAssert($metadata['source_table_name'] === 'T1' && $metadata['destination_table_name'] === 'T2', 'event metadata table names expected');

    phase4TableTransferExpectException(function () use ($service, $conn) {
        $service->moveOrder($conn, [
            'source_table_id' => 2,
            'destination_table_id' => 3,
            'mutation_version' => max(1, (int) $conn->query('SELECT mutation_version FROM ot_head WHERE id = 100')->fetch_assoc()['mutation_version']),
        ]);
    }, 'DESTINATION_TABLE_OCCUPIED');
    phase4TableTransferAssert((int) $conn->query("SELECT table_id FROM ot_head WHERE id = 100")->fetch_assoc()['table_id'] === 2, 'failed occupied move should roll back order table');

    phase4TableTransferExpectException(function () use ($service, $conn) {
        $service->moveOrder($conn, ['source_table_id' => 2, 'destination_table_id' => 2]);
    }, 'TABLE_MOVE_SAME_TABLE');

    phase4TableTransferExpectException(function () use ($service, $conn) {
        $service->moveOrder($conn, ['source_table_id' => 4, 'destination_table_id' => 5, 'order_id' => 400]);
    }, 'ORDER_NOT_ACTIVE');

    phase4TableTransferExpectException(function () use ($service, $conn) {
        $service->moveOrder($conn, ['source_table_id' => 99, 'destination_table_id' => 5]);
    }, 'TABLE_NOT_FOUND');

    phase4TableTransferAssert((int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE event_type = 'table_moved'")->fetch_assoc()['c'] === 1, 'failed moves should not write events');

    echo "phase4-table-transfer-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4TableTransferCreateLegacyTables(mysqli $conn): void
{
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
            order_status VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL PRIMARY KEY,
            fatid INT NOT NULL,
            item_id INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            det_value DECIMAL(15,4) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function phase4TableTransferSeed(mysqli $conn): void
{
    $conn->query("
        INSERT INTO tables (id, tname, table_case, isdeleted) VALUES
        (1, 'T1', 1, 0),
        (2, 'T2', 0, 0),
        (3, 'T3', 1, 0),
        (4, 'T4', 1, 0),
        (5, 'T5', 0, 0)
    ");
    $conn->query("
        INSERT INTO ot_head (
            id, pro_tybe, table_id, isdeleted, order_status, payment_status,
            fat_total, fat_net, paid_amount, remaining_amount, tenant, branch
        ) VALUES
        (100, 9, 1, 0, 'active', 'partial', 50, 45, 20, 25, 5, 6),
        (300, 9, 3, 0, 'active', 'unpaid', 30, 30, 0, 30, 5, 6),
        (400, 9, 4, 0, 'completed', 'paid', 25, 25, 25, 0, 5, 6)
    ");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, isdeleted, det_value) VALUES
        (1001, 100, 10, 0, 20),
        (1002, 100, 11, 0, 30),
        (3001, 300, 12, 0, 30),
        (4001, 400, 13, 0, 25)
    ");
}

function phase4TableTransferExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4TableTransferAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4TableTransferAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
