<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerFloatExpectationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerBranchBlockedException.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosRegisterService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ManagerApprovalService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentMethodService.php';
require_once __DIR__ . '/../../includes/shift_handover_idempotency.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_drawer_takeover_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function takeoverAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);
    // Fresh CREATE skips column upgrades in the same pass — apply again for approval columns.
    (new SyncSchemaManager())->apply($conn);

    $conn->query("
        CREATE TABLE users (
            id INT PRIMARY KEY,
            uname VARCHAR(50) NOT NULL DEFAULT '',
            display_name VARCHAR(100) NULL,
            userrole INT NOT NULL DEFAULT 0,
            isdeleted TINYINT NOT NULL DEFAULT 0,
            permission_mode ENUM('role_only','role_with_overrides') NOT NULL DEFAULT 'role_only'
        )
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT PRIMARY KEY,
            rollname VARCHAR(80) NOT NULL DEFAULT '',
            role_key VARCHAR(80) NULL
        )
    ");
    $conn->query("INSERT INTO usr_pwrs (id, rollname, role_key) VALUES (1, 'admin', 'owner'), (2, 'cashier', 'cashier')");
    $conn->query("INSERT INTO users (id, uname, display_name, userrole) VALUES
        (10, 'cashier_a', 'أحمد الكاشير', 2),
        (20, 'cashier_b', 'سارة', 2),
        (90, 'manager', 'المدير', 1)
    ");
    $conn->query("CREATE TABLE ot_head (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pro_date DATE NULL,
        crtime DATETIME NULL,
        payment_date DATETIME NULL,
        user VARCHAR(50) NULL,
        pro_tybe INT NULL,
        payment_status VARCHAR(40) NULL,
        isdeleted TINYINT NOT NULL DEFAULT 0,
        fat_total DECIMAL(12,3) NOT NULL DEFAULT 0,
        fat_disc DECIMAL(12,3) NOT NULL DEFAULT 0,
        fat_net DECIMAL(12,3) NOT NULL DEFAULT 0
    )");
    $conn->query("CREATE TABLE order_payments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(19,2) NOT NULL DEFAULT 0.00,
        payment_method VARCHAR(50) NULL,
        reference_no VARCHAR(100) NULL,
        paid_by_customer_id BIGINT UNSIGNED NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_voided TINYINT(1) NOT NULL DEFAULT 0,
        voided_at DATETIME NULL,
        voided_by BIGINT UNSIGNED NULL,
        void_reason VARCHAR(255) NULL,
        KEY idx_order_payments_order_active (order_id, is_voided),
        KEY idx_order_payments_created (created_at)
    )");

    $_SESSION['pos_tenant'] = 3;
    $_SESSION['pos_branch'] = 7;
    $_SESSION['userid'] = 90;
    $_SESSION['usrole'] = 1;
    $_SESSION['userrole'] = 1;
    $GLOBALS['role'] = ['id' => 1, 'rollname' => 'admin', 'role_key' => 'owner'];
    $GLOBALS['conn'] = $conn;
    $syncConfig = [
        'role' => 'branch',
        'branch' => [
            'uuid' => '08b21475-22d8-4ad5-8975-416a2d57052d',
            'name' => 'Takeover close test',
            'pos_tenant' => 3,
            'pos_branch' => 7,
        ],
        'sync' => [
            'branch_sync_enabled' => true,
            'outbox_enabled' => true,
            'operational_sync_enabled' => true,
        ],
    ];
    (new SyncBranchIdentity())->ensure($conn, $syncConfig);
    $registerService = new PosRegisterService();
    $register = $registerService->ensureDefaultRegister($conn, 3, 7);
    $_COOKIE[PosRegisterService::COOKIE_NAME] = (string) ($register['_pairing_token_once'] ?? '');
    $_SESSION['pos_register_id'] = (int) $register['id'];

    $count = new ShiftCountService();
    $shifts = new ShiftSessionService();
    $drawer = new DrawerSessionService();
    $float = new DrawerFloatExpectationService();

    takeoverAssert($count->handoverEnabled($conn), 'handover enabled');
    $float->setOpeningBaseline($conn, 3, 7, '100.000', 90);

    // Cashier A opens the branch drawer.
    $_SESSION['userid'] = 10;
    $_SESSION['usrole'] = 2;
    $count->beginOpenCount($conn, 10);
    $opened = $count->submitOpenCount($conn, 10, '100.000');
    takeoverAssert(($opened['status'] ?? '') === 'opened', 'cashier A opened');
    $sessionA = (int) ($opened['drawer_session_id'] ?? 0);
    takeoverAssert($sessionA > 0, 'session A id');

    $drawer->recordMovement($conn, $sessionA, [
        'movement_type' => 'sale_cash',
        'amount' => '25.000',
        'created_by' => 10,
        'idempotency_key' => 'drawer-takeover:sale:' . $sessionA,
    ]);
    $paymentMethods = new PaymentMethodService();
    $paymentMethods->saveMethod($conn, [
        'code' => 'cash',
        'name_ar' => 'Cash',
        'type' => 'cash',
        'account_id' => 51,
        'settlement_policy' => 'cash_drawer',
    ]);
    $bankMethod = $paymentMethods->saveMethod($conn, [
        'code' => 'bank',
        'name_ar' => 'Bank',
        'type' => 'bank',
        'account_id' => 52,
        'settlement_policy' => 'manual_external',
    ]);
    $businessDay = (string) ($drawer->sessionById($conn, $sessionA)['business_day'] ?? date('Y-m-d'));
    $now = date('Y-m-d H:i:s');
    $conn->query("
        INSERT INTO ot_head
            (id, pro_date, crtime, payment_date, user, pro_tybe, payment_status, isdeleted, fat_total, fat_disc, fat_net)
        VALUES
            (100, '{$businessDay}', '{$now}', '{$now}', '10', 9, 'paid', 0, 25, 0, 25),
            (101, '{$businessDay}', '{$now}', '{$now}', '10', 9, 'refunded', 0, 20, 0, 20)
    ");
    $conn->query("
        INSERT INTO order_payments (order_id, amount, payment_method, created_by, created_at)
        VALUES
            (100, 25, 'cash', 10, '{$now}'),
            (101, 20, 'bank', 10, '{$now}')
    ");
    $bankPaymentId = (int) $conn->query(
        'SELECT id FROM order_payments WHERE order_id = 101 LIMIT 1'
    )->fetch_assoc()['id'];
    $creditNoteUuid = SyncBranchIdentity::generateUuidV4();
    $creditNoteStmt = $conn->prepare("
        INSERT INTO credit_notes (
            uuid, tenant, branch, business_day, drawer_session_id,
            original_order_id, customer_account_id, total_amount,
            refund_mode, reason, status, created_by, created_at
        ) VALUES (?, 3, 7, ?, ?, 101, 1, '12.00', 'amount', 'takeover tender regression', 'posted', 90, ?)
    ");
    $creditNoteStmt->bind_param('ssis', $creditNoteUuid, $businessDay, $sessionA, $now);
    $creditNoteStmt->execute();
    $creditNoteId = (int) $conn->insert_id;
    $creditNoteStmt->close();
    $bankMethodId = (int) $bankMethod['id'];
    $refundKey = 'takeover-bank-refund-' . getmypid();
    $refundStmt = $conn->prepare("
        INSERT INTO payment_refunds (
            credit_note_id, original_order_id, original_payment_id,
            payment_method_id, account_id, amount, settlement_policy,
            settlement_declared_by, settlement_declared_at, status,
            idempotency_key, created_by, created_at
        ) VALUES (?, 101, ?, ?, 52, '12.00', 'manual_external', 90, ?, 'settled', ?, 90, ?)
    ");
    $refundStmt->bind_param(
        'iiisss',
        $creditNoteId,
        $bankPaymentId,
        $bankMethodId,
        $now,
        $refundKey,
        $now
    );
    $refundStmt->execute();
    $refundStmt->close();

    // Cashier B is blocked with structured metadata.
    unset($_SESSION['pos_shift_open_count'], $_SESSION['pos_drawer_session_id']);
    $_SESSION['userid'] = 20;
    $blocked = false;
    $blockingPayload = null;
    try {
        $count->beginOpenCount($conn, 20);
    } catch (DrawerBranchBlockedException $exception) {
        $blocked = true;
        $blockingPayload = $exception->blockingSession();
        takeoverAssert($exception->getMessage() === 'BRANCH_DRAWER_ALREADY_OPEN', 'error code preserved');
    }
    takeoverAssert($blocked, 'beginOpenCount blocked for cashier B');
    takeoverAssert(is_array($blockingPayload), 'blocking payload present');
    takeoverAssert((int) ($blockingPayload['drawer_session_id'] ?? 0) === $sessionA, 'blocking session id');
    takeoverAssert((int) ($blockingPayload['user_id'] ?? 0) === 10, 'blocking user id');
    takeoverAssert(($blockingPayload['cashier_name'] ?? '') === 'أحمد الكاشير', 'blocking cashier display name');
    takeoverAssert(($blockingPayload['opened_at'] ?? '') !== '', 'blocking opened_at');

    // Scope mismatch rejection.
    $_SESSION['userid'] = 90;
    $_SESSION['usrole'] = 1;
    $scopeRejected = false;
    try {
        $shifts->forceCloseDrawerForUser($conn, 90, $sessionA, [
            'tenant' => 9,
            'branch' => 9,
            'counted_cash' => '120.000',
            'reason' => 'wrong scope',
        ]);
    } catch (RuntimeException $exception) {
        $scopeRejected = $exception->getMessage() === 'DRAWER_SESSION_SCOPE_MISMATCH';
    }
    takeoverAssert($scopeRejected, 'force close rejects wrong tenant/branch');

    // Reason required when closing another user's session.
    $reasonRejected = false;
    try {
        $shifts->forceCloseDrawerForUser($conn, 90, $sessionA, [
            'tenant' => 3,
            'branch' => 7,
            'counted_cash' => '120.000',
            'reason' => '',
        ]);
    } catch (RuntimeException $exception) {
        $reasonRejected = $exception->getMessage() === 'FORCE_CLOSE_REASON_REQUIRED';
    }
    takeoverAssert($reasonRejected, 'force close requires reason for other user');

    // Takeover without manager approval must fail — even for admin with force_close.
    $noApprovalRejected = false;
    try {
        $shifts->forceCloseDrawerForUser($conn, 90, $sessionA, [
            'tenant' => 3,
            'branch' => 7,
            'counted_cash' => '120.000',
            'reason' => 'كاشير غادر دون إغلاق',
            'takeover' => true,
            'incoming_user_id' => 20,
        ]);
    } catch (ManagerApprovalRequiredException $exception) {
        $noApprovalRejected = true;
    } catch (RuntimeException $exception) {
        $noApprovalRejected = in_array($exception->getMessage(), [
            'MANAGER_APPROVAL_REQUIRED',
            'MANAGER_APPROVAL_NOT_APPROVED',
        ], true);
    }
    takeoverAssert($noApprovalRejected, 'takeover without manager_approval_id must be rejected');
    $stillOpenA = $drawer->sessionById($conn, $sessionA);
    takeoverAssert(($stillOpenA['status'] ?? '') === 'open', 'session remains open after rejected takeover');

    // Issue a real manager approval (simulates PIN override), then takeover.
    $approvals = new ManagerApprovalService();
    $approval = $approvals->requestApproval($conn, [
        'action_type' => 'pos.shift.force_close',
        'target_type' => 'drawer_session',
        'target_id' => $sessionA,
        'requested_by' => 20,
        'permission_key' => 'pos.shift.force_close',
        'reason' => 'كاشير غادر دون إغلاق',
    ]);
    $approvals->decide($conn, (int) $approval['id'], [
        'approved_by' => 90,
        'status' => 'approved',
        'reason' => 'كاشير غادر دون إغلاق',
    ]);
    $approvalId = (int) $approval['id'];
    takeoverAssert($approvalId > 0, 'manager approval created');

    // Idempotent takeover close-then-open.
    $idemKey = 'takeover-test:' . bin2hex(random_bytes(6));
    $post = [
        'idempotency_key' => $idemKey,
        'drawer_session_id' => $sessionA,
        'counted_amount' => '120.000',
        'reason' => 'كاشير غادر دون إغلاق',
        'manager_approval_id' => $approvalId,
    ];
    // Manager-approval consumption records inside the same outer transaction
    // using process config. Use that already-provisioned identity for the
    // drawer events too, so the test models one stable production branch.
    $currentSyncIdentity = (new SyncBranchIdentity())->current($conn);
    $syncConfig['branch']['uuid'] = (string) $currentSyncIdentity['branch_uuid'];
    $first = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.takeover_drawer',
        $post,
        [],
        20,
        static function () use ($conn, $shifts, $sessionA, $approvalId, $syncConfig): array {
            $closed = $shifts->forceCloseDrawerForUser($conn, 20, $sessionA, [
                'tenant' => 3,
                'branch' => 7,
                'counted_cash' => '120.000',
                'reason' => 'كاشير غادر دون إغلاق',
                'manager_approval_id' => $approvalId,
                'takeover' => true,
                'incoming_user_id' => 20,
                'sync_config' => $syncConfig,
            ]);

            $_SESSION['pos_pending_takeover'] = [
                'preceding_session_id' => (int) ($closed['id'] ?? $sessionA),
                'preceding_user_id' => 10,
                'authorized_by' => 90,
                'incoming_user_id' => 20,
                'closed_at' => time(),
            ];

            return [
                'success' => true,
                'data' => [
                    'closed_session_id' => (int) ($closed['id'] ?? 0),
                    'counted_cash' => (string) ($closed['counted_cash'] ?? '0.00'),
                    'variance_status' => (string) ($closed['variance_status'] ?? 'none'),
                    'owner_user_id' => (int) ($closed['user_id'] ?? 0),
                    'authorized_by' => 90,
                ],
            ];
        }
    );
    takeoverAssert(($first['success'] ?? false) === true, 'takeover close succeeds');
    takeoverAssert((int) ($first['data']['closed_session_id'] ?? 0) === $sessionA, 'closed session id');
    takeoverAssert(
        CashAmount::compare($first['data']['counted_cash'] ?? '0.00', '120.00') === 0,
        'counted cash stored'
    );
    takeoverAssert((int) ($first['data']['owner_user_id'] ?? 0) === 10, 'owner user_id not reassigned');

    $closedRow = $drawer->sessionById($conn, $sessionA);
    takeoverAssert(($closedRow['status'] ?? '') === 'forced_closed', 'status forced_closed');
    takeoverAssert((int) ($closedRow['user_id'] ?? 0) === 10, 'user_id immutable after takeover');
    takeoverAssert((int) ($closedRow['sync_revision'] ?? 0) === 4, 'forced close follows opening metadata with core and final session revisions');
    $forcedSessionEvents = $conn->query(
        "SELECT id, event_version, payload_json FROM sync_outbox WHERE aggregate_type = 'drawer_session'"
        . " AND aggregate_local_id = {$sessionA} ORDER BY event_version ASC"
    )->fetch_all(MYSQLI_ASSOC);
    $forcedCloseSessionEvents = array_slice($forcedSessionEvents, -2);
    takeoverAssert(array_map('intval', array_column($forcedCloseSessionEvents, 'event_version')) === [3, 4], 'forced close emits core then final session revisions');
    $forcedFinalPayload = json_decode((string) $forcedCloseSessionEvents[1]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    takeoverAssert(($forcedFinalPayload['drawer_session']['status'] ?? '') === 'forced_closed', 'final forced-close event is terminal');
    takeoverAssert(($forcedFinalPayload['drawer_session']['variance_status'] ?? '') === 'unresolved', 'final forced-close event carries final variance status');
    takeoverAssert(($forcedFinalPayload['drawer_session']['variance_type'] ?? '') === 'closing', 'final forced-close event carries final variance type');
    $forcedShiftCloseEvent = $conn->query(
        "SELECT id FROM sync_outbox WHERE aggregate_type = 'shift_close'"
        . " AND aggregate_local_id = {$sessionA} LIMIT 1"
    )->fetch_assoc();
    takeoverAssert(is_array($forcedShiftCloseEvent), 'forced close emits shift-close bundle');
    takeoverAssert((int) $forcedCloseSessionEvents[1]['id'] < (int) $forcedShiftCloseEvent['id'], 'final forced-close session revision is queued before shift-close bundle');
    takeoverAssert(($closedRow['open_branch_lock'] ?? null) === null || $closedRow['open_branch_lock'] === '', 'branch lock cleared');
    $closeSummary = $conn->query('SELECT * FROM drawer_session_close_summaries')->fetch_assoc();
    takeoverAssert((int) ($closeSummary['drawer_session_id'] ?? 0) === $sessionA, 'forced close creates one canonical session summary');
    takeoverAssert(($closeSummary['close_path'] ?? '') === 'drawer_takeover_force_close', 'summary records the takeover close path');
    takeoverAssert((string) ($closeSummary['total_sales'] ?? '') === '33.000', 'forced close net sales subtract posted refund');
    takeoverAssert((string) ($closeSummary['cash_sales'] ?? '') === '25.000', 'forced close excludes opening float from cash sales');
    takeoverAssert((string) ($closeSummary['non_cash_sales'] ?? '') === '8.000', 'forced close reduces settled bank refund from non-cash');
    takeoverAssert((string) ($closeSummary['return_total'] ?? '') === '12.000', 'forced close records posted return total');
    $forcedPaymentSummary = json_decode(
        (string) ($closeSummary['payment_summary_json'] ?? ''),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    takeoverAssert(($forcedPaymentSummary['total'] ?? '') === '45.000', 'forced close retains gross tender evidence');
    takeoverAssert(($forcedPaymentSummary['settled_refund_total'] ?? '') === '12.000', 'forced close retains settled refund evidence');
    takeoverAssert(($forcedPaymentSummary['net_total'] ?? '') === '33.000', 'forced close retains net tender evidence');

    $conn->begin_transaction();
    $rolledBackResolution = $count->resolveSession($conn, 90, $sessionA, [
        'resolution_reason_code' => 'recount_confirmed',
        'resolution_notes' => 'atomic rollback proof',
    ], [
        'in_transaction' => true,
        'sync_config' => $syncConfig,
    ]);
    takeoverAssert((int) ($rolledBackResolution['resolution_id'] ?? 0) > 0, 'resolution is visible inside caller transaction');
    $resolutionEventInTransaction = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'drawer_session'"
        . " AND aggregate_local_id = {$sessionA} AND event_version = 5"
    )->fetch_assoc()['c'];
    takeoverAssert($resolutionEventInTransaction === 1, 'resolution event is written inside caller transaction');
    $conn->rollback();
    $afterResolutionRollback = $drawer->sessionById($conn, $sessionA);
    takeoverAssert(($afterResolutionRollback['variance_status'] ?? '') === 'unresolved', 'caller rollback restores unresolved status');
    takeoverAssert((int) ($afterResolutionRollback['sync_revision'] ?? 0) === 4, 'caller rollback restores pre-resolution revision');
    takeoverAssert((int) $conn->query(
        "SELECT COUNT(*) AS c FROM drawer_session_resolutions WHERE drawer_session_id = {$sessionA}"
    )->fetch_assoc()['c'] === 0, 'caller rollback removes resolution row');
    takeoverAssert((int) $conn->query(
        "SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'drawer_session'"
        . " AND aggregate_local_id = {$sessionA} AND event_version = 5"
    )->fetch_assoc()['c'] === 0, 'caller rollback removes resolution event');

    $resolution = $count->resolveSession($conn, 90, $sessionA, [
        'resolution_reason_code' => 'recount_confirmed',
        'resolution_notes' => 'manager confirmed recount',
    ], ['sync_config' => $syncConfig]);
    takeoverAssert((int) ($resolution['resolution_id'] ?? 0) > 0, 'manager resolution commits');
    $resolvedSession = $drawer->sessionById($conn, $sessionA);
    takeoverAssert(($resolvedSession['variance_status'] ?? '') === 'resolved', 'committed session is resolved');
    takeoverAssert((int) ($resolvedSession['sync_revision'] ?? 0) === 5, 'manager resolution commits the next session revision');
    $resolvedEvent = $conn->query(
        "SELECT payload_json FROM sync_outbox WHERE aggregate_type = 'drawer_session'"
        . " AND aggregate_local_id = {$sessionA} AND event_version = 5 LIMIT 1"
    )->fetch_assoc();
    $resolvedPayload = json_decode((string) ($resolvedEvent['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    takeoverAssert(($resolvedPayload['drawer_session']['variance_status'] ?? '') === 'resolved', 'resolution event carries resolved status');
    takeoverAssert(count($resolvedPayload['resolutions'] ?? []) === 1, 'resolution event carries one append-only manager record');
    $resolutionPayload = $resolvedPayload['resolutions'][0] ?? [];
    takeoverAssert((int) ($resolutionPayload['drawer_session_id'] ?? 0) === $sessionA, 'resolution record belongs to source drawer');
    takeoverAssert((int) ($resolutionPayload['resolved_by'] ?? 0) === 90, 'resolution record carries manager actor');
    takeoverAssert(($resolutionPayload['resolution_reason_code'] ?? '') === 'recount_confirmed', 'resolution record carries structured reason');
    takeoverAssert(($resolutionPayload['resolution_notes'] ?? '') === 'manager confirmed recount', 'resolution record carries manager notes');
    takeoverAssert(
        ($resolutionPayload['ledger_ot_head_id'] ?? null) === ($resolution['ledger_ot_head_id'] ?? null),
        'resolution record carries the committed ledger link when accounting is available'
    );
    $resolutionSnapshot = json_decode((string) ($resolutionPayload['snapshot_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
    takeoverAssert(array_key_exists('difference', $resolutionSnapshot), 'resolution record carries the accepted variance snapshot');

    $replay = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.takeover_drawer',
        $post,
        [],
        20,
        static function (): array {
            throw new RuntimeException('SHOULD_NOT_RUN_ON_REPLAY');
        }
    );
    takeoverAssert(!empty($replay['idempotency_replayed']), 'takeover idempotent replay');
    takeoverAssert((int) ($replay['data']['closed_session_id'] ?? 0) === $sessionA, 'replay returns same session');

    // Incoming cashier B can now open; float chains from takeover counted_cash.
    unset($_SESSION['pos_shift_open_count'], $_SESSION['pos_drawer_session_id']);
    $_SESSION['userid'] = 20;
    $_SESSION['usrole'] = 2;
    $expected = $float->expectedOpeningFloat($conn, 3, 7);
    takeoverAssert(abs((float) ($expected['expected'] ?? 0) - 120.0) < 0.01, 'next expected float uses takeover counted_cash');

    $count->beginOpenCount($conn, 20);
    $openedB = $count->submitOpenCount($conn, 20, '120.000');
    takeoverAssert(($openedB['status'] ?? '') === 'opened', 'cashier B opens after takeover');
    $sessionB = (int) ($openedB['drawer_session_id'] ?? 0);
    takeoverAssert($sessionB > 0 && $sessionB !== $sessionA, 'new session for cashier B');
    $rowB = $drawer->sessionById($conn, $sessionB);
    takeoverAssert((int) ($rowB['user_id'] ?? 0) === 20, 'new session owned by cashier B');
    takeoverAssert((int) ($rowB['preceding_session_id'] ?? 0) === $sessionA, 'preceding session linked');
    takeoverAssert((int) ($rowB['takeover_authorized_by'] ?? 0) === 90, 'takeover authorized by manager');

    $_SESSION['userid'] = 20;
    $_SESSION['usrole'] = 2;
    $_SESSION['pos_acting_user_id'] = 20;
    $_SESSION['pos_acting_user_name'] = 'سارة';
    $_SESSION['pos_drawer_session_id'] = $sessionB;
    $identity = $shifts->resolvePosIdentity($conn);
    takeoverAssert(($identity['cashier_name'] ?? '') === 'سارة', 'identity shows current cashier');
    takeoverAssert(!empty($identity['is_takeover']), 'identity marks takeover');
    takeoverAssert(($identity['preceding_cashier_name'] ?? '') === 'أحمد الكاشير', 'identity shows original cashier');
    takeoverAssert(($identity['authorized_by_name'] ?? '') === 'المدير', 'identity shows authorizer');

    // Blocking mismatch: cannot takeover a non-blocking / already-closed id via service open check.
    $stillOpen = $drawer->findOpenSessionForBranch($conn, 3, 7);
    takeoverAssert((int) ($stillOpen['id'] ?? 0) === $sessionB, 'branch now held by B');

    echo "drawer_takeover_block_and_close_test: OK\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
}
