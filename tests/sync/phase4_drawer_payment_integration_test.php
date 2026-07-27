<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_drawer_payment_' . getmypid();
$previousRequireOpenShift = getenv('POSMAIN_REQUIRE_OPEN_SHIFT');
$conn = new mysqli($host, $user, $pass, '', $port);

putenv('POSMAIN_REQUIRE_OPEN_SHIFT=1');

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    phase4DrawerPaymentCreateLegacyTables($conn);
    (new SyncSchemaManager())->apply($conn);

    $paymentMethods = new PaymentMethodService();
    $paymentMethods->saveMethod($conn, [
        'code' => 'cash_drawer',
        'name_ar' => 'Cash drawer',
        'name_en' => 'Cash drawer',
        'type' => 'cash',
        'account_id' => 51,
    ]);
    $paymentMethods->saveMethod($conn, [
        'code' => 'card_terminal',
        'name_ar' => 'Card terminal',
        'name_en' => 'Card terminal',
        'type' => 'card',
        'account_id' => 52,
        'requires_reference' => true,
        'sort_order' => 1,
    ]);

    phase4DrawerPaymentSeedTables($conn);
    $drawer = new DrawerSessionService();
    $session = $drawer->openSession($conn, [
        'user_id' => 7,
        'opened_by' => 7,
        'tenant' => 2,
        'branch' => 3,
        'opening_cash' => '100.000',
    ]);
    $service = new PosOrderMutationService();
    $context = ['user_id' => 7, 'tenant' => 2, 'branch' => 3, 'skip_idempotency' => true];

    $full = $service->payTableOrder($conn, [
        'table_id' => 1,
        'order_id' => 100,
        'paid' => 100,
        'payment_method' => 'cash_drawer',
        'notes' => 'full cash',
    ], $context);
    phase4DrawerPaymentAssert($full['success'] === true, 'full cash payment should succeed');
    phase4DrawerPaymentAssert($full['data']['payment_status'] === 'paid', 'full cash payment should mark paid');
    phase4DrawerPaymentAssert(abs((float) $full['data']['applied_amount'] - 100.0) < 0.0001, 'full cash applied amount expected');
    phase4DrawerPaymentAssert(phase4DrawerPaymentMovementCount($conn) === 1, 'full cash should record one drawer movement');
    $movement = phase4DrawerPaymentLatestMovement($conn);
    phase4DrawerPaymentAssert($movement['movement_type'] === 'sale_cash', 'full cash movement type expected');
    phase4DrawerPaymentAssert((int) $movement['order_id'] === 100, 'full cash movement order id expected');
    phase4DrawerPaymentAssert(abs((float) $movement['amount'] - 100.0) < 0.0001, 'full cash movement amount expected');
    phase4DrawerPaymentAssert((int) $movement['drawer_session_id'] === (int) $session['id'], 'full cash movement session expected');
    phase4DrawerPaymentAssert(phase4DrawerPaymentOrderPaymentCount($conn, 100) === 1, 'full cash order_payments row expected');

    $overpay = $service->payTableOrder($conn, [
        'table_id' => 2,
        'order_id' => 101,
        'paid' => 70,
        'payment_method' => 'cash_drawer',
        'notes' => 'existing partial overpay',
    ], $context);
    phase4DrawerPaymentAssert($overpay['data']['payment_status'] === 'paid', 'overpay should complete partial order');
    phase4DrawerPaymentAssert(abs((float) $overpay['data']['applied_amount'] - 50.0) < 0.0001, 'overpay should apply only remaining amount');
    $movement = phase4DrawerPaymentLatestMovement($conn);
    phase4DrawerPaymentAssert(abs((float) $movement['amount'] - 50.0) < 0.0001, 'overpay drawer movement should use applied amount only');
    $payment = phase4DrawerPaymentLatestOrderPayment($conn, 101);
    phase4DrawerPaymentAssert(abs((float) $payment['amount'] - 50.0) < 0.0001, 'overpay order_payments amount should use applied amount only');

    $card = $service->payTableOrder($conn, [
        'table_id' => 3,
        'order_id' => 102,
        'paid' => 60,
        'payment_method' => 'card_terminal',
        'notes' => 'card payment',
    ], $context);
    phase4DrawerPaymentAssert($card['data']['payment_status'] === 'paid', 'card payment should still mark paid');
    phase4DrawerPaymentAssert(phase4DrawerPaymentMovementCount($conn) === 2, 'card payment should not record a drawer movement');
    phase4DrawerPaymentAssert(phase4DrawerPaymentOrderPaymentCount($conn, 102) === 1, 'card order_payments row should remain present');

    phase4DrawerPaymentExpectException(function () use ($service, $conn) {
        $service->payTableOrder($conn, [
            'table_id' => 4,
            'order_id' => 103,
            'paid' => 10,
            'payment_method' => 'cash_drawer',
            'notes' => 'blocked without drawer',
        ], ['user_id' => 8, 'tenant' => 2, 'branch' => 3, 'skip_idempotency' => true]);
    }, 'DRAWER_SESSION_REQUIRED');
    $blocked = $conn->query("SELECT payment_status, paid_amount, remaining_amount FROM ot_head WHERE id = 103")->fetch_assoc();
    phase4DrawerPaymentAssert($blocked['payment_status'] === 'unpaid', 'blocked cash payment should not mutate status');
    phase4DrawerPaymentAssert(abs((float) $blocked['paid_amount']) < 0.0001, 'blocked cash payment should not mutate paid amount');
    phase4DrawerPaymentAssert(abs((float) $blocked['remaining_amount'] - 40.0) < 0.0001, 'blocked cash payment should preserve remaining amount');
    phase4DrawerPaymentAssert(phase4DrawerPaymentMovementCount($conn) === 2, 'blocked cash payment should not record movement');
    phase4DrawerPaymentAssert(phase4DrawerPaymentOrderPaymentCount($conn, 103) === 0, 'blocked cash payment should not insert order payment');

    $split = $service->splitTablePayment($conn, [
        'order_id' => 200,
        'table_id' => 5,
        'items' => [
            ['detail_id' => 2000],
            ['detail_id' => 2001, 'qty' => 1],
        ],
        'paid_amount' => 30,
        'payment_method' => 'cash_drawer',
    ], $context);
    $childOrderId = (int) $split['data']['new_invoice_id'];
    phase4DrawerPaymentAssert($childOrderId > 0, 'split should create child order');
    phase4DrawerPaymentAssert(abs((float) $split['data']['paid_amount'] - 30.0) < 0.0001, 'split paid amount should match child total');
    phase4DrawerPaymentAssert(abs((float) $split['data']['remaining_total'] - 20.0) < 0.0001, 'split remaining total should preserve original behavior');
    phase4DrawerPaymentAssert(phase4DrawerPaymentMovementCount($conn) === 3, 'split cash should record one drawer movement');
    $movement = phase4DrawerPaymentLatestMovement($conn);
    phase4DrawerPaymentAssert((int) $movement['order_id'] === $childOrderId, 'split movement should point at child order');
    phase4DrawerPaymentAssert(abs((float) $movement['amount'] - 30.0) < 0.0001, 'split movement amount should match child paid amount');
    $splitPayment = phase4DrawerPaymentLatestOrderPayment($conn, $childOrderId);
    phase4DrawerPaymentAssert((int) $movement['payment_id'] === (int) $splitPayment['id'], 'split movement should link to order payment row');
    phase4DrawerPaymentAssert(abs((float) $splitPayment['amount'] - 30.0) < 0.0001, 'split order_payments amount expected');
    $original = $conn->query("SELECT payment_status, order_status, remaining_amount FROM ot_head WHERE id = 200")->fetch_assoc();
    phase4DrawerPaymentAssert($original['payment_status'] === 'unpaid', 'split original payment status should remain unchanged');
    phase4DrawerPaymentAssert($original['order_status'] === 'active', 'split original order should remain active');
    phase4DrawerPaymentAssert(abs((float) $original['remaining_amount'] - 20.0) < 0.0001, 'split original remaining amount expected');

    echo "phase4-drawer-payment-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
    if ($previousRequireOpenShift === false) {
        putenv('POSMAIN_REQUIRE_OPEN_SHIFT');
    } else {
        putenv('POSMAIN_REQUIRE_OPEN_SHIFT=' . $previousRequireOpenShift);
    }
}

