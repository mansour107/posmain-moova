<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchRestoreRunService.php';

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$dbName = 'posmain_restore_run_' . getmypid();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = new mysqli($host, $user, $pass, '', $port);

try {
    $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn = new mysqli($host, $user, $pass, $dbName, $port);
    $schema = new SyncSchemaManager();
    $conn->query($schema->plannedStatements()['sync_branch_restore_runs']);

    $binding = [
        'run_uuid' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
        'branch_uuid' => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb',
        'contract_version' => 2,
        'source' => 'cloud_snapshot',
        'recovery_profile' => 'operational_v1',
        'snapshot_checkpoint' => 123,
        'history_since_utc' => '2026-06-17T00:00:00Z',
        'manifest_hash' => str_repeat('a', 64),
        'expected_events' => 3,
        'confirmation_token' => 'RESTORE_EMPTY_BBBBBBBB2222_AAAAAAAAAAAAAAAA',
        'backup_sha256' => str_repeat('b', 64),
    ];
    $service = new BranchRestoreRunService();
    $prepared = $service->prepare($conn, $binding);
    branchRestoreRunAssert($prepared['status'] === BranchRestoreRunService::STATUS_PREPARED, 'run must start prepared');
    branchRestoreRunExpectFailure(
        static fn () => $service->assertResumeBinding($conn, $binding['run_uuid'], $binding),
        'not incomplete and resumable'
    );

    $running = $service->start($conn, $binding['run_uuid']);
    branchRestoreRunAssert($running['status'] === BranchRestoreRunService::STATUS_RUNNING, 'prepared run must start once');
    branchRestoreRunAssert($service->acquireWriterLock($conn), 'first recovery writer lock must be acquired');
    $secondConn = new mysqli($host, $user, $pass, $dbName, $port);
    branchRestoreRunAssert(!$service->acquireWriterLock($secondConn), 'second recovery writer must be refused');
    $secondConn->close();
    $service->releaseWriterLock($conn);
    $service->assertResumeBinding($conn, $binding['run_uuid'], $binding);
    branchRestoreRunExpectFailure(
        static fn () => $service->assertResumeBinding($conn, $binding['run_uuid'], array_merge($binding, [
            'snapshot_checkpoint' => 124,
        ])),
        'snapshot_checkpoint'
    );

    $running = $service->advancePage($conn, $binding['run_uuid'], RestoreEventPhase::MENU, 0, 10, true, [
        'pages' => 1, 'fetched' => 1, 'mirrored' => 1,
    ]);
    branchRestoreRunAssert((int) $running['phase_state'][RestoreEventPhase::MENU]['cursor'] === 10, 'menu cursor must persist');
    branchRestoreRunAssert((int) $running['phase_state'][RestoreEventPhase::MENU]['pages'] === 1, 'menu page count must persist');
    branchRestoreRunExpectFailure(
        static fn () => $service->advancePage($conn, $binding['run_uuid'], RestoreEventPhase::TABLES, 9, 10, true, []),
        'cursor changed'
    );
    $service->advancePage($conn, $binding['run_uuid'], RestoreEventPhase::TABLES, 0, 20, true, [
        'pages' => 1, 'fetched' => 1, 'mirrored' => 1,
    ]);
    $service->advancePage($conn, $binding['run_uuid'], RestoreEventPhase::ORDERS, 0, 30, true, [
        'pages' => 1, 'fetched' => 1, 'mirrored' => 1,
    ]);
    branchRestoreRunExpectFailure(
        static fn () => $service->complete($conn, $binding['run_uuid']),
        'every phase'
    );
    $service->advancePage($conn, $binding['run_uuid'], RestoreEventPhase::OPERATIONAL, 0, 0, true, []);
    $completed = $service->complete($conn, $binding['run_uuid']);
    branchRestoreRunAssert($completed['status'] === BranchRestoreRunService::STATUS_COMPLETED, 'exactly reconciled run must complete');
    branchRestoreRunAssert((int) $completed['fetched'] === 3 && (int) $completed['mirrored'] === 3, 'completion counters must match manifest');
    branchRestoreRunExpectFailure(
        static fn () => $service->assertResumeBinding($conn, $binding['run_uuid'], $binding),
        'not incomplete and resumable'
    );

    $conn->close();
    echo "branch-restore-run-service-ok db={$dbName}\n";
} finally {
    $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->close();
}

function branchRestoreRunAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function branchRestoreRunExpectFailure(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        branchRestoreRunAssert(
            strpos($e->getMessage(), $messagePart) !== false,
            'expected failure containing ' . $messagePart . ', got: ' . $e->getMessage()
        );
        return;
    }
    throw new RuntimeException('Expected restore run failure containing: ' . $messagePart);
}
