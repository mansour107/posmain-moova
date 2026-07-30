<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_drawer_cash_flow_' . getmypid();
$previousRequireOpenShift = getenv('POSMAIN_REQUIRE_OPEN_SHIFT');
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "drawer-cash-flow-integration-skipped-db-unavailable\n";
    exit(0);
}

putenv('POSMAIN_REQUIRE_OPEN_SHIFT=1');
putenv('POSMAIN_RECIPE_MODE=off');

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    drawerCashFlowIntegrationCreateLegacyTables($conn);
    (new SyncSchemaManager())->apply($conn);
    drawerCashFlowIntegrationSeedTables($conn);

    $paymentMethods = new PaymentMethodService();
    $paymentMethods->saveMethod($conn, [
        'code' => 'cash_drawer',
        'name_ar' => 'Cash drawer',
        'name_en' => 'Cash drawer',
        'type' => 'cash',
        'account_id' => 51,
        'settlement_policy' => 'cash_drawer',
    ]);
    $paymentMethods->saveMethod($conn, [
        'code' => 'card_terminal',
        'name_ar' => 'Card terminal',
        'name_en' => 'Card terminal',
        'type' => 'card',
        'account_id' => 61,
        'settlement_policy' => 'reference_required',
        'sort_order' => 1,
    ]);

    drawerCashFlowIntegrationSeedRefundSchema($conn);

    $drawer = new DrawerSessionService();
    $session = $drawer->openSession($conn, [
        'user_id' => 7,
        'opened_by' => 7,
        'tenant' => 2,
        'branch' => 3,
        'opening_cash' => '100.00',
    ]);
    $service = new PosOrderMutationService();
    $payments = new PaymentService();
    $context = [
        'user_id' => 7,
        'tenant' => 2,
        'branch' => 3,
        'skip_idempotency' => true,
        'idempotency_key' => 'drawer-cash-flow-table-100',
    ];

    $tableCash = $service->payTableOrder($conn, [
        'table_id' => 1,
        'order_id' => 100,
        'paid' => '100.00',
        'payment_method' => 'cash_drawer',
        'mutation_version' => 1,
        'idempotency_key' => 'drawer-cash-flow-table-100',
    ], $context);
    drawerCashFlowIntegrationAssert($tableCash['success'] === true, 'table cash payment should succeed');
    drawerCashFlowIntegrationAssert($drawer->expectedCash($conn, (int) $session['id']) === '200.00', 'expected cash should include opening and table cash sale');

    $payments->recordCollectedOrderPayments(
        $conn,
        150,
        '35.00',
        '18.00',
        7,
        array_merge($context, ['idempotency_key' => 'drawer-cash-flow-legacy-150']),
        'legacy_mixed_payment'
    );
    drawerCashFlowIntegrationAssert(drawerCashFlowIntegrationNetCashForOrder($conn, 150) === '35.00', 'legacy mixed path should record only cash in drawer');
    drawerCashFlowIntegrationAssert(drawerCashFlowIntegrationOrderPaymentTotal($conn, 150, 'bank') === '18.00', 'legacy mixed path should record bank payment row');
    drawerCashFlowIntegrationAssert($drawer->expectedCash($conn, (int) $session['id']) === '235.00', 'expected cash should include legacy cash portion');

    $overpay = $service->payTableOrder($conn, [
        'table_id' => 2,
        'order_id' => 101,
        'paid' => '70.00',
        'payment_method' => 'cash_drawer',
        'mutation_version' => 1,
        'idempotency_key' => 'drawer-cash-flow-table-101',
    ], $context);
    drawerCashFlowIntegrationAssert($overpay['data']['applied_amount'] === '50.00', 'overpay should apply remaining amount only');
    $movement = drawerCashFlowIntegrationLatestMovement($conn);
    drawerCashFlowIntegrationAssert(CashAmount::normalize($movement['amount']) === '50.00', 'overpay drawer movement should use applied amount only');
    drawerCashFlowIntegrationAssert($drawer->expectedCash($conn, (int) $session['id']) === '285.00', 'expected cash should include partial overpay applied amount');

    drawerCashFlowIntegrationSeedPaidOrderForRefund($conn, 501, '40.00');
    $payments->recordCollectedOrderPayments(
        $conn,
        501,
        '40.00',
        '0.00',
        7,
        array_merge($context, ['idempotency_key' => 'drawer-cash-flow-sale-501']),
        'refund_seed_sale'
    );
    drawerCashFlowIntegrationAssert($drawer->expectedCash($conn, (int) $session['id']) === '325.00', 'expected cash should include seeded sale before refund');
    $payments->recordCashRefundMovementForPayment($conn, '40.00', 501, 7, array_merge($context, [
        'drawer_reason' => 'drawer_refund_test',
        'idempotency_key' => 'drawer-cash-flow-refund-501',
    ]));
    drawerCashFlowIntegrationAssert(drawerCashFlowIntegrationMovementCount($conn, 'refund_cash', 501) === 1, 'refund should record refund_cash');
    drawerCashFlowIntegrationAssert($drawer->expectedCash($conn, (int) $session['id']) === '285.00', 'expected cash should return to pre-refund-sale total after refund');

    echo "drawer-cash-flow-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
    if ($previousRequireOpenShift === false) {
        putenv('POSMAIN_REQUIRE_OPEN_SHIFT');
    } else {
        putenv('POSMAIN_REQUIRE_OPEN_SHIFT=' . $previousRequireOpenShift);
    }
}

