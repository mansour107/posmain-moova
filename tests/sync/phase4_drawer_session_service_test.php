<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_phase4_drawer_sessions_' . getmypid();
$cloudDb = $db . '_cloud';
$conn = new mysqli($host, $user, $pass, '', $port);
$cloudConn = null;

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->query("CREATE DATABASE `{$cloudDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);
    $cloudConn = new mysqli($host, $user, $pass, $cloudDb, $port);
    (new SyncSchemaManager())->apply($cloudConn);
    $branchUuid = '3bd3ac2f-e6f0-4ec5-a08d-a72eff12d5e0';
    $syncConfig = [
        'role' => 'branch',
        'branch' => ['uuid' => $branchUuid, 'name' => 'Drawer test', 'pos_tenant' => 3, 'pos_branch' => 4],
        'sync' => [
            'branch_sync_enabled' => true,
            'outbox_enabled' => true,
            'operational_sync_enabled' => true,
        ],
    ];

    $service = new DrawerSessionService();
    $session = $service->openSession($conn, [
        'user_id' => 7,
        'opened_by' => 99,
        'tenant' => 3,
        'branch' => 4,
        'fund_account_id' => 5001,
        'opening_cash' => '100.125',
        'opened_at' => '2026-05-13 09:00:00',
        'notes' => 'صباح',
        'sync_config' => $syncConfig,
    ]);
    phase4DrawerAssert($session['status'] === 'open', 'session should be open');
    phase4DrawerAssert($session['opening_cash'] === '100.125', 'opening cash should preserve decimal string');
    phase4DrawerAssert($session['fund_account_id'] === 5001, 'fund account expected');
    phase4DrawerAssert(strlen($session['uuid']) === 36, 'uuid should be stored');

    $open = $service->findOpenSession($conn, 7, 3, 4);
    phase4DrawerAssert($open !== null && $open['id'] === $session['id'], 'open session lookup expected');

    phase4DrawerExpectException(function () use ($service, $conn) {
        $service->openSession($conn, [
            'user_id' => 7,
            'tenant' => 3,
            'branch' => 4,
            'opening_cash' => '5.000',
        ]);
    }, 'DRAWER_SESSION_ALREADY_OPEN');

    $sale = $service->recordMovement($conn, $session['id'], [
        'movement_type' => 'sale_cash',
        'amount' => '50.250',
        'order_id' => 300,
        'payment_id' => 400,
        'reason' => 'table payment',
        'created_by' => 99,
        'sync_config' => $syncConfig,
    ]);
    phase4DrawerAssert($sale['amount'] === '50.250', 'sale movement amount expected');
    phase4DrawerAssert($sale['order_id'] === 300, 'sale order id expected');
    $saleEvents = phase4DrawerOutboxRows($conn, (int) $sale['id']);
    phase4DrawerAssert(count($saleEvents) === 1 && (int) $saleEvents[0]['event_version'] === 1, 'assigned movement must append revision 1');
    $salePayload = json_decode((string) $saleEvents[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    phase4DrawerAssert((int) $salePayload['drawer_session']['id'] === (int) $session['id'], 'assigned payload must retain local session id for validation');
    foreach (['close_token_hash', 'open_branch_lock', 'open_register_lock', 'open_user_lock'] as $sensitiveField) {
        phase4DrawerAssert(!array_key_exists($sensitiveField, $salePayload['drawer_session']), "{$sensitiveField} must be excluded from producer payload");
    }
    $sessionSeedKeys = array_keys($salePayload['drawer_session']);
    sort($sessionSeedKeys);
    $expectedSeedKeys = ['branch', 'business_day', 'fund_account_id', 'id', 'opened_at', 'opened_by', 'opening_cash', 'tenant', 'user_id', 'uuid'];
    sort($expectedSeedKeys);
    phase4DrawerAssert($sessionSeedKeys === $expectedSeedKeys, 'movement bundle must contain only stable drawer identity seed fields');

    $conn->query(
        'UPDATE drawer_sessions SET register_id = 77, expected_opening_cash = 95.000,'
        . " opening_variance = 5.000, variance_status = 'unresolved', variance_type = 'opening', notes = 'mutable state'"
        . ' WHERE id = ' . (int) $session['id']
    );
    (new OperationalSyncEventService())->recordDrawerMovementSnapshot($conn, (int) $sale['id'], [
        'event_type' => 'drawer_movement.saved',
        'source_system' => 'drawer',
        'config' => $syncConfig,
    ]);
    $recapturedSaleEvents = phase4DrawerOutboxRows($conn, (int) $sale['id']);
    phase4DrawerAssert(count($recapturedSaleEvents) === 1, 'same movement revision recapture must reuse one deterministic outbox event');
    phase4DrawerAssert($recapturedSaleEvents[0]['payload_hash'] === $saleEvents[0]['payload_hash'], 'mutable session changes must not alter movement revision payload');
    phase4DrawerExpectException(
        static function () use ($conn, $session, $syncConfig): void {
            (new OperationalSyncEventService())->recordRowSnapshot(
                $conn,
                'drawer_session',
                (int) $session['id'],
                ['config' => $syncConfig]
            );
        },
        'Drawer sessions require the typed movement or shift-close sync contract.'
    );
    $transferred = $service->transferOpenSessionRegister($conn, (int) $session['id'], 78, [
        'authorized_by' => 99,
        'sync_config' => $syncConfig,
    ]);
    phase4DrawerAssert((int) $transferred['register_id'] === 78, 'register transfer expected');
    $sessionEvents = phase4DrawerSessionOutboxRows($conn, (int) $session['id']);
    phase4DrawerAssert(array_map('intval', array_column($sessionEvents, 'event_version')) === [1, 2], 'open and transfer must emit session revisions 1 and 2');

    $unassigned = $service->recordUnassignedMovement($conn, [
        'movement_type' => 'paid_out',
        'amount' => '2.000',
        'created_by' => 99,
        'tenant' => 3,
        'branch' => 4,
        'reason' => 'unassigned proof',
        'sync_config' => $syncConfig,
    ]);
    phase4DrawerAssert($unassigned !== null && $unassigned['drawer_session_id'] === null, 'unassigned movement expected');
    $unassignedEvents = phase4DrawerOutboxRows($conn, (int) $unassigned['id']);
    $unassignedPayload = json_decode((string) $unassignedEvents[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    phase4DrawerAssert($unassignedPayload['drawer_session'] === null && $unassignedPayload['drawer_session_uuid'] === null, 'unassigned payload must not carry drawer identity');

    $conn->begin_transaction();
    $rolledBack = $service->recordUnassignedMovement($conn, [
        'movement_type' => 'paid_in',
        'amount' => '3.000',
        'created_by' => 99,
        'tenant' => 3,
        'branch' => 4,
        'sync_config' => $syncConfig,
    ]);
    $rolledBackId = (int) $rolledBack['id'];
    phase4DrawerAssert(count(phase4DrawerOutboxRows($conn, $rolledBackId)) === 1, 'caller transaction must see movement outbox before rollback');
    $conn->rollback();
    phase4DrawerAssert((int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE id = {$rolledBackId}")->fetch_assoc()['c'] === 0, 'caller rollback must remove movement');
    phase4DrawerAssert(count(phase4DrawerOutboxRows($conn, $rolledBackId)) === 0, 'caller rollback must remove movement outbox');

    phase4DrawerAssert($service->linkMovementToVoucher($conn, (int) $sale['id'], 7001, ['sync_config' => $syncConfig]), 'voucher link expected');
    phase4DrawerAssert(!$service->linkMovementToVoucher($conn, (int) $sale['id'], 7002, ['sync_config' => $syncConfig]), 'voucher link must be immutable once assigned');
    $saleEvents = phase4DrawerOutboxRows($conn, (int) $sale['id']);
    phase4DrawerAssert(array_map('intval', array_column($saleEvents, 'event_version')) === [1, 2], 'voucher link must append revision 2');

    // Hosted proof: the same drawer UUID already has a deliberately different hosted id.
    $hostedDrawerId = (int) $session['id'] + 1000;
    $hostedUuid = $cloudConn->real_escape_string((string) $session['uuid']);
    $cloudConn->query("
        INSERT INTO drawer_sessions (id, uuid, user_id, tenant, branch, opened_at, opened_by, opening_cash, status)
        VALUES ({$hostedDrawerId}, '{$hostedUuid}', 7, 3, 4, '2026-05-13 09:00:00', 99, 100.125, 'open')
    ");
    $inbox = new SyncInboxService();
    $newer = $inbox->receiveBranchEvent(
        $cloudConn,
        $branchUuid,
        phase4DrawerEventFromOutbox($saleEvents[1]),
        SyncApplyMode::LIVE_APPLY
    );
    phase4DrawerAssert($newer['status'] === 'processed', 'linked movement revision must apply');
    $hostedMovement = $cloudConn->query('SELECT drawer_session_id, ref_ot_head_id FROM drawer_movements WHERE id = ' . (int) $sale['id'])->fetch_assoc();
    phase4DrawerAssert((int) $hostedMovement['drawer_session_id'] === $hostedDrawerId, 'hosted movement must remap local drawer id by UUID');
    phase4DrawerAssert((int) $hostedMovement['ref_ot_head_id'] === 7001, 'hosted movement must retain voucher link');
    $older = $inbox->receiveBranchEvent(
        $cloudConn,
        $branchUuid,
        phase4DrawerEventFromOutbox($saleEvents[0]),
        SyncApplyMode::LIVE_APPLY
    );
    phase4DrawerAssert($older['status'] === 'stale', 'unlinked revision must be stale after linked revision');
    $hostedMovement = $cloudConn->query('SELECT drawer_session_id, ref_ot_head_id FROM drawer_movements WHERE id = ' . (int) $sale['id'])->fetch_assoc();
    phase4DrawerAssert((int) $hostedMovement['drawer_session_id'] === $hostedDrawerId && (int) $hostedMovement['ref_ot_head_id'] === 7001, 'stale event must not revert hosted drawer mapping or voucher link');

    $cloudConn->query("UPDATE drawer_sessions SET status = 'closed', closed_at = '2026-05-13 17:00:00' WHERE id = {$hostedDrawerId}");
    $openingMovementId = (int) $conn->query(
        'SELECT id FROM drawer_movements WHERE drawer_session_id = ' . (int) $session['id'] . " AND movement_type = 'opening' LIMIT 1"
    )->fetch_assoc()['id'];
    $openingEvents = phase4DrawerOutboxRows($conn, $openingMovementId);
    $openingResult = $inbox->receiveBranchEvent(
        $cloudConn,
        $branchUuid,
        phase4DrawerEventFromOutbox($openingEvents[0]),
        SyncApplyMode::LIVE_APPLY
    );
    phase4DrawerAssert($openingResult['status'] === 'processed', 'opening movement must still restore after hosted drawer close');
    $hostedStatus = (string) $cloudConn->query("SELECT status FROM drawer_sessions WHERE id = {$hostedDrawerId}")->fetch_assoc()['status'];
    phase4DrawerAssert($hostedStatus === 'closed', 'embedded open session must not reopen a terminal hosted drawer');

    $unassignedResult = $inbox->receiveBranchEvent(
        $cloudConn,
        $branchUuid,
        phase4DrawerEventFromOutbox($unassignedEvents[0]),
        SyncApplyMode::LIVE_APPLY
    );
    phase4DrawerAssert($unassignedResult['status'] === 'processed', 'unassigned movement must apply');
    $hostedUnassigned = $cloudConn->query('SELECT drawer_session_id FROM drawer_movements WHERE id = ' . (int) $unassigned['id'])->fetch_assoc();
    phase4DrawerAssert($hostedUnassigned['drawer_session_id'] === null, 'hosted unassigned movement must remain unassigned');

    $maliciousEvent = phase4DrawerEventFromOutbox($saleEvents[1]);
    $maliciousEvent['payload']['drawer_session']['open_branch_lock'] = 'branch-local-lock';
    phase4DrawerExpectException(
        static function () use ($cloudConn, $branchUuid, $maliciousEvent): void {
            (new CloudOperationalMirrorService())->applyFromBranchEvent($cloudConn, $branchUuid, $maliciousEvent);
        },
        'DRAWER_MOVEMENT_SESSION_FIELD_INVALID'
    );

    $service->recordMovement($conn, $session['id'], [
        'movement_type' => 'refund_cash',
        'amount' => '10.000',
        'created_by' => 99,
    ]);
    $service->recordMovement($conn, $session['id'], [
        'movement_type' => 'paid_in',
        'amount' => '20.125',
        'created_by' => 99,
    ]);
    $service->recordMovement($conn, $session['id'], [
        'movement_type' => 'paid_out',
        'amount' => '5.500',
        'created_by' => 99,
    ]);
    $service->recordMovement($conn, $session['id'], [
        'movement_type' => 'safe_drop',
        'amount' => '30.000',
        'created_by' => 99,
    ]);

    phase4DrawerAssert($service->expectedCash($conn, $session['id']) === '125.000', 'expected cash should match signed movement math');
    phase4DrawerAssert(count($service->movementsForSession($conn, $session['id'])) === 6, 'six drawer movements expected including opening');

    $closed = $service->closeSession($conn, $session['id'], [
        'closed_by' => 101,
        'counted_cash' => '124.500',
        'closed_at' => '2026-05-13 17:00:00',
        'notes' => 'نقص بسيط',
    ], ['sync_config' => $syncConfig]);
    phase4DrawerAssert($closed['status'] === 'closed', 'session should close');
    phase4DrawerAssert($closed['expected_cash'] === '124.500', 'closed expected cash should include closing adjustment');
    phase4DrawerAssert($closed['counted_cash'] === '124.500', 'counted cash mismatch');
    phase4DrawerAssert($closed['difference'] === '-0.500', 'difference should keep pre-close over/short (counted - expected before adjustment)');
    phase4DrawerAssert($service->findOpenSession($conn, 7, 3, 4) === null, 'closed session should no longer be open');
    phase4DrawerExpectException(function () use ($service, $conn, $session, $syncConfig) {
        $service->captureExternalSessionMutation($conn, (int) $session['id'], ['sync_config' => $syncConfig]);
    }, 'DRAWER_SESSION_SYNC_TRANSACTION_REQUIRED');

    $conn->begin_transaction();
    $conn->query(
        'INSERT INTO drawer_count_attempts ('
        . 'drawer_session_id, count_phase, attempt_number, counted_amount, expected_amount, variance,'
        . ' matched, expected_snapshot_json, tenant, branch, created_by, created_at'
        . ') VALUES (' . (int) $session['id'] . ", 'close', 1, 124.500, 125.000, -0.500,"
        . " 0, JSON_OBJECT('source', 'phase4'), 3, 4, 101, '2026-05-13 17:00:00')"
    );
    $conn->query(
        "UPDATE drawer_sessions SET variance_status = 'unresolved', variance_type = 'closing'"
        . ', close_expected_snapshot = 125.000 WHERE id = ' . (int) $session['id']
    );
    $rolledBackFinal = $service->captureExternalSessionMutation(
        $conn,
        (int) $session['id'],
        ['sync_config' => $syncConfig]
    );
    phase4DrawerAssert((int) ($rolledBackFinal['sync_revision'] ?? 0) === 4, 'external mutation must increment the session revision inside the caller transaction');
    phase4DrawerAssert(count(phase4DrawerSessionOutboxRows($conn, (int) $session['id'])) === 4, 'external mutation event must be visible before caller rollback');
    $conn->rollback();
    $afterExternalRollback = $service->sessionById($conn, (int) $session['id']);
    phase4DrawerAssert((int) ($afterExternalRollback['sync_revision'] ?? 0) === 3, 'caller rollback must restore the prior session revision');
    phase4DrawerAssert(count(phase4DrawerSessionOutboxRows($conn, (int) $session['id'])) === 3, 'caller rollback must remove the external mutation event');
    phase4DrawerAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM drawer_count_attempts WHERE drawer_session_id = ' . (int) $session['id'])->fetch_assoc()['c'] === 0,
        'caller rollback must remove linked count audit rows with the session event'
    );

    $conn->begin_transaction();
    $conn->query(
        'INSERT INTO drawer_count_attempts ('
        . 'drawer_session_id, count_phase, attempt_number, counted_amount, expected_amount, variance,'
        . ' matched, expected_snapshot_json, tenant, branch, created_by, created_at'
        . ') VALUES (' . (int) $session['id'] . ", 'close', 1, 124.500, 125.000, -0.500,"
        . " 0, JSON_OBJECT('source', 'phase4'), 3, 4, 101, '2026-05-13 17:00:00')"
    );
    $conn->query(
        "UPDATE drawer_sessions SET variance_status = 'unresolved', variance_type = 'closing'"
        . ', close_expected_snapshot = 125.000 WHERE id = ' . (int) $session['id']
    );
    $finalSession = $service->captureExternalSessionMutation(
        $conn,
        (int) $session['id'],
        ['sync_config' => $syncConfig]
    );
    $conn->commit();
    phase4DrawerAssert((int) ($finalSession['sync_revision'] ?? 0) === 4, 'committed final metadata must use the next session revision');
    $sessionEvents = phase4DrawerSessionOutboxRows($conn, (int) $session['id']);
    phase4DrawerAssert(array_map('intval', array_column($sessionEvents, 'event_version')) === [1, 2, 3, 4], 'open, transfer, core close and final metadata must emit monotonic session revisions');
    $finalPayload = json_decode((string) $sessionEvents[3]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    phase4DrawerAssert(($finalPayload['drawer_session']['variance_status'] ?? '') === 'unresolved', 'final session event must contain final variance status');
    phase4DrawerAssert(($finalPayload['drawer_session']['variance_type'] ?? '') === 'closing', 'final session event must contain final variance type');
    phase4DrawerAssert(count($finalPayload['count_attempts'] ?? []) === 1, 'final session event must carry linked count history');
    phase4DrawerAssert(($finalPayload['resolutions'] ?? null) === [], 'unresolved session event must not invent a resolution');
    $sourceAttemptId = (int) ($finalPayload['count_attempts'][0]['id'] ?? 0);
    $cloudConn->query(
        'INSERT INTO drawer_count_attempts ('
        . 'id, drawer_session_id, count_phase, attempt_number, counted_amount, expected_amount, variance,'
        . ' matched, expected_snapshot_json, tenant, branch, created_by, created_at'
        . ") VALUES ({$sourceAttemptId}, 999999, 'close', 9, 1.000, 1.000, 0.000,"
        . " 1, JSON_OBJECT('source', 'unrelated'), 9, 9, 9, '2026-05-01 00:00:00')"
    );
    $closeSessionResult = $inbox->receiveBranchEvent(
        $cloudConn,
        $branchUuid,
        phase4DrawerEventFromOutbox($sessionEvents[3]),
        SyncApplyMode::LIVE_APPLY
    );
    phase4DrawerAssert($closeSessionResult['status'] === 'processed', 'final terminal session revision must apply');
    $hostedAttempt = $cloudConn->query(
        "SELECT id, drawer_session_id FROM drawer_count_attempts"
        . " WHERE drawer_session_id = {$hostedDrawerId} AND count_phase = 'close' AND attempt_number = 1"
    )->fetch_assoc();
    phase4DrawerAssert((int) ($hostedAttempt['drawer_session_id'] ?? 0) === $hostedDrawerId, 'hosted count attempt remaps to drawer UUID');
    phase4DrawerAssert((int) ($hostedAttempt['id'] ?? 0) !== $sourceAttemptId, 'hosted count attempt does not overwrite a colliding source id');
    $coreCloseSessionResult = $inbox->receiveBranchEvent(
        $cloudConn,
        $branchUuid,
        phase4DrawerEventFromOutbox($sessionEvents[2]),
        SyncApplyMode::LIVE_APPLY
    );
    phase4DrawerAssert($coreCloseSessionResult['status'] === 'stale', 'core close revision must be stale after final metadata revision');
    $openSessionResult = $inbox->receiveBranchEvent(
        $cloudConn,
        $branchUuid,
        phase4DrawerEventFromOutbox($sessionEvents[0]),
        SyncApplyMode::LIVE_APPLY
    );
    phase4DrawerAssert($openSessionResult['status'] === 'stale', 'open session revision must be stale after terminal revision');
    $hostedSession = $cloudConn->query("SELECT status, variance_status, variance_type FROM drawer_sessions WHERE id = {$hostedDrawerId}")->fetch_assoc();
    phase4DrawerAssert(($hostedSession['status'] ?? '') === 'closed', 'stale session revision must not reopen hosted drawer');
    phase4DrawerAssert(($hostedSession['variance_status'] ?? '') === 'unresolved', 'stale core close must not downgrade final variance status');
    phase4DrawerAssert(($hostedSession['variance_type'] ?? '') === 'closing', 'stale core close must not downgrade final variance type');

    $conn->begin_transaction();
    $conn->query(
        'INSERT INTO drawer_session_resolutions ('
        . 'drawer_session_id, variance_type, variance_amount, resolved_by, resolved_at,'
        . ' resolution_notes, resolution_reason_code, prior_status, snapshot_json'
        . ') VALUES (' . (int) $session['id'] . ", 'closing', -0.500, 101, '2026-05-13 17:05:00',"
        . " 'manager confirmed', 'recount_confirmed', 'unresolved', JSON_OBJECT('difference', '-0.500'))"
    );
    $conn->query(
        "UPDATE drawer_sessions SET variance_status = 'resolved' WHERE id = " . (int) $session['id']
    );
    $resolvedSession = $service->captureExternalSessionMutation(
        $conn,
        (int) $session['id'],
        ['sync_config' => $syncConfig]
    );
    $conn->commit();
    phase4DrawerAssert((int) ($resolvedSession['sync_revision'] ?? 0) === 5, 'resolution audit bundle uses the next session revision');
    $resolvedEvents = phase4DrawerSessionOutboxRows($conn, (int) $session['id']);
    $resolvedPayload = json_decode((string) $resolvedEvents[4]['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    phase4DrawerAssert(count($resolvedPayload['count_attempts'] ?? []) === 1, 'resolution bundle retains complete count history');
    phase4DrawerAssert(count($resolvedPayload['resolutions'] ?? []) === 1, 'resolution bundle carries complete resolution history');
    $sourceResolutionId = (int) ($resolvedPayload['resolutions'][0]['id'] ?? 0);
    $cloudConn->query(
        'INSERT INTO drawer_session_resolutions ('
        . 'id, drawer_session_id, variance_type, variance_amount, resolved_by, resolved_at,'
        . ' resolution_notes, resolution_reason_code, prior_status, snapshot_json'
        . ") VALUES ({$sourceResolutionId}, 999999, 'opening', 1.000, 9, '2026-05-01 00:00:00',"
        . " 'unrelated', 'other', 'unresolved', JSON_OBJECT('source', 'unrelated'))"
    );
    $resolvedEvent = phase4DrawerEventFromOutbox($resolvedEvents[4]);
    $resolvedResult = $inbox->receiveBranchEvent(
        $cloudConn,
        $branchUuid,
        $resolvedEvent,
        SyncApplyMode::LIVE_APPLY
    );
    phase4DrawerAssert($resolvedResult['status'] === 'processed', 'resolution audit bundle must apply');
    $hostedResolution = $cloudConn->query(
        "SELECT id, drawer_session_id, resolution_reason_code FROM drawer_session_resolutions"
        . " WHERE drawer_session_id = {$hostedDrawerId} AND resolved_at = '2026-05-13 17:05:00'"
    )->fetch_assoc();
    phase4DrawerAssert((int) ($hostedResolution['drawer_session_id'] ?? 0) === $hostedDrawerId, 'hosted resolution remaps to drawer UUID');
    phase4DrawerAssert((int) ($hostedResolution['id'] ?? 0) !== $sourceResolutionId, 'hosted resolution does not overwrite a colliding source id');
    phase4DrawerAssert(($hostedResolution['resolution_reason_code'] ?? '') === 'recount_confirmed', 'hosted resolution retains structured reason');
    $duplicateResolution = $inbox->receiveBranchEvent(
        $cloudConn,
        $branchUuid,
        $resolvedEvent,
        SyncApplyMode::LIVE_APPLY
    );
    phase4DrawerAssert($duplicateResolution['status'] === 'duplicate', 'resolution audit replay is idempotent');
    phase4DrawerAssert(
        (int) $cloudConn->query("SELECT COUNT(*) AS c FROM drawer_session_resolutions WHERE drawer_session_id = {$hostedDrawerId}")->fetch_assoc()['c'] === 1,
        'resolution audit replay must not duplicate rows'
    );

    $legacyPayload = $resolvedPayload;
    unset($legacyPayload['count_attempts'], $legacyPayload['resolutions']);
    $legacyPayload['sync_revision'] = 1;
    $legacyPayload['drawer_session']['id'] = 900001;
    $legacyPayload['drawer_session']['uuid'] = '90000000-0000-4000-8000-000000000001';
    $legacyPayload['drawer_session']['sync_revision'] = 1;
    (new CloudOperationalMirrorService())->applyFromBranchEvent($cloudConn, $branchUuid, ['payload' => $legacyPayload]);
    phase4DrawerAssert(
        (int) $cloudConn->query("SELECT COUNT(*) AS c FROM drawer_sessions WHERE uuid = '90000000-0000-4000-8000-000000000001'")->fetch_assoc()['c'] === 1,
        'legacy drawer-session payload without audit arrays remains compatible'
    );

    $conn->begin_transaction();
    $rolledBackSession = $service->openSession($conn, [
        'user_id' => 70,
        'opened_by' => 70,
        'tenant' => 3,
        'branch' => 4,
        'opening_cash' => '10.000',
        'in_transaction' => true,
        'sync_config' => $syncConfig,
    ]);
    $rolledBackSessionId = (int) $rolledBackSession['id'];
    $rolledBackOpeningId = (int) $conn->query(
        "SELECT id FROM drawer_movements WHERE drawer_session_id = {$rolledBackSessionId} AND movement_type = 'opening' LIMIT 1"
    )->fetch_assoc()['id'];
    phase4DrawerAssert(count(phase4DrawerSessionOutboxRows($conn, $rolledBackSessionId)) === 1, 'caller transaction must see session event before rollback');
    phase4DrawerAssert(count(phase4DrawerOutboxRows($conn, $rolledBackOpeningId)) === 1, 'caller transaction must see opening movement event before rollback');
    $conn->rollback();
    phase4DrawerAssert((int) $conn->query("SELECT COUNT(*) AS c FROM drawer_sessions WHERE id = {$rolledBackSessionId}")->fetch_assoc()['c'] === 0, 'caller rollback must remove drawer session');
    phase4DrawerAssert((int) $conn->query("SELECT COUNT(*) AS c FROM drawer_movements WHERE id = {$rolledBackOpeningId}")->fetch_assoc()['c'] === 0, 'caller rollback must remove opening movement');
    phase4DrawerAssert(count(phase4DrawerSessionOutboxRows($conn, $rolledBackSessionId)) === 0, 'caller rollback must remove session outbox');
    phase4DrawerAssert(count(phase4DrawerOutboxRows($conn, $rolledBackOpeningId)) === 0, 'caller rollback must remove opening movement outbox');

    phase4DrawerExpectException(function () use ($service, $conn, $session) {
        $service->recordMovement($conn, $session['id'], [
            'movement_type' => 'sale_cash',
            'amount' => '1.000',
            'created_by' => 1,
        ]);
    }, 'DRAWER_SESSION_NOT_OPEN');

    phase4DrawerExpectException(function () use ($service, $conn, $session) {
        $service->closeSession($conn, $session['id'], [
            'closed_by' => 1,
            'counted_cash' => '1.000',
        ]);
    }, 'DRAWER_SESSION_NOT_OPEN');

    $forced = $service->openSession($conn, [
        'user_id' => 8,
        'tenant' => 3,
        'branch' => 4,
        'opening_cash' => '0.000',
    ]);
    $forcedClosed = $service->forceCloseSession($conn, $forced['id'], [
        'closed_by' => 102,
        'counted_cash' => '0.000',
        'notes' => 'force close test',
    ]);
    phase4DrawerAssert($forcedClosed['status'] === 'forced_closed', 'force close status expected');

    phase4DrawerExpectException(function () use ($service, $conn, $forced) {
        $service->recordMovement($conn, $forced['id'], [
            'movement_type' => 'tip',
            'amount' => '1.000',
            'created_by' => 1,
        ]);
    }, 'DRAWER_SESSION_NOT_OPEN');

    $invalid = $service->openSession($conn, [
        'user_id' => 9,
        'opening_cash' => '0.000',
    ]);
    phase4DrawerExpectException(function () use ($service, $conn, $invalid) {
        $service->recordMovement($conn, $invalid['id'], [
            'movement_type' => 'tip',
            'amount' => '1.000',
            'created_by' => 1,
        ]);
    }, 'DRAWER_MOVEMENT_TYPE_INVALID');
    phase4DrawerExpectException(function () use ($service, $conn, $invalid) {
        $service->recordMovement($conn, $invalid['id'], [
            'movement_type' => 'sale_cash',
            'amount' => '0',
            'created_by' => 1,
        ]);
    }, 'DRAWER_AMOUNT_INVALID');

    echo "phase4-drawer-session-service-ok db={$db}\n";
} finally {
    if ($cloudConn instanceof mysqli) {
        $cloudConn->close();
    }
    $conn->query("DROP DATABASE IF EXISTS `{$cloudDb}`");
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function phase4DrawerExpectException(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        phase4DrawerAssert($e->getMessage() === $message, "expected {$message}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$message}");
}

function phase4DrawerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function phase4DrawerOutboxRows(mysqli $conn, int $movementId): array
{
    $result = $conn->query(
        "SELECT * FROM sync_outbox WHERE aggregate_type = 'drawer_movement'"
        . ' AND aggregate_local_id = ' . $movementId
        . ' ORDER BY event_version ASC, id ASC'
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function phase4DrawerSessionOutboxRows(mysqli $conn, int $sessionId): array
{
    $result = $conn->query(
        "SELECT * FROM sync_outbox WHERE aggregate_type = 'drawer_session'"
        . ' AND aggregate_local_id = ' . $sessionId
        . ' ORDER BY event_version ASC, id ASC'
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function phase4DrawerEventFromOutbox(array $row): array
{
    return [
        'event_uuid' => (string) $row['event_uuid'],
        'idempotency_key' => (string) $row['idempotency_key'],
        'payload_hash' => (string) $row['payload_hash'],
        'event_type' => (string) $row['event_type'],
        'event_version' => (int) $row['event_version'],
        'source_system' => (string) $row['source_system'],
        'aggregate_type' => (string) $row['aggregate_type'],
        'aggregate_uuid' => (string) $row['aggregate_uuid'],
        'aggregate_local_id' => (int) $row['aggregate_local_id'],
        'entity_type' => (string) $row['entity_type'],
        'entity_uuid' => (string) $row['entity_uuid'],
        'entity_local_id' => (int) $row['entity_local_id'],
        'payload' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
    ];
}
