<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_order_events_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-order-mutation-event-recording-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posOrderEventCreateSchema($conn);

    $service = new PosOrderMutationService();
    $conn->query("INSERT INTO settings (id, def_pos_client, isdeleted) VALUES (1, 501, 0)");
    $conn->query("INSERT INTO acc_head (id, code, isdeleted) VALUES (501, '122001', 0)");
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'T1', 0, 0), (2, 'T2', 1, 0)");

    $save = $service->saveTableOrder($conn, [
        'table_id' => 1,
        'order_date' => '2026-05-12',
        'store_id' => 3,
        'emp_id' => 4,
        'fund_id' => 51,
        'items' => [
            ['id' => 10, 'qty' => 2, 'price' => 15],
        ],
        'total' => 30,
        'discount' => 0,
        'net' => 30,
    ], ['user_id' => 7]);
    $savedOrderId = (int) $save['data']['order_id'];
    posOrderEventAssertEvent($conn, $savedOrderId, 'order.saved', 'pos_table_save', 7, 'table_id', 1);

    $payment = $service->payTableOrder($conn, [
        'table_id' => 1,
        'order_id' => $savedOrderId,
        'paid' => 10,
        'payment_method' => 'cash',
    ], ['user_id' => 8]);
    posOrderEventAssert($payment['data']['payment_status'] === 'partial', 'payment smoke should remain partial');
    posOrderEventAssertEvent($conn, $savedOrderId, 'order.payment_recorded', 'pos_table_payment', 8, 'applied_amount', 10.0);

    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, table_id, pro_tybe, isdeleted, order_status, payment_status,
            invoice_status, fat_total, fat_disc, fat_net, paid_amount, remaining_amount
        ) VALUES (
            200, 20, 2, 9, 0, 'active', 'partial',
            'draft', 20, 0, 20, 5, 15
        )
    ");
    $conn->query("INSERT INTO fat_details (id, fatid, isdeleted, det_value, profit) VALUES (2000, 200, 0, 20, 0)");
    $cancel = $service->cancelTableOrder($conn, [
        'table_id' => 2,
        'order_id' => 200,
        'reason' => 'event fixture',
    ], ['user_id' => 9]);
    posOrderEventAssert($cancel['success'] === true, 'cancel smoke should still succeed');
    posOrderEventAssertEvent($conn, 200, 'order.cancelled', 'pos_order_cancel', 9, 'reason', 'event fixture');

    $conn->query("DROP TABLE order_events");
    $service->payTableOrder($conn, [
        'table_id' => 1,
        'order_id' => $savedOrderId,
        'paid' => 20,
        'payment_method' => 'cash',
    ], ['user_id' => 10]);

    echo "pos-order-mutation-event-recording-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posOrderEventCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(255) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE document_counters (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            counter_type VARCHAR(50) NOT NULL,
            counter_key VARCHAR(100) NOT NULL,
            current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_document_counter_scope (pos_tenant, pos_branch, counter_type, counter_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_id INT NULL,
            branch_id INT NULL,
            table_id INT NULL,
            order_type VARCHAR(40) NULL,
            pro_tybe INT NULL,
            pro_date DATE NULL,
            accural_date DATE NULL,
            store_id INT NULL,
            emp_id INT NULL,
            emp2_id INT NULL,
            acc1 INT NULL,
            acc2 INT NULL,
            pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_status VARCHAR(40) NULL,
            invoice_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            waiter_id INT NULL,
            payment_method VARCHAR(50) NULL,
            payment_notes TEXT NULL,
            payment_date DATETIME NULL,
            completed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancellation_reason VARCHAR(255) NULL,
            parent_order_id INT NULL,
            split_group_id VARCHAR(64) NULL,
            info TEXT NULL,
            user INT NULL,
            crtime DATETIME NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_tybe INT NULL,
            det_store INT NULL,
            pro_id INT NULL,
            item_id INT NULL,
            u_val DECIMAL(15,4) NOT NULL DEFAULT 1,
            qty_in DECIMAL(15,4) NOT NULL DEFAULT 0,
            qty_out DECIMAL(15,4) NOT NULL DEFAULT 0,
            price DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            stock_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            discount DECIMAL(15,4) NOT NULL DEFAULT 0,
            plus DECIMAL(15,4) NOT NULL DEFAULT 0,
            det_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            fatid INT NOT NULL,
            fat_tybe INT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NULL,
            reference_no VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            event_source VARCHAR(80) NOT NULL,
            actor_user_id BIGINT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            before_state_json JSON NULL,
            after_state_json JSON NULL,
            metadata_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_order_created (order_id, created_at),
            KEY idx_type_created (event_type, created_at),
            KEY idx_tenant_branch_created (tenant, branch, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function posOrderEventAssertEvent(mysqli $conn, int $orderId, string $type, string $source, int $actor, string $metadataKey, $metadataValue): void
{
    $escapedType = $conn->real_escape_string($type);
    $escapedSource = $conn->real_escape_string($source);
    $row = $conn->query("
        SELECT *
        FROM order_events
        WHERE order_id = {$orderId}
          AND event_type = '{$escapedType}'
          AND event_source = '{$escapedSource}'
        ORDER BY id DESC
        LIMIT 1
    ")->fetch_assoc();
    posOrderEventAssert(is_array($row), "event {$type} should exist");
    posOrderEventAssert((int) $row['actor_user_id'] === $actor, "event {$type} actor expected");
    $metadata = json_decode((string) $row['metadata_json'], true);
    posOrderEventAssert(is_array($metadata), "event {$type} metadata should decode");
    posOrderEventAssert((string) $metadata[$metadataKey] === (string) $metadataValue, "event {$type} metadata {$metadataKey} expected");
}

function posOrderEventAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
