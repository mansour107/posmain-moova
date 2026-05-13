<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_uuid_population_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posUuidCreateSchema($conn);

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
        'items' => [['id' => 10, 'qty' => 2, 'price' => 15]],
        'total' => 30,
        'discount' => 0,
        'net' => 30,
    ], ['user_id' => 7]);
    $savedOrderId = (int) $save['data']['order_id'];
    posUuidAssertTableRow($conn, 'ot_head', $savedOrderId, 'table save order uuid expected');
    posUuidAssert((int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$savedOrderId} AND uuid REGEXP '" . posUuidRegexSql() . "'")->fetch_assoc()['c'] === 1, 'table save detail uuid expected');

    $service->payTableOrder($conn, [
        'table_id' => 1,
        'order_id' => $savedOrderId,
        'paid' => 10,
        'payment_method' => 'cash',
    ], ['user_id' => 8]);
    posUuidAssert((int) $conn->query("SELECT COUNT(*) AS c FROM order_payments WHERE order_id = {$savedOrderId} AND uuid REGEXP '" . posUuidRegexSql() . "'")->fetch_assoc()['c'] === 1, 'table payment uuid expected');

    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total,
            fat_disc, fat_net, paid_amount, remaining_amount, payment_status,
            invoice_status, order_status, isdeleted
        ) VALUES (
            200, 20, 2, 'table', 9, '2026-05-12', '2026-05-12',
            3, 4, 4, 51, 501, 30, 30,
            0, 30, 0, 30, 'unpaid',
            'draft', 'active', 0
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id, pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, discount, det_value, profit, fatid, fat_tybe, isdeleted
        ) VALUES (
            2001, 9, 3, 200, 11, 1, 0, 2,
            15, 5, 0, 30, 20, 200, 9, 0
        )
    ");
    $split = $service->splitTablePayment($conn, [
        'table_id' => 2,
        'order_id' => 200,
        'paid_amount' => 15,
        'payment_method' => 'cash',
        'items' => [
            ['detail_id' => 2001, 'qty' => 1],
        ],
    ], ['user_id' => 9]);
    $childOrderId = (int) $split['data']['order_id'];
    posUuidAssertTableRow($conn, 'ot_head', $childOrderId, 'split child order uuid expected');
    posUuidAssert((int) $conn->query("SELECT COUNT(*) AS c FROM fat_details WHERE fatid = {$childOrderId} AND uuid REGEXP '" . posUuidRegexSql() . "'")->fetch_assoc()['c'] === 1, 'split copied detail uuid expected');
    posUuidAssert((int) $conn->query("SELECT COUNT(*) AS c FROM order_payments WHERE order_id = {$childOrderId} AND uuid REGEXP '" . posUuidRegexSql() . "'")->fetch_assoc()['c'] === 1, 'split payment uuid expected');

    echo "pos-uuid-population-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posUuidCreateSchema(mysqli $conn): void
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
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL
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
            parent_order_id INT NULL,
            split_group_id VARCHAR(64) NULL,
            info TEXT NULL,
            user INT NULL,
            crtime DATETIME NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL,
            UNIQUE KEY uq_ot_head_uuid (uuid)
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
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL,
            UNIQUE KEY uq_fat_details_uuid (uuid)
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
            created_at DATETIME NULL,
            uuid CHAR(36) NULL,
            UNIQUE KEY uq_order_payments_uuid (uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function posUuidAssertTableRow(mysqli $conn, string $table, int $id, string $message): void
{
    $table = str_replace('`', '``', $table);
    $row = $conn->query("SELECT uuid FROM `{$table}` WHERE id = {$id} LIMIT 1")->fetch_assoc();
    posUuidAssert(is_array($row) && preg_match('/' . posUuidRegexSql() . '/', (string) $row['uuid']) === 1, $message);
}

function posUuidRegexSql(): string
{
    return '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$';
}

function posUuidAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
