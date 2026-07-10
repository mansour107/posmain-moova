<?php

/**
 * Seed a clean certification database with approved chart of accounts,
 * payment methods, tax categories (disabled), and opening documents.
 */
require_once __DIR__ . '/../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../classes/Pos/Service/PaymentMethodService.php';
require_once __DIR__ . '/../classes/Accounting/JournalPostingService.php';
require_once __DIR__ . '/../classes/Financial/Money.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = getenv('POSMAIN_MYSQL_DATABASE') ?: getenv('POSMAIN_FINANCIAL_CERT_DB') ?: 'posmain_financial_cert';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $user, $pass, '', $port);
$conn->query("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$conn->select_db($db);

// Minimal legacy tables required before SchemaManager upgrades.
foreach ([
    "CREATE TABLE IF NOT EXISTS acc_head (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(40) NULL,
        name VARCHAR(120) NOT NULL,
        balance DECIMAL(19,2) NOT NULL DEFAULT 0.00
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS journal_heads (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        journal_id INT NOT NULL,
        total DECIMAL(19,2) NOT NULL DEFAULT 0.00,
        jdate DATE NOT NULL,
        details VARCHAR(255) NULL,
        user INT NULL,
        op_id INT NULL,
        op2 INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS journal_entries (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        journal_id INT NOT NULL,
        account_id INT NOT NULL,
        debit DECIMAL(19,2) NOT NULL DEFAULT 0.00,
        credit DECIMAL(19,2) NOT NULL DEFAULT 0.00,
        tybe INT NOT NULL DEFAULT 0,
        op2 INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS ot_head (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pro_tybe INT NOT NULL DEFAULT 9,
        fat_net DECIMAL(19,2) NOT NULL DEFAULT 0.00,
        fat_tax DECIMAL(19,2) NOT NULL DEFAULT 0.00,
        payment_status VARCHAR(20) NULL,
        isdeleted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS fat_details (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fatid INT NOT NULL,
        item_id INT NOT NULL,
        qty_in DECIMAL(19,6) NOT NULL DEFAULT 0,
        qty_out DECIMAL(19,6) NOT NULL DEFAULT 0,
        price DECIMAL(19,6) NOT NULL DEFAULT 0,
        discount DECIMAL(19,2) NOT NULL DEFAULT 0,
        det_value DECIMAL(19,2) NOT NULL DEFAULT 0,
        cost_price DECIMAL(19,6) NOT NULL DEFAULT 0,
        profit DECIMAL(19,2) NOT NULL DEFAULT 0,
        isdeleted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS order_payments (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        amount DECIMAL(19,2) NOT NULL,
        payment_method VARCHAR(50) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS myusers (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(40) NOT NULL DEFAULT 'owner'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
] as $sql) {
    $conn->query($sql);
}

(new SyncSchemaManager())->apply($conn);

$accounts = [
    [51, '101001', 'Cash drawer'],
    [52, '102001', 'Card clearing'],
    [53, '103001', 'Bank'],
    [54, '104001', 'Wallet clearing'],
    [501, '121001', 'Accounts receivable'],
    [91, '411001', 'Sales revenue'],
    [92, '211001', 'VAT payable'],
    [93, '511001', 'Cost of goods sold'],
    [94, '131001', 'Inventory'],
];
foreach ($accounts as [$id, $code, $name]) {
    $stmt = $conn->prepare('INSERT IGNORE INTO acc_head (id, code, name, balance) VALUES (?, ?, ?, 0.00)');
    $stmt->bind_param('iss', $id, $code, $name);
    $stmt->execute();
    $stmt->close();
}

$methods = new PaymentMethodService();
foreach ([
    ['code' => 'cash', 'name_ar' => 'نقدي', 'name_en' => 'Cash', 'type' => 'cash', 'account_id' => 51, 'requires_reference' => false],
    ['code' => 'card_terminal', 'name_ar' => 'بطاقة', 'name_en' => 'Card', 'type' => 'card', 'account_id' => 52],
    ['code' => 'bank_transfer', 'name_ar' => 'تحويل', 'name_en' => 'Bank', 'type' => 'bank', 'account_id' => 53],
    ['code' => 'wallet', 'name_ar' => 'محفظة', 'name_en' => 'Wallet', 'type' => 'wallet', 'account_id' => 54],
] as $method) {
    $methods->saveMethod($conn, $method);
}

// Tax disabled until accountant approval.
$conn->query("
    INSERT INTO tax_categories (code, name, rate, is_inclusive, is_active)
    VALUES ('eg_vat_14', 'Egypt VAT 14%', 14.000000, 0, 0)
    ON DUPLICATE KEY UPDATE is_active = 0
");

// Opening cash via balanced opening journal (not direct balance update).
$opening = '1000.00';
JournalPostingService::postBalancedHead(
    $conn,
    '1',
    $opening,
    date('Y-m-d'),
    'Opening cash balance',
    1,
    [
        ['account_id' => 51, 'debit' => $opening, 'credit' => '0.00', 'tybe' => 0, 'op2' => 0],
        ['account_id' => 501, 'debit' => '0.00', 'credit' => $opening, 'tybe' => 1, 'op2' => 0],
    ],
    [
        'source_type' => 'opening_balance',
        'source_id' => 1,
        'posting_kind' => 'opening_cash',
        'idempotency_key' => 'opening-cash-1',
    ]
);
$conn->query("UPDATE acc_head SET balance = {$opening} WHERE id = 51");
$conn->query("UPDATE acc_head SET balance = -{$opening} WHERE id = 501");

$hash = password_hash('1234', PASSWORD_DEFAULT);
$stmt = $conn->prepare('INSERT IGNORE INTO myusers (id, username, password, role) VALUES (1, ?, ?, ?)');
$username = 'owner';
$role = 'owner';
$stmt->bind_param('sss', $username, $hash, $role);
$stmt->execute();
$stmt->close();

echo "financial-certification-seed-ok db={$db}\n";
