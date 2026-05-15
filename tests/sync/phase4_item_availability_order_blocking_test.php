<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ItemAvailabilityService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$source = file_get_contents(__DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php');
if ($source === false) {
    throw new RuntimeException('Unable to read PosOrderMutationService.php');
}
phase4AvailabilityOrderAssert(strpos($source, "require_once __DIR__ . '/ItemAvailabilityService.php';") !== false, 'mutation service should require ItemAvailabilityService');
phase4AvailabilityOrderAssert(strpos($source, '$this->assertItemsAvailable($conn, $items, $request, $context);') !== false, 'mutation service should enforce availability after item normalization');
phase4AvailabilityOrderAssert(strpos($source, "throw new RuntimeException('ITEM_UNAVAILABLE')") === false, 'mutation service should delegate ITEM_UNAVAILABLE to ItemAvailabilityService');

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_availability_block_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    phase4AvailabilityOrderCreateSchema($conn);

    $availability = new ItemAvailabilityService();
    $availability->setAvailability($conn, 99, false, ['tenant' => 5, 'branch' => 6, 'channel' => 'pos'], 'sold out', 7);

    $service = new PosOrderMutationService();
    phase4AvailabilityOrderExpectException(function () use ($service, $conn) {
        $service->saveTableOrder($conn, [
            'table_id' => 1,
            'order_date' => '2026-05-13',
            'store_id' => 3,
            'emp_id' => 4,
            'fund_id' => 51,
            'tenant' => 5,
            'branch' => 6,
            'items' => [
                ['id' => 99, 'qty' => 1, 'price' => 12],
            ],
            'total' => 12,
            'discount' => 0,
            'net' => 12,
        ], ['user_id' => 7, 'tenant' => 5, 'branch' => 6]);
    }, 'ITEM_UNAVAILABLE');
    phase4AvailabilityOrderAssert((int) $conn->query("SELECT COUNT(*) AS c FROM ot_head")->fetch_assoc()['c'] === 0, 'blocked unavailable item should not insert order header');
    phase4AvailabilityOrderAssert((int) $conn->query("SELECT COUNT(*) AS c FROM fat_details")->fetch_assoc()['c'] === 0, 'blocked unavailable item should not insert detail rows');
    phase4AvailabilityOrderAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 0, 'blocked unavailable item should not occupy table');

    $available = $service->saveTableOrder($conn, [
        'table_id' => 1,
        'order_date' => '2026-05-13',
        'store_id' => 3,
        'emp_id' => 4,
        'fund_id' => 51,
        'tenant' => 5,
        'branch' => 6,
        'items' => [
            ['id' => 10, 'qty' => 2, 'price' => 15],
        ],
        'total' => 30,
        'discount' => 0,
        'net' => 30,
    ], ['user_id' => 7, 'tenant' => 5, 'branch' => 6]);
    phase4AvailabilityOrderAssert($available['success'] === true, 'available item should save');
    phase4AvailabilityOrderAssert((int) $conn->query("SELECT COUNT(*) AS c FROM ot_head")->fetch_assoc()['c'] === 1, 'available item should insert one order');
    phase4AvailabilityOrderAssert((int) $conn->query("SELECT COUNT(*) AS c FROM fat_details")->fetch_assoc()['c'] === 1, 'available item should insert one detail row');

    echo "phase4-item-availability-order-blocking-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4AvailabilityOrderCreateSchema(mysqli $conn): void
{
    $conn->query((new SyncSchemaManager())->plannedStatements()['item_availability']);
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO settings (id, def_pos_client, isdeleted) VALUES (1, 501, 0)");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO acc_head (id, code, isdeleted) VALUES (501, '122001', 0)");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(255) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'T1', 0, 0)");
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
            pro_tybe INT NULL,
            pro_date DATE NULL,
            accural_date DATE NULL,
            store_id INT NULL,
            emp_id INT NULL,
            emp2_id INT NULL,
            acc1 INT NULL,
            acc2 INT NULL,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            table_id INT NULL,
            order_type VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            invoice_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            waiter_id INT NULL,
            info TEXT NULL,
            user INT NULL,
            crtime DATETIME NULL,
            completed_at DATETIME NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_tybe INT NULL,
            pro_id INT NULL,
            item_id INT NULL,
            u_val DECIMAL(15,4) NOT NULL DEFAULT 1,
            qty_in DECIMAL(15,4) NOT NULL DEFAULT 0,
            qty_out DECIMAL(15,4) NOT NULL DEFAULT 0,
            price DECIMAL(15,4) NOT NULL DEFAULT 0,
            discount DECIMAL(15,4) NOT NULL DEFAULT 0,
            det_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            fatid INT NOT NULL,
            fat_tybe INT NULL,
            det_store INT NULL,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function phase4AvailabilityOrderExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4AvailabilityOrderAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4AvailabilityOrderAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
