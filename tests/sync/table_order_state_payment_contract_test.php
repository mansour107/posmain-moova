<?php

require_once __DIR__ . '/../../classes/TableOrderService.php';

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_table_state_contract_' . getmypid();
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "table-order-state-payment-contract-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    tableStateContractCreateSchema($conn);

    $service = new TableOrderService();
    $conn->begin_transaction();
    try {
        $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'T1', 0, 0)");
        $conn->query("
            INSERT INTO ot_head (
                id, table_id, pro_tybe, isdeleted, order_status, payment_status,
                fat_total, fat_disc, fat_net, paid_amount, remaining_amount
            ) VALUES (
                10, 1, 9, 0, 'active', 'unpaid',
                100, 0, 100, 0, 100
            )
        ");
        $conn->query("INSERT INTO fat_details (id, fatid, isdeleted, det_value, profit) VALUES (100, 10, 0, 100, 0)");

        assertContract($service->findActiveOrderByTableId($conn, 1, true)['id'] === 10, 'active unpaid order should be table truth');

        $partial = $service->payTableOrder($conn, 1, 10, 40, 'cash', 'partial smoke', 7);
        assertContract($partial['payment_status'] === 'partial', 'partial payment status expected');
        assertContract(abs($partial['remaining_amount'] - 60) < 0.0001, 'partial remaining amount expected');
        assertContract((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 1, 'partial payment keeps table occupied');
        assertContract($service->findActiveOrderByTableId($conn, 1, true) !== null, 'partial order remains active table truth');

        $full = $service->payTableOrder($conn, 1, 10, 60, 'cash', 'full smoke', 7);
        assertContract($full['payment_status'] === 'paid', 'full payment status expected');
        assertContract($full['fully_paid'] === true, 'full payment flag expected');
        assertContract((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 0, 'full payment frees table');
        assertContract($service->findActiveOrderByTableId($conn, 1, true) === null, 'paid order is not active table truth');

        $conn->query("
            INSERT INTO ot_head (
                id, table_id, pro_tybe, isdeleted, order_status, payment_status,
                fat_total, fat_disc, fat_net, paid_amount, remaining_amount
            ) VALUES
                (11, 1, 9, 0, 'cancelled', 'voided', 50, 0, 50, 0, 0),
                (12, 1, 9, 0, 'completed', 'paid', 50, 0, 50, 50, 0)
        ");
        $service->setTableFreeIfNoActiveOrder($conn, 1);
        assertContract((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 0, 'paid/cancelled orders do not occupy table');

        $conn->query("
            INSERT INTO ot_head (
                id, table_id, pro_tybe, isdeleted, order_status, payment_status,
                fat_total, fat_disc, fat_net, paid_amount, remaining_amount
            ) VALUES (
                13, 1, 9, 0, 'active', 'partial',
                80, 0, 80, 20, 60
            )
        ");
        $service->markTableOccupied($conn, 1);
        assertContract($service->findActiveOrderByTableId($conn, 1, true)['id'] === 13, 'active partial order should be table truth');
        $service->cancelTableOrder($conn, 1, 13, 'contract cancel', 7);
        assertContract($service->findActiveOrderByTableId($conn, 1, true) === null, 'cancelled order is removed from active table truth');
        assertContract((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 0, 'cancelled last active order frees table');
    } finally {
        $conn->rollback();
    }

    echo "table-order-state-payment-contract-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function tableStateContractCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(255) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            table_id INT NULL,
            pro_tybe INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            order_status VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            invoice_status VARCHAR(40) NULL,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NULL,
            payment_notes TEXT NULL,
            payment_date DATETIME NULL,
            completed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancellation_reason VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL PRIMARY KEY,
            fatid INT NOT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            det_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0
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
}

function assertContract(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
