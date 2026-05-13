<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const POS_TAKEAWAY_BRANCH_UUID = '11111111-1111-4111-8111-111111111111';

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_takeaway_handler_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posTakeawayInvoiceCreateSchema($conn);
    posTakeawayInvoiceSeedFixtures($conn);

    $runner = posTakeawayInvoiceCreateRunner($db, $host, $port, $user, $pass, posTakeawayInvoiceCashPost());
    try {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1';
        $lines = [];
        exec($command, $lines, $exitCode);
        posTakeawayInvoiceAssert($exitCode === 0, "handler runner failed:\n" . implode("\n", $lines));
    } finally {
        @unlink($runner);
    }

    posTakeawayInvoiceAssertCommittedTakeawaySale($conn);

    $runner = posTakeawayInvoiceCreateRunner($db, $host, $port, $user, $pass, posTakeawayInvoiceMixedPost());
    try {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1';
        $lines = [];
        exec($command, $lines, $exitCode);
        posTakeawayInvoiceAssert($exitCode === 0, "mixed handler runner failed:\n" . implode("\n", $lines));
    } finally {
        @unlink($runner);
    }

    posTakeawayInvoiceAssertCommittedMixedTakeawaySale($conn);
    echo "pos-takeaway-invoice-handler-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posTakeawayInvoiceCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            edit_pass VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE towns (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL PRIMARY KEY,
            name VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE myitems (
            id INT NOT NULL PRIMARY KEY,
            iname VARCHAR(255) NULL,
            barcode VARCHAR(80) NULL,
            itmqty DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            last_price DECIMAL(15,4) NULL,
            price1 DECIMAL(15,4) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(255) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL,
            crtime DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            mdtime DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_id INT NULL,
            pro_tybe INT NULL,
            is_stock INT NULL,
            is_journal INT NULL,
            journal_tybe INT NULL,
            info TEXT NULL,
            pro_date DATE NULL,
            accural_date DATE NULL,
            pro_pattren INT NULL,
            pro_serial VARCHAR(80) NULL,
            price_list INT NULL,
            store_id INT NULL,
            emp_id INT NULL,
            emp2_id INT NULL,
            acc1 INT NULL,
            acc2 INT NULL,
            pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_cost DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_center INT NULL,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc_per DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_plus DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_plus_per DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_tax DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_tax_per DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            user INT NULL,
            jal_name VARCHAR(255) NULL,
            jal_notes TEXT NULL,
            jal_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            table_id INT NULL,
            order_type VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            invoice_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            waiter_id INT NULL,
            completed_at DATETIME NULL,
            payment_method VARCHAR(40) NULL,
            payment_date DATETIME NULL,
            op2 INT NULL,
            branch_id INT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            closed TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL,
            crtime DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            mdtime DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ot_head_op2 (op2),
            KEY idx_ot_head_pro_tybe (pro_tybe)
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
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL,
            KEY idx_fat_details_fatid (fatid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_heads (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            total DECIMAL(15,4) NOT NULL DEFAULT 0,
            jdate DATE NULL,
            details VARCHAR(255) NULL,
            user INT NULL,
            op_id INT NULL,
            op2 INT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            KEY idx_journal_heads_op_id (op_id),
            KEY idx_journal_heads_op2 (op2)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_entries (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            account_id INT NOT NULL,
            debit DECIMAL(15,4) NOT NULL DEFAULT 0,
            credit DECIMAL(15,4) NOT NULL DEFAULT 0,
            tybe INT NOT NULL DEFAULT 0,
            op_id INT NULL,
            op2 INT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            KEY idx_journal_entries_journal_id (journal_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE process (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_method VARCHAR(40) NULL,
            reference_no VARCHAR(80) NULL,
            created_by INT NULL,
            created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            uuid CHAR(36) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    (new SyncSchemaManager())->apply($conn);
}

function posTakeawayInvoiceSeedFixtures(mysqli $conn): void
{
    $conn->query("INSERT INTO settings (id, def_pos_client, edit_pass, isdeleted) VALUES (1, 501, '1234', 0)");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, isdeleted) VALUES
            (91, '400001', 'Sales', 0),
            (501, '122001', 'Walk-in Customer', 0),
            (51, '101001', 'Cash Drawer', 0),
            (61, '102001', 'Bank Account', 0)
    ");
    $conn->query("
        INSERT INTO myitems (id, iname, barcode, itmqty, cost_price, price1, isdeleted) VALUES
            (10, 'Coffee', 'COF10', 20, 4, 10, 0),
            (11, 'Cake', 'CAK11', 15, 2, 8, 0)
    ");
}

function posTakeawayInvoiceCashPost(): array
{
    return [
        'pro_tybe' => '9',
        'idempotency_key' => 'phpunit:takeaway-handler:create:1',
        'store_id' => '3',
        'pro_serial' => 'TAKEAWAY-FIXTURE-1',
        'pro_date' => '2026-05-12',
        'accural_date' => '2026-05-12',
        'acc2_id' => '501',
        'emp_id' => '4',
        'headtotal' => '28',
        'headdisc' => '0',
        'headplus' => '0',
        'headnet' => '28',
        'fund_id' => '51',
        'payment_fund_id' => '51',
        'payment_bank_id' => '0',
        'paid' => '28',
        'paid_cash' => '28',
        'paid_bank' => '0',
        'info' => 'paid takeaway fixture',
        'submit_action' => 'cash',
        'age' => '1',
        'itmname' => ['10', '11'],
        'itmqty' => ['2', '1'],
        'itmprice' => ['10', '8'],
        'itmdisc' => ['0', '0'],
        'u_val' => ['1', '1'],
    ];
}

function posTakeawayInvoiceMixedPost(): array
{
    $post = posTakeawayInvoiceCashPost();
    $post['idempotency_key'] = 'phpunit:takeaway-handler:create:mixed:1';
    $post['pro_serial'] = 'TAKEAWAY-FIXTURE-MIXED-1';
    $post['payment_bank_id'] = '61';
    $post['paid_cash'] = '10';
    $post['paid_bank'] = '18';
    $post['info'] = 'mixed takeaway fixture';

    return $post;
}

function posTakeawayInvoiceCreateRunner(string $db, string $host, int $port, string $user, string $pass, array $post): string
{
    $repoRoot = dirname(__DIR__, 2);
    $runner = tempnam(sys_get_temp_dir(), 'posmain_takeaway_runner_');
    if (!is_string($runner)) {
        throw new RuntimeException('Unable to create temporary handler runner.');
    }

    $code = <<<'PHP'
<?php

$repoRoot = __REPO_ROOT__;
chdir($repoRoot . '/do');

$env = [
    'POSMAIN_DB_HOST' => __DB_HOST__,
    'POSMAIN_DB_PORT' => __DB_PORT__,
    'POSMAIN_DB_USER' => __DB_USER__,
    'POSMAIN_DB_PASS' => __DB_PASS__,
    'POSMAIN_DB_NAME' => __DB_NAME__,
    'POSMAIN_TEST_MYSQL_DB' => __DB_NAME__,
    'POSMAIN_ENV' => 'test',
    'POSMAIN_PRODUCTION_MODE' => '0',
    'POSMAIN_ENABLE_SYNC_OUTBOX' => '1',
    'POSMAIN_SYNC_OUTBOX_ENABLED' => '1',
    'POSMAIN_BRANCH_UUID' => __BRANCH_UUID__,
    'POSMAIN_BRANCH_NAME' => 'Takeaway Handler Fixture',
    'POSMAIN_POS_TENANT' => '0',
    'POSMAIN_POS_BRANCH' => '0',
    'POSMAIN_CLOUD_BASE_URL' => 'http://127.0.0.1/cloud-fixture',
];

foreach ($env as $name => $value) {
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['PHP_SELF'] = '/do/doadd_invoice.php';
$_SERVER['SCRIPT_NAME'] = '/do/doadd_invoice.php';
$_SERVER['REQUEST_URI'] = '/do/doadd_invoice.php';
$_SERVER['HTTP_HOST'] = '127.0.0.1';

session_id('posmain-takeaway-' . getmypid());
session_start();
$_SESSION['userid'] = 7;
$_SESSION['usname'] = 'fixture-cashier';
session_write_close();

$_POST = __POST__;
$_REQUEST = $_POST;

require $repoRoot . '/do/doadd_invoice.php';
PHP;

    $replacements = [
        '__REPO_ROOT__' => var_export($repoRoot, true),
        '__DB_HOST__' => var_export($host, true),
        '__DB_PORT__' => var_export((string) $port, true),
        '__DB_USER__' => var_export($user, true),
        '__DB_PASS__' => var_export($pass, true),
        '__DB_NAME__' => var_export($db, true),
        '__BRANCH_UUID__' => var_export(POS_TAKEAWAY_BRANCH_UUID, true),
        '__POST__' => var_export($post, true),
    ];
    file_put_contents($runner, strtr($code, $replacements));

    return $runner;
}

function posTakeawayInvoiceAssertCommittedTakeawaySale(mysqli $conn): void
{
    $order = $conn->query("
        SELECT *
        FROM ot_head
        WHERE pro_tybe = 9
          AND op2 IS NULL
        ORDER BY id ASC
        LIMIT 1
    ")->fetch_assoc();
    posTakeawayInvoiceAssert(is_array($order), 'main POS order header should be inserted');
    $orderId = (int) $order['id'];

    posTakeawayInvoiceAssert((int) $order['pro_id'] === 1, 'POS pro_id should be allocated through document counter');
    posTakeawayInvoiceAssert($order['order_type'] === 'takeaway', 'age=1 should persist as takeaway order_type');
    posTakeawayInvoiceAssert($order['payment_status'] === 'paid', 'fully paid cash sale should be paid');
    posTakeawayInvoiceAssert($order['invoice_status'] === 'completed', 'fully paid cash sale should complete invoice');
    posTakeawayInvoiceAssert($order['order_status'] === 'completed', 'fully paid cash sale should complete order');
    posTakeawayInvoiceAssert((int) $order['table_id'] === 0, 'takeaway sale should not bind a table');
    posTakeawayInvoiceAssertFloat((float) $order['fat_total'], 28.0, 'order fat_total expected');
    posTakeawayInvoiceAssertFloat((float) $order['fat_net'], 28.0, 'order fat_net expected');
    posTakeawayInvoiceAssertFloat((float) $order['paid_amount'], 28.0, 'order paid_amount expected');
    posTakeawayInvoiceAssertFloat((float) $order['remaining_amount'], 0.0, 'order remaining_amount expected');
    posTakeawayInvoiceAssertFloat((float) $order['profit'], 18.0, 'order profit should equal summed line profit');
    posTakeawayInvoiceAssert(!empty($order['payment_date']), 'paid order should stamp payment_date');
    posTakeawayInvoiceAssert(!empty($order['completed_at']), 'completed order should stamp completed_at');

    $details = $conn->query("
        SELECT *
        FROM fat_details
        WHERE fatid = {$orderId}
        ORDER BY item_id ASC
    ")->fetch_all(MYSQLI_ASSOC);
    posTakeawayInvoiceAssert(count($details) === 2, 'two POS detail rows should be inserted');
    posTakeawayInvoiceAssertLine($details[0], 10, 2.0, 10.0, 4.0, 20.0, 12.0);
    posTakeawayInvoiceAssertLine($details[1], 11, 1.0, 8.0, 2.0, 8.0, 6.0);

    $invoiceJournal = $conn->query("
        SELECT *
        FROM journal_heads
        WHERE op_id = {$orderId}
        ORDER BY id ASC
        LIMIT 1
    ")->fetch_assoc();
    posTakeawayInvoiceAssert(is_array($invoiceJournal), 'sales journal head should be inserted for paid POS sale');
    posTakeawayInvoiceAssert((int) $invoiceJournal['journal_id'] === 1, 'sales journal should use first journal counter value');
    posTakeawayInvoiceAssertFloat((float) $invoiceJournal['total'], 28.0, 'sales journal total expected');

    $invoiceEntries = $conn->query("
        SELECT *
        FROM journal_entries
        WHERE journal_id = " . (int) $invoiceJournal['id'] . "
        ORDER BY tybe ASC
    ")->fetch_all(MYSQLI_ASSOC);
    posTakeawayInvoiceAssert(count($invoiceEntries) === 2, 'sales journal should have customer debit and sales credit');
    posTakeawayInvoiceAssert((int) $invoiceEntries[0]['account_id'] === 501, 'sales debit should hit customer account');
    posTakeawayInvoiceAssertFloat((float) $invoiceEntries[0]['debit'], 28.0, 'sales debit amount expected');
    posTakeawayInvoiceAssert((int) $invoiceEntries[1]['account_id'] === 91, 'sales credit should hit sales account');
    posTakeawayInvoiceAssertFloat((float) $invoiceEntries[1]['credit'], 28.0, 'sales credit amount expected');

    $receipt = $conn->query("
        SELECT *
        FROM ot_head
        WHERE pro_tybe = 1
          AND op2 = {$orderId}
        ORDER BY id ASC
        LIMIT 1
    ")->fetch_assoc();
    posTakeawayInvoiceAssert(is_array($receipt), 'cash receipt operation should be inserted');
    posTakeawayInvoiceAssert((int) $receipt['pro_id'] === 1, 'receipt pro_id should use receipt counter scope');
    posTakeawayInvoiceAssert((int) $receipt['acc1'] === 51, 'cash receipt debit account should be the selected fund');
    posTakeawayInvoiceAssert((int) $receipt['acc2'] === 501, 'cash receipt credit account should be the selected customer');
    posTakeawayInvoiceAssertFloat((float) $receipt['pro_value'], 28.0, 'cash receipt amount expected');

    $receiptJournal = $conn->query("
        SELECT *
        FROM journal_heads
        WHERE op_id = " . (int) $receipt['id'] . "
          AND op2 = {$orderId}
        ORDER BY id ASC
        LIMIT 1
    ")->fetch_assoc();
    posTakeawayInvoiceAssert(is_array($receiptJournal), 'cash receipt journal head should be inserted');
    posTakeawayInvoiceAssert((int) $receiptJournal['journal_id'] === 2, 'cash receipt journal should use second journal counter value');
    posTakeawayInvoiceAssertFloat((float) $receiptJournal['total'], 28.0, 'cash receipt journal total expected');

    $receiptEntries = $conn->query("
        SELECT *
        FROM journal_entries
        WHERE journal_id = " . (int) $receiptJournal['id'] . "
        ORDER BY tybe ASC
    ")->fetch_all(MYSQLI_ASSOC);
    posTakeawayInvoiceAssert(count($receiptEntries) === 2, 'cash receipt journal should have fund debit and customer credit');
    posTakeawayInvoiceAssert((int) $receiptEntries[0]['account_id'] === 51, 'cash receipt debit should hit fund account');
    posTakeawayInvoiceAssertFloat((float) $receiptEntries[0]['debit'], 28.0, 'cash receipt debit amount expected');
    posTakeawayInvoiceAssert((int) $receiptEntries[1]['account_id'] === 501, 'cash receipt credit should hit customer account');
    posTakeawayInvoiceAssertFloat((float) $receiptEntries[1]['credit'], 28.0, 'cash receipt credit amount expected');

    $process = $conn->query("SELECT * FROM process ORDER BY id ASC LIMIT 1")->fetch_assoc();
    posTakeawayInvoiceAssert(is_array($process), 'process audit row should be inserted');
    posTakeawayInvoiceAssert($process['type'] === 'add cash', 'POS process audit type expected');

    posTakeawayInvoiceAssertCounter($conn, 'pro_id', 'pro_tybe:9', 1);
    posTakeawayInvoiceAssertCounter($conn, 'pro_id', 'pro_tybe:1', 1);
    posTakeawayInvoiceAssertCounter($conn, 'journal_id', 'journal:default', 2);

    $outbox = $conn->query("
        SELECT *
        FROM sync_outbox
        ORDER BY id ASC
        LIMIT 1
    ")->fetch_assoc();
    posTakeawayInvoiceAssert(is_array($outbox), 'POS takeaway sale should record an outbox event');
    posTakeawayInvoiceAssert($outbox['branch_uuid'] === POS_TAKEAWAY_BRANCH_UUID, 'outbox should use configured branch uuid');
    posTakeawayInvoiceAssert($outbox['aggregate_type'] === 'order', 'outbox aggregate type expected');
    posTakeawayInvoiceAssert((int) $outbox['aggregate_local_id'] === $orderId, 'outbox aggregate local id should point at order');
    posTakeawayInvoiceAssert($outbox['entity_type'] === 'order', 'outbox entity type expected');
    posTakeawayInvoiceAssert($outbox['event_type'] === 'order.saved', 'outbox event type expected');
    posTakeawayInvoiceAssert($outbox['source_system'] === 'pos_cashier', 'outbox source system expected');
    posTakeawayInvoiceAssert($outbox['status'] === 'pending', 'outbox should be pending for worker delivery');
    posTakeawayInvoiceAssert($outbox['payload_hash'] === hash('sha256', $outbox['payload_json']), 'outbox payload hash should match JSON');

    $payload = json_decode($outbox['payload_json'], true);
    posTakeawayInvoiceAssert(is_array($payload), 'outbox payload should decode');
    posTakeawayInvoiceAssert($payload['snapshot_type'] === 'pos_order', 'outbox payload should be an order snapshot');
    posTakeawayInvoiceAssert((int) $payload['local_order_id'] === $orderId, 'payload local order id expected');
    posTakeawayInvoiceAssert($payload['order']['order_type'] === 'takeaway', 'payload order type expected');
    posTakeawayInvoiceAssert($payload['order']['payment_status'] === 'paid', 'payload payment status expected');
    posTakeawayInvoiceAssert($payload['order']['order_status'] === 'completed', 'payload order status expected');
    posTakeawayInvoiceAssert(count($payload['lines']) === 2, 'payload should include two detail lines');
    posTakeawayInvoiceAssert(count($payload['payments']) === 1, 'payload should include cash payment');
    posTakeawayInvoiceAssert(count($payload['receipts']) === 1, 'payload should include cash receipt');
    posTakeawayInvoiceAssert($payload['payments'][0]['payment_method'] === 'cash', 'payload payment method expected');
    posTakeawayInvoiceAssert($payload['receipts'][0]['payment_method'] === 'cash', 'payload receipt method expected');
}

function posTakeawayInvoiceAssertCommittedMixedTakeawaySale(mysqli $conn): void
{
    $order = $conn->query("
        SELECT *
        FROM ot_head
        WHERE pro_tybe = 9
          AND pro_serial = 'TAKEAWAY-FIXTURE-MIXED-1'
          AND op2 IS NULL
        ORDER BY id ASC
        LIMIT 1
    ")->fetch_assoc();
    posTakeawayInvoiceAssert(is_array($order), 'mixed POS order header should be inserted');
    $orderId = (int) $order['id'];

    posTakeawayInvoiceAssert((int) $order['pro_id'] === 2, 'mixed POS pro_id should use next document counter value');
    posTakeawayInvoiceAssert($order['payment_status'] === 'paid', 'mixed sale should be paid');
    posTakeawayInvoiceAssert($order['invoice_status'] === 'completed', 'mixed sale should complete invoice');
    posTakeawayInvoiceAssert($order['order_status'] === 'completed', 'mixed sale should complete order');
    posTakeawayInvoiceAssertFloat((float) $order['paid_amount'], 28.0, 'mixed order paid_amount expected');
    posTakeawayInvoiceAssertFloat((float) $order['remaining_amount'], 0.0, 'mixed order remaining_amount expected');

    $receipts = $conn->query("
        SELECT *
        FROM ot_head
        WHERE pro_tybe = 1
          AND op2 = {$orderId}
        ORDER BY id ASC
    ")->fetch_all(MYSQLI_ASSOC);
    posTakeawayInvoiceAssert(count($receipts) === 2, 'mixed sale should insert cash and bank receipt operations');
    posTakeawayInvoiceAssert((int) $receipts[0]['acc1'] === 51, 'mixed cash receipt fund account expected');
    posTakeawayInvoiceAssertFloat((float) $receipts[0]['pro_value'], 10.0, 'mixed cash receipt amount expected');
    posTakeawayInvoiceAssert((int) $receipts[1]['acc1'] === 61, 'mixed bank receipt account expected');
    posTakeawayInvoiceAssertFloat((float) $receipts[1]['pro_value'], 18.0, 'mixed bank receipt amount expected');

    posTakeawayInvoiceAssertCounter($conn, 'pro_id', 'pro_tybe:9', 2);
    posTakeawayInvoiceAssertCounter($conn, 'pro_id', 'pro_tybe:1', 3);
    posTakeawayInvoiceAssertCounter($conn, 'journal_id', 'journal:default', 5);
    posTakeawayInvoiceAssert((int) $conn->query("SELECT COUNT(*) AS c FROM process WHERE type = 'add cash'")->fetch_assoc()['c'] === 2, 'mixed route should add one process row per order');

    $outbox = $conn->query("
        SELECT *
        FROM sync_outbox
        WHERE aggregate_local_id = {$orderId}
        ORDER BY id DESC
        LIMIT 1
    ")->fetch_assoc();
    posTakeawayInvoiceAssert(is_array($outbox), 'mixed POS takeaway sale should record an outbox event');
    $payload = json_decode($outbox['payload_json'], true);
    posTakeawayInvoiceAssert(is_array($payload), 'mixed outbox payload should decode');
    posTakeawayInvoiceAssert(count($payload['payments']) === 2, 'mixed payload should include cash and bank payments');
    posTakeawayInvoiceAssert(count($payload['receipts']) === 2, 'mixed payload should include cash and bank receipts');
    posTakeawayInvoiceAssert($payload['payments'][0]['payment_method'] === 'cash', 'mixed payload first payment method expected');
    posTakeawayInvoiceAssert($payload['payments'][1]['payment_method'] === 'bank', 'mixed payload second payment method expected');
}

function posTakeawayInvoiceAssertLine(array $line, int $itemId, float $qtyOut, float $price, float $cost, float $value, float $profit): void
{
    posTakeawayInvoiceAssert((int) $line['item_id'] === $itemId, 'detail item id expected');
    posTakeawayInvoiceAssertFloat((float) $line['qty_in'], 0.0, 'POS sale should not add qty_in');
    posTakeawayInvoiceAssertFloat((float) $line['qty_out'], $qtyOut, 'POS sale qty_out expected');
    posTakeawayInvoiceAssertFloat((float) $line['price'], $price, 'POS sale unit price expected');
    posTakeawayInvoiceAssertFloat((float) $line['cost_price'], $cost, 'POS sale cost price expected');
    posTakeawayInvoiceAssertFloat((float) $line['det_value'], $value, 'POS sale detail value expected');
    posTakeawayInvoiceAssertFloat((float) $line['profit'], $profit, 'POS sale detail profit expected');
}

function posTakeawayInvoiceAssertCounter(mysqli $conn, string $type, string $key, int $expected): void
{
    $escapedType = $conn->real_escape_string($type);
    $escapedKey = $conn->real_escape_string($key);
    $row = $conn->query("
        SELECT current_value
        FROM document_counters
        WHERE pos_tenant = 0
          AND pos_branch = 0
          AND counter_type = '{$escapedType}'
          AND counter_key = '{$escapedKey}'
        LIMIT 1
    ")->fetch_assoc();
    posTakeawayInvoiceAssert(is_array($row), "counter {$type}:{$key} should exist");
    posTakeawayInvoiceAssert((int) $row['current_value'] === $expected, "counter {$type}:{$key} value expected");
}

function posTakeawayInvoiceAssertFloat(float $actual, float $expected, string $message): void
{
    posTakeawayInvoiceAssert(abs($actual - $expected) < 0.0001, $message . " actual={$actual} expected={$expected}");
}

function posTakeawayInvoiceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
