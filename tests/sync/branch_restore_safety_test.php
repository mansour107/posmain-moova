<?php

require_once __DIR__ . '/../../classes/Sync/BranchRestoreFromHostedService.php';
require_once __DIR__ . '/../../classes/Sync/RestoreEventPhase.php';

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$dbName = 'posmain_restore_guard_' . getmypid();
$branchUuid = 'abababab-1212-4121-8121-abababababab';
$backup = tempnam(sys_get_temp_dir(), 'posmain-restore-backup-');
$pidFile = tempnam(sys_get_temp_dir(), 'posmain-restore-pid-');

if (!is_string($backup) || !is_string($pidFile)) {
    throw new RuntimeException('Unable to create restore safety fixtures.');
}
file_put_contents($backup, "-- disposable restore safety backup\n");
file_put_contents($pidFile, (string) getmypid() . "\n");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = new mysqli($host, $user, $pass, '', $port);

try {
    $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->query("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn = new mysqli($host, $user, $pass, $dbName, $port);
    $conn->query("
        CREATE TABLE sync_branch_identity (
            id TINYINT UNSIGNED NOT NULL,
            branch_uuid CHAR(36) NOT NULL,
            cloud_base_url VARCHAR(500) NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB
    ");

    $config = [
        'role' => 'branch',
        'branch' => [
            'uuid' => $branchUuid,
            'cloud_base_url' => 'https://hosted.example.test',
        ],
        'sync' => [
            'branch_secret' => 'restore-safety-secret',
            'cloud_pull_enabled' => false,
            'image_sync_enabled' => false,
        ],
    ];
    $httpGet = static function (): array {
        return [
            'ok' => true,
            'status' => 200,
            'json' => [
                'ok' => true,
                'source' => 'inbox',
                'events' => [],
                'next_after_id' => 0,
                'has_more' => false,
            ],
        ];
    };
    $service = new BranchRestoreFromHostedService();
    $dryRun = $service->restore($conn, $config, [
        'apply' => false,
        'phases' => RestoreEventPhase::all(),
        'http_get' => $httpGet,
    ]);

    branchRestoreSafetyAssert(empty($dryRun['apply']), 'dry-run must not apply events');
    branchRestoreSafetyAssert(!empty($dryRun['safety']['apply_allowed']), 'empty database dry-run should be eligible');
    branchRestoreSafetyAssert((int) $dryRun['safety']['expected_events'] === 0, 'empty hosted fixture should plan zero events');
    branchRestoreSafetyAssert(
        (int) $conn->query('SELECT COUNT(*) AS c FROM sync_branch_identity')->fetch_assoc()['c'] === 0,
        'dry-run must not create or update branch identity'
    );

    branchRestoreSafetyExpectBlocked(
        static fn () => $service->restore($conn, $config, [
            'apply' => true,
            'phases' => RestoreEventPhase::all(),
            'http_get' => $httpGet,
        ]),
        'restore_backup_file_missing'
    );

    $authorized = [
        'apply' => true,
        'phases' => RestoreEventPhase::all(),
        'http_get' => $httpGet,
        'scope' => BranchRestoreSafetyGuard::SCOPE_EMPTY,
        'backup_file' => $backup,
        'workers_stopped' => true,
        'worker_pid_file' => $pidFile . '.stopped',
        'dry_run_manifest' => $dryRun['safety']['manifest_hash'],
        'expected_events' => $dryRun['safety']['expected_events'],
        'confirmation_token' => $dryRun['safety']['confirmation_token'],
    ];
    $applied = $service->restore($conn, $config, $authorized);
    branchRestoreSafetyAssert(!empty($applied['apply']), 'authorized restore should enter apply mode');
    branchRestoreSafetyAssert(!empty($applied['reconciliation']['ok']), 'authorized empty restore should reconcile');
    branchRestoreSafetyAssert((int) $applied['mirrored'] === 0, 'zero-event restore should not write business rows');

    branchRestoreSafetyExpectBlocked(
        static fn () => $service->restore($conn, $config, array_merge($authorized, [
            'worker_pid_file' => $pidFile,
        ])),
        'restore_worker_process_is_still_active'
    );

    $conn->query('CREATE TABLE myitems (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $conn->query('INSERT INTO myitems (id) VALUES (1)');
    $nonEmptyPlan = $service->restore($conn, $config, [
        'apply' => false,
        'phases' => RestoreEventPhase::all(),
        'http_get' => $httpGet,
    ]);
    branchRestoreSafetyAssert(empty($nonEmptyPlan['safety']['apply_allowed']), 'non-empty database must not be eligible');
    branchRestoreSafetyAssert((int) ($nonEmptyPlan['safety']['non_empty_tables']['myitems'] ?? 0) === 1, 'blocker must identify non-empty table');

    $nonEmptyOptions = $authorized;
    $nonEmptyOptions['dry_run_manifest'] = $nonEmptyPlan['safety']['manifest_hash'];
    $nonEmptyOptions['confirmation_token'] = $nonEmptyPlan['safety']['confirmation_token'];
    branchRestoreSafetyExpectBlocked(
        static fn () => $service->restore($conn, $config, $nonEmptyOptions),
        'restore_target_business_database_is_not_empty'
    );

    touch($backup, time() - 25 * 3600);
    $evidence = (new BranchRestoreSafetyGuard())->backupEvidence($backup, 24);
    branchRestoreSafetyAssert(empty($evidence['ok']), 'stale backup must be rejected');
    branchRestoreSafetyAssert(($evidence['blocker'] ?? '') === 'restore_backup_file_too_old', 'stale backup blocker should be explicit');

    $conn->close();
    echo "branch-restore-safety-ok db={$dbName}\n";
} finally {
    $root->query("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->close();
    @unlink($backup);
    @unlink($pidFile);
}

function branchRestoreSafetyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function branchRestoreSafetyExpectBlocked(callable $callback, string $reason): void
{
    try {
        $callback();
    } catch (RuntimeException $e) {
        branchRestoreSafetyAssert(strpos($e->getMessage(), $reason) !== false, 'expected blocker ' . $reason . ', got: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected restore blocker: ' . $reason);
}
