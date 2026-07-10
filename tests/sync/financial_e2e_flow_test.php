<?php

/**
 * End-to-end financial smoke against a clean certification DB:
 * price → invoice journal → payment journal → partial refund → recon zero on journals.
 */
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Financial/FinancialPricingService.php';
require_once __DIR__ . '/../../classes/Financial/FinancialInvoicePostingService.php';
require_once __DIR__ . '/../../classes/Financial/FinancialRefundService.php';
require_once __DIR__ . '/../../classes/Financial/FinancialReconciliationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';
require_once __DIR__ . '/../../classes/Pos/Service/AccountingPostingService.php';
require_once __DIR__ . '/../../classes/Accounting/JournalPostingService.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_financial_e2e_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    fwrite(STDERR, "financial-e2e-failed-db-unavailable\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    foreach ([
        "CREATE TABLE journal_heads (id INT AUTO_INCREMENT PRIMARY KEY, journal_id INT NOT NULL, total DECIMAL(19,2) NOT NULL, jdate DATE NOT NULL, details VARCHAR(255) NULL, user INT NULL, op_id INT NULL, op2 INT NULL) ENGINE=InnoDB",
        "CREATE TABLE journal_entries (id INT AUTO_INCREMENT PRIMARY KEY, journal_id INT NOT NULL, account_id INT NOT NULL, debit DECIMAL(19,2) NOT NULL DEFAULT 0, credit DECIMAL(19,2) NOT NULL DEFAULT 0, tybe INT NOT NULL DEFAULT 0, op2 INT NULL) ENGINE=InnoDB",
        "CREATE TABLE ot_head (id INT AUTO_INCREMENT PRIMARY KEY, pro_tybe INT NOT NULL DEFAULT 9, fat_net DECIMAL(19,2) NOT NULL DEFAULT 0, fat_tax DECIMAL(19,2) NOT NULL DEFAULT 0, payment_status VARCHAR(20) NULL, isdeleted TINYINT(1) NOT NULL DEFAULT 0, table_id INT NULL, info VARCHAR(255) NULL, emp_id INT NULL, acc1 INT NULL, acc2 INT NULL, pro_value DECIMAL(19,2) NULL, cost_center INT NULL, profit DECIMAL(19,2) NULL, user INT NULL, op2 INT NULL, pro_id INT NULL, is_journal TINYINT NULL, journal_tybe INT NULL, pro_date DATE NULL) ENGINE=InnoDB",
        "CREATE TABLE fat_details (id INT AUTO_INCREMENT PRIMARY KEY, fatid INT NOT NULL, item_id INT NOT NULL, qty_in DECIMAL(19,6) NOT NULL DEFAULT 0, qty_out DECIMAL(19,6) NOT NULL DEFAULT 0, price DECIMAL(19,6) NOT NULL DEFAULT 0, discount DECIMAL(19,2) NOT NULL DEFAULT 0, det_value DECIMAL(19,2) NOT NULL DEFAULT 0, isdeleted TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB",
        "CREATE TABLE order_payments (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, amount DECIMAL(19,2) NOT NULL, payment_method VARCHAR(50) NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE acc_head (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NULL, name VARCHAR(120) NOT NULL, balance DECIMAL(19,2) NOT NULL DEFAULT 0) ENGINE=InnoDB",
    ] as $sql) {
        $conn->query($sql);
    }
    (new SyncSchemaManager())->apply($conn);

    foreach ([[51,'cash'],[52,'card'],[501,'ar'],[91,'sales']] as [$id, $name]) {
        $conn->query("INSERT INTO acc_head (id, code, name, balance) VALUES ({$id}, '{$id}', '{$name}', 0.00)");
    }
    $methods = new PaymentMethodService();
    $methods->saveMethod($conn, ['code' => 'cash', 'name_ar' => 'Cash', 'type' => 'cash', 'account_id' => 51, 'requires_reference' => false]);
    $methods->saveMethod($conn, ['code' => 'card_terminal', 'name_ar' => 'Card', 'type' => 'card', 'account_id' => 52]);

    $priced = (new FinancialPricingService())->price([
        ['id' => 1, 'qty' => '2', 'price' => '35', 'discount' => '1'],
    ]);
    e2eAssert($priced['totals']['net'] === '68.00', 'pricing 68.00');

    $conn->query("INSERT INTO ot_head (id, pro_tybe, fat_net, payment_status) VALUES (800, 9, 68.00, 'unpaid')");
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, price, discount, det_value, posted_qty, posted_unit_price, posted_net, posted_tax, tax_rate_snapshot)
        VALUES (11, 800, 1, 0, 2, 35.000000, 1.000000, 68.00, 2.000000, 35.000000, 68.00, 0.00, 0.000000)
    ");

    $invoice = (new FinancialInvoicePostingService())->postInvoiceFinalization(
        $conn,
        800,
        $priced['totals'],
        501,
        91,
        1,
        ['idempotency_key' => 'e2e-invoice-800']
    );
    e2eAssert($invoice['replayed'] === false, 'invoice posted');

    $payment = (new AccountingPostingService())->postTablePaymentReceipt($conn, [
        'order_id' => 800,
        'amount' => '68.00',
        'safe_account_id' => 51,
        'customer_account_id' => 501,
        'emp_id' => 1,
        'table_name' => 'E2E',
        'idempotency_key' => 'e2e-pay-800',
    ], ['user_id' => 1, 'emp_id' => 1]);
    e2eAssert($payment['amount'] === '68.00', 'payment posted as string amount');
    $conn->query("INSERT INTO order_payments (id, order_id, amount, payment_method) VALUES (801, 800, 68.00, 'cash')");
    $conn->query("UPDATE ot_head SET payment_status = 'paid' WHERE id = 800");

    // Cash refund needs drawer session — use card path with reference for e2e simplicity after converting payment.
    $conn->query("UPDATE order_payments SET payment_method = 'card_terminal' WHERE id = 801");
    $cardId = (int) $conn->query("SELECT id FROM payment_methods WHERE code='card_terminal'")->fetch_assoc()['id'];
    $refund = (new FinancialRefundService())->createPostedRefund($conn, [
        'original_order_id' => 800,
        'customer_account_id' => 501,
        'revenue_account_id' => 91,
        'user_id' => 1,
        'reason' => 'E2E partial refund',
        'idempotency_key' => 'e2e-refund-800',
        'lines' => [['original_detail_id' => 11, 'quantity' => '1.000000', 'stock_disposition' => 'waste']],
        'payments' => [[
            'original_payment_id' => 801,
            'payment_method_id' => $cardId,
            'amount' => '34.00',
            'external_reference' => 'e2e-term-1',
        ]],
    ]);
    e2eAssert($refund['total_amount'] === '34.00', 'partial refund 34.00');
    e2eAssert($refund['pending_external_amount'] === '0.00', 'settled immediately');

    $imbalanced = (int) $conn->query("
        SELECT COUNT(*) AS c FROM (
            SELECT journal_id FROM journal_entries GROUP BY journal_id
            HAVING ROUND(SUM(debit),2) <> ROUND(SUM(credit),2)
        ) x
    ")->fetch_assoc()['c'];
    e2eAssert($imbalanced === 0, 'all journals balanced');

    $reversalId = JournalPostingService::postReversal(
        $conn,
        (int) $invoice['journal_head_id'],
        1,
        'e2e reversal demo',
        ['journal_id' => '9001', 'idempotency_key' => 'e2e-rev-800']
    );
    e2eAssert($reversalId > 0, 'linked reversal posted');

    echo "financial-e2e-ok db=$db\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `$db`");
    $conn->close();
}

function e2eAssert(bool $ok, string $msg): void
{
    if (!$ok) {
        throw new RuntimeException($msg);
    }
}
