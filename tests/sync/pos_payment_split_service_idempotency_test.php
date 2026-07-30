<?php

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_OFF);

// Force recipe off before any service construction so split/pay side effects stay payment-scoped.
putenv('POSMAIN_RECIPE_MODE=off');
putenv('POSMAIN_RECIPE_RESERVATIONS=0');
putenv('POSMAIN_RECIPE_CONSUMPTION=0');
putenv('POSMAIN_RECIPE_ACCOUNTING=0');
putenv('POSMAIN_RECIPE_AVAILABILITY=0');
putenv('POSMAIN_RECIPE_MOOVA_SYNC=0');
putenv('POSMAIN_RECIPE_STRICT_STOCK=0');
$_ENV['POSMAIN_RECIPE_MODE'] = 'off';
$_SERVER['POSMAIN_RECIPE_MODE'] = 'off';

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_payment_split_idemp_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "pos-payment-split-service-idempotency-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$previousRequireOpenShift = getenv('POSMAIN_REQUIRE_OPEN_SHIFT');
putenv('POSMAIN_REQUIRE_OPEN_SHIFT=0');
putenv('POSMAIN_RECIPE_MODE=off');

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    posPaymentSplitIdempotencyCreateSchema($conn);

    $service = new PosOrderMutationService();

    posPaymentSplitIdempotencyMissingKeyRequired($service, $conn);
    posPaymentSplitIdempotencyPayReplayAndConflict($service, $conn);
    posPaymentSplitIdempotencySplitReplayAndConflict($service, $conn);
    posPaymentSplitIdempotencySkipPathDoesNotTouchKeys($service, $conn);
    posPaymentSplitIdempotencySkipPathRollsBackOwnedTransaction($conn);
    posPaymentSplitIdempotencyTwoKeysAllowSecondIntentionalPartial($service, $conn);

    echo "pos-payment-split-service-idempotency-ok db={$db}\n";
} finally {
    if ($previousRequireOpenShift === false) {
        putenv('POSMAIN_REQUIRE_OPEN_SHIFT');
    } else {
        putenv('POSMAIN_REQUIRE_OPEN_SHIFT=' . $previousRequireOpenShift);
    }
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posPaymentSplitIdempotencyCreateSchema(mysqli $conn): void
{
    (new SyncSchemaManager())->apply($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            def_pos_store INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(255) NULL,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(255) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS document_counters (
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
        CREATE TABLE IF NOT EXISTS ot_head (
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
            mutation_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            payment_method VARCHAR(50) NULL,
            payment_notes TEXT NULL,
            payment_date DATETIME NULL,
            completed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancellation_reason VARCHAR(255) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            parent_order_id INT NULL,
            split_group_id VARCHAR(64) NULL,
            uuid CHAR(36) NULL,
            op2 INT NULL,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0,
            info TEXT NULL,
            user INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS fat_details (
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
            fatid INT NULL,
            fat_tybe INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL,
            note TEXT NULL,
            stock_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            plus DECIMAL(15,4) NOT NULL DEFAULT 0,
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(15,4) NOT NULL DEFAULT 0,
            tendered_amount DECIMAL(19,2) NULL,
            applied_amount DECIMAL(19,2) NULL,
            change_due DECIMAL(19,2) NULL,
            payment_method VARCHAR(50) NULL,
            reference_no VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME NULL,
            uuid CHAR(36) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS myitems (
            id INT NOT NULL PRIMARY KEY,
            iname VARCHAR(255) NULL,
            barcode VARCHAR(80) NULL,
            itmqty DECIMAL(15,4) NOT NULL DEFAULT 0,
            cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
            price1 DECIMAL(15,4) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("INSERT INTO settings (id, def_pos_client, def_pos_store, isdeleted) VALUES (1, 501, 3, 0)");
    $conn->query("INSERT INTO acc_head (id, code, aname, is_stock, isdeleted) VALUES (501, '122001', 'Customer', 0, 0), (51, '101001', 'Cash', 0, 0), (3, 'ST001', 'Main Store', 1, 0)");
    $conn->query("INSERT INTO myitems (id, iname, barcode, itmqty, cost_price, price1, isdeleted) VALUES (10, 'Coffee', 'C10', 50, 4, 10, 0), (11, 'Cake', 'C11', 50, 2, 15, 0)");

    $paymentMethods = new PaymentMethodService();
    $paymentMethods->saveMethod($conn, [
        'code' => 'cash',
        'name_ar' => 'Cash',
        'name_en' => 'Cash',
        'type' => 'cash',
        'account_id' => 51,
    ]);
    $paymentMethods->saveMethod($conn, [
        'code' => 'card',
        'name_ar' => 'Card',
        'name_en' => 'Card',
        'type' => 'card',
        'account_id' => 51,
        'requires_reference' => true,
        'sort_order' => 1,
    ]);
}

function posPaymentSplitIdempotencySeedOpenOrder(mysqli $conn, int $tableId, int $orderId, float $net, int $detailId, int $itemId = 10, float $qty = 1.0): array
{
    $conn->query("DELETE FROM drawer_movements");
    $conn->query("DELETE FROM drawer_sessions");
    $conn->query("DELETE FROM order_payments");
    $conn->query("DELETE FROM fat_details");
    $conn->query("DELETE FROM ot_head");
    $conn->query("DELETE FROM tables");
    $conn->query("DELETE FROM pos_request_keys");
    $conn->query("DELETE FROM document_counters");

    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES ({$tableId}, 'T{$tableId}', 1, 0)");
    $unit = $qty > 0 ? ($net / $qty) : $net;
    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, table_id, order_type, pro_tybe, pro_date, accural_date,
            store_id, emp_id, emp2_id, acc1, acc2, pro_value, fat_total,
            fat_disc, fat_net, paid_amount, remaining_amount, payment_status,
            invoice_status, order_status, isdeleted, tenant, branch
        ) VALUES (
            {$orderId}, {$orderId}, {$tableId}, 'table', 9, '2026-05-12', '2026-05-12',
            3, 4, 4, 51, 501, {$net}, {$net},
            0, {$net}, 0, {$net}, 'unpaid',
            'draft', 'active', 0, 0, 0
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id, pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, discount, det_value, profit, fatid, fat_tybe, isdeleted, tenant, branch
        ) VALUES (
            {$detailId}, 9, 3, {$orderId}, {$itemId}, 1, 0, {$qty},
            {$unit}, 4, 0, {$net}, 0, {$orderId}, 9, 0, 0, 0
        )
    ");

    $drawer = new DrawerSessionService();
    $session = $drawer->openSession($conn, [
        'user_id' => 7,
        'opened_by' => 7,
        'tenant' => 0,
        'branch' => 0,
        'opening_cash' => '100.000',
    ]);

    return $session;
}


function posPaymentSplitIdempotencyVersion(mysqli $conn, int $orderId): int
{
    $row = $conn->query('SELECT mutation_version FROM ot_head WHERE id = ' . (int) $orderId)->fetch_assoc();
    return max(1, (int) ($row['mutation_version'] ?? 1));
}

function posPaymentSplitIdempotencyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function posPaymentSplitIdempotencyMissingKeyRequired(PosOrderMutationService $service, mysqli $conn): void
{
    posPaymentSplitIdempotencySeedOpenOrder($conn, 1, 10, 100.0, 1001);
    try {
        $service->payTableOrder($conn, [
            'table_id' => 1,
            'order_id' => 10,
            'paid' => 40,
            'payment_method' => 'cash',
        ], ['user_id' => 7]);
        posPaymentSplitIdempotencyAssert(false, 'missing payment key should fail closed');
    } catch (InvalidArgumentException $exception) {
        posPaymentSplitIdempotencyAssert($exception->getMessage() === 'IDEMPOTENCY_REQUIRED', 'missing payment key should throw IDEMPOTENCY_REQUIRED');
    }

    try {
        $service->splitTablePayment($conn, [
            'table_id' => 1,
            'order_id' => 10,
            'paid_amount' => 50,
            'payment_method' => 'cash',
            'items' => [['detail_id' => 1001, 'qty' => 1]],
        ], ['user_id' => 7]);
        posPaymentSplitIdempotencyAssert(false, 'missing split key should fail closed');
    } catch (InvalidArgumentException $exception) {
        posPaymentSplitIdempotencyAssert($exception->getMessage() === 'IDEMPOTENCY_REQUIRED', 'missing split key should throw IDEMPOTENCY_REQUIRED');
    }
}

function posPaymentSplitIdempotencyPayReplayAndConflict(PosOrderMutationService $service, mysqli $conn): void
{
    posPaymentSplitIdempotencySeedOpenOrder($conn, 1, 20, 100.0, 2001);
    $request = [
        'table_id' => 1,
        'order_id' => 20,
        'paid' => 40,
        'payment_method' => 'cash',
        'notes' => 'idempotent partial',
        'mutation_version' => posPaymentSplitIdempotencyVersion($conn, 20),
        'idempotency_key' => 'phpunit:table-pay:1',
    ];
    $context = ['user_id' => 7, 'tenant' => 0, 'branch' => 0];

    $first = $service->payTableOrder($conn, $request, $context);
    posPaymentSplitIdempotencyAssert(($first['success'] ?? false) === true, 'first payment should succeed');
    posPaymentSplitIdempotencyAssert(($first['data']['payment_status'] ?? '') === 'partial', 'first payment should be partial');
    posPaymentSplitIdempotencyAssert(empty($first['idempotency_replayed']), 'first payment is not a replay');

    $replay = $service->payTableOrder($conn, $request, $context);
    posPaymentSplitIdempotencyAssert(($replay['idempotency_replayed'] ?? false) === true, 'same key/hash payment should replay');
    posPaymentSplitIdempotencyAssert((int) ($replay['data']['order_id'] ?? 0) === 20, 'payment replay preserves order id');
    posPaymentSplitIdempotencyAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM order_payments WHERE order_id = 20')->fetch_assoc()['c'] === 1,
        'payment replay must not create a second payment row'
    );
    posPaymentSplitIdempotencyAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM pos_request_keys WHERE scope = 'pos.payment.table' AND idempotency_key = 'phpunit:table-pay:1' AND status = 'completed'")->fetch_assoc()['c'] === 1,
        'payment replay keeps one completed key row'
    );

    $conflict = $request;
    $conflict['paid'] = 41;
    try {
        $service->payTableOrder($conn, $conflict, $context);
        posPaymentSplitIdempotencyAssert(false, 'same key different payment payload should conflict');
    } catch (RuntimeException $exception) {
        posPaymentSplitIdempotencyAssert($exception->getMessage() === 'IDEMPOTENCY_CONFLICT', 'payment conflict code expected');
    }
}

function posPaymentSplitIdempotencySplitReplayAndConflict(PosOrderMutationService $service, mysqli $conn): void
{
    posPaymentSplitIdempotencySeedOpenOrder($conn, 2, 30, 30.0, 3001, 11, 2.0);
    $request = [
        'table_id' => 2,
        'order_id' => 30,
        'paid_amount' => 15,
        'payment_method' => 'cash',
        'items' => [
            ['detail_id' => 3001, 'qty' => 1],
        ],
        'mutation_version' => posPaymentSplitIdempotencyVersion($conn, 30),
        'idempotency_key' => 'phpunit:table-split:1',
    ];
    $context = ['user_id' => 7, 'tenant' => 0, 'branch' => 0];

    $first = $service->splitTablePayment($conn, $request, $context);
    posPaymentSplitIdempotencyAssert(($first['success'] ?? false) === true, 'first split should succeed');
    $childOrderId = (int) ($first['data']['new_invoice_id'] ?? $first['data']['order_id'] ?? 0);
    posPaymentSplitIdempotencyAssert($childOrderId > 0, 'split should create child invoice');
    posPaymentSplitIdempotencyAssert(empty($first['idempotency_replayed']), 'first split is not a replay');

    $ordersBefore = (int) $conn->query('SELECT COUNT(*) AS c FROM ot_head')->fetch_assoc()['c'];
    $paymentsBefore = (int) $conn->query('SELECT COUNT(*) AS c FROM order_payments')->fetch_assoc()['c'];

    $replay = $service->splitTablePayment($conn, $request, $context);
    posPaymentSplitIdempotencyAssert(($replay['idempotency_replayed'] ?? false) === true, 'same key/hash split should replay');
    posPaymentSplitIdempotencyAssert(
        (int) ($replay['data']['new_invoice_id'] ?? $replay['data']['order_id'] ?? 0) === $childOrderId,
        'split replay preserves child invoice id'
    );
    posPaymentSplitIdempotencyAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM ot_head')->fetch_assoc()['c'] === $ordersBefore,
        'split replay must not create another child order'
    );
    posPaymentSplitIdempotencyAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM order_payments')->fetch_assoc()['c'] === $paymentsBefore,
        'split replay must not create another payment row'
    );
    posPaymentSplitIdempotencyAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM pos_request_keys WHERE scope = 'pos.payment.split' AND idempotency_key = 'phpunit:table-split:1' AND status = 'completed'")->fetch_assoc()['c'] === 1,
        'split replay keeps one completed key row'
    );

    $conflict = $request;
    $conflict['paid_amount'] = 16;
    try {
        $service->splitTablePayment($conn, $conflict, $context);
        posPaymentSplitIdempotencyAssert(false, 'same key different split payload should conflict');
    } catch (RuntimeException $exception) {
        posPaymentSplitIdempotencyAssert($exception->getMessage() === 'IDEMPOTENCY_CONFLICT', 'split conflict code expected');
    }
}

