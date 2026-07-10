<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerFloatExpectationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerBranchBlockedException.php';
require_once __DIR__ . '/../../classes/Pos/Service/ManagerApprovalService.php';
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

    $_SESSION['pos_tenant'] = 3;
    $_SESSION['pos_branch'] = 7;
    $_SESSION['userid'] = 90;
    $_SESSION['usrole'] = 1;
    $_SESSION['userrole'] = 1;
    $GLOBALS['role'] = ['id' => 1, 'rollname' => 'admin', 'role_key' => 'owner'];
    $GLOBALS['conn'] = $conn;

    $count = new ShiftCountService();
    $shifts = new ShiftSessionService();
    $drawer = new DrawerSessionService();
    $float = new DrawerFloatExpectationService();

    takeoverAssert($count->handoverEnabled($conn), 'handover enabled');
    $float->setOpeningBaseline($conn, 3, 7, 100.0, 90);

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
    ]);

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
    $first = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.takeover_drawer',
        $post,
        [],
        20,
        static function () use ($conn, $shifts, $sessionA, $approvalId): array {
            $closed = $shifts->forceCloseDrawerForUser($conn, 20, $sessionA, [
                'tenant' => 3,
                'branch' => 7,
                'counted_cash' => '120.000',
                'reason' => 'كاشير غادر دون إغلاق',
                'manager_approval_id' => $approvalId,
                'takeover' => true,
                'incoming_user_id' => 20,
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
                    'counted_cash' => (float) ($closed['counted_cash'] ?? 0),
                    'variance_status' => (string) ($closed['variance_status'] ?? 'none'),
                    'owner_user_id' => (int) ($closed['user_id'] ?? 0),
                    'authorized_by' => 90,
                ],
            ];
        }
    );
    takeoverAssert(($first['success'] ?? false) === true, 'takeover close succeeds');
    takeoverAssert((int) ($first['data']['closed_session_id'] ?? 0) === $sessionA, 'closed session id');
    takeoverAssert(abs((float) ($first['data']['counted_cash'] ?? 0) - 120.0) < 0.01, 'counted cash stored');
    takeoverAssert((int) ($first['data']['owner_user_id'] ?? 0) === 10, 'owner user_id not reassigned');

    $closedRow = $drawer->sessionById($conn, $sessionA);
    takeoverAssert(($closedRow['status'] ?? '') === 'forced_closed', 'status forced_closed');
    takeoverAssert((int) ($closedRow['user_id'] ?? 0) === 10, 'user_id immutable after takeover');
    takeoverAssert(($closedRow['open_branch_lock'] ?? null) === null || $closedRow['open_branch_lock'] === '', 'branch lock cleared');

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
