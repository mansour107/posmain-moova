<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_cash_flow_legacy_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query("
        CREATE TABLE ot_head (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pro_tybe INT NOT NULL,
            pro_date DATE NOT NULL,
            user VARCHAR(50) NOT NULL,
            pro_value DECIMAL(12,3) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
    ");
    $conn->query("
        CREATE TABLE order_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(12,3) NOT NULL,
            payment_method VARCHAR(40) NOT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
    ");
    $conn->query("INSERT INTO ot_head (pro_tybe, pro_date, user, pro_value) VALUES (1, CURDATE(), '1', 80)");
    $conn->query("INSERT INTO ot_head (pro_tybe, pro_date, user, pro_value) VALUES (2, CURDATE(), '1', 15)");
    $conn->query("INSERT INTO order_payments (order_id, amount, payment_method, created_by) VALUES (10, 80, 'cash', 1)");

    $period = new CashFlowPeriodService();
    $today = date('Y-m-d');
    $summary = $period->summary($conn, ['date_from' => $today, 'date_to' => $today]);
    cashFlowLegacyAssert(($summary['source'] ?? '') === 'legacy', 'legacy shop should use legacy summary');
    cashFlowLegacyAssert(abs((float) ($summary['movement_totals']['sale_cash'] ?? 0) - 80.0) < 0.01, 'legacy receipts should count as sale_cash proxy');

    $payments = $period->paymentBreakdown($conn, ['date_from' => $today, 'date_to' => $today]);
    cashFlowLegacyAssert(abs((float) ($payments['cash_net'] ?? 0) - 80.0) < 0.01, 'order payments should be included');

    echo "cash-flow-legacy-shop-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function cashFlowLegacyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