function posPaymentSplitIdempotencySkipPathDoesNotTouchKeys(PosOrderMutationService $service, mysqli $conn): void
{
    posPaymentSplitIdempotencySeedOpenOrder($conn, 3, 40, 80.0, 4001);
    $before = (int) $conn->query('SELECT COUNT(*) AS c FROM pos_request_keys')->fetch_assoc()['c'];
    $context = [
        'user_id' => 7,
        'tenant' => 0,
        'branch' => 0,
        'skip_idempotency' => true,
        'in_transaction' => false,
    ];

    $partial = $service->payTableOrder($conn, [
        'table_id' => 3,
        'order_id' => 40,
        'paid' => 20,
        'payment_method' => 'cash',
        'mutation_version' => posPaymentSplitIdempotencyVersion($conn, 40),
        'idempotency_key' => 'phpunit:skip-should-not-write',
    ], $context);
    posPaymentSplitIdempotencyAssert(($partial['success'] ?? false) === true, 'skip path payment should succeed');
    posPaymentSplitIdempotencyAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM pos_request_keys')->fetch_assoc()['c'] === $before,
        'controller skip path must not write pos_request_keys'
    );
    posPaymentSplitIdempotencyAssert(empty($partial['idempotency_replayed']), 'skip path is not a service replay');

    // A second call with the same key under skip still mutates domain state (outer controller owns uniqueness).
    $second = $service->payTableOrder($conn, [
        'table_id' => 3,
        'order_id' => 40,
        'paid' => 20,
        'payment_method' => 'cash',
        'mutation_version' => posPaymentSplitIdempotencyVersion($conn, 40),
        'idempotency_key' => 'phpunit:skip-should-not-write',
    ], $context);
    posPaymentSplitIdempotencyAssert(($second['success'] ?? false) === true, 'skip path second payment should apply domain payment');
    posPaymentSplitIdempotencyAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM order_payments WHERE order_id = 40')->fetch_assoc()['c'] === 2,
        'skip path intentionally allows multi-call domain behavior for outer-owned keys'
    );
    posPaymentSplitIdempotencyAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM pos_request_keys')->fetch_assoc()['c'] === $before,
        'skip path still does not complete or begin request keys'
    );
}

