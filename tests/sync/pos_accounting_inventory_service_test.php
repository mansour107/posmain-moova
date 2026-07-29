<?php

require_once __DIR__ . '/../../classes/Pos/Service/AccountingPostingService.php';
require_once __DIR__ . '/../../classes/Pos/Service/InventoryMovementService.php';

mysqli_report(MYSQLI_REPORT_OFF);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_accounting_inventory_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-accounting-inventory-service-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posAccountingInventoryCreateSchema($conn);

    $conn->begin_transaction();
    try {
        $conn->query("INSERT INTO myitems (id, cost_price, itmqty, price1) VALUES (5, 10, 20, 15)");
        $inventory = new InventoryMovementService();
        $posLine = $inventory->normalizeInvoiceLine($conn, InventoryMovementService::TYPE_POS, [
            'item_id' => 5,
            'qty' => 2,
            'price' => 15,
            'discount' => 1,
            'u_val' => 1,
            'store_id' => 3,
        ]);

        posAccountingInventoryAssert($posLine['qty_in'] === 0.0, 'POS line should not add stock');
        posAccountingInventoryAssert(abs($posLine['qty_out'] - 2.0) < 0.0001, 'POS line should subtract quantity through detail qty_out');
        posAccountingInventoryAssert(abs($posLine['det_value'] - 28.0) < 0.0001, 'POS det_value should match legacy calculation');
        posAccountingInventoryAssert(abs($posLine['profit'] - 10.0) < 0.0001, 'POS profit should match legacy calculation');
        posAccountingInventoryAssert($posLine['item_update'] === null, 'POS line should not update item cost');

        $purchaseLine = $inventory->normalizeInvoiceLine($conn, InventoryMovementService::TYPE_PURCHASE, [
            'item_id' => 5,
            'qty' => 5,
            'price' => 8,
            'discount' => 0,
            'u_val' => 1,
        ]);

        posAccountingInventoryAssert(abs($purchaseLine['qty_in'] - 5.0) < 0.0001, 'purchase line should add stock');
        posAccountingInventoryAssert(abs($purchaseLine['cost_price'] - 9.6) < 0.0001, 'purchase weighted cost should match legacy calculation');
        posAccountingInventoryAssert(abs($purchaseLine['item_update']['last_price'] - 8.0) < 0.0001, 'purchase item update should carry unit price');

        $accounting = new AccountingPostingService();
        $posted = $accounting->postTablePaymentReceipt($conn, [
            'order_id' => 77,
            'table_name' => 'T1',
            'amount' => 125,
            'safe_account_id' => 100,
            'customer_account_id' => 200,
            'emp_id' => 8,
            'payment_date' => '2026-05-12',
            'idempotency_key' => 'receipt-77-1',
        ], ['user_id' => 7, 'tenant' => 12, 'branch' => 34]);

        posAccountingInventoryAssert($posted['receipt_id'] > 0, 'receipt id expected');
        posAccountingInventoryAssert($posted['journal_id'] === 1, 'first journal counter value expected');
        posAccountingInventoryAssert($posted['journal_head_id'] > 0, 'journal head id expected');
        posAccountingInventoryAssert($posted['entry_count'] === 2, 'two journal entries expected when customer account is set');

        $receipt = $conn->query("SELECT * FROM ot_head WHERE id = {$posted['receipt_id']}")->fetch_assoc();
        posAccountingInventoryAssert((int) $receipt['pro_tybe'] === 1, 'receipt should use legacy receipt pro_tybe');
        posAccountingInventoryAssert((int) $receipt['op2'] === 77, 'receipt should link to table order through op2');
        posAccountingInventoryAssert(abs((float) $receipt['pro_value'] - 125.0) < 0.0001, 'receipt value expected');
        posAccountingInventoryAssert((int) $receipt['tenant'] === 12 && (int) $receipt['branch'] === 34, 'receipt voucher must inherit operational scope');

        $journal = $conn->query("SELECT * FROM journal_heads WHERE id = {$posted['journal_head_id']}")->fetch_assoc();
        posAccountingInventoryAssert((int) $journal['journal_id'] === 1, 'journal head should store allocated journal id');
        posAccountingInventoryAssert((int) $journal['op_id'] === $posted['receipt_id'], 'journal should link to receipt header');
        posAccountingInventoryAssert(abs((float) $journal['total'] - 125.0) < 0.0001, 'journal total expected');

        $entries = $conn->query("SELECT * FROM journal_entries WHERE journal_id = {$posted['journal_head_id']} ORDER BY tybe ASC")->fetch_all(MYSQLI_ASSOC);
        posAccountingInventoryAssert(count($entries) === 2, 'two entries should be inserted');
        posAccountingInventoryAssert((int) $entries[0]['account_id'] === 100 && abs((float) $entries[0]['debit'] - 125.0) < 0.0001, 'safe debit expected');
        posAccountingInventoryAssert((int) $entries[1]['account_id'] === 200 && abs((float) $entries[1]['credit'] - 125.0) < 0.0001, 'customer credit expected');

        $replayed = $accounting->postTablePaymentReceipt($conn, [
            'order_id' => 77,
            'table_name' => 'T1',
            'amount' => 125,
            'safe_account_id' => 100,
            'customer_account_id' => 200,
            'emp_id' => 8,
            'payment_date' => '2026-05-12',
            'idempotency_key' => 'receipt-77-1',
        ], ['user_id' => 7, 'tenant' => 12, 'branch' => 34]);
        posAccountingInventoryAssert($replayed['replayed'] === true, 'same payment idempotency key must replay');
        posAccountingInventoryAssert((int) $conn->query('SELECT COUNT(*) AS c FROM ot_head')->fetch_assoc()['c'] === 1, 'receipt replay must not create another voucher');
        posAccountingInventoryAssert((int) $conn->query('SELECT COUNT(*) AS c FROM journal_heads')->fetch_assoc()['c'] === 1, 'receipt replay must not create another journal');
    } finally {
        $conn->rollback();
    }

    echo "pos-accounting-inventory-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posAccountingInventoryCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE myitems (
            id INT NOT NULL PRIMARY KEY,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            itmqty DECIMAL(15,4) NOT NULL DEFAULT 0,
            price1 DECIMAL(15,4) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE item_units (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            unit_id INT NOT NULL DEFAULT 1,
            price1 DECIMAL(15,4) NOT NULL DEFAULT 0,
            u_val DECIMAL(18,6) NOT NULL DEFAULT 1,
            def_sale TINYINT(1) NOT NULL DEFAULT 1,
            def_buy TINYINT(1) NOT NULL DEFAULT 1,
            def_stock TINYINT(1) NOT NULL DEFAULT 1,
            conversion_swapped TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE document_counters (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pos_tenant INT NOT NULL DEFAULT 0,
            pos_branch INT NOT NULL DEFAULT 0,
            counter_type VARCHAR(50) NOT NULL,
            counter_key VARCHAR(100) NOT NULL,
            current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_document_counter_scope (pos_tenant, pos_branch, counter_type, counter_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_tybe INT NULL,
            is_journal INT NULL,
            journal_tybe INT NULL,
            info VARCHAR(255) NULL,
            pro_date DATE NULL,
            emp_id INT NULL,
            acc1 INT NULL,
            acc2 INT NULL,
            pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_center INT NULL,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            user INT NULL,
            op2 INT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE journal_heads (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            op_id INT NULL,
            total DECIMAL(15,4) NOT NULL DEFAULT 0,
            jdate DATE NULL,
            details VARCHAR(255) NULL,
            user INT NULL,
            op2 INT NULL,
            source_type VARCHAR(64) NULL,
            source_id BIGINT NULL,
            posting_kind VARCHAR(64) NULL,
            idempotency_key VARCHAR(191) NULL,
            reversal_of_journal_id BIGINT NULL,
            UNIQUE KEY uq_journal_heads_idempotency (idempotency_key),
            UNIQUE KEY uq_journal_heads_source_kind (source_type, source_id, posting_kind)
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
            op2 INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function posAccountingInventoryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
