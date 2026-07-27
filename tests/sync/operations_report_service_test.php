<?php

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Pos/Service/OperationsReportService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: getenv('POSMAIN_DB_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: getenv('POSMAIN_DB_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: getenv('POSMAIN_DB_PASS') ?: '';
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_error) {
    echo "operations-report-service-skipped-db-unavailable\n";
    exit(0);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$dbName = 'posmain_operations_report_' . getmypid();
$conn->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn->select_db($dbName);
$conn->set_charset('utf8mb4');

try {
    $conn->query("CREATE TABLE users (
        id INT PRIMARY KEY,
        uname VARCHAR(80) NOT NULL,
        display_name VARCHAR(120) NULL
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE ot_head (
        id INT PRIMARY KEY,
        pro_id VARCHAR(40) NULL,
        receipt_number VARCHAR(40) NULL,
        pro_tybe INT NOT NULL,
        pro_date DATE NOT NULL,
        crtime DATETIME NULL,
        payment_date DATETIME NULL,
        fat_total DECIMAL(12,3) NOT NULL DEFAULT 0,
        fat_disc DECIMAL(12,3) NOT NULL DEFAULT 0,
        fat_net DECIMAL(12,3) NOT NULL DEFAULT 0,
        payment_status VARCHAR(30) NULL,
        order_status VARCHAR(30) NULL,
        order_type VARCHAR(30) NULL,
        isdeleted TINYINT NOT NULL DEFAULT 0,
        user INT NOT NULL,
        tenant INT NOT NULL,
        branch INT NOT NULL
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE myitems (
        id INT PRIMARY KEY,
        iname VARCHAR(120) NOT NULL
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE fat_details (
        id INT PRIMARY KEY,
        fatid INT NOT NULL,
        item_id INT NOT NULL,
        qty_out DECIMAL(12,6) NOT NULL DEFAULT 0,
        qty_in DECIMAL(12,6) NOT NULL DEFAULT 0,
        det_value DECIMAL(12,3) NOT NULL DEFAULT 0,
        isdeleted TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE credit_notes (
        id INT PRIMARY KEY,
        original_order_id INT NOT NULL,
        status VARCHAR(30) NOT NULL,
        total_amount DECIMAL(12,3) NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE credit_note_lines (
        id INT PRIMARY KEY,
        credit_note_id INT NOT NULL,
        original_detail_id INT NOT NULL,
        quantity DECIMAL(12,6) NOT NULL,
        line_amount DECIMAL(12,3) NOT NULL
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE payment_methods (
        id INT PRIMARY KEY,
        code VARCHAR(40) NOT NULL,
        name_ar VARCHAR(80) NULL,
        name_en VARCHAR(80) NULL,
        type VARCHAR(30) NOT NULL,
        account_id INT NULL,
        requires_reference TINYINT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE order_payments (
        id INT PRIMARY KEY,
        order_id INT NOT NULL,
        payment_method VARCHAR(40) NOT NULL,
        amount DECIMAL(12,3) NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL,
        is_voided TINYINT NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE payment_refunds (
        id INT PRIMARY KEY,
        original_order_id INT NOT NULL,
        credit_note_id INT NOT NULL,
        payment_method_id INT NOT NULL,
        amount DECIMAL(12,3) NOT NULL,
        status VARCHAR(30) NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB");

    $conn->query("INSERT INTO users VALUES (7, 'cashier7', 'Amina'), (8, 'cashier8', 'Other Branch')");
    $conn->query("INSERT INTO myitems VALUES (10, 'Flat White')");
    $conn->query("INSERT INTO payment_methods VALUES
        (1, 'cash', 'نقدي', 'Cash', 'cash', 101, 0, 1, 1),
        (2, 'card', 'بطاقة', 'Card', 'card', 102, 1, 1, 2)");
    $conn->query("INSERT INTO ot_head VALUES
        (1, 'P-1', 'R-1', 9, '2026-07-10', '2026-07-10 10:00:00', '2026-07-10 10:02:00', 100, 10, 90, 'paid', 'completed', 'dine_in', 0, 7, 1, 2),
        (2, 'P-2', 'R-2', 9, '2026-07-10', '2026-07-10 11:00:00', '2026-07-10 11:03:00', 50, 0, 50, 'refunded', 'completed', 'takeaway', 0, 7, 1, 2),
        (3, 'P-3', 'R-3', 9, '2026-07-10', '2026-07-10 12:00:00', NULL, 40, 0, 40, 'unpaid', 'draft', 'dine_in', 0, 7, 1, 2),
        (4, 'P-4', 'R-4', 9, '2026-07-10', '2026-07-10 13:00:00', '2026-07-10 13:01:00', 999, 0, 999, 'paid', 'completed', 'dine_in', 0, 8, 1, 3),
        (5, 'P-5', 'R-5', 3, '2026-07-10', '2026-07-10 14:00:00', '2026-07-10 14:01:00', 777, 0, 777, 'paid', 'completed', 'invoice', 0, 7, 1, 2),
        (6, 'P-6', 'R-6', 9, '2026-07-10', '2026-07-10 09:00:00', NULL, 30, 0, 30, 'unpaid', 'cancelled', 'takeaway', 1, 7, 1, 2)");
    $conn->query("ALTER TABLE ot_head ADD fat_plus DECIMAL(12,3) NOT NULL DEFAULT 0, ADD fat_tax DECIMAL(12,3) NOT NULL DEFAULT 0");
    $conn->query("UPDATE ot_head SET fat_plus = 5, fat_tax = 2, fat_net = 97 WHERE id = 1");
    $conn->query("UPDATE ot_head SET fat_plus = 3, fat_net = 53 WHERE id = 2");
    $conn->query("INSERT INTO fat_details VALUES
        (101, 1, 10, 2, 0, 90, 0),
        (102, 2, 10, 1, 0, 50, 0),
        (103, 3, 10, 4, 0, 40, 0),
        (104, 4, 10, 9, 0, 999, 0)");
    $conn->query("INSERT INTO credit_notes VALUES
        (201, 2, 'posted', 20, '2026-07-10 12:30:00')");
    $conn->query("INSERT INTO credit_note_lines VALUES
        (301, 201, 102, 0.5, 20)");
    $conn->query("INSERT INTO order_payments VALUES
        (401, 1, 'cash', 47, 7, '2026-07-10 10:02:00', 0),
        (402, 1, 'card', 50, 7, '2026-07-10 10:02:00', 0),
        (403, 2, 'cash', 53, 7, '2026-07-10 11:03:00', 0),
        (404, 4, 'cash', 999, 8, '2026-07-10 13:01:00', 0),
        (405, 1, 'cash', 500, 7, '2026-07-10 10:02:00', 1)");
    $conn->query("INSERT INTO payment_refunds VALUES
        (501, 2, 201, 1, 20, 'posted', 7, '2026-07-10 12:31:00'),
        (502, 1, 201, 2, 10, 'pending_external', 7, '2026-07-10 12:32:00')");

    $filters = ['date_from' => '2026-07-10', 'date_to' => '2026-07-10', 'tenant' => 1, 'branch' => 2, 'cashier_id' => 0];
    $service = new OperationsReportService();
    $sales = $service->salesSummary($conn, $filters);
    operationsReportAssert($sales['order_count'] === 2, 'only completed POS orders in branch count as sales');
    operationsReportAssert(abs($sales['gross_sales'] - 150.0) < 0.001, 'gross sales');
    operationsReportAssert(abs($sales['discounts'] - 10.0) < 0.001, 'discounts');
    operationsReportAssert(abs($sales['service_plus'] - 8.0) < 0.001, 'service and plus values');
    operationsReportAssert(abs($sales['tax'] - 2.0) < 0.001, 'tax values');
    operationsReportAssert(abs($sales['refunds'] - 20.0) < 0.001, 'posted credit-note refunds');
    operationsReportAssert(abs($sales['net_sales'] - 130.0) < 0.001, 'net sales after discounts, additions, tax, and refunds');

    $orders = $service->orders($conn, $filters);
    operationsReportAssert(count($orders) === 4, 'order register includes unpaid and cancelled orders but excludes other branch and non-POS');
    operationsReportAssert($orders[0]['payment_methods'][0] === 'Unpaid', 'unpaid order has an explicit payment state');
    $refundedOrder = array_values(array_filter($orders, static fn (array $order): bool => $order['id'] === 2))[0];
    operationsReportAssert($refundedOrder['reversal_status'] === 'partial', 'order register derives partial state from posted credit notes');
    operationsReportAssert(abs($refundedOrder['cumulative_refunded_amount'] - 20.0) < 0.001, 'order register exposes cumulative refunded amount');
    $cancelledOrders = $service->orders($conn, $filters + ['focus' => 'order_cancelled']);
    operationsReportAssert(count($cancelledOrders) === 1 && $cancelledOrders[0]['id'] === 6, 'cancelled order focus must return only cancelled orders');
    $discountedOrders = $service->orders($conn, $filters + ['focus' => 'order_discounted']);
    operationsReportAssert(count($discountedOrders) === 1 && $discountedOrders[0]['id'] === 1, 'discount focus must match completed discounted sales');
    $attention = $service->attention($conn, $filters, $sales, null, []);
    $reversalAttention = array_values(array_filter($attention, static fn (array $row): bool => $row['key'] === 'reversals'));
    operationsReportAssert(count($reversalAttention) === 1 && $reversalAttention[0]['count'] === 1, 'cancelled order card and filtered order register must share one predicate');

    $items = $service->itemSales($conn, $filters);
    operationsReportAssert(count($items) === 1, 'single item rollup');
    operationsReportAssert(abs($items[0]['sold_qty'] - 3.0) < 0.000001, 'sold quantity excludes unpaid lines');
    operationsReportAssert(abs($items[0]['returned_qty'] - 0.5) < 0.000001, 'returned decimal quantity');
    operationsReportAssert(abs($items[0]['net_qty'] - 2.5) < 0.000001, 'net decimal quantity');
    operationsReportAssert(abs($items[0]['net_value'] - 120.0) < 0.001, 'item net value');

    $payments = $service->paymentSummary($conn, $filters);
    operationsReportAssert(abs((float) $payments['cash_collected'] - 100.0) < 0.001, 'cash tender excludes voided and other branch rows');
    operationsReportAssert(abs((float) $payments['by_type']['card'] - 50.0) < 0.001, 'card tender');
    operationsReportAssert(abs((float) $payments['cash_refunds'] - 20.0) < 0.001, 'cash refund by method');
    operationsReportAssert(abs((float) $payments['refund_total'] - 30.0) < 0.001, 'all refund obligations stay visible');
    operationsReportAssert(abs((float) $payments['custody_refund_total'] - 20.0) < 0.001, 'only posted cash or settled noncash reduces custody');
    operationsReportAssert(abs((float) $payments['pending_external_refund_total'] - 10.0) < 0.001, 'pending card refund stays visible separately');
    operationsReportAssert(abs((float) $payments['net_total'] - 130.0) < 0.001, 'net tenders match net sales including additions and tax');

    $refundRows = $service->refunds($conn, $filters);
    operationsReportAssert(count($refundRows) === 1, 'refund drilldown should expose posted credit notes');
    operationsReportAssert($refundRows[0]['reversal_status'] === 'partial', 'refund drilldown should label partial reversal');
    operationsReportAssert($refundRows[0]['total_amount'] === '20.00', 'refund drilldown should expose this reversal amount');
    operationsReportAssert($refundRows[0]['pending_external_amount'] === '10.00', 'refund drilldown should expose pending settlement separately');

    $conn->query("UPDATE ot_head SET payment_status = 'voided', order_status = 'cancelled', isdeleted = 1 WHERE id = 2");
    $conn->query("UPDATE credit_notes SET total_amount = 53 WHERE id = 201");
    $historicalVoidSales = $service->salesSummary($conn, $filters);
    operationsReportAssert(abs($historicalVoidSales['sales_after_discount'] - 150.0) < 0.001, 'historical paid void must retain original gross evidence');
    operationsReportAssert(abs($historicalVoidSales['refunds'] - 53.0) < 0.001, 'historical paid void uses its posted credit note once');
    operationsReportAssert(abs($historicalVoidSales['net_sales'] - 97.0) < 0.001, 'historical paid void must not double-subtract the reversal');

    echo "operations-report-service-ok\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$dbName}`");
    $conn->close();
}

function operationsReportAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "operations-report-service-fail: {$message}\n");
        exit(1);
    }
}
