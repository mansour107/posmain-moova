<?php

require_once __DIR__ . '/../../classes/Financial/LegacySalesReportService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_legacy_refund_reports_' . getmypid();
$admin = @new mysqli($host, $user, $pass, '', $port);
if ($admin->connect_error) {
    echo "legacy-sales-report-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$admin->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn = new mysqli($host, $user, $pass, $db, $port);
$conn->set_charset('utf8mb4');

try {
    legacyRefundReportSchema($conn);
    $conn->query("INSERT INTO item_group VALUES (5, 'Drinks')");
    $conn->query("INSERT INTO myitems VALUES (21, 'Tea', 'T21', 50.00, 20.00, 5, 0)");
    $conn->query("INSERT INTO ot_head VALUES
        (101, 9, '2026-07-20', '2026-07-20 10:00:00', 100.00, 90.00, 'voided', 1, 4, 6)");
    $conn->query("INSERT INTO fat_details VALUES
        (401, 101, 21, 2.000000, 0.000000, 90.00, 50.00, 0)");
    $conn->query("INSERT INTO credit_notes VALUES
        (701, 4, 6, '2026-07-21', 101, 30.00, 'posted', 9, '2026-07-21 11:00:00')");
    $conn->query("INSERT INTO credit_note_lines VALUES
        (801, 701, 401, 0.500000, 20.00)");

    $service = new LegacySalesReportService();
    $days = $service->timeBuckets($conn, '2026-07-20', '2026-07-21', 'day', ['tenant' => 4, 'branch' => 6]);
    legacyRefundReportAssert(count($days) === 2, 'sale day and refund-only day should both be visible');
    legacyRefundReportAssert((string) $days[0]['pro_date'] === '2026-07-20', 'first bucket should be sale day');
    legacyRefundReportAssert(abs((float) $days[0]['total_sales'] - 90.0) < 0.001, 'sale day should use posted after-discount amount');
    legacyRefundReportAssert((string) $days[1]['pro_date'] === '2026-07-21', 'second bucket should be refund day');
    legacyRefundReportAssert(abs((float) $days[1]['refunds'] - 30.0) < 0.001, 'refund should belong to its own business day');
    legacyRefundReportAssert(abs((float) $days[1]['total_sales'] + 30.0) < 0.001, 'refund-only day may have negative net sales');

    $refundDayItems = $service->itemTotals($conn, '2026-07-21', '2026-07-21', ['tenant' => 4, 'branch' => 6]);
    legacyRefundReportAssert(count($refundDayItems) === 1, 'refund-only item should remain visible');
    legacyRefundReportAssert(abs((float) $refundDayItems[0]['total_qty'] + 0.5) < 0.001, 'item quantity should reverse on refund day');
    legacyRefundReportAssert(abs((float) $refundDayItems[0]['total_value'] + 20.0) < 0.001, 'item value should reverse on refund day');

    $periodItems = $service->itemTotals($conn, '2026-07-20', '2026-07-21', ['tenant' => 4, 'branch' => 6]);
    legacyRefundReportAssert(abs((float) $periodItems[0]['total_qty'] - 1.5) < 0.001, 'period item quantity should be sale minus returned quantity');
    legacyRefundReportAssert(abs((float) $periodItems[0]['total_value'] - 70.0) < 0.001, 'period item value should be sale minus credited line amount');
    legacyRefundReportAssert(abs((float) $periodItems[0]['total_profit'] - 38.8888889) < 0.001, 'profit should reverse in proportion to the credited original line');

    $categories = $service->categoryTotals($conn, '2026-07-20', '2026-07-21', ['tenant' => 4, 'branch' => 6]);
    legacyRefundReportAssert(abs((float) $categories[0]['total_sales'] - 70.0) < 0.001, 'category report should use net item value');

    echo "legacy-sales-report-service-ok db={$db}\n";
} finally {
    $conn->close();
    $admin->query("DROP DATABASE IF EXISTS `{$db}`");
    $admin->close();
}

function legacyRefundReportSchema(mysqli $conn): void
{
    foreach ([
        "CREATE TABLE item_group (id INT PRIMARY KEY, gname VARCHAR(100)) ENGINE=InnoDB",
        "CREATE TABLE myitems (id INT PRIMARY KEY, iname VARCHAR(100), barcode VARCHAR(100), price1 DECIMAL(19,2), cost_price DECIMAL(19,2), group1 INT, isdeleted TINYINT) ENGINE=InnoDB",
        "CREATE TABLE ot_head (id INT PRIMARY KEY, pro_tybe INT, pro_date DATE, crtime DATETIME, pro_value DECIMAL(19,2), fat_net DECIMAL(19,2), payment_status VARCHAR(30), isdeleted TINYINT, tenant INT, branch INT) ENGINE=InnoDB",
        "CREATE TABLE fat_details (id INT PRIMARY KEY, fatid INT, item_id INT, qty_out DECIMAL(19,6), qty_in DECIMAL(19,6), det_value DECIMAL(19,2), profit DECIMAL(19,2), isdeleted TINYINT) ENGINE=InnoDB",
        "CREATE TABLE credit_notes (id INT PRIMARY KEY, tenant INT, branch INT, business_day DATE, original_order_id INT, total_amount DECIMAL(19,2), status VARCHAR(20), created_by INT, created_at DATETIME) ENGINE=InnoDB",
        "CREATE TABLE credit_note_lines (id INT PRIMARY KEY, credit_note_id INT, original_detail_id INT, quantity DECIMAL(19,6), line_amount DECIMAL(19,2)) ENGINE=InnoDB",
    ] as $sql) {
        $conn->query($sql);
    }
}

function legacyRefundReportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
