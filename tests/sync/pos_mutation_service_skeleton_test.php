<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_pos_mutation_skeleton_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-mutation-service-skeleton-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posMutationSkeletonCreateSchema($conn);

    $service = new PosOrderMutationService();
    $conn->begin_transaction();
    try {
        $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'T1', 1, 0)");
        $conn->query("
            INSERT INTO ot_head (
                id, table_id, pro_tybe, isdeleted, order_status, payment_status,
                fat_total, fat_disc, fat_net, paid_amount, remaining_amount
            ) VALUES
                (10, 1, 9, 0, 'active', 'unpaid', 100, 0, 100, 0, 100),
                (11, 1, 9, 0, 'completed', 'paid', 50, 0, 50, 50, 0)
        ");
        $conn->query("INSERT INTO fat_details (id, fatid, isdeleted, det_value, profit) VALUES (100, 10, 0, 100, 0)");

        $partial = $service->payTableOrder($conn, [
            'table_id' => 1,
            'order_id' => 10,
            'paid' => 40,
            'payment_method' => 'cash',
            'notes' => 'skeleton partial',
        ], ['user_id' => 7]);

        posMutationSkeletonAssert($partial['success'] === true, 'payment wrapper should return success envelope');
        posMutationSkeletonAssert($partial['code'] === 'OK', 'payment wrapper should return OK code');
        posMutationSkeletonAssert($partial['data']['payment_status'] === 'partial', 'partial payment status expected');
        posMutationSkeletonAssert(abs($partial['data']['remaining_amount'] - 60) < 0.0001, 'partial remaining amount expected');
        posMutationSkeletonAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 1, 'partial payment keeps table occupied');

        $full = $service->payTableOrder($conn, [
            'table_id' => 1,
            'order_id' => 10,
            'amount_paid' => 60,
            'payment_method' => 'cash',
            'notes' => 'skeleton full',
        ], ['user_id' => 7]);

        posMutationSkeletonAssert($full['data']['payment_status'] === 'paid', 'full payment status expected');
        posMutationSkeletonAssert($full['data']['fully_paid'] === true, 'full payment flag expected');
        posMutationSkeletonAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 0, 'full payment frees table');
    } finally {
        $conn->rollback();
    }

    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM order_payments");
        $conn->query("DELETE FROM fat_details");
        $conn->query("DELETE FROM ot_head");
        $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'T1', 1, 0)");
        $conn->query("
            INSERT INTO ot_head (
                id, table_id, pro_tybe, isdeleted, order_status, payment_status,
                fat_total, fat_disc, fat_net, paid_amount, remaining_amount
            ) VALUES (
                20, 1, 9, 0, 'active', 'partial',
                80, 0, 80, 20, 60
            )
        ");
        $conn->query("INSERT INTO fat_details (id, fatid, isdeleted, det_value, profit) VALUES (200, 20, 0, 80, 0)");

        $cancel = $service->cancelTableOrder($conn, [
            'table_id' => 1,
            'order_id' => 20,
            'reason' => 'skeleton cancel',
        ], ['user_id' => 7]);

        posMutationSkeletonAssert($cancel['success'] === true, 'cancel wrapper should return success envelope');
        posMutationSkeletonAssert($cancel['data']['order_id'] === 20, 'cancel wrapper should return order id');
        posMutationSkeletonAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 0, 'cancel frees table when no active order remains');
        posMutationSkeletonAssert((int) $conn->query("SELECT isdeleted FROM ot_head WHERE id = 20")->fetch_assoc()['isdeleted'] === 1, 'cancel marks order deleted using existing service behavior');
    } finally {
        $conn->rollback();
    }

    posMutationSkeletonAssert(method_exists($service, 'createTakeawayOrder'), 'takeaway create route should be exposed on mutation service');

    echo "pos-mutation-service-skeleton-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posMutationSkeletonCreateSchema(mysqli $conn): void
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

function posMutationSkeletonAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