function drawerCashFlowIntegrationCreateLegacyTables(mysqli $conn): void
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
            info TEXT NULL,
            user INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancellation_reason VARCHAR(255) NULL,
            updated_by INT NULL,
            mdtime DATETIME NULL,
            op2 INT NULL
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
            discount DECIMAL(15,4) NOT NULL DEFAULT 0,
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
    $conn->query("
        CREATE TABLE journal_heads (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            total DECIMAL(15,4) NOT NULL DEFAULT 0,
            jdate DATE NULL,
            details VARCHAR(255) NULL,
            user INT NULL,
            op_id INT NULL,
            op2 INT NULL
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
            op2 INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            edit_pass VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
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
}

function drawerCashFlowIntegrationEnsureSecurityTables(mysqli $conn): void
{
    if ($conn->query("SHOW TABLES LIKE 'usr_pwrs'")->num_rows < 1) {
        $conn->query("
            CREATE TABLE usr_pwrs (
                id INT NOT NULL PRIMARY KEY,
                rollname VARCHAR(120) NULL,
                edit_sales TINYINT(1) NOT NULL DEFAULT 0,
                add_sales TINYINT(1) NOT NULL DEFAULT 0,
                add_payment TINYINT(1) NOT NULL DEFAULT 0,
                show_payment TINYINT(1) NOT NULL DEFAULT 0,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $conn->query("INSERT INTO usr_pwrs (id, rollname, edit_sales, add_sales, add_payment, show_payment, isdeleted) VALUES (1, 'Admin', 1, 1, 1, 1, 0)");
    }

    if ($conn->query("SHOW TABLES LIKE 'users'")->num_rows < 1) {
        $conn->query("
            CREATE TABLE users (
                id INT NOT NULL PRIMARY KEY,
                uname VARCHAR(120) NOT NULL,
                password VARCHAR(255) NULL,
                userrole INT NULL,
                usertype INT NULL,
                isdeleted TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

function drawerCashFlowIntegrationSeedTables(mysqli $conn): void
{
    drawerCashFlowIntegrationEnsureSecurityTables($conn);
    $conn->query("INSERT INTO settings (id, def_pos_client, edit_pass, isdeleted) VALUES (1, 501, '1234', 0)");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, isdeleted) VALUES
            (91, '400001', 'Sales', 0),
            (501, '122001', 'Walk-in Customer', 0),
            (51, '101001', 'Cash Drawer', 0),
            (61, '102001', 'Bank Account', 0)
    ");
    if ($conn->query("SHOW TABLES LIKE 'users'")->num_rows > 0) {
        $conn->query("INSERT INTO users (id, uname, password, userrole, usertype, isdeleted) VALUES (7, 'drawer_cashier', 'x', 1, 2, 0) ON DUPLICATE KEY UPDATE userrole = 1");
    }
    $conn->query("
        INSERT INTO tables (id, tname, table_case, isdeleted) VALUES
        (1, 'T1', 1, 0),
        (2, 'T2', 1, 0)
    ");
    drawerCashFlowIntegrationSeedOrder($conn, 100, 1, 10, '100.00', '100.00', '0.00');
    drawerCashFlowIntegrationSeedOrder($conn, 101, 2, 11, '80.00', '80.00', '30.00');
}

function drawerCashFlowIntegrationSeedRefundSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS order_events (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            event_source VARCHAR(80) NULL,
            actor_user_id INT NULL,
            metadata_json JSON NULL,
            created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function drawerCashFlowIntegrationSeedOrder(mysqli $conn, int $id, int $tableId, int $proId, string $total, string $net, string $paid): void
{
    $remaining = Money::from($net)->subtract(Money::from($paid))->toString();
    $paymentStatus = Money::from($paid)->isPositive() ? 'partial' : 'unpaid';
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
            fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, isdeleted, tenant, branch, info
        ) VALUES (
            {$id}, {$proId}, 3, {$tableId}, 'table', 9, '2026-05-13', '2026-05-13',
            3, 4, 4, 51, 501, {$total}, {$total}, 0,
            {$net}, {$paid}, {$remaining}, '{$paymentStatus}', 'draft',
            'active', 0, 2, 3, 'drawer flow order'
        )
    ");
}

function drawerCashFlowIntegrationSeedPaidOrderForRefund(mysqli $conn, int $orderId, string $amount): void
{
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, branch_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total, fat_disc,
            fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
            order_status, isdeleted, tenant, branch, info
        ) VALUES (
            {$orderId}, 99, 3, 0, 'takeaway', 9, '2026-05-13', '2026-05-13',
            3, 4, 4, 51, 501, {$amount}, {$amount}, 0,
            {$amount}, {$amount}, 0, 'paid', 'completed',
            'completed', 0, 2, 3, 'refund seed order'
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, discount, det_value, profit, fatid, fat_tybe, tenant, branch, isdeleted
        ) VALUES
            (9, 3, {$orderId}, 20, 1, 0, 1, {$amount}, 5, 0, {$amount}, 0, {$orderId}, 9, 2, 3, 0)
    ");
}

function drawerCashFlowIntegrationMovementCount(mysqli $conn, string $type, int $orderId): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM drawer_movements WHERE movement_type = ? AND order_id = ?');
    $stmt->bind_param('si', $type, $orderId);
    $stmt->execute();
    $count = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();

    return $count;
}

function drawerCashFlowIntegrationLatestMovement(mysqli $conn): array
{
    $row = $conn->query('SELECT * FROM drawer_movements ORDER BY id DESC LIMIT 1')->fetch_assoc();
    if (!$row) {
        throw new RuntimeException('expected drawer movement row');
    }

    return $row;
}

function drawerCashFlowIntegrationNetCashForOrder(mysqli $conn, int $orderId): string
{
    return (new DrawerSessionService())->netCashRecordedForOrder($conn, $orderId);
}

function drawerCashFlowIntegrationOrderPaymentTotal(mysqli $conn, int $orderId, string $method): string
{
    $stmt = $conn->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM order_payments WHERE order_id = ? AND payment_method = ?');
    $stmt->bind_param('is', $orderId, $method);
    $stmt->execute();
    $total = (string) ($stmt->get_result()->fetch_assoc()['total'] ?? '0.00');
    $stmt->close();

    return CashAmount::normalize($total);
}

function drawerCashFlowIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
