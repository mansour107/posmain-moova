<?php

require_once __DIR__ . '/../../classes/Financial/RefundReversalReadService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_refund_read_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query("CREATE TABLE ot_head (
        id INT PRIMARY KEY, pro_id VARCHAR(40), receipt_number VARCHAR(40), fat_net DECIMAL(19,2),
        tenant INT, branch INT, user INT, pro_date DATE, payment_status VARCHAR(30)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE credit_notes (
        id INT PRIMARY KEY, original_order_id INT NOT NULL, total_amount DECIMAL(19,2) NOT NULL,
        status VARCHAR(20) NOT NULL, tenant INT NOT NULL, branch INT NOT NULL,
        business_day DATE NULL, drawer_session_id INT NULL, manager_approval_id INT NULL,
        reason VARCHAR(255) NULL, created_by INT NOT NULL, created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE fat_details (
        id INT PRIMARY KEY, det_value DECIMAL(19,2) NOT NULL, profit DECIMAL(19,6) NOT NULL
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE credit_note_lines (
        id INT PRIMARY KEY, credit_note_id INT NOT NULL, original_detail_id INT NULL,
        line_amount DECIMAL(19,2) NOT NULL
    ) ENGINE=InnoDB");
    $conn->query("INSERT INTO ot_head VALUES
        (1, 'SALE-1', 'R-1', 100.00, 1, 2, 7, '2026-07-10', 'paid'),
        (2, 'SALE-2', 'R-2', 40.00, 1, 2, 7, '2026-07-10', 'refunded')");
    $conn->query("INSERT INTO credit_notes VALUES
        (11, 1, 25.00, 'posted', 1, 2, '2026-07-11', 90, 501, 'partial', 8, '2026-07-11 09:00:00'),
        (12, 2, 40.00, 'posted', 1, 2, '2026-07-12', 91, 502, 'full', 9, '2026-07-12 10:00:00'),
        (13, 1, 10.00, 'void',   1, 2, '2026-07-11', 90, 501, 'ignored', 8, '2026-07-11 11:00:00')");
    $conn->query("INSERT INTO fat_details VALUES
        (101, 100.00, 60.000000),
        (102, 40.00, 20.000000)");
    $conn->query("INSERT INTO credit_note_lines VALUES
        (201, 11, 101, 25.00),
        (202, 12, 102, 40.00),
        (203, 13, 101, 10.00)");

    $service = new RefundReversalReadService();
    $saleDay = $service->periodSummary($conn, ['date_from' => '2026-07-10', 'date_to' => '2026-07-10']);
    refundReadAssert($saleDay['total_amount'] === '0.00', 'refund must not rewrite original sale day');

    $refundDay = $service->periodSummary($conn, [
        'date_from' => '2026-07-11',
        'date_to' => '2026-07-11',
        'tenant' => 1,
        'branch' => 2,
        'cashier_id' => 8,
        'drawer_session_id' => 90,
    ], true);
    refundReadAssert($refundDay['total_amount'] === '25.00' && $refundDay['count'] === 1, 'refund-day attribution');
    refundReadAssert($refundDay['rows'][0]['manager_approval_id'] === 501, 'approval drilldown attribution');
    refundReadAssert($refundDay['rows'][0]['original_order_id'] === 1, 'original sale link');

    $wrongOperator = $service->periodSummary($conn, [
        'date_from' => '2026-07-11',
        'date_to' => '2026-07-11',
        'cashier_id' => 7,
    ]);
    refundReadAssert($wrongOperator['total_amount'] === '0.00', 'refund belongs to refunding operator');

    $partial = $service->stateForOrder($conn, 1);
    refundReadAssert($partial['reversal_status'] === 'partial', 'partial state derives from posted notes');
    refundReadAssert($partial['cumulative_refunded_amount'] === '25.00', 'partial cumulative amount');
    refundReadAssert($partial['remaining_refundable_amount'] === '75.00', 'partial remaining amount');
    $full = $service->stateForOrder($conn, 2);
    refundReadAssert($full['reversal_status'] === 'full' && $full['remaining_refundable_amount'] === '0.00', 'full state');
    refundReadAssert($service->refundedProfitForOrder($conn, 1) === '15.000000', 'partial posted refund reverses proportional profit');
    refundReadAssert($service->refundedProfitForOrder($conn, 2) === '20.000000', 'full posted refund reverses all profit');

    echo "refund-reversal-read-service-ok\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function refundReadAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
