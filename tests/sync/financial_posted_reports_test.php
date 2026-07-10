<?php

require_once __DIR__ . '/../../classes/Financial/FinancialPostedReportsService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_financial_reports_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    fwrite(STDERR, "financial-posted-reports-failed-db\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    foreach ([
        "CREATE TABLE ot_head (id INT AUTO_INCREMENT PRIMARY KEY, pro_tybe INT NOT NULL DEFAULT 9, fat_net DECIMAL(19,2) NOT NULL DEFAULT 0, fat_tax DECIMAL(19,2) NOT NULL DEFAULT 0, payment_status VARCHAR(20) NULL, isdeleted TINYINT(1) NOT NULL DEFAULT 0, pro_date DATE NULL) ENGINE=InnoDB",
        "CREATE TABLE fat_details (id INT AUTO_INCREMENT PRIMARY KEY, fatid INT NOT NULL, item_id INT NOT NULL, qty_in DECIMAL(19,6) NOT NULL DEFAULT 0, qty_out DECIMAL(19,6) NOT NULL DEFAULT 0, price DECIMAL(19,6) NOT NULL DEFAULT 0, discount DECIMAL(19,2) NOT NULL DEFAULT 0, det_value DECIMAL(19,2) NOT NULL DEFAULT 0, isdeleted TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB",
        "CREATE TABLE order_payments (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, amount DECIMAL(19,2) NOT NULL, payment_method VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
        "CREATE TABLE journal_heads (id INT AUTO_INCREMENT PRIMARY KEY, journal_id INT NOT NULL, total DECIMAL(19,2) NOT NULL, jdate DATE NOT NULL, details VARCHAR(255) NULL, user INT NULL, op_id INT NULL, op2 INT NULL) ENGINE=InnoDB",
        "CREATE TABLE journal_entries (id INT AUTO_INCREMENT PRIMARY KEY, journal_id INT NOT NULL, account_id INT NOT NULL, debit DECIMAL(19,2) NOT NULL DEFAULT 0, credit DECIMAL(19,2) NOT NULL DEFAULT 0, tybe INT NOT NULL DEFAULT 0, op2 INT NULL) ENGINE=InnoDB",
        "CREATE TABLE acc_head (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NULL, name VARCHAR(120) NOT NULL, aname VARCHAR(120) NULL, balance DECIMAL(19,2) NOT NULL DEFAULT 0) ENGINE=InnoDB",
    ] as $sql) {
        $conn->query($sql);
    }
    (new SyncSchemaManager())->apply($conn);
    $today = date('Y-m-d');
    $conn->query("INSERT INTO ot_head (id, pro_tybe, fat_net, fat_tax, payment_status, pro_date) VALUES (1, 9, 68.00, 0.00, 'paid', '{$today}')");
    $conn->query("INSERT INTO fat_details (fatid, item_id, qty_out, price, det_value, posted_qty, posted_unit_price, posted_gross, posted_net, posted_tax, posted_line_discount, posted_order_discount) VALUES (1, 10, 2, 35, 68.00, 2, 35, 70.00, 68.00, 0.00, 2.00, 0.00)");
    $conn->query("INSERT INTO order_payments (order_id, amount, payment_method) VALUES (1, 68.00, 'cash')");
    $conn->query("INSERT INTO acc_head (id, name) VALUES (51, 'Cash'), (91, 'Sales')");
    $conn->query("INSERT INTO journal_heads (id, journal_id, total, jdate) VALUES (1, 1, 68.00, '{$today}')");
    $conn->query("INSERT INTO journal_entries (journal_id, account_id, debit, credit) VALUES (1, 51, 68.00, 0.00), (1, 91, 0.00, 68.00)");

    $reports = new FinancialPostedReportsService();
    $sales = $reports->salesFromFinalizedDocuments($conn, $today, $today);
    reportsAssert($sales['net'] === '68.00', 'sales net');
    reportsAssert($sales['tax'] === '0.00', 'VAT remains zero while disabled');
    reportsAssert($sales['invoice_count'] === 1, 'one invoice');

    $tenders = $reports->tenderReport($conn, $today, $today);
    reportsAssert($tenders['total_paid'] === '68.00', 'tender paid');
    reportsAssert($tenders['total_net'] === '68.00', 'tender net');

    $gl = $reports->generalLedger($conn, $today, $today);
    reportsAssert($gl['balanced'] === true, 'GL balanced');
    reportsAssert($gl['total_debit'] === '68.00', 'GL debit');
    reportsAssert($reports->pendingExternalRefundLiability($conn) === '0.00', 'no pending external');

    echo "financial-posted-reports-ok db=$db\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `$db`");
    $conn->close();
}

function reportsAssert(bool $ok, string $msg): void
{
    if (!$ok) {
        throw new RuntimeException($msg);
    }
}
