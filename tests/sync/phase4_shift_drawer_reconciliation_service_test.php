<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftDrawerReconciliationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_shift_reconcile_' . getmypid();
$legacyDb = $db . '_legacy';
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    phase4ShiftReconcileCreateLegacyTables($conn);
    (new SyncSchemaManager())->apply($conn);

    $methods = new PaymentMethodService();
    $methods->saveMethod($conn, [
        'code' => 'cash_drawer',
        'name_ar' => 'Cash drawer',
        'type' => 'cash',
    ]);
    $methods->saveMethod($conn, [
        'code' => 'card_terminal',
        'name_ar' => 'Card terminal',
        'type' => 'card',
    ]);
    $methods->saveMethod($conn, [
        'code' => 'wallet',
        'name_ar' => 'Wallet',
        'type' => 'wallet',
    ]);

    $drawer = new DrawerSessionService();
    $session = $drawer->openSession($conn, [
        'user_id' => 7,
        'opened_by' => 7,
        'tenant' => 2,
        'branch' => 3,
        'opening_cash' => '100.000',
        'opened_at' => '2026-05-13 08:00:00',
    ]);
    $drawer->recordMovement($conn, $session['id'], [
        'movement_type' => 'sale_cash',
        'amount' => '110.000',
        'order_id' => 10,
        'created_by' => 7,
    ]);
    $drawer->recordMovement($conn, $session['id'], [
        'movement_type' => 'refund_cash',
        'amount' => '2.000',
        'created_by' => 7,
    ]);
    $drawer->recordMovement($conn, $session['id'], [
        'movement_type' => 'paid_out',
        'amount' => '10.000',
        'created_by' => 7,
    ]);
    $drawer->recordMovement($conn, $session['id'], [
        'movement_type' => 'safe_drop',
        'amount' => '5.000',
        'created_by' => 7,
    ]);

    phase4ShiftReconcileSeedPayments($conn);
    $service = new ShiftDrawerReconciliationService();
    $summary = $service->buildForUser($conn, [
        'user_id' => 7,
        'tenant' => 2,
        'branch' => 3,
        'date' => '2026-05-13',
    ]);

    phase4ShiftReconcileAssert($summary['drawer_session']['id'] === $session['id'], 'open drawer session should be selected');
    phase4ShiftReconcileAssert($summary['drawer']['opening_cash'] === '100.000', 'opening cash expected');
    phase4ShiftReconcileAssert($summary['drawer']['movement_totals']['sale_cash'] === '110.000', 'sale cash movement total expected');
    phase4ShiftReconcileAssert($summary['drawer']['movement_totals']['refund_cash'] === '2.000', 'refund movement total expected');
    phase4ShiftReconcileAssert($summary['drawer']['expected_cash'] === '193.000', 'expected cash should use signed drawer movement math');
    // opening + sale_cash + refund_cash + paid_out + safe_drop
    phase4ShiftReconcileAssert($summary['drawer']['movement_count'] === 5, 'drawer movement count includes opening movement');

    phase4ShiftReconcileAssert($summary['payments']['total'] === '165.000', 'payment total expected');
    phase4ShiftReconcileAssert($summary['payments']['cash'] === '110.000', 'cash payment total expected');
    phase4ShiftReconcileAssert($summary['payments']['non_cash'] === '55.000', 'non-cash payment total expected');
    phase4ShiftReconcileAssert($summary['payments']['by_type']['card'] === '40.000', 'card payment total expected');
    phase4ShiftReconcileAssert($summary['payments']['by_type']['wallet'] === '15.000', 'wallet payment total expected');
    phase4ShiftReconcileAssert($summary['reconciliation']['cash_difference'] === '0.000', 'drawer sale cash should reconcile with cash payments');
    phase4ShiftReconcileAssert(count($summary['payments']['methods']) === 4, 'method rows should be retained');

    $specific = $service->buildForUser($conn, [
        'user_id' => 7,
        'tenant' => 2,
        'branch' => 3,
        'date' => '2026-05-13',
        'drawer_session_id' => $session['id'],
    ]);
    phase4ShiftReconcileAssert($specific['drawer_session']['id'] === $session['id'], 'explicit drawer session should be accepted');

    $conn->query("CREATE DATABASE `{$legacyDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($legacyDb);
    $legacy = $service->buildForUser($conn, [
        'user_id' => 7,
        'tenant' => 2,
        'branch' => 3,
        'date' => '2026-05-13',
    ]);
    phase4ShiftReconcileAssert($legacy['drawer_session'] === null, 'legacy DB without drawer tables should have no drawer session');
    phase4ShiftReconcileAssert($legacy['payments']['total'] === '0.000', 'legacy DB without order_payments should return zero payment total');
    phase4ShiftReconcileAssert($legacy['drawer']['expected_cash'] === '0.000', 'legacy DB without drawer tables should return zero expected cash');

    echo "phase4-shift-drawer-reconciliation-service-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$legacyDb}`");
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4ShiftReconcileCreateLegacyTables(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            table_id INT NULL,
            pro_date DATE NULL,
            payment_date DATETIME NULL,
            user INT NULL,
            pro_tybe INT NULL,
            payment_status VARCHAR(40) NULL,
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

function phase4ShiftReconcileSeedPayments(mysqli $conn): void
{
    $conn->query("
        INSERT INTO ot_head (id, pro_date, payment_date, user, pro_tybe, payment_status, isdeleted) VALUES
        (10, '2026-05-13', '2026-05-13 09:00:00', 7, 9, 'paid', 0),
        (11, '2026-05-13', '2026-05-13 09:30:00', 7, 9, 'paid', 0),
        (12, '2026-05-13', '2026-05-13 10:00:00', 7, 9, 'paid', 0),
        (13, '2026-05-13', '2026-05-13 10:30:00', 7, 9, 'paid', 0),
        (14, '2026-05-13', '2026-05-13 07:30:00', 7, 9, 'paid', 0),
        (15, '2026-05-13', '2026-05-13 11:00:00', 8, 9, 'paid', 0),
        (16, '2026-05-12', '2026-05-12 11:00:00', 7, 9, 'paid', 0)
    ");
    $conn->query("
        INSERT INTO order_payments (order_id, amount, payment_method, created_by, created_at) VALUES
        (10, 90, 'cash_drawer', 7, '2026-05-13 09:00:00'),
        (11, 40, 'card_terminal', 7, '2026-05-13 09:30:00'),
        (12, 15, 'wallet', 7, '2026-05-13 10:00:00'),
        (13, 20, 'cash', 7, '2026-05-13 10:30:00'),
        (14, 999, 'cash_drawer', 7, '2026-05-13 07:30:00'),
        (15, 500, 'cash_drawer', 8, '2026-05-13 11:00:00'),
        (16, 300, 'cash_drawer', 7, '2026-05-12 11:00:00')
    ");
}

function phase4ShiftReconcileAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