function phase4DrawerPaymentCreateLegacyTables(mysqli $conn): void
{
    // The split lifecycle is scoped to the configured single operational store.
    // Keep this fixture aligned with a newly bootstrapped shop instead of relying
    // on an implicit or missing legacy settings row.
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_store INT NULL,
            def_pos_employee INT NULL,
            def_pos_fund INT NULL,
            def_pos_client INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(50) NULL,
            aname VARCHAR(255) NULL,
            parent_id INT NOT NULL DEFAULT 0,
            is_basic TINYINT(1) NOT NULL DEFAULT 0,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            is_fund TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, is_stock, is_fund, isdeleted)
        VALUES
            (3, '123001', 'Main store', 1, 0, 0),
            (51, '111001', 'Cash drawer', 0, 1, 0),
            (52, '111002', 'Card clearing', 0, 1, 0),
            (501, '120001', 'POS customer', 0, 0, 0)
    ");
    $conn->query("
        INSERT INTO settings (id, def_pos_store, def_pos_employee, def_pos_fund, def_pos_client, isdeleted)
        VALUES (1, 3, 0, 51, 501, 0)
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(255) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
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
            payment_notes TEXT NULL,
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
            reference_no VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function phase4DrawerPaymentSeedTables(mysqli $conn): void
{
    $conn->query("
        INSERT INTO tables (id, tname, table_case, isdeleted) VALUES
        (1, 'T1', 1, 0),
        (2, 'T2', 1, 0),
        (3, 'T3', 1, 0),
        (4, 'T4', 1, 0),
        (5, 'T5', 1, 0)
    ");
    phase4DrawerPaymentSeedOrder($conn, 100, 1, 10, 100, 100, 0);
    phase4DrawerPaymentSeedOrder($conn, 101, 2, 11, 80, 80, 30);
    phase4DrawerPaymentSeedOrder($conn, 102, 3, 12, 60, 60, 0);
    phase4DrawerPaymentSeedOrder($conn, 103, 4, 13, 40, 40, 0);
    phase4DrawerPaymentSeedOrder($conn, 200, 5, 20, 50, 50, 0);
    $conn->query("
        INSERT INTO fat_details (
            id, pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, stock_value, discount, plus, det_value,
            profit, fatid, fat_tybe, tenant, branch, isdeleted
        ) VALUES
            (2000, 9, 3, 200, 20, 1, 0, 2, 10, 5, 0, 0, 0, 20, 10, 200, 9, 2, 3, 0),
            (2001, 9, 3, 200, 21, 1, 0, 3, 10, 5, 0, 0, 0, 30, 15, 200, 9, 2, 3, 0)
    ");
}

function phase4DrawerPaymentSeedOrder(mysqli $conn, int $id, int $tableId, int $proId, float $total, float $net, float $paid): void
{
    $remaining = max(0, $net - $paid);
    $paymentStatus = $paid > 0 ? 'partial' : 'unpaid';
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
            fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, isdeleted, tenant, branch
        ) VALUES (
            {$id}, {$proId}, 3, {$tableId}, 'table', 9, '2026-05-13', '2026-05-13',
            3, 4, 4, 51, 501, {$total}, {$total}, 0,
            {$net}, {$paid}, {$remaining}, '{$paymentStatus}', 'draft',
            'active', 0, 2, 3
        )
    ");
}

function phase4DrawerPaymentMovementCount(mysqli $conn): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE movement_type IN ('sale_cash', 'refund_cash')")->fetch_assoc()['c'];
}

function phase4DrawerPaymentLatestMovement(mysqli $conn): array
{
    $row = $conn->query("SELECT * FROM drawer_movements ORDER BY id DESC LIMIT 1")->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('expected drawer movement row');
    }

    return $row;
}

function phase4DrawerPaymentOrderPaymentCount(mysqli $conn, int $orderId): int
{
    return (int) $conn->query("SELECT COUNT(*) AS c FROM order_payments WHERE order_id = {$orderId}")->fetch_assoc()['c'];
}

function phase4DrawerPaymentLatestOrderPayment(mysqli $conn, int $orderId): array
{
    $row = $conn->query("SELECT * FROM order_payments WHERE order_id = {$orderId} ORDER BY id DESC LIMIT 1")->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('expected order payment row');
    }

    return $row;
}

function phase4DrawerPaymentExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4DrawerPaymentAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4DrawerPaymentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
