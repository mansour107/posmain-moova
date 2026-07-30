<?php

/**
 * Commercial V1 Step 3 runtime: stale version, idempotent replay, move/merge version bumps.
 */

require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/TableTransferService.php';
require_once __DIR__ . '/../../classes/Pos/Service/TableMergeService.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderMutationVersionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_OFF);
putenv('POSMAIN_RECIPE_MODE=off');
putenv('POSMAIN_REQUIRE_OPEN_SHIFT=0');
$_ENV['POSMAIN_RECIPE_MODE'] = 'off';
$_SERVER['POSMAIN_RECIPE_MODE'] = 'off';

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_c1_step3_atomic_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "commercial-v1-step3-atomic-runtime-skipped-db-unavailable\n";
    exit(0);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function step3RuntimeAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function step3RuntimeVersion(mysqli $conn, int $orderId): int
{
    $row = $conn->query('SELECT mutation_version FROM ot_head WHERE id = ' . (int) $orderId)->fetch_assoc();
    step3RuntimeAssert(is_array($row), 'order missing for version read');

    return max(1, (int) ($row['mutation_version'] ?? 1));
}

function step3RuntimeSeedBase(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS settings (
            id INT NOT NULL PRIMARY KEY,
            def_pos_client INT NULL,
            def_pos_store INT NULL,
            def_pos_employee INT NULL,
            def_pos_fund INT NULL,
            negative_stock_sale_policy VARCHAR(64) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS acc_head (
            id INT NOT NULL PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(255) NULL,
            parent_id INT NULL,
            is_basic TINYINT(1) NOT NULL DEFAULT 0,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            is_fund TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS myitems (
            id INT NOT NULL PRIMARY KEY,
            iname VARCHAR(255) NULL,
            price1 DECIMAL(18,6) NOT NULL DEFAULT 0,
            item_type VARCHAR(40) NULL,
            track_stock TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(120) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE IF NOT EXISTS ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pro_id INT NULL,
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
            tenant INT NULL DEFAULT 0,
            branch INT NULL DEFAULT 0,
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
            is_voided TINYINT(1) NOT NULL DEFAULT 0,
            uuid CHAR(36) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    (new SyncSchemaManager())->apply($conn);

    $conn->query("INSERT INTO settings (id, def_pos_client, def_pos_store, def_pos_employee, def_pos_fund, negative_stock_sale_policy, isdeleted)
        VALUES (1, 501, 3, 4, 51, 'allow_with_warning', 0)");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund, isdeleted) VALUES
            (3, '123001', 'Main store', 0, 0, 1, 0, 0),
            (4, '213001', 'Employee 1', 35, 0, 0, 0, 0),
            (35, '213', 'Employees', 0, 1, 0, 0, 0),
            (51, '121001', 'Default fund', 0, 0, 0, 1, 0),
            (501, '122001', 'Default client', 0, 0, 0, 0, 0),
            (91, '3111', 'Sales', 0, 0, 0, 0, 0)
    ");
    $conn->query("INSERT INTO myitems (id, iname, price1, item_type, track_stock, isdeleted) VALUES (10, 'Item 10', 50, 'sellable', 0, 0)");
    $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES (1, 'T1', 1, 0), (2, 'T2', 0, 0), (3, 'T3', 1, 0), (4, 'T4', 1, 0)");

    $paymentMethods = new PaymentMethodService();
    $paymentMethods->saveMethod($conn, [
        'code' => 'cash',
        'name_ar' => 'Cash',
        'name_en' => 'Cash',
        'type' => 'cash',
        'account_id' => 51,
    ]);
    $drawer = new DrawerSessionService();
    $drawer->openSession($conn, [
        'user_id' => 7,
        'opened_by' => 7,
        'tenant' => 0,
        'branch' => 0,
        'opening_cash' => '100.000',
    ]);
}

function step3RuntimeInsertOrder(mysqli $conn, int $orderId, int $tableId, string $net, int $detailId): void
{
    $conn->query("
        INSERT INTO ot_head (
            id, table_id, pro_tybe, isdeleted, order_status, payment_status, invoice_status,
            fat_total, fat_disc, fat_net, paid_amount, remaining_amount, store_id, emp_id, acc2, mutation_version
        ) VALUES (
            {$orderId}, {$tableId}, 9, 0, 'active', 'unpaid', 'open',
            {$net}, 0, {$net}, 0, {$net}, 3, 4, 501, 1
        )
    ");
    $conn->query("
        INSERT INTO fat_details (
            id, pro_tybe, det_store, pro_id, item_id, u_val, qty_in, qty_out,
            price, cost_price, discount, det_value, profit, fatid, fat_tybe, isdeleted
        ) VALUES (
            {$detailId}, 9, 3, {$orderId}, 10, 1, 0, 1,
            {$net}, 0, 0, {$net}, 0, {$orderId}, 9, 0
        )
    ");
    $conn->query("UPDATE tables SET table_case = 1 WHERE id = {$tableId}");
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    step3RuntimeSeedBase($conn);

    $service = new PosOrderMutationService();
    $versions = new OrderMutationVersionService();

    // Legacy rows written before the version contract may contain zero. The
    // first certified mutation must normalize the durable row before asserting
    // the browser-visible version 1, otherwise every such order becomes stuck.
    step3RuntimeInsertOrder($conn, 90, 0, '5.00', 901);
    $conn->query('UPDATE ot_head SET mutation_version = 0 WHERE id = 90');
    $legacyVersion = $versions->lockAndAssert($conn, 90, 1, true);
    step3RuntimeAssert($legacyVersion === 1, 'legacy zero version must normalize to one under lock');
    step3RuntimeAssert(
        (int) $conn->query('SELECT mutation_version FROM ot_head WHERE id = 90')->fetch_assoc()['mutation_version'] === 1,
        'legacy version normalization must be durable'
    );
    step3RuntimeAssert($versions->bumpAndGet($conn, 90, 1) === 2, 'normalized legacy row must remain bumpable');

    // Missing version blocked on pay.
    step3RuntimeInsertOrder($conn, 100, 1, '50.00', 1001);
    $missingBlocked = false;
    try {
        $service->payTableOrder($conn, [
            'table_id' => 1,
            'order_id' => 100,
            'paid' => '20.00',
            'payment_method' => 'cash',
            'idempotency_key' => 'step3:missing-version',
        ], ['user_id' => 7]);
    } catch (InvalidArgumentException $e) {
        $missingBlocked = $e->getMessage() === 'MUTATION_VERSION_REQUIRED';
    }
    step3RuntimeAssert($missingBlocked, 'pay without mutation_version must fail closed');

    // Stale version blocked; successful pay bumps version and is idempotent on replay.
    $v1 = step3RuntimeVersion($conn, 100);
    $staleBlocked = false;
    try {
        $service->payTableOrder($conn, [
            'table_id' => 1,
            'order_id' => 100,
            'paid' => '20.00',
            'payment_method' => 'cash',
            'mutation_version' => $v1 + 5,
            'idempotency_key' => 'step3:stale-pay',
        ], ['user_id' => 7]);
    } catch (RuntimeException $e) {
        $staleBlocked = $e->getMessage() === 'STALE_ORDER_VERSION';
    }
    step3RuntimeAssert($staleBlocked, 'stale pay mutation_version must fail');
    step3RuntimeAssert(step3RuntimeVersion($conn, 100) === $v1, 'stale pay must not bump version');

    $payRequest = [
        'table_id' => 1,
        'order_id' => 100,
        'paid' => '20.00',
        'payment_method' => 'cash',
        'mutation_version' => $v1,
        'idempotency_key' => 'step3:pay-replay',
    ];
    $firstPay = $service->payTableOrder($conn, $payRequest, ['user_id' => 7]);
    step3RuntimeAssert(($firstPay['success'] ?? false) === true, 'first pay should succeed');
    $vAfterPay = step3RuntimeVersion($conn, 100);
    step3RuntimeAssert($vAfterPay === $v1 + 1, 'successful pay must bump mutation_version');
    step3RuntimeAssert((int) ($firstPay['data']['mutation_version'] ?? 0) === $vAfterPay, 'pay response returns bumped version');

    $replayPay = $service->payTableOrder($conn, $payRequest, ['user_id' => 7]);
    step3RuntimeAssert(!empty($replayPay['idempotency_replayed']), 'same pay key must replay');
    step3RuntimeAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM order_payments WHERE order_id = 100')->fetch_assoc()['c'] === 1,
        'pay replay must not create a second payment'
    );
    step3RuntimeAssert(step3RuntimeVersion($conn, 100) === $vAfterPay, 'pay replay must not bump version again');

    // Cancel requires current version.
    step3RuntimeInsertOrder($conn, 200, 2, '30.00', 2001);
    $cancelV = step3RuntimeVersion($conn, 200);
    $cancel = $service->cancelTableOrder($conn, [
        'table_id' => 2,
        'order_id' => 200,
        'reason' => 'step3 cancel',
        'mutation_version' => $cancelV,
        'idempotency_key' => 'step3:cancel-1',
    ], ['user_id' => 7]);
    step3RuntimeAssert(($cancel['success'] ?? false) === true, 'cancel should succeed with version');
    step3RuntimeAssert(step3RuntimeVersion($conn, 200) === $cancelV + 1, 'cancel must bump mutation_version');

    $cancelReplay = $service->cancelTableOrder($conn, [
        'table_id' => 2,
        'order_id' => 200,
        'reason' => 'step3 cancel',
        'mutation_version' => $cancelV,
        'idempotency_key' => 'step3:cancel-1',
    ], ['user_id' => 7]);
    step3RuntimeAssert(!empty($cancelReplay['idempotency_replayed']), 'cancel replay must return completed key');

    // Move requires version and is transactional with sibling writes.
    step3RuntimeInsertOrder($conn, 300, 1, '40.00', 3001);
    $conn->query('UPDATE tables SET table_case = 0 WHERE id = 2');
    $moveV = step3RuntimeVersion($conn, 300);
    $conn->query('CREATE TABLE IF NOT EXISTS step3_txn_probe (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB');
    $conn->query('TRUNCATE TABLE step3_txn_probe');
    $rolledBack = false;
    try {
        $conn->begin_transaction();
        (new TableTransferService())->moveOrder($conn, [
            'source_table_id' => 1,
            'destination_table_id' => 2,
            'order_id' => 300,
            'mutation_version' => $moveV,
        ], ['user_id' => 7, 'in_transaction' => true, 'event_source' => 'step3_move']);
        $conn->query('INSERT INTO step3_txn_probe VALUES (NULL)');
        throw new RuntimeException('STEP3_FORCE_ROLLBACK');
    } catch (RuntimeException $e) {
        $conn->rollback();
        $rolledBack = $e->getMessage() === 'STEP3_FORCE_ROLLBACK';
    }
    step3RuntimeAssert($rolledBack, 'forced rollback path exercised');
    step3RuntimeAssert(
        (int) $conn->query('SELECT table_id FROM ot_head WHERE id = 300')->fetch_assoc()['table_id'] === 1,
        'rolled-back move must restore source table_id'
    );
    step3RuntimeAssert(step3RuntimeVersion($conn, 300) === $moveV, 'rolled-back move must not bump version');
    step3RuntimeAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM step3_txn_probe')->fetch_assoc()['c'] === 0,
        'sibling write in same txn must roll back with move'
    );

    $moved = (new TableTransferService())->moveOrder($conn, [
        'source_table_id' => 1,
        'destination_table_id' => 2,
        'order_id' => 300,
        'mutation_version' => $moveV,
    ], ['user_id' => 7, 'event_source' => 'step3_move']);
    step3RuntimeAssert((int) $moved['order_id'] === 300, 'move returns order id');
    step3RuntimeAssert((int) ($moved['mutation_version'] ?? 0) === $moveV + 1, 'move bumps mutation_version');
    step3RuntimeAssert(
        (int) $conn->query("SELECT table_id FROM ot_head WHERE id = 300")->fetch_assoc()['table_id'] === 2,
        'order moved to destination'
    );
    step3RuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = 300 AND event_type = 'table_moved'")->fetch_assoc()['c'] === 1,
        'successful move records table_moved event'
    );

    // Merge version-locks both orders (stale destination fails before mutation).
    step3RuntimeInsertOrder($conn, 500, 3, '10.00', 5001);
    step3RuntimeInsertOrder($conn, 501, 4, '12.00', 5002);
    $staleMerge = false;
    try {
        (new TableMergeService())->mergeOrders($conn, [
            'source_table_id' => 3,
            'destination_table_id' => 4,
            'source_order_id' => 500,
            'destination_order_id' => 501,
            'source_mutation_version' => 1,
            'destination_mutation_version' => 99,
        ], ['user_id' => 7]);
    } catch (RuntimeException $e) {
        $staleMerge = $e->getMessage() === 'STALE_ORDER_VERSION';
    }
    step3RuntimeAssert($staleMerge, 'stale destination merge version must fail');
    step3RuntimeAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM fat_details WHERE fatid = 500 AND isdeleted = 0')->fetch_assoc()['c'] === 1,
        'stale merge must not move source lines'
    );

    // Outbox failure must roll back every money-side effect.
    step3RuntimeInsertOrder($conn, 600, 1, '25.00', 6001);
    $outboxFailureVersion = step3RuntimeVersion($conn, 600);
    $expectedCashBeforeFailure = (string) $conn->query(
        "SELECT expected_cash FROM drawer_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1"
    )->fetch_assoc()['expected_cash'];
    $conn->query('DROP TABLE sync_outbox');
    $outboxFailureBlocked = false;
    try {
        $service->payTableOrder($conn, [
            'table_id' => 1,
            'order_id' => 600,
            'paid' => '10.00',
            'payment_method' => 'cash',
            'mutation_version' => $outboxFailureVersion,
            'idempotency_key' => 'step3:outbox-failure',
        ], ['user_id' => 7]);
    } catch (Throwable $exception) {
        $outboxFailureBlocked = str_contains($exception->getMessage(), 'sync_outbox');
    }
    step3RuntimeAssert($outboxFailureBlocked, 'missing outbox must fail the payment');
    step3RuntimeAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM order_payments WHERE order_id = 600')->fetch_assoc()['c'] === 0,
        'outbox failure must roll back payment row'
    );
    step3RuntimeAssert(step3RuntimeVersion($conn, 600) === $outboxFailureVersion, 'outbox failure must not bump order version');
    step3RuntimeAssert(
        (string) $conn->query(
            "SELECT expected_cash FROM drawer_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1"
        )->fetch_assoc()['expected_cash'] === $expectedCashBeforeFailure,
        'outbox failure must roll back drawer cash'
    );
    step3RuntimeAssert(
        (int) $conn->query(
            "SELECT COUNT(*) AS c FROM drawer_movements WHERE order_id = 600"
        )->fetch_assoc()['c'] === 0,
        'outbox failure must roll back drawer movement'
    );
    (new SyncSchemaManager())->apply($conn);

    // Two independent terminal connections race the same version. The parent
    // holds the order lock until both workers are running, then releases them.
    step3RuntimeInsertOrder($conn, 700, 2, '20.00', 7001);
    $raceVersion = step3RuntimeVersion($conn, 700);
    $conn->begin_transaction();
    $conn->query('SELECT id FROM ot_head WHERE id = 700 FOR UPDATE');
    $worker = __DIR__ . '/commercial_v1_step3_concurrent_worker.php';
    $processes = [];
    foreach (['terminal-a', 'terminal-b'] as $terminal) {
        $command = [
            PHP_BINARY,
            $worker,
            $db,
            '700',
            '2',
            (string) $raceVersion,
            'step3:race:' . $terminal,
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, __DIR__);
        step3RuntimeAssert(is_resource($process), 'concurrent terminal worker must start');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $processes[] = ['process' => $process, 'pipes' => $pipes];
    }
    usleep(250000);
    $conn->commit();

    $raceResults = [];
    foreach ($processes as $entry) {
        $stdout = '';
        $stderr = '';
        $pipes = $entry['pipes'];
        while (true) {
            $status = proc_get_status($entry['process']);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if (!$status['running']) {
                break;
            }
            usleep(20000);
        }
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($entry['process']);
        $decoded = json_decode(trim($stdout), true);
        step3RuntimeAssert(is_array($decoded), 'concurrent worker returned invalid JSON: ' . $stderr);
        $raceResults[] = $decoded;
    }
    $successCount = count(array_filter($raceResults, static fn(array $row): bool => ($row['status'] ?? '') === 'success'));
    $staleCount = count(array_filter(
        $raceResults,
        static fn(array $row): bool => ($row['status'] ?? '') === 'error' && ($row['code'] ?? '') === 'STALE_ORDER_VERSION'
    ));
    step3RuntimeAssert(
        $successCount === 1 && $staleCount === 1,
        'two-terminal race must yield one success and one stale rejection: '
            . json_encode($raceResults, JSON_UNESCAPED_SLASHES)
    );
    $racePaymentCount = (int) $conn->query(
        'SELECT COUNT(*) AS c FROM order_payments WHERE order_id = 700'
    )->fetch_assoc()['c'];
    step3RuntimeAssert(
        $racePaymentCount === 1,
        'two-terminal race must persist exactly one payment; count=' . $racePaymentCount
            . ' results=' . json_encode($raceResults, JSON_UNESCAPED_SLASHES)
    );
    step3RuntimeAssert(step3RuntimeVersion($conn, 700) === $raceVersion + 1, 'two-terminal race must bump version once');
    $raceOrder = $conn->query(
        'SELECT paid_amount, remaining_amount FROM ot_head WHERE id = 700'
    )->fetch_assoc();
    step3RuntimeAssert(
        (string) $raceOrder['paid_amount'] === '10.0000' && (string) $raceOrder['remaining_amount'] === '10.0000',
        'two-terminal race must preserve exact paid and remaining amounts'
    );
    $raceDrawerCount = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM drawer_movements WHERE order_id = 700 AND movement_type = 'sale_cash'"
    )->fetch_assoc()['c'];
    step3RuntimeAssert(
        $raceDrawerCount === 1,
        'two-terminal race must persist exactly one cash movement; count=' . $raceDrawerCount
    );
    step3RuntimeAssert(
        (int) $conn->query(
            "SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'order' AND aggregate_local_id = 700 AND event_type = 'order.payment_recorded'"
        )->fetch_assoc()['c'] === 1,
        'two-terminal race must persist exactly one final order outbox snapshot'
    );

    echo "commercial-v1-step3-atomic-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}
