<?php

require_once __DIR__ . '/../../classes/Financial/FinancialReconciliationService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_refund_reconciliation_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query("CREATE TABLE ot_head (
        id INT PRIMARY KEY, pro_tybe INT, isdeleted TINYINT, payment_status VARCHAR(30), fat_net DECIMAL(19,2)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE fat_details (
        id INT PRIMARY KEY, fatid INT, isdeleted TINYINT, posted_net DECIMAL(19,2)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE order_payments (
        id INT PRIMARY KEY, order_id INT, amount DECIMAL(19,2), is_voided TINYINT DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE credit_notes (
        id INT PRIMARY KEY, original_order_id INT, total_amount DECIMAL(19,2), status VARCHAR(20)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE payment_refunds (
        id INT PRIMARY KEY, original_order_id INT, amount DECIMAL(19,2), status VARCHAR(30)
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO ot_head VALUES (1, 9, 0, 'refunded', 100.00)");
    $conn->query("INSERT INTO fat_details VALUES (1, 1, 0, 100.00)");
    $conn->query("INSERT INTO order_payments VALUES (1, 1, 100.00, 0)");
    $conn->query("INSERT INTO credit_notes VALUES (1, 1, 100.00, 'posted')");
    $conn->query("INSERT INTO payment_refunds VALUES (1, 1, 100.00, 'pending_external')");

    $service = new FinancialReconciliationService();
    refundReconciliationAssert($service->invoiceVersusLines($conn) === 0, 'fully refunded invoice keeps original posted-line proof');
    refundReconciliationAssert($service->invoiceVersusPaymentsAndRefunds($conn) === 0, 'pending settlement is a liability, not a source mismatch');

    $conn->query("UPDATE payment_refunds SET amount = 99.00 WHERE id = 1");
    refundReconciliationAssert($service->invoiceVersusPaymentsAndRefunds($conn) === 1, 'credit note and refund allocation mismatch must be detected');

    echo "financial-refund-reconciliation-ok\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function refundReconciliationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
