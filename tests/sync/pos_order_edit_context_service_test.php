<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderEditContextService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$database = 'posmain_order_edit_context_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "pos-order-edit-context-service-skipped mysql-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function editContextAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function editContextExpect(string $expected, callable $callback): void
{
    try {
        $callback();
    } catch (RuntimeException $actual) {
        editContextAssert(
            $actual->getMessage() === $expected,
            'unexpected error: ' . $actual->getMessage()
        );
        return;
    }
    throw new RuntimeException('expected error: ' . $expected);
}

try {
    $conn->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($database);
    $conn->query(
        "CREATE TABLE ot_head (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            pro_tybe INT NOT NULL,
            order_type VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            mutation_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            tenant INT NOT NULL,
            branch INT NOT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB"
    );
    $conn->query(
        "CREATE TABLE order_fulfillment (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL UNIQUE,
            fulfillment_type VARCHAR(40) NOT NULL,
            customer_name VARCHAR(160) NULL,
            customer_phone VARCHAR(60) NULL,
            customer_address VARCHAR(500) NULL,
            pos_customer_id INT NULL,
            delivery_zone VARCHAR(120) NULL,
            delivery_zone_id INT NULL,
            delivery_worker_id BIGINT UNSIGNED NULL,
            courier_source VARCHAR(20) NOT NULL DEFAULT 'in_house',
            collection_mode VARCHAR(20) NOT NULL DEFAULT 'prepaid',
            delivery_fee DECIMAL(12,3) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB"
    );

    $conn->query(
        "INSERT INTO ot_head
            (id, pro_tybe, order_type, order_status, payment_status, paid_amount, mutation_version, tenant, branch, isdeleted)
         VALUES
            (11, 9, 'delivery', 'active', 'unpaid', 0, 4, 1, 1, 0),
            (12, 9, 'takeaway', 'active', 'unpaid', 0, 2, 2, 2, 0),
            (13, 9, 'takeaway', 'completed', 'paid', 10, 2, 1, 1, 0),
            (14, 9, 'delivery', 'active', 'unpaid', 0, 1, 1, 1, 0)"
    );
    $conn->query(
        "INSERT INTO order_fulfillment
            (order_id, fulfillment_type, customer_name, customer_phone, customer_address,
             pos_customer_id, delivery_zone, delivery_zone_id, delivery_worker_id,
             courier_source, collection_mode, delivery_fee)
         VALUES
            (11, 'delivery', 'QA Customer', '01090000001', 'QA Address',
             31, 'QA Zone', 7, 9, 'in_house', 'cod', 10.000)"
    );

    $service = new PosOrderEditContextService();
    $context = $service->load($conn, 11, 1, 1);
    editContextAssert((int) $context['order']['mutation_version'] === 4, 'mutation version must be preserved');
    editContextAssert((string) $context['delivery']['fee'] === '10.000', 'saved fee must be preserved exactly');
    editContextAssert((int) $context['delivery']['zone_id'] === 7, 'saved zone must be preserved');
    editContextAssert((string) $context['delivery']['collection_mode'] === 'cod', 'collection mode must be preserved');

    editContextExpect(
        'POS_ORDER_NOT_EDITABLE',
        static fn () => $service->load($conn, 12, 1, 1)
    );
    editContextExpect(
        'POS_ORDER_NOT_EDITABLE',
        static fn () => $service->load($conn, 13, 1, 1)
    );
    editContextExpect(
        'POS_DELIVERY_FULFILLMENT_REQUIRED',
        static fn () => $service->load($conn, 14, 1, 1)
    );

    echo "pos-order-edit-context-service-ok\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$database}`");
    $conn->close();
}
