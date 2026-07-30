<?php

/**
 * Temporary manager shift override: entry invariant, lifecycle, attribution.
 */

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ShiftEntryService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerOverrideService.php';
require_once __DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerFloatExpectationService.php';
require_once __DIR__ . '/../../classes/Pos/Service/ManagerApprovalService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PaymentService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosRegisterService.php';
require_once __DIR__ . '/../../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../../includes/auth_guard.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_drawer_override_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function overrideAssert(bool $ok, string $message): void
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
    (new SyncSchemaManager())->apply($conn);

    $table = $conn->query("SHOW TABLES LIKE 'drawer_override_periods'");
    overrideAssert($table && $table->num_rows > 0, 'drawer_override_periods table exists');

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
            role_key VARCHAR(80) NULL,
            sid_sales TINYINT(1) NOT NULL DEFAULT 0,
            edit_sales TINYINT(1) NOT NULL DEFAULT 0,
            add_sales TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT NOT NULL DEFAULT 0
        )
    ");
    $conn->query("INSERT INTO usr_pwrs (id, rollname, role_key, sid_sales, edit_sales, add_sales) VALUES
        (1, 'admin', 'owner', 1, 1, 1),
        (2, 'cashier', 'cashier', 1, 1, 1),
        (3, 'manager', 'manager', 1, 1, 1)
    ");
    $conn->query("INSERT INTO users (id, uname, display_name, userrole) VALUES
        (10, 'cashier_a', 'أحمد الكاشير', 2),
        (20, 'cashier_b', 'سارة', 2),
        (80, 'manager', 'المدير', 3),
        (90, 'owner', 'المالك', 1)
    ");

    // Grant manager override permission via role_capabilities.
    $conn->query("
        INSERT INTO role_capabilities (role_id, permission_key, is_enabled)
        VALUES
            (3, 'pos.shift.override', 1),
            (3, 'pos.open', 1),
            (3, 'pos.shift.open', 1),
            (3, 'pos.shift.force_close', 1),
            (2, 'pos.open', 1),
            (2, 'pos.shift.open', 1),
            (1, 'pos.shift.override', 1),
            (1, 'pos.open', 1),
            (1, 'pos.shift.open', 1)
        ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)
    ");

    $_SESSION['pos_tenant'] = 3;
    $_SESSION['pos_branch'] = 7;
    $_SESSION['userid'] = 10;
    $_SESSION['usrole'] = 2;
    $_SESSION['userrole'] = 2;
    $GLOBALS['role'] = ['id' => 2, 'rollname' => 'cashier', 'role_key' => 'cashier'];
    $GLOBALS['conn'] = $conn;

    $registers = new PosRegisterService();
    $register = $registers->createRegister($conn, [
        'tenant' => 3,
        'branch' => 7,
        'code' => 'REG1',
        'name' => 'صندوق 1',
        'paired_by' => 90,
    ]);
    $registerId = (int) $register['id'];
    $_SESSION['pos_register_id'] = $registerId;
    $syncConfig = [
        'role' => 'branch',
        'branch' => [
            'uuid' => '9ab7d40a-e8f2-4d30-98a9-c79e86e7b06f',
            'name' => 'Legacy register repair test',
            'pos_tenant' => 3,
            'pos_branch' => 7,
        ],
        'sync' => [
            'branch_sync_enabled' => true,
            'outbox_enabled' => true,
            'operational_sync_enabled' => true,
        ],
    ];
    $count = new ShiftCountService();
    $shifts = new ShiftSessionService();
    $drawer = new DrawerSessionService();
    $entry = new ShiftEntryService();
    $overrides = new DrawerOverrideService();
    $float = new DrawerFloatExpectationService();
    $approvals = new ManagerApprovalService();
    $audit = new SecurityAuditLogger();

    overrideAssert($count->handoverEnabled($conn), 'handover enabled');
    $float->setOpeningBaseline($conn, 3, 7, '100.000', 90);

    // Cashier A opens.
    $count->beginOpenCount($conn, 10);
    $opened = $count->submitOpenCount($conn, 10, '100.000');
    overrideAssert(($opened['status'] ?? '') === 'opened', 'cashier A opened');
    $sessionA = (int) ($opened['drawer_session_id'] ?? 0);
    overrideAssert($sessionA > 0, 'session A id');
    $conn->query("UPDATE drawer_sessions SET register_id = {$registerId}, open_register_lock = '3:7:r{$registerId}' WHERE id = {$sessionA}");

    // Same-user current-day resume.
    $resume = $entry->resolveForUser($conn, 10);
    overrideAssert(($resume['state'] ?? '') === ShiftEntryService::STATE_SELLING_READY, 'same user resumes selling_ready');

    // Same-user stale shift.
    $conn->query("UPDATE drawer_sessions SET business_day = DATE_SUB(CURDATE(), INTERVAL 2 DAY) WHERE id = {$sessionA}");
    $stale = $entry->resolveForUser($conn, 10);
    overrideAssert(($stale['state'] ?? '') === ShiftEntryService::STATE_STALE_SHIFT, 'same user stale_shift');
    $conn->query("UPDATE drawer_sessions SET business_day = CURDATE() WHERE id = {$sessionA}");

    // Another cashier blocked — no override option.
    unset($_SESSION['pos_drawer_session_id'], $_SESSION['posmain_shift_blocking'], $_SESSION['pos_override_period_id']);
    $_SESSION['userid'] = 20;
    $_SESSION['usrole'] = 2;
    $GLOBALS['role'] = ['id' => 2, 'rollname' => 'cashier', 'role_key' => 'cashier'];
    $blockedCashier = $entry->resolveForUser($conn, 20);
    overrideAssert(($blockedCashier['state'] ?? '') === ShiftEntryService::STATE_BRANCH_BLOCKED, 'cashier B blocked');
    overrideAssert(empty($blockedCashier['blocking_session']['can_override']), 'cashier cannot override');
    overrideAssert((int) ($blockedCashier['blocking_session']['owner_user_id'] ?? 0) === 10, 'blocking owner id');

    // Manager offered override.
    $_SESSION['userid'] = 80;
    $_SESSION['usrole'] = 3;
    $GLOBALS['role'] = ['id' => 3, 'rollname' => 'manager', 'role_key' => 'manager'];
    $blockedManager = $entry->resolveForUser($conn, 80);
    overrideAssert(($blockedManager['state'] ?? '') === ShiftEntryService::STATE_BRANCH_BLOCKED, 'manager blocked before override');
    overrideAssert(!empty($blockedManager['blocking_session']['can_override']), 'manager can_override');

    // Legacy null-register session blocks rather than allowing a second open.
    $syncIdentity = new SyncBranchIdentity();
    $currentIdentity = $syncIdentity->find($conn);
    if ($currentIdentity) {
        $syncConfig['branch']['uuid'] = (string) $currentIdentity['branch_uuid'];
    }
    $syncIdentity->ensure($conn, $syncConfig);
    $conn->query("UPDATE drawer_sessions SET register_id = NULL, open_register_lock = NULL WHERE id = {$sessionA}");
    $beforeLegacy = $drawer->sessionById($conn, $sessionA);
    $beforeLegacyRevision = (int) ($beforeLegacy['sync_revision'] ?? 0);

    $conn->begin_transaction();
    $legacyRollback = $entry->resolveForUser($conn, 80, ['sync_config' => $syncConfig]);
    overrideAssert(($legacyRollback['state'] ?? '') === ShiftEntryService::STATE_BRANCH_BLOCKED, 'legacy repair keeps manager blocked inside caller transaction');
    $insideLegacy = $drawer->sessionById($conn, $sessionA);
    overrideAssert((int) ($insideLegacy['register_id'] ?? 0) === $registerId, 'legacy repair is visible inside caller transaction');
    overrideAssert((int) ($insideLegacy['sync_revision'] ?? 0) === $beforeLegacyRevision + 1, 'legacy repair advances revision inside caller transaction');
    $insideLegacyEvents = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'drawer_session'"
        . " AND aggregate_local_id = {$sessionA} AND event_version = " . ($beforeLegacyRevision + 1)
    )->fetch_assoc()['c'];
    overrideAssert($insideLegacyEvents === 1, 'legacy repair event is visible inside caller transaction');
    $conn->rollback();

    $afterLegacyRollback = $drawer->sessionById($conn, $sessionA);
    overrideAssert(empty($afterLegacyRollback['register_id']), 'caller rollback restores null legacy register');
    overrideAssert(empty($afterLegacyRollback['open_register_lock']), 'caller rollback restores null legacy register lock');
    overrideAssert((int) ($afterLegacyRollback['sync_revision'] ?? 0) === $beforeLegacyRevision, 'caller rollback restores legacy revision');
    $rolledBackLegacyEvents = (int) $conn->query(
        "SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'drawer_session'"
        . " AND aggregate_local_id = {$sessionA} AND event_version = " . ($beforeLegacyRevision + 1)
    )->fetch_assoc()['c'];
    overrideAssert($rolledBackLegacyEvents === 0, 'caller rollback removes legacy repair event');

    $legacyBlocked = $entry->resolveForUser($conn, 80, ['sync_config' => $syncConfig]);
    overrideAssert(($legacyBlocked['state'] ?? '') === ShiftEntryService::STATE_BRANCH_BLOCKED, 'legacy null register blocks');
    // Backfill when single active register.
    $afterLegacy = $drawer->sessionById($conn, $sessionA);
    overrideAssert((int) ($afterLegacy['register_id'] ?? 0) === $registerId, 'legacy register backfilled');
    overrideAssert((int) ($afterLegacy['sync_revision'] ?? 0) === $beforeLegacyRevision + 1, 'legacy register backfill commits the next revision');
    $legacyEvent = $conn->query(
        "SELECT payload_json FROM sync_outbox WHERE aggregate_type = 'drawer_session'"
        . " AND aggregate_local_id = {$sessionA} AND event_version = " . ($beforeLegacyRevision + 1)
        . " ORDER BY id DESC LIMIT 1"
    )->fetch_assoc();
    overrideAssert($legacyEvent !== null, 'legacy register backfill commits one typed event');
    $legacyPayload = json_decode((string) $legacyEvent['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    overrideAssert((int) ($legacyPayload['drawer_session']['register_id'] ?? 0) === $registerId, 'legacy repair event carries repaired register');
    overrideAssert(!array_key_exists('open_register_lock', $legacyPayload['drawer_session'] ?? []), 'legacy repair event excludes register lock');

    // Start override requires approval + reason.
    $noApproval = false;
    try {
        $overrides->startOverride($conn, 80, $sessionA, 'مساعدة مؤقتة', 0);
    } catch (ManagerApprovalRequiredException $e) {
        $noApproval = true;
    } catch (RuntimeException $e) {
        $noApproval = in_array($e->getMessage(), ['MANAGER_APPROVAL_REQUIRED', 'OVERRIDE_REASON_REQUIRED'], true);
    }
    overrideAssert($noApproval, 'override without approval rejected');

    $shortReason = false;
    try {
        $overrides->startOverride($conn, 80, $sessionA, 'ab', 1);
    } catch (RuntimeException $e) {
        $shortReason = $e->getMessage() === 'OVERRIDE_REASON_REQUIRED';
    }
    overrideAssert($shortReason, 'short reason rejected');

    $approval = $approvals->requestApproval($conn, [
        'action_type' => 'pos.shift.override',
        'target_type' => 'drawer_session',
        'target_id' => $sessionA,
        'requested_by' => 80,
        'permission_key' => 'pos.shift.override',
        'reason' => 'مساعدة مؤقتة أثناء الاستراحة',
    ]);
    $approvals->decide($conn, (int) $approval['id'], [
        'approved_by' => 80,
        'status' => 'approved',
    ]);

    $period = $overrides->startOverride(
        $conn,
        80,
        $sessionA,
        'مساعدة مؤقتة أثناء الاستراحة',
        (int) $approval['id']
    );
    overrideAssert((int) ($period['id'] ?? 0) > 0, 'override period created');
    overrideAssert((int) ($period['original_owner_user_id'] ?? 0) === 10, 'owner preserved on period');
    overrideAssert((int) ($period['operator_user_id'] ?? 0) === 80, 'operator set');

    $owned = $drawer->sessionById($conn, $sessionA);
    overrideAssert((int) ($owned['user_id'] ?? 0) === 10, 'drawer ownership never changes');

    // Concurrent override rejected.
    $approval2 = $approvals->requestApproval($conn, [
        'action_type' => 'pos.shift.override',
        'target_type' => 'drawer_session',
        'target_id' => $sessionA,
        'requested_by' => 90,
        'permission_key' => 'pos.shift.override',
        'reason' => 'محاولة ثانية',
    ]);
    $approvals->decide($conn, (int) $approval2['id'], [
        'approved_by' => 90,
        'status' => 'approved',
    ]);
    $concurrent = false;
    try {
        $overrides->startOverride($conn, 90, $sessionA, 'محاولة ثانية متزامنة', (int) $approval2['id']);
    } catch (RuntimeException $e) {
        $concurrent = $e->getMessage() === 'OVERRIDE_ALREADY_ACTIVE';
    }
    overrideAssert($concurrent, 'concurrent override rejected');

    // Manager can resolve drawer via currentDrawerSession under override.
    $_SESSION['pos_override_period_id'] = (int) $period['id'];
    $_SESSION['pos_override_drawer_session_id'] = $sessionA;
    $_SESSION['pos_drawer_session_id'] = $sessionA;
    $current = $shifts->currentDrawerSession($conn, 80, ['tenant' => 3, 'branch' => 7]);
    overrideAssert($current !== null && (int) $current['id'] === $sessionA, 'override operator sees drawer');

    // Cash payment/refund preflight must use the same approved drawer override.
    $paymentService = new PaymentService(null, null, $drawer);
    $paymentDrawer = $paymentService->preflightCashDrawerForPayment(
        $conn,
        'cash',
        '1.00',
        80,
        ['tenant' => 3, 'branch' => 7, 'drawer_session_id' => $sessionA]
    );
    overrideAssert(
        $paymentDrawer !== null && (int) ($paymentDrawer['id'] ?? 0) === $sessionA,
        'override operator may settle cash against the approved cashier drawer'
    );
    overrideAssert(
        (int) ($paymentDrawer['user_id'] ?? 0) === 10,
        'payment preflight must not transfer drawer ownership to override operator'
    );

    // Attribute a cash movement to the manager operator.
    $movement = $drawer->recordMovement($conn, $sessionA, [
        'movement_type' => 'paid_out',
        'amount' => '5.000',
        'reason' => 'override expense',
        'created_by' => 80,
        'idempotency_key' => 'drawer-override-paid-out-1',
    ]);
    overrideAssert((int) ($movement['created_by'] ?? 0) === 80, 'movement attributed to manager');

    $overrides->auditPosAuthorization($conn, 'do/do_record_shift_expense.php', true, [
        'target_id' => (int) ($movement['id'] ?? 0),
    ]);
    $overrides->auditPosWrite($conn, 'do/do_record_shift_expense.php', true, [
        'target_id' => (int) ($movement['id'] ?? 0),
    ]);

    $startedAudit = $conn->query("SELECT COUNT(*) AS c FROM security_audit_log WHERE event_type = 'drawer_override_started'")->fetch_assoc();
    overrideAssert((int) ($startedAudit['c'] ?? 0) >= 1, 'started audit present');
    $opAudit = $conn->query("SELECT metadata_json FROM security_audit_log WHERE event_type = 'drawer_override_operation' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    overrideAssert($opAudit !== null, 'operation audit present');
    $opMeta = json_decode((string) ($opAudit['metadata_json'] ?? ''), true);
    overrideAssert((int) ($opMeta['override_period_id'] ?? 0) === (int) $period['id'], 'operation correlated to period');
    overrideAssert((int) ($opMeta['original_owner_user_id'] ?? 0) === 10, 'operation retains original owner');
    $authorizationAudit = $conn->query("SELECT metadata_json FROM security_audit_log WHERE event_type = 'drawer_override_authorization' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    overrideAssert($authorizationAudit !== null, 'authorization audit present');
    $authorizationMeta = json_decode((string) ($authorizationAudit['metadata_json'] ?? ''), true);
    overrideAssert(($authorizationMeta['authorization_granted'] ?? null) === true, 'authorization outcome is explicit');
    overrideAssert(!array_key_exists('success', $authorizationMeta), 'authorization must not claim business-operation success');
    overrideAssert(($authorizationMeta['operation_outcome'] ?? '') === 'not_recorded', 'authorization distinguishes unknown operation outcome');
    $overrideEvents = (new CashFlowPeriodService())->overrideAuditEvents($conn, $sessionA);
    $authorizationEvent = null;
    foreach ($overrideEvents as $event) {
        if (($event['event_type'] ?? '') === 'drawer_override_authorization') {
            $authorizationEvent = $event;
            break;
        }
    }
    overrideAssert($authorizationEvent !== null, 'cash-flow audit view includes override authorization');
    overrideAssert(strpos((string) ($authorizationEvent['summary'] ?? ''), 'تم التفويض') !== false, 'cash-flow audit view labels authorization without claiming operation success');

    // Entry while override active resumes selling_ready.
    $activeEntry = $entry->resolveForUser($conn, 80);
    overrideAssert(($activeEntry['state'] ?? '') === ShiftEntryService::STATE_SELLING_READY, 'active override resumes selling');
    overrideAssert(!empty($activeEntry['override_period']), 'entry returns override_period');

    // End override leaves cashier shift open.
    $ended = $overrides->endOverride($conn, (int) $period['id'], DrawerOverrideService::END_REASON_EXPLICIT, 80, true);
    overrideAssert(!empty($ended['ended_at']), 'override ended');
    $stillOpen = $drawer->sessionById($conn, $sessionA);
    overrideAssert(($stillOpen['status'] ?? '') === 'open', 'cashier shift still open after override end');
    overrideAssert((int) ($stillOpen['user_id'] ?? 0) === 10, 'ownership unchanged after end');

    // Writes after end must not see override binding.
    unset($_SESSION['pos_override_period_id'], $_SESSION['pos_override_drawer_session_id']);
    $afterEnd = $shifts->currentDrawerSession($conn, 80, ['tenant' => 3, 'branch' => 7, 'drawer_session_id' => $sessionA]);
    overrideAssert($afterEnd === null, 'manager cannot use drawer after override ends');
    $paymentAfterEndBlocked = false;
    try {
        $paymentService->preflightCashDrawerForPayment(
            $conn,
            'cash',
            '1.00',
            80,
            ['tenant' => 3, 'branch' => 7, 'drawer_session_id' => $sessionA]
        );
    } catch (RuntimeException $exception) {
        $paymentAfterEndBlocked = $exception->getMessage() === 'DRAWER_SESSION_REQUIRED';
    }
    overrideAssert($paymentAfterEndBlocked, 'cash settlement must fail after the drawer override ends');

    // Inactivity expiry.
    $approval3 = $approvals->requestApproval($conn, [
        'action_type' => 'pos.shift.override',
        'target_type' => 'drawer_session',
        'target_id' => $sessionA,
        'requested_by' => 80,
        'permission_key' => 'pos.shift.override',
        'reason' => 'اختبار انتهاء المهلة',
    ]);
    $approvals->decide($conn, (int) $approval3['id'], [
        'approved_by' => 80,
        'status' => 'approved',
    ]);
    $period2 = $overrides->startOverride($conn, 80, $sessionA, 'اختبار انتهاء المهلة', (int) $approval3['id']);
    $staleTs = date('Y-m-d H:i:s', time() - 7200);
    $pid2 = (int) $period2['id'];
    $conn->query("UPDATE drawer_override_periods SET last_activity_at = '{$staleTs}', started_at = '{$staleTs}' WHERE id = {$pid2}");
    $expired = $overrides->findActiveForDrawer($conn, $sessionA, true);
    overrideAssert($expired === null, 'stale override expires');
    $expiredRow = $overrides->periodById($conn, $pid2);
    overrideAssert(($expiredRow['end_reason'] ?? '') === DrawerOverrideService::END_REASON_INACTIVITY, 'inactivity end reason');

    // Cashier denied permission to start override.
    $approval4 = $approvals->requestApproval($conn, [
        'action_type' => 'pos.shift.override',
        'target_type' => 'drawer_session',
        'target_id' => $sessionA,
        'requested_by' => 20,
        'permission_key' => 'pos.shift.override',
        'reason' => 'محاولة كاشير',
    ]);
    $approvals->decide($conn, (int) $approval4['id'], [
        'approved_by' => 80,
        'status' => 'approved',
    ]);
    $denied = false;
    try {
        $overrides->startOverride($conn, 20, $sessionA, 'محاولة كاشير غير مسموح', (int) $approval4['id']);
    } catch (RuntimeException $e) {
        $denied = $e->getMessage() === 'OVERRIDE_PERMISSION_DENIED';
    }
    overrideAssert($denied, 'cashier permission denied for override');

    echo "drawer-override-lifecycle-ok\n";
} finally {
    try {
        $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    } catch (Throwable $ignored) {
    }
    $conn->close();
}