function posPaymentSplitIdempotencyTwoKeysAllowSecondIntentionalPartial(PosOrderMutationService $service, mysqli $conn): void
{
    posPaymentSplitIdempotencySeedOpenOrder($conn, 4, 50, 100.0, 5001);
    $context = ['user_id' => 7, 'tenant' => 0, 'branch' => 0];

    $first = $service->payTableOrder($conn, [
        'table_id' => 4,
        'order_id' => 50,
        'paid' => 30,
        'payment_method' => 'cash',
        'mutation_version' => posPaymentSplitIdempotencyVersion($conn, 50),
        'idempotency_key' => 'phpunit:table-pay:partial-a',
    ], $context);
    posPaymentSplitIdempotencyAssert(($first['data']['payment_status'] ?? '') === 'partial', 'first intentional partial expected');

    $second = $service->payTableOrder($conn, [
        'table_id' => 4,
        'order_id' => 50,
        'paid' => 70,
        'payment_method' => 'cash',
        'mutation_version' => posPaymentSplitIdempotencyVersion($conn, 50),
        'idempotency_key' => 'phpunit:table-pay:partial-b',
    ], $context);
    posPaymentSplitIdempotencyAssert(($second['data']['payment_status'] ?? '') === 'paid', 'second key may complete remaining balance');
    posPaymentSplitIdempotencyAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM order_payments WHERE order_id = 50')->fetch_assoc()['c'] === 2,
        'two intentional partials with distinct keys create two payment rows'
    );
    posPaymentSplitIdempotencyAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM pos_request_keys WHERE scope = 'pos.payment.table' AND status = 'completed'")->fetch_assoc()['c'] === 2,
        'two intentional keys complete two idempotency rows'
    );
}

