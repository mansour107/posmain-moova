<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';

if (($argv[1] ?? '') === '--child') {
    recipePaidReversalEndpointRuntimeChild($argv[2] ?? '');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_paid_reversal_endpoint_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    recipePaidReversalEndpointRuntimeCreateSchema($conn);
    recipePaidReversalEndpointRuntimeInstallFinancialSchema($conn);
    (new PaymentMethodService())->saveMethod($conn, [
        'code' => 'card_terminal',
        'name_ar' => 'Card',
        'name_en' => 'Card',
        'type' => 'card',
        'account_id' => 52,
    ]);
    (new PaymentMethodService())->saveMethod($conn, [
        'code' => 'cash',
        'name_ar' => 'Cash',
        'name_en' => 'Cash',
        'type' => 'cash',
        'account_id' => 51,
        'requires_reference' => false,
    ]);
    recipePaidReversalEndpointRuntimeSeedCommonRows($conn);

    recipePaidReversalEndpointRuntimeSeedOrder($conn, 701, 'takeaway');
    $refund = recipePaidReversalEndpointRuntimeRunChild($db, [
        'order_id' => 701,
        'action' => 'refund',
        'refund_stock_policy' => 'return_to_stock',
        'refund_payment_method' => 'cash',
        'reason' => 'endpoint smoke refund',
        'idempotency_key' => 'recipe-endpoint-refund-' . getmypid(),
    ]);
    recipePaidReversalEndpointRuntimeAssert(
        ($refund['success'] ?? false) === true,
        'refund endpoint should succeed: ' . json_encode($refund, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    recipePaidReversalEndpointRuntimeAssert(($refund['data']['payment_status'] ?? '') === 'refunded', 'refund endpoint should return refunded status');
    recipePaidReversalEndpointRuntimeAssert(($refund['data']['pending_external_amount'] ?? '') === '0.00', 'cash refund must be posted, not pending external');
    recipePaidReversalEndpointRuntimeAssert(($refund['data']['refund_tenders'][0]['code'] ?? '') === 'cash', 'response must return the persisted cashier-selected tender');
    recipePaidReversalEndpointRuntimeAssert(($refund['data']['refund_tenders'][0]['status'] ?? '') === 'posted', 'cash tender must be posted');
    recipePaidReversalEndpointRuntimeAssert(($refund['data']['recipe']['noop'] ?? null) === true, 'recipe lifecycle should stay no-op while recipe flags are off');

    $refundedOrder = $conn->query('SELECT * FROM ot_head WHERE id = 701')->fetch_assoc();
    recipePaidReversalEndpointRuntimeAssert($refundedOrder['payment_status'] === 'refunded', 'refund endpoint should mutate only the temp order');
    recipePaidReversalEndpointRuntimeAssert((int) $refundedOrder['isdeleted'] === 0, 'refund should keep the temp order visible for audit');
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = 701 AND event_type = 'order.refunded'")->fetch_assoc()['c'] === 1,
        'refund endpoint should write one order event'
    );
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE order_id = 701 AND movement_type = 'refund_cash'")->fetch_assoc()['c'] === 1,
        'cashier-selected cash refund must write one drawer movement'
    );
    $cashTender = $conn->query("
        SELECT pm.code, pr.status
        FROM payment_refunds pr
        INNER JOIN payment_methods pm ON pm.id = pr.payment_method_id
        WHERE pr.original_order_id = 701
    ")->fetch_assoc();
    recipePaidReversalEndpointRuntimeAssert(($cashTender['code'] ?? '') === 'cash', 'payment_refunds must be the selected-tender authority');
    recipePaidReversalEndpointRuntimeAssert(($cashTender['status'] ?? '') === 'posted', 'cash authority row must be posted');

    $refundReplay = recipePaidReversalEndpointRuntimeRunChild($db, [
        'order_id' => 701,
        'action' => 'refund',
        'refund_stock_policy' => 'return_to_stock',
        'refund_payment_method' => 'cash',
        'reason' => 'endpoint smoke refund',
        'idempotency_key' => 'recipe-endpoint-refund-' . getmypid(),
    ]);
    recipePaidReversalEndpointRuntimeAssert(($refundReplay['success'] ?? false) === true, 'refund idempotency replay should return completed response');
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = 701 AND event_type = 'order.refunded'")->fetch_assoc()['c'] === 1,
        'refund idempotency replay should not write a second order event'
    );
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE order_id = 701 AND movement_type = 'refund_cash'")->fetch_assoc()['c'] === 1,
        'refund idempotency replay should not duplicate the cash drawer movement'
    );

    $conn->query("UPDATE drawer_sessions SET status = 'closed', closed_at = NOW() WHERE id = 1");
    recipePaidReversalEndpointRuntimeSeedOrder($conn, 703, 'takeaway');
    $pendingCard = recipePaidReversalEndpointRuntimeRunChild($db, [
        'order_id' => 703,
        'action' => 'refund',
        'refund_stock_policy' => 'waste',
        'refund_payment_method' => 'card_terminal',
        'refund_external_reference' => '',
        'with_drawer_session' => false,
        'reason' => 'endpoint pending card refund',
        'idempotency_key' => 'recipe-endpoint-card-pending-' . getmypid(),
    ]);
    recipePaidReversalEndpointRuntimeAssert(($pendingCard['success'] ?? false) === true, 'non-cash refund must not require an open drawer');
    recipePaidReversalEndpointRuntimeAssert(($pendingCard['data']['pending_external_amount'] ?? '') === '20.00', 'card refund without a reference must remain pending external');
    recipePaidReversalEndpointRuntimeAssert(($pendingCard['data']['refund_tenders'][0]['status'] ?? '') === 'pending_external', 'response must expose pending external state');
    $pendingTender = $conn->query("SELECT status, external_reference, journal_head_id FROM payment_refunds WHERE original_order_id = 703")->fetch_assoc();
    recipePaidReversalEndpointRuntimeAssert(($pendingTender['status'] ?? '') === 'pending_external', 'persisted non-cash refund must remain pending');
    recipePaidReversalEndpointRuntimeAssert($pendingTender['external_reference'] === null, 'pending non-cash refund must not invent a settlement reference');
    recipePaidReversalEndpointRuntimeAssert($pendingTender['journal_head_id'] === null, 'pending non-cash refund must not post a settlement journal');
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE order_id = 703")->fetch_assoc()['c'] === 0,
        'non-cash pending refund must not affect the cash drawer'
    );

    $conn->query("UPDATE drawer_sessions SET status = 'open', closed_at = NULL WHERE id = 1");
    recipePaidReversalEndpointRuntimeSeedOrder($conn, 704, 'takeaway');
    $itemPartialPayload = [
        'order_id' => 704,
        'action' => 'refund',
        'refund_mode' => 'items',
        'refund_lines' => [[
            'original_detail_id' => 1704,
            'quantity' => '1.000000',
            'stock_disposition' => 'restock',
        ]],
        'refund_stock_policy' => 'return_to_stock',
        'refund_payment_method' => 'cash',
        'reason' => 'endpoint item partial',
        'idempotency_key' => 'recipe-endpoint-item-partial-' . getmypid(),
    ];
    $itemPartial = recipePaidReversalEndpointRuntimeRunChild($db, $itemPartialPayload);
    recipePaidReversalEndpointRuntimeAssert(($itemPartial['success'] ?? false) === true, 'item partial endpoint request should succeed');
    recipePaidReversalEndpointRuntimeAssert(($itemPartial['data']['refund_mode'] ?? '') === 'items', 'item partial response must expose item mode');
    recipePaidReversalEndpointRuntimeAssert(($itemPartial['data']['refund_amount'] ?? '') === '10.00', 'item partial endpoint must use selected quantity value');
    recipePaidReversalEndpointRuntimeAssert(($itemPartial['data']['reversal_status'] ?? '') === 'partial', 'item partial must keep partial state');
    recipePaidReversalEndpointRuntimeAssert(($itemPartial['data']['remaining_refundable_amount'] ?? '') === '10.00', 'item partial must expose remaining balance');
    $partialOrder = $conn->query('SELECT payment_status, invoice_status, order_status FROM ot_head WHERE id = 704')->fetch_assoc();
    recipePaidReversalEndpointRuntimeAssert($partialOrder['payment_status'] === 'paid', 'partial endpoint must keep original order paid');
    recipePaidReversalEndpointRuntimeAssert($partialOrder['invoice_status'] === 'completed', 'partial endpoint must keep original invoice immutable');
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = 704 AND event_type = 'order.partially_refunded'")->fetch_assoc()['c'] === 1,
        'item partial endpoint must write one partial audit event'
    );
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE order_id = 704 AND movement_type = 'refund_cash'")->fetch_assoc()['c'] === 1,
        'cash item partial endpoint must write exactly one drawer movement'
    );
    $itemPartialReplay = recipePaidReversalEndpointRuntimeRunChild($db, $itemPartialPayload);
    recipePaidReversalEndpointRuntimeAssert(($itemPartialReplay['success'] ?? false) === true, 'item partial retry must replay');
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM credit_notes WHERE original_order_id = 704")->fetch_assoc()['c'] === 1,
        'item partial retry must not duplicate credit notes'
    );
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE order_id = 704 AND movement_type = 'refund_cash'")->fetch_assoc()['c'] === 1,
        'item partial retry must not duplicate drawer movements'
    );

    $amountPartialPayload = [
        'order_id' => 704,
        'action' => 'refund',
        'refund_mode' => 'amount',
        'refund_amount' => '5.00',
        'refund_stock_policy' => 'waste',
        'refund_payment_method' => 'card_terminal',
        'refund_external_reference' => '',
        'reason' => 'endpoint amount partial',
        'idempotency_key' => 'recipe-endpoint-amount-partial-' . getmypid(),
    ];
    $amountPartial = recipePaidReversalEndpointRuntimeRunChild($db, $amountPartialPayload);
    recipePaidReversalEndpointRuntimeAssert(($amountPartial['success'] ?? false) === true, 'amount partial endpoint request should succeed');
    recipePaidReversalEndpointRuntimeAssert(($amountPartial['data']['refund_mode'] ?? '') === 'amount', 'amount partial response must expose amount mode');
    recipePaidReversalEndpointRuntimeAssert(($amountPartial['data']['refund_amount'] ?? '') === '5.00', 'amount partial endpoint must post exact selected amount');
    recipePaidReversalEndpointRuntimeAssert(($amountPartial['data']['cumulative_refunded_amount'] ?? '') === '15.00', 'endpoint partials must accumulate exactly');
    recipePaidReversalEndpointRuntimeAssert(($amountPartial['data']['remaining_refundable_amount'] ?? '') === '5.00', 'amount partial endpoint must preserve exact remainder');
    recipePaidReversalEndpointRuntimeAssert(($amountPartial['data']['pending_external_amount'] ?? '') === '5.00', 'non-cash partial without reference must remain pending external');
    $amountCredit = $conn->query("
        SELECT cn.refund_mode, cnl.quantity, cnl.line_amount, pr.status
        FROM credit_notes cn
        INNER JOIN credit_note_lines cnl ON cnl.credit_note_id = cn.id
        INNER JOIN payment_refunds pr ON pr.credit_note_id = cn.id
        WHERE cn.original_order_id = 704 AND cn.refund_mode = 'amount'
    ")->fetch_assoc();
    recipePaidReversalEndpointRuntimeAssert(($amountCredit['refund_mode'] ?? '') === 'amount', 'amount mode must persist through endpoint');
    recipePaidReversalEndpointRuntimeAssert((string) $amountCredit['quantity'] === '0.500000', 'amount endpoint must persist allocated line quantity');
    recipePaidReversalEndpointRuntimeAssert((string) $amountCredit['line_amount'] === '5.00', 'amount endpoint must persist exact allocated line value');
    recipePaidReversalEndpointRuntimeAssert(($amountCredit['status'] ?? '') === 'pending_external', 'amount endpoint must preserve external settlement state');
    $amountPartialReplay = recipePaidReversalEndpointRuntimeRunChild($db, $amountPartialPayload);
    recipePaidReversalEndpointRuntimeAssert(($amountPartialReplay['success'] ?? false) === true, 'amount partial retry must replay');
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM credit_notes WHERE original_order_id = 704 AND refund_mode = 'amount'")->fetch_assoc()['c'] === 1,
        'amount partial retry must not duplicate credit notes'
    );
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM payment_refunds WHERE original_order_id = 704 AND status = 'pending_external'")->fetch_assoc()['c'] === 1,
        'amount partial retry must not duplicate pending external refunds'
    );
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = 704 AND event_type = 'order.partially_refunded'")->fetch_assoc()['c'] === 2,
        'amount partial retry must not duplicate audit events'
    );

    $overPartial = recipePaidReversalEndpointRuntimeRunChild($db, [
        'order_id' => 704,
        'action' => 'refund',
        'refund_mode' => 'amount',
        'refund_amount' => '5.01',
        'refund_stock_policy' => 'waste',
        'refund_payment_method' => 'card_terminal',
        'reason' => 'endpoint over refund',
        'idempotency_key' => 'recipe-endpoint-over-partial-' . getmypid(),
    ]);
    recipePaidReversalEndpointRuntimeAssert(($overPartial['success'] ?? true) === false, 'endpoint must reject over-refund');
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM credit_notes WHERE original_order_id = 704")->fetch_assoc()['c'] === 2,
        'rejected over-refund must not persist a third credit note'
    );

    $conn->query("UPDATE drawer_sessions SET status = 'closed', closed_at = NOW() WHERE id = 1");
    recipePaidReversalEndpointRuntimeSeedOrder($conn, 702, 'table');
    $void = recipePaidReversalEndpointRuntimeRunChild($db, [
        'order_id' => 702,
        'action' => 'void',
        'refund_stock_policy' => 'waste',
        'reason' => 'endpoint smoke void',
        'idempotency_key' => 'recipe-endpoint-void-' . getmypid(),
    ]);
    recipePaidReversalEndpointRuntimeAssert(($void['success'] ?? false) === true, 'void endpoint should succeed');
    recipePaidReversalEndpointRuntimeAssert(($void['data']['payment_status'] ?? '') === 'voided', 'void endpoint should return voided status');

    $voidedOrder = $conn->query('SELECT * FROM ot_head WHERE id = 702')->fetch_assoc();
    $table = $conn->query('SELECT * FROM tables WHERE id = 12')->fetch_assoc();
    recipePaidReversalEndpointRuntimeAssert($voidedOrder['payment_status'] === 'voided', 'void endpoint should mark the temp order voided');
    recipePaidReversalEndpointRuntimeAssert((int) $voidedOrder['isdeleted'] === 0, 'void endpoint should retain the original temp order for audit');
    recipePaidReversalEndpointRuntimeAssert((int) $table['table_case'] === 0, 'void endpoint should free the temp table');
    recipePaidReversalEndpointRuntimeAssert(
        (int) $conn->query("SELECT COUNT(*) AS c FROM order_events WHERE order_id = 702 AND event_type = 'order.voided'")->fetch_assoc()['c'] === 1,
        'void endpoint should write one order event'
    );

    echo "recipe-paid-reversal-endpoint-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function recipePaidReversalEndpointRuntimeChild(string $json): void
{
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Invalid child payload.\n");
        exit(9);
    }

    $csrf = 'recipe-endpoint-csrf-fixed';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['PHP_SELF'] = 'ajax/refund_order.php';
    $_SERVER['SCRIPT_NAME'] = 'ajax/refund_order.php';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $csrf;
    $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN'] = $csrf;
    $_POST = [
        'order_id' => (int) ($payload['order_id'] ?? 0),
        'action' => (string) ($payload['action'] ?? ''),
        'refund_stock_policy' => (string) ($payload['refund_stock_policy'] ?? ''),
        'refund_payment_method' => (string) ($payload['refund_payment_method'] ?? ''),
        'refund_external_reference' => (string) ($payload['refund_external_reference'] ?? ''),
        'refund_mode' => (string) ($payload['refund_mode'] ?? 'full'),
        'refund_amount' => (string) ($payload['refund_amount'] ?? ''),
        'refund_lines' => json_encode($payload['refund_lines'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'reason' => (string) ($payload['reason'] ?? ''),
        'idempotency_key' => (string) ($payload['idempotency_key'] ?? ''),
        'csrf_token' => $csrf,
    ];

    session_id('recipeendpoint' . getmypid());
    require_once dirname(__DIR__, 2) . '/includes/session_bootstrap.php';
    $_SESSION['login'] = 'endpoint_smoke';
    $_SESSION['userid'] = 1;
    $_SESSION['user_id'] = 1;
    $_SESSION['usrole'] = 1;
    $_SESSION['userrole'] = 1;
    $_SESSION['usty'] = 2;
    $_SESSION['pos_authenticated'] = true;
    $_SESSION['pos_user_id'] = 1;
    $_SESSION['pos_tenant'] = 1;
    $_SESSION['pos_branch'] = 1;
    if (($payload['with_drawer_session'] ?? true) === true) {
        $_SESSION['pos_drawer_session_id'] = 1;
    } else {
        unset($_SESSION['pos_drawer_session_id']);
    }
    $_SESSION['posmain_csrf_tokens'] = [
        'pos_browser' => $csrf,
    ];

    chdir(dirname(__DIR__, 2) . '/ajax');
    require dirname(__DIR__, 2) . '/ajax/refund_order.php';
    exit(0);
}

function recipePaidReversalEndpointRuntimeRunChild(string $db, array $payload): array
{
    $env = array_merge($_ENV, [
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'POSMAIN_DB_NAME' => $db,
        'POSMAIN_SESSION_DRIVER' => 'file',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '0',
        'POSMAIN_RECIPE_MODE' => 'off',
        'POSMAIN_RECIPE_MODE' => 'off',
        'POSMAIN_ROUTER_ENABLED' => '0',
        'POSMAIN_REQUIRE_MANAGER_APPROVAL' => '0',
    ]);
    $command = [
        PHP_BINARY,
        __FILE__,
        '--child',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__, 2), $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start paid reversal endpoint child process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException("Paid reversal endpoint child failed with code {$exitCode}: {$stderr}\n{$stdout}");
    }

    $decoded = json_decode((string) $stdout, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Paid reversal endpoint child did not return JSON: {$stderr}\n{$stdout}");
    }

    return $decoded;
}

function recipePaidReversalEndpointRuntimeCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE settings (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lang VARCHAR(20) NULL DEFAULT 'ar',
            edit_pass VARCHAR(191) NULL DEFAULT '',
            def_pos_client INT NULL,
            def_pos_store INT NULL,
            def_pos_employee INT NULL,
            def_pos_fund INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO settings (lang, edit_pass, def_pos_client, def_pos_store, def_pos_employee, def_pos_fund) VALUES ('ar', '', 501, 61, 71, 51)");
    $conn->query("
        CREATE TABLE acc_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NULL,
            aname VARCHAR(120) NULL,
            parent_id INT NOT NULL DEFAULT 0,
            is_basic TINYINT(1) NOT NULL DEFAULT 0,
            is_stock TINYINT(1) NOT NULL DEFAULT 0,
            is_fund TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            balance DECIMAL(19,2) NOT NULL DEFAULT 0.00
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        INSERT INTO acc_head (id, code, aname, parent_id, is_basic, is_stock, is_fund)
        VALUES
            (35, '213', 'Employees', 0, 1, 0, 0),
            (51, '121001', 'Cash', 0, 0, 0, 1),
            (52, '124001', 'Card', 0, 0, 0, 0),
            (61, '123001', 'Store', 0, 0, 1, 0),
            (71, '213001', 'Employee', 35, 0, 0, 0),
            (91, '3111', 'Sales', 0, 0, 0, 0),
            (501, '122001', 'Customer', 0, 0, 0, 0)
    ");
    $conn->query("
        CREATE TABLE journal_heads (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            journal_id INT NOT NULL,
            total DECIMAL(19,2) NOT NULL,
            jdate DATE NOT NULL,
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
            debit DECIMAL(19,2) NOT NULL DEFAULT 0.00,
            credit DECIMAL(19,2) NOT NULL DEFAULT 0.00,
            tybe INT NOT NULL DEFAULT 0,
            op2 INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_payments (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            amount DECIMAL(19,2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE towns (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tname VARCHAR(191) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(191) NOT NULL,
            password VARCHAR(255) NULL,
            userrole INT NULL,
            usertype INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL PRIMARY KEY,
            rollname VARCHAR(191) NULL,
            role_key VARCHAR(40) NULL,
            info TEXT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            edit_payment TINYINT(1) NOT NULL DEFAULT 0,
            delete_payment TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            pro_id VARCHAR(80) NULL,
            table_id INT NULL,
            pro_tybe INT NULL,
            order_type VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            invoice_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            fat_net DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            remaining_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            mutation_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancellation_reason VARCHAR(255) NULL,
            updated_by INT NULL,
            crtime DATETIME NULL,
            mdtime DATETIME NULL,
            pro_date DATE NULL,
            completed_at DATETIME NULL,
            acc1 INT NULL,
            info TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL PRIMARY KEY,
            fatid INT NOT NULL,
            item_id INT NOT NULL,
            qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            price DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            det_value DECIMAL(18,2) NOT NULL DEFAULT 0.00,
            u_val DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            det_store INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE tables (
            id INT NOT NULL PRIMARY KEY,
            tname VARCHAR(191) NULL,
            table_case INT NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE pos_request_keys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope VARCHAR(80) NOT NULL,
            idempotency_key VARCHAR(128) NOT NULL,
            request_hash CHAR(64) NOT NULL,
            user_id BIGINT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            status ENUM('processing','completed','failed','voided') NOT NULL DEFAULT 'processing',
            response_json JSON NULL,
            error_code VARCHAR(80) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_scope_key (scope, idempotency_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            event_source VARCHAR(80) NOT NULL,
            actor_user_id BIGINT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            before_state_json JSON NULL,
            after_state_json JSON NULL,
            metadata_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_order_created (order_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function recipePaidReversalEndpointRuntimeInstallFinancialSchema(mysqli $conn): void
{
    $planned = (new SyncSchemaManager())->plannedStatements();
    foreach ([
        'document_counters',
        'payment_methods',
        'credit_notes',
        'credit_note_lines',
        'payment_refunds',
        'manager_approvals',
        'drawer_sessions',
        'drawer_movements',
        'recipe_order_line_usage',
        'sync_outbox',
    ] as $table) {
        $conn->query($planned[$table]);
    }

    $conn->query('ALTER TABLE journal_heads ADD COLUMN source_type VARCHAR(64) NULL');
    $conn->query('ALTER TABLE journal_heads ADD COLUMN source_id BIGINT NULL');
    $conn->query('ALTER TABLE journal_heads ADD COLUMN posting_kind VARCHAR(64) NULL');
    $conn->query('ALTER TABLE journal_heads ADD COLUMN idempotency_key VARCHAR(191) NULL');
    $conn->query('ALTER TABLE journal_heads ADD COLUMN reversal_of_journal_id BIGINT NULL');
    $conn->query('ALTER TABLE journal_heads ADD UNIQUE KEY uq_journal_heads_idempotency (idempotency_key)');
    $conn->query('ALTER TABLE journal_heads ADD UNIQUE KEY uq_journal_heads_source_kind (source_type, source_id, posting_kind)');
}

function recipePaidReversalEndpointRuntimeSeedCommonRows(mysqli $conn): void
{
    $conn->query("INSERT INTO users (id, uname, password, userrole, usertype, isdeleted) VALUES (1, 'endpoint_smoke', '', 1, 2, 0)");
    $conn->query("INSERT INTO usr_pwrs (id, rollname, role_key, is_system, is_active, edit_payment, delete_payment, isdeleted) VALUES (1, 'admin', 'owner', 1, 1, 1, 1, 0)");
    $conn->query("
        INSERT INTO drawer_sessions (
            id, uuid, user_id, tenant, branch, fund_account_id, opened_at,
            business_day, opened_by, opening_cash, status, notes
        ) VALUES (
            1, '11111111-1111-4111-8111-111111111111', 1, 1, 1, 51, NOW(),
            CURDATE(), 1, 0.000, 'open', 'endpoint test'
        )
    ");
}

function recipePaidReversalEndpointRuntimeSeedOrder(mysqli $conn, int $orderId, string $orderType): void
{
    $tableId = $orderType === 'table' ? 12 : 0;
    if ($tableId > 0) {
        $conn->query("INSERT INTO tables (id, tname, table_case, isdeleted) VALUES ({$tableId}, 'Endpoint Smoke Table', 1, 0) ON DUPLICATE KEY UPDATE table_case = 1");
    }

    $conn->query("
        INSERT INTO ot_head (
            id, pro_id, table_id, pro_tybe, order_type, payment_status, invoice_status,
            order_status, fat_net, paid_amount, remaining_amount, isdeleted, crtime, mdtime, pro_date
        ) VALUES (
            {$orderId}, 'EP-{$orderId}', {$tableId}, 9, '{$orderType}', 'paid', 'completed',
            'completed', 20.00, 20.00, 0.00, 0, NOW(), NOW(), CURDATE()
        )
    ");
    $detailId = $orderId + 1000;
    $conn->query("
        INSERT INTO fat_details (id, fatid, item_id, qty_in, qty_out, price, det_value, u_val, det_store, isdeleted)
        VALUES ({$detailId}, {$orderId}, 3001, 0.000000, 2.000000, 10.000000, 20.00, 1.000000, 0, 0)
    ");
    $paymentId = $orderId + 2000;
    $conn->query("INSERT INTO order_payments (id, order_id, amount, payment_method) VALUES ({$paymentId}, {$orderId}, 20.00, 'card_terminal')");
}

function recipePaidReversalEndpointRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
