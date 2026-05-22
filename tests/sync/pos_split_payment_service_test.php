<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_split_payment_service_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posSplitPaymentCreateSchema($conn);

    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'T1', 1, 0)");
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
            fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, isdeleted, tenant, branch
        ) VALUES (
            100, 10, 0, 1, 'table', 9, '2026-05-12', '2026-05-12',
            3, 4, 4, 51, 501, 50, 50, 0,
            50, 0, 50, 'unpaid', 'draft',
            'active', 0, 0, 0
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id, pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, stock_value, discount, plus, det_value,
            profit, fatid, fat_tybe, tenant, branch, isdeleted
        ) VALUES
            (1000, 9, 3, 100, 10, 1, 0, 2, 10, 5, 0, 0, 0, 20, 10, 100, 9, 0, 0, 0),
            (1001, 9, 3, 100, 11, 1, 0, 3, 10, 5, 0, 0, 0, 30, 15, 100, 9, 0, 0, 0)
    ");

    $service = new PosOrderMutationService();
    $split = $service->splitTablePayment($conn, [
        'order_id' => 100,
        'table_id' => 1,
        'items' => [
            ['detail_id' => 1000],
            ['detail_id' => 1001, 'qty' => 1],
        ],
        'paid_amount' => 30,
        'payment_method' => 'cash',
    ], ['user_id' => 7]);

    $newOrderId = (int) $split['data']['new_invoice_id'];
    posSplitPaymentAssert($split['success'] === true, 'split should return success envelope');
    posSplitPaymentAssert($newOrderId > 100, 'split should create child order');
    posSplitPaymentAssert($split['data']['active_order_id'] === 100, 'original should remain active after partial split');
    posSplitPaymentAssert(abs($split['data']['remaining_total'] - 20.0) < 0.0001, 'remaining total should reflect leftover line value');
    posSplitPaymentAssert(strlen((string) $split['data']['split_group_id']) === 32, 'split group id should be a 32-character hex token');

    $child = $conn->query("SELECT * FROM ot_head WHERE id = {$newOrderId}")->fetch_assoc();
    posSplitPaymentAssert((int) $child['pro_id'] === 11, 'child order should allocate next pro_id through document counter');
    posSplitPaymentAssert($child['payment_status'] === 'paid', 'child split order should be paid');
    posSplitPaymentAssert($child['order_status'] === 'completed', 'child split order should be completed');
    posSplitPaymentAssert((int) $child['parent_order_id'] === 100, 'child should link to original order');
    posSplitPaymentAssert(abs((float) $child['fat_net'] - 30.0) < 0.0001, 'child net should equal selected value');

    $moved = $conn->query("SELECT fatid, qty_out, det_value FROM fat_details WHERE id = 1000")->fetch_assoc();
    posSplitPaymentAssert((int) $moved['fatid'] === $newOrderId, 'full selected line should move to child order');

    $originalPartial = $conn->query("SELECT fatid, qty_out, det_value, profit FROM fat_details WHERE id = 1001")->fetch_assoc();
    posSplitPaymentAssert((int) $originalPartial['fatid'] === 100, 'partial selected line should remain on original');
    posSplitPaymentAssert(abs((float) $originalPartial['qty_out'] - 2.0) < 0.0001, 'partial split should reduce original quantity');
    posSplitPaymentAssert(abs((float) $originalPartial['det_value'] - 20.0) < 0.0001, 'partial split should reduce original value');
    posSplitPaymentAssert(abs((float) $originalPartial['profit'] - 10.0) < 0.0001, 'partial split should reduce original profit');

    $copied = $conn->query("SELECT qty_out, det_value, profit FROM fat_details WHERE fatid = {$newOrderId} AND item_id = 11")->fetch_assoc();
    posSplitPaymentAssert(abs((float) $copied['qty_out'] - 1.0) < 0.0001, 'partial split should copy requested quantity to child');
    posSplitPaymentAssert(abs((float) $copied['det_value'] - 10.0) < 0.0001, 'partial split should copy proportional value to child');

    $original = $conn->query("SELECT payment_status, order_status, fat_net, remaining_amount FROM ot_head WHERE id = 100")->fetch_assoc();
    posSplitPaymentAssert($original['payment_status'] === 'unpaid', 'original remains unpaid when no prior payment exists');
    posSplitPaymentAssert($original['order_status'] === 'active', 'original remains active with remaining lines');
    posSplitPaymentAssert(abs((float) $original['fat_net'] - 20.0) < 0.0001, 'original total should be recalculated from remaining lines');
    posSplitPaymentAssert(abs((float) $original['remaining_amount'] - 20.0) < 0.0001, 'original remaining should match recalculated net');
    posSplitPaymentAssert((int) $conn->query("SELECT table_case FROM tables WHERE id = 1")->fetch_assoc()['table_case'] === 1, 'table should stay occupied while original remains active');

    $payment = $conn->query("SELECT * FROM order_payments WHERE order_id = {$newOrderId}")->fetch_assoc();
    posSplitPaymentAssert(abs((float) $payment['amount'] - 30.0) < 0.0001, 'child payment row should be inserted');

    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (2, 'T2', 1, 0)");
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
            fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, isdeleted, tenant, branch
        ) VALUES (
            200, 12, 0, 2, 'table', 9, '2026-05-12', '2026-05-12',
            3, 4, 4, 51, 501, 10, 10, 0,
            10, 0, 10, 'unpaid', 'draft',
            'active', 0, 0, 0
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id, pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, stock_value, discount, plus, det_value,
            profit, fatid, fat_tybe, tenant, branch, isdeleted
        ) VALUES
            (2000, 9, 3, 200, 12, 1, 0, 3, 3.3333, 1, 0, 0, 0, 10, 7, 200, 9, 0, 0, 0)
    ");

    $roundingSplit = $service->splitTablePayment($conn, [
        'order_id' => 200,
        'table_id' => 2,
        'items' => [
            ['detail_id' => 2000, 'qty' => 1],
        ],
        'paid_amount' => 3.33,
        'payment_method' => 'cash',
    ], ['user_id' => 7]);
    posSplitPaymentAssert($roundingSplit['success'] === true, 'split should accept cashier cent-rounded partial item amounts');

    echo "pos-split-payment-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posSplitPaymentCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(255) NULL,
            table_case INT NOT NULL DEFAULT 0,
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
            pro_id INT NULL,
            branch_id INT NULL,
            table_id INT NULL,
            order_type VARCHAR(40) NULL,
            pro_tybe INT NULL,
            pro_date DATE NULL,
            accural_date DATE NULL,
            store_id INT NULL,
            emp_id INT NULL,
            emp2_id INT NULL,
            acc1 INT NULL,
            acc2 INT NULL,
            pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
            fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_status VARCHAR(40) NULL,
            invoice_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            payment_method VARCHAR(50) NULL,
            payment_date DATETIME NULL,
            completed_at DATETIME NULL,
            parent_order_id INT NULL,
            split_group_id VARCHAR(64) NULL,
            info TEXT NULL,
            user INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_tybe INT NULL,
            det_store INT NULL,
            pro_id INT NULL,
            item_id INT NULL,
            u_val DECIMAL(15,4) NOT NULL DEFAULT 1,
            qty_in DECIMAL(15,4) NOT NULL DEFAULT 0,
            qty_out DECIMAL(15,4) NOT NULL DEFAULT 0,
            price DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            stock_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            discount DECIMAL(15,4) NOT NULL DEFAULT 0,
            plus DECIMAL(15,4) NOT NULL DEFAULT 0,
            det_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            profit DECIMAL(15,4) NOT NULL DEFAULT 0,
            fatid INT NOT NULL,
            fat_tybe INT NULL,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            payment_method VARCHAR(50) NULL,
            created_by INT NULL,
            created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function posSplitPaymentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