function posPaymentSplitIdempotencySkipPathRollsBackOwnedTransaction(mysqli $conn): void
{
    $conn->query('CREATE TABLE IF NOT EXISTS payment_transaction_probe (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
    $conn->query('TRUNCATE TABLE payment_transaction_probe');

    $failingPaymentService = new class extends PaymentService {
        public function payTableOrder(mysqli $conn, array $request, array $context = []): array
        {
            $conn->query('INSERT INTO payment_transaction_probe VALUES (NULL)');
            throw new RuntimeException('PAYMENT_PROBE_FAILURE');
        }
    };
    $service = new PosOrderMutationService($failingPaymentService);

    posPaymentSplitIdempotencySeedOpenOrder($conn, 5, 99, 10.0, 9901);
    try {
        $service->payTableOrder($conn, [
            'table_id' => 5,
            'order_id' => 99,
            'paid' => 1,
            'payment_method' => 'cash',
            'mutation_version' => posPaymentSplitIdempotencyVersion($conn, 99),
        ], [
            'user_id' => 7,
            'skip_idempotency' => true,
            'in_transaction' => false,
        ]);
        posPaymentSplitIdempotencyAssert(false, 'failing skip payment should throw');
    } catch (RuntimeException $exception) {
        posPaymentSplitIdempotencyAssert($exception->getMessage() === 'PAYMENT_PROBE_FAILURE', 'probe failure should propagate');
    }

    posPaymentSplitIdempotencyAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM payment_transaction_probe')->fetch_assoc()['c'] === 0,
        'skip payment must roll back mutations when it owns the transaction'
    );
}
