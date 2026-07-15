<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionCloseSummaryService.php';
require_once __DIR__ . '/../../classes/Sync/ShiftCloseSyncPayloadService.php';
require_once __DIR__ . '/../../classes/Sync/CloudOperationalMirrorService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_drawer_close_summary_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

function closeSummaryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    createCloseSummarySchema($conn);
    // Simulate code deployment arriving before the migration runner. The
    // close-path preflight must add only its new table and continue safely.
    $conn->query('DROP TABLE drawer_session_close_summaries');
    $service = new DrawerSessionCloseSummaryService();
    $service->ensureSchema($conn);
    closeSummaryAssert(
        $conn->query("SHOW TABLES LIKE 'drawer_session_close_summaries'")->num_rows === 1,
        'close summary schema preflight must provision the additive table'
    );
    $service->ensureSchema($conn);
    $conn->query("INSERT INTO drawer_sessions
        (id, uuid, user_id, tenant, branch, opened_at, closed_at, expected_cash, counted_cash, difference, status, variance_status, variance_type)
        VALUES
        (1, '11111111-1111-4111-a111-111111111111', 9, 1, 2, '2026-07-15 08:00:00', '2026-07-15 16:00:00', 150, 148, -2, 'closed', 'unresolved', 'closing'),
        (2, '22222222-2222-4222-a222-222222222222', 9, 1, 2, '2026-07-14 08:00:00', '2026-07-14 16:00:00', 90, 90, 0, 'closed', 'none', 'none'),
        (3, '33333333-3333-4333-a333-333333333333', 9, 1, 2, '2026-07-13 08:00:00', '2026-07-13 16:00:00', 75, 75, 0, 'closed', 'none', 'none')");

    $conn->begin_transaction();
    $service->createForSession($conn, 1, [
        'shift_number' => 'S-1',
        'total_orders' => 3,
        'total_sales' => 200,
        'cash_sales' => 150,
        'non_cash_sales' => 50,
        'expense_total' => 5,
        'close_path' => 'integration_test',
        'report_snapshot' => ['z' => true],
    ]);
    $conn->rollback();
    closeSummaryAssert((int) $conn->query('SELECT COUNT(*) c FROM drawer_session_close_summaries')->fetch_assoc()['c'] === 0, 'summary insert must roll back with its parent close');

    $summary = $service->createForSession($conn, 1, [
        'shift_number' => 'S-1',
        'total_orders' => 3,
        'total_sales' => 200,
        'cash_sales' => 150,
        'non_cash_sales' => 50,
        'expense_total' => 5,
        'close_path' => 'integration_test',
        'report_snapshot' => ['z' => true],
    ]);
    closeSummaryAssert((int) ($summary['drawer_session_id'] ?? 0) === 1, 'summary must belong to its drawer session');
    try {
        $service->createForSession($conn, 1, ['shift_number' => 'duplicate']);
        throw new RuntimeException('duplicate summary should fail');
    } catch (RuntimeException $exception) {
        closeSummaryAssert($exception->getMessage() === 'DRAWER_CLOSE_SUMMARY_ALREADY_EXISTS', 'one-to-one summary must reject duplicate close writes');
    }

    $payload = (new ShiftCloseSyncPayloadService())->build(
        $conn,
        (int) $summary['id'],
        'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa'
    );
    closeSummaryAssert((int) ($payload['schema_version'] ?? 0) === 2, 'new close payload must be v2');
    closeSummaryAssert(($payload['drawer_session_uuid'] ?? '') === '11111111-1111-4111-a111-111111111111', 'payload must use drawer_session_uuid');
    closeSummaryAssert(!isset($payload['shift']['legacy']), 'v2 writer must not emit a legacy close row');

    $remotePayload = $payload;
    $remotePayload['drawer_session_uuid'] = '33333333-3333-4333-a333-333333333333';
    $remotePayload['close_uuid'] = 'bbbbbbbb-bbbb-4bbb-abbb-bbbbbbbbbbbb';
    $remotePayload['shift']['local_drawer_session_id'] = 3003;
    $remotePayload['shift']['drawer_session_uuid'] = '33333333-3333-4333-a333-333333333333';
    $remotePayload['shift']['close_uuid'] = 'bbbbbbbb-bbbb-4bbb-abbb-bbbbbbbbbbbb';
    $remotePayload['close_summary']['uuid'] = 'bbbbbbbb-bbbb-4bbb-abbb-bbbbbbbbbbbb';
    // Deliberately retain source id=1 to prove v2 restore does not overwrite
    // the unrelated local summary that already owns that auto-increment id.
    (new CloudOperationalMirrorService())->applyFromBranchEvent($conn, 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', [
        'payload' => $remotePayload,
    ]);
    $restored = $service->findBySessionId($conn, 3);
    closeSummaryAssert($restored !== null, 'v2 restore must resolve the local drawer by UUID');
    closeSummaryAssert((int) ($restored['id'] ?? 0) !== (int) ($summary['id'] ?? 0), 'v2 restore must allocate a local close-summary id');
    closeSummaryAssert(($service->findBySessionId($conn, 1)['uuid'] ?? '') === ($summary['uuid'] ?? ''), 'v2 restore must not overwrite an id collision');

    $legacyRestore = (new CloudOperationalMirrorService())->applyFromBranchEvent(
        $conn,
        'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa',
        [
            'payload' => [
                'schema_version' => 1,
                'snapshot_type' => 'shift_close',
                'shift' => [
                    'local_closed_order_id' => 900,
                    'cashier_user_id' => 9,
                    'opened_at' => '2026-07-12 08:00:00',
                    'closed_at' => '2026-07-12 16:00:00',
                    'actual_cash' => 50,
                    'cash_deficit' => -2,
                    'legacy' => ['id' => 900, 'shift' => 'LEGACY-900', 'total_sales' => 75],
                ],
            ],
        ]
    );
    closeSummaryAssert(!empty($legacyRestore['recovered_legacy_shift_close']), 'unlinked v1 close must report explicit recovery');
    $legacyDrawer = $conn->query("SELECT id FROM drawer_sessions WHERE notes = 'Recovered from unlinked v1 shift-close backup' LIMIT 1")->fetch_assoc();
    closeSummaryAssert((int) ($legacyDrawer['id'] ?? 0) > 0, 'unlinked v1 close must materialize a canonical drawer session');
    closeSummaryAssert($service->findBySessionId($conn, (int) $legacyDrawer['id']) !== null, 'recovered v1 drawer must own a close summary');

    $conn->query("INSERT INTO closed_orders
        (shift, drawer_session_id, total_sales, total_cash, total_visa, actual_visa, expenses, exp_notes, json_details)
        VALUES
        ('S-2', 2, 120, 90, 30, 30, 4, 'legacy linked', JSON_OBJECT('source', 'legacy')),
        ('ORPHAN', NULL, 10, 10, 0, 0, 0, NULL, NULL)");
    $schema = new SyncSchemaManager();
    $pending = $schema->pendingStatements($conn);
    closeSummaryAssert(isset($pending['closed_orders.backfill_close_summaries']), 'first retirement pass must backfill linked close rows');
    closeSummaryAssert(!isset($pending['closed_orders.drop_legacy']), 'legacy table must not drop in the same pass as backfill');
    $conn->query($pending['closed_orders.backfill_close_summaries']);
    closeSummaryAssert((int) $conn->query('SELECT COUNT(*) c FROM drawer_session_close_summaries')->fetch_assoc()['c'] === 4, 'linked legacy session must be backfilled once');

    $pending = $schema->pendingStatements($conn);
    closeSummaryAssert(isset($pending['closed_orders.drop_legacy']), 'second verified pass may retire the legacy table');
    $conn->query($pending['closed_orders.drop_legacy']);
    closeSummaryAssert($conn->query("SHOW TABLES LIKE 'closed_orders'")->num_rows === 0, 'legacy table must be absent after retirement');

    echo "drawer-close-summary-integration-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function createCloseSummarySchema(mysqli $conn): void
{
    $conn->query("CREATE TABLE drawer_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        uuid CHAR(36) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        tenant INT NOT NULL DEFAULT 0,
        branch INT NOT NULL DEFAULT 0,
        register_id BIGINT UNSIGNED NULL,
        fund_account_id BIGINT UNSIGNED NULL,
        opened_at DATETIME NOT NULL,
        business_day DATE NULL,
        opened_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        opening_cash DECIMAL(12,3) NOT NULL DEFAULT 0,
        expected_opening_cash DECIMAL(12,3) NULL,
        opening_variance DECIMAL(12,3) NULL,
        closed_at DATETIME NULL,
        closed_by BIGINT UNSIGNED NULL,
        expected_cash DECIMAL(12,3) NULL,
        counted_cash DECIMAL(12,3) NULL,
        difference DECIMAL(12,3) NULL,
        close_expected_snapshot DECIMAL(12,3) NULL,
        status ENUM('open','closed','forced_closed') NOT NULL DEFAULT 'open',
        variance_status ENUM('none','unresolved','resolved') NOT NULL DEFAULT 'none',
        variance_type ENUM('none','opening','closing','both') NOT NULL DEFAULT 'none',
        open_branch_lock VARCHAR(64) NULL,
        open_register_lock VARCHAR(64) NULL,
        open_user_lock VARCHAR(64) NULL,
        close_token_hash CHAR(64) NULL,
        preceding_session_id BIGINT UNSIGNED NULL,
        takeover_authorized_by BIGINT UNSIGNED NULL,
        notes VARCHAR(500) NULL,
        UNIQUE KEY uq_drawer_sessions_uuid (uuid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE drawer_session_close_summaries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        uuid CHAR(36) NOT NULL,
        drawer_session_id BIGINT UNSIGNED NOT NULL,
        shift_number VARCHAR(64) NOT NULL,
        total_orders INT UNSIGNED NOT NULL DEFAULT 0,
        total_sales DECIMAL(12,3) NOT NULL DEFAULT 0,
        cash_sales DECIMAL(12,3) NOT NULL DEFAULT 0,
        non_cash_sales DECIMAL(12,3) NOT NULL DEFAULT 0,
        discount_total DECIMAL(12,3) NOT NULL DEFAULT 0,
        return_total DECIMAL(12,3) NOT NULL DEFAULT 0,
        expense_total DECIMAL(12,3) NOT NULL DEFAULT 0,
        expense_notes VARCHAR(500) NULL,
        expected_non_cash DECIMAL(12,3) NULL,
        counted_non_cash DECIMAL(12,3) NULL,
        non_cash_difference DECIMAL(12,3) NULL,
        close_path VARCHAR(120) NOT NULL,
        report_snapshot_json JSON NULL,
        payment_summary_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_drawer_close_summary_uuid (uuid),
        UNIQUE KEY uq_drawer_close_summary_session (drawer_session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE closed_orders (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        shift VARCHAR(64) NULL,
        drawer_session_id BIGINT UNSIGNED NULL,
        total_sales DECIMAL(12,3) NULL,
        total_cash DECIMAL(12,3) NULL,
        total_visa DECIMAL(12,3) NULL,
        actual_visa DECIMAL(12,3) NULL,
        expenses DECIMAL(12,3) NULL,
        exp_notes VARCHAR(500) NULL,
        json_details JSON NULL,
        crtime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
