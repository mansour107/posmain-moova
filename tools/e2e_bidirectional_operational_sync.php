<?php

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../classes/Sync/BranchCatalogPushService.php';
require_once __DIR__ . '/../classes/Sync/BranchSyncWorker.php';
require_once __DIR__ . '/../classes/Sync/BranchRestoreFromHostedService.php';
require_once __DIR__ . '/../classes/Sync/OperationalSyncEventService.php';
require_once __DIR__ . '/../classes/Sync/OperationalSyncDomains.php';
require_once __DIR__ . '/../classes/Sync/SyncOutboxEventService.php';
require_once __DIR__ . '/../classes/Sync/RestoreEventPhase.php';
require_once __DIR__ . '/../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../classes/Sync/SyncInboxService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, "Usage: php tools/e2e_bidirectional_operational_sync.php [--keep-databases]\n");
    fwrite(STDOUT, "Creates disposable branch, hosted, and empty-recovery databases.\n");
    fwrite(STDOUT, "Proves automatic branch-to-hosted delivery and guarded manual hosted-to-branch recovery.\n");
    fwrite(STDOUT, "Automatic cloud-to-branch polling is intentionally disabled.\n");
    fwrite(STDOUT, "Requires Docker posmain-mysql on 3307 and PHP extensions curl,mysqli,pcntl,posix.\n");
    exit(0);
}

assertE2eBidirectionalRequirements();

$runId = 'bsync:' . date('YmdHis');
$branchUuid = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';
$branchSecret = 'e2e-bidirectional-branch-secret';
$dbSuffix = (string) getmypid();
$branchDb = 'posmain_e2e_bsync_branch_' . $dbSuffix;
$cloudDb = 'posmain_e2e_bsync_cloud_' . $dbSuffix;
$recoveryDb = 'posmain_e2e_bsync_recovery_' . $dbSuffix;
$databaseNames = [$branchDb, $cloudDb, $recoveryDb];
$keepDatabases = in_array('--keep-databases', $argv, true);
$tag = 'E2E-BSYNC';
$tmpRoot = sys_get_temp_dir() . '/posmain-bsync-e2e-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $runId);
mkdir($tmpRoot, 0777, true);

$children = [];
$results = [];
$branchConn = null;
$cloudConn = null;
$recoveryConn = null;
$cloudUrl = null;
$summary = null;
$exitCode = 2;

try {
    fwrite(STDERR, "[e2e] setup\n");
    e2eBsyncLog($tmpRoot, 'setup_databases');
    e2eBsyncCloneSchema($databaseNames);

    $branchConn = e2eBsyncConnect($branchDb);
    $cloudConn = e2eBsyncConnect($cloudDb);
    $recoveryConn = e2eBsyncConnect($recoveryDb);
    foreach ([$branchConn, $cloudConn, $recoveryConn] as $conn) {
        (new SyncSchemaManager())->apply($conn);
    }
    e2eBsyncRegisterPairing($branchConn, $cloudConn, $branchUuid, $branchSecret, $tag);

    $cloudPort = e2eBsyncFreePort();
    $cloudUrl = 'http://127.0.0.1:' . $cloudPort;
    $cloud = e2eBsyncStartCloudServer($cloudPort, $cloudDb, $branchUuid, $branchSecret, $tmpRoot);
    $children[] = $cloud;
    e2eBsyncConfigureBranchIdentity($branchConn, $branchUuid, $tag . ' Branch', $cloudUrl);
    e2eBsyncConfigureBranchIdentity($recoveryConn, $branchUuid, $tag . ' Recovery', $cloudUrl);

    $branchConfig = e2eBsyncBranchConfig($branchUuid, $branchSecret, $cloudUrl);

    $seed = e2eBsyncSeedBranch($branchConn, $tag);
    e2eBsyncLog($tmpRoot, 'seed_complete', $seed);

    fwrite(STDERR, "[e2e] seed complete\n");

    $results[] = e2eBsyncScenario('manual_push_local_to_hosted', function () use ($branchConn, $branchConfig, $cloudConn, $seed, $tag, $tmpRoot) {
        fwrite(STDERR, "[e2e] manual push start\n");
        $summary = (new BranchCatalogPushService())->pushToHosted($branchConn, $branchConfig, [
            'include_deleted' => false,
            'drain_outbox' => false,
            'max_batches' => 25,
            'batch_size' => 25,
        ]);
        for ($attempt = 0; $attempt < 25; $attempt++) {
            if ((int) ($summary['pending_outbox'] ?? 1) === 0) {
                break;
            }
            $batch = (new BranchCatalogPushService())->runPushDispatchBatch($branchConn, $branchConfig, [
                'batch_size' => 25,
            ]);
            $summary['pending_outbox'] = (int) ($batch['pending_outbox'] ?? 0);
            $summary['dispatch_batches'][] = $batch;
            if (!empty($batch['done'])) {
                break;
            }
        }
        $checks = [
            (int) ($summary['pending_outbox'] ?? 1) === 0,
            (int) ($summary['dispatch']['synced'] ?? 0) >= 1,
        ];
        foreach ($seed as $domain => $meta) {
            if (!e2eBsyncTableExists($cloudConn, $meta['table'])) {
                continue;
            }
            $checks[] = e2eBsyncRowExists($cloudConn, $meta['table'], (int) $meta['id'], $tag, $meta['marker_column'] ?? null);
        }
        $inboxCount = e2eBsyncCount($cloudConn, "
            SELECT COUNT(*) AS c FROM sync_inbox
            WHERE branch_uuid = '" . $cloudConn->real_escape_string($branchConfig['branch']['uuid']) . "'
              AND direction = 'branch_to_cloud'
              AND status IN ('processed','duplicate','received')
        ");
        $checks[] = $inboxCount > 0;

        return e2eBsyncResult($checks, ['push_summary' => $summary, 'cloud_inbox_events' => $inboxCount]);
    });

    $workerZoneId = 0;
    $results[] = e2eBsyncScenario('worker_push_new_local_change', function () use ($branchConn, $branchConfig, $cloudConn, $tag, &$workerZoneId, &$seed) {
        $zoneId = e2eBsyncInsertRow($branchConn, 'delivery_zones', [
            'name' => $tag . '-worker-zone',
            'fee' => '3.500',
            'is_active' => 1,
            'sort_order' => 99,
            'tenant' => 0,
            'branch' => 0,
        ]);
        $recorded = (new OperationalSyncEventService())->recordRowSnapshot($branchConn, 'delivery_zone', $zoneId, [
            'config' => $branchConfig,
            'source_system' => 'e2e_worker',
            'event_version' => 1,
        ]);
        $worker = (new BranchSyncWorker())->runOnce($branchConn, $branchConfig, [
            'batch_size' => 25,
            'worker_id' => 'e2e-bsync-worker',
        ]);
        $cloudRow = e2eBsyncFetchRow($cloudConn, 'delivery_zones', $zoneId);
        $workerZoneId = $zoneId;
        $seed['worker_delivery_zone'] = [
            'table' => 'delivery_zones',
            'id' => $zoneId,
            'marker_column' => 'name',
        ];

        return e2eBsyncResult([
            $recorded !== null,
            (int) ($worker['synced'] ?? 0) >= 1,
            $cloudRow && strpos((string) ($cloudRow['name'] ?? ''), $tag . '-worker-zone') !== false,
        ], ['zone_id' => $zoneId, 'worker' => $worker]);
    });

    $outageEventId = 0;
    $results[] = e2eBsyncScenario('hosted_outage_retry_and_recovery', function () use (
        $branchConn,
        $cloudConn,
        $recoveryConn,
        $cloudDb,
        $branchUuid,
        $branchSecret,
        $tag,
        $tmpRoot,
        &$cloud,
        &$cloudUrl,
        &$branchConfig,
        &$children,
        &$outageEventId
    ) {
        $zoneId = e2eBsyncInsertRow($branchConn, 'delivery_zones', [
            'name' => $tag . '-outage-zone',
            'fee' => '4.250',
            'is_active' => 1,
            'sort_order' => 100,
            'tenant' => 0,
            'branch' => 0,
        ]);
        $recorded = (new OperationalSyncEventService())->recordRowSnapshot($branchConn, 'delivery_zone', $zoneId, [
            'config' => $branchConfig,
            'source_system' => 'e2e_outage',
            'event_version' => 1,
        ]);
        $outageEventId = (int) ($recorded['outbox_id'] ?? 0);

        $stoppedCloudPid = (int) ($cloud['pid'] ?? 0);
        e2eBsyncStopServer($cloud);
        $children = array_values(array_filter(
            $children,
            static fn (array $child): bool => (int) ($child['pid'] ?? 0) !== $stoppedCloudPid
        ));
        $failedRun = (new BranchSyncWorker())->runOnce($branchConn, $branchConfig, [
            'batch_size' => 25,
            'worker_id' => 'e2e-bsync-outage',
        ]);
        $failedRow = $branchConn->query('SELECT status, attempts, last_error FROM sync_outbox WHERE id = ' . $outageEventId)->fetch_assoc();

        $newPort = e2eBsyncFreePort();
        $cloudUrl = 'http://127.0.0.1:' . $newPort;
        $cloud = e2eBsyncStartCloudServer($newPort, $cloudDb, $branchUuid, $branchSecret, $tmpRoot);
        $children[] = $cloud;
        e2eBsyncConfigureBranchIdentity($branchConn, $branchUuid, $tag . ' Branch', $cloudUrl);
        e2eBsyncConfigureBranchIdentity($recoveryConn, $branchUuid, $tag . ' Recovery', $cloudUrl);
        $branchConfig = e2eBsyncBranchConfig($branchUuid, $branchSecret, $cloudUrl);
        $branchConn->query("UPDATE sync_outbox SET next_retry_at = NOW(6), locked_until = NULL, locked_by = NULL WHERE id = {$outageEventId}");
        $recoveredRun = (new BranchSyncWorker())->runOnce($branchConn, $branchConfig, [
            'batch_size' => 25,
            'worker_id' => 'e2e-bsync-recovered',
        ]);
        $recoveredRow = $branchConn->query('SELECT status, attempts, locked_until, locked_by FROM sync_outbox WHERE id = ' . $outageEventId)->fetch_assoc();
        $cloudRow = e2eBsyncFetchRow($cloudConn, 'delivery_zones', $zoneId);

        return e2eBsyncResult([
            $recorded !== null,
            (int) ($failedRun['failed'] ?? 0) >= 1,
            in_array((string) ($failedRow['status'] ?? ''), ['failed', 'pending'], true),
            (int) ($failedRow['attempts'] ?? 0) >= 1,
            (int) ($recoveredRun['synced'] ?? 0) >= 1,
            (string) ($recoveredRow['status'] ?? '') === 'synced',
            empty($recoveredRow['locked_until']) && empty($recoveredRow['locked_by']),
            $cloudRow && strpos((string) ($cloudRow['name'] ?? ''), $tag . '-outage-zone') !== false,
        ], [
            'zone_id' => $zoneId,
            'failed_worker' => $failedRun,
            'failed_row' => $failedRow,
            'recovered_worker' => $recoveredRun,
            'recovered_row' => $recoveredRow,
        ]);
    });

    $results[] = e2eBsyncScenario('duplicate_stale_and_same_version_safety', function () use (
        $branchConn,
        $cloudConn,
        &$branchConfig,
        $branchUuid,
        $outageEventId,
        $seed
    ) {
        $duplicateBefore = e2eBsyncCount($cloudConn, "SELECT COUNT(*) AS c FROM sync_inbox WHERE branch_uuid = '" . $cloudConn->real_escape_string($branchUuid) . "'");
        $branchConn->query("UPDATE sync_outbox SET status = 'pending', attempts = 0, next_retry_at = NULL, locked_until = NULL, locked_by = NULL WHERE id = {$outageEventId}");
        $duplicateRun = (new BranchSyncWorker())->runOnce($branchConn, $branchConfig, [
            'batch_size' => 25,
            'worker_id' => 'e2e-bsync-duplicate',
        ]);
        $duplicateAfter = e2eBsyncCount($cloudConn, "SELECT COUNT(*) AS c FROM sync_inbox WHERE branch_uuid = '" . $cloudConn->real_escape_string($branchUuid) . "'");

        $orderId = (int) ($seed['order']['id'] ?? 0);
        $oldRow = $branchConn->query("SELECT * FROM sync_outbox WHERE aggregate_type = 'order' AND aggregate_local_id = {$orderId} ORDER BY event_version ASC, id ASC LIMIT 1")->fetch_assoc();
        $branchConn->query("UPDATE ot_head SET pro_tybe = 8 WHERE id = {$orderId}");
        $newRecord = (new SyncOutboxEventService())->recordOrderSnapshot($branchConn, $orderId, [
            'config' => $branchConfig,
            'source_system' => 'e2e_version_guard',
            'event_type' => 'order.updated',
        ]);
        $newRun = (new BranchSyncWorker())->runOnce($branchConn, $branchConfig, [
            'batch_size' => 25,
            'worker_id' => 'e2e-bsync-v2',
        ]);
        $newRow = $branchConn->query('SELECT * FROM sync_outbox WHERE id = ' . (int) ($newRecord['outbox_id'] ?? 0))->fetch_assoc();
        $staleEvent = e2eBsyncEventFromOutboxRow($oldRow);
        e2eBsyncGiveEventNewIdentity($staleEvent, 'stale');
        $stale = (new SyncInboxService())->receiveBranchEvent($cloudConn, $branchUuid, $staleEvent, SyncApplyMode::LIVE_APPLY);

        $conflictEvent = e2eBsyncEventFromOutboxRow($newRow);
        e2eBsyncGiveEventNewIdentity($conflictEvent, 'same-version');
        $conflictEvent['payload']['order']['pro_tybe'] = 7;
        e2eBsyncRehashEvent($conflictEvent);
        $conflict = (new SyncInboxService())->receiveBranchEvent($cloudConn, $branchUuid, $conflictEvent, SyncApplyMode::LIVE_APPLY);
        $cloudRow = e2eBsyncFetchRow($cloudConn, 'ot_head', $orderId);

        return e2eBsyncResult([
            (int) ($duplicateRun['synced'] ?? 0) >= 1,
            $duplicateAfter === $duplicateBefore,
            (int) ($newRun['synced'] ?? 0) >= 1,
            (string) ($stale['status'] ?? '') === 'stale',
            (string) ($conflict['status'] ?? '') === 'conflict',
            (int) ($cloudRow['pro_tybe'] ?? 0) === 8,
        ], [
            'duplicate_worker' => $duplicateRun,
            'inbox_count_before_duplicate' => $duplicateBefore,
            'inbox_count_after_duplicate' => $duplicateAfter,
            'newer_worker' => $newRun,
            'stale_result' => $stale,
            'same_version_result' => $conflict,
        ]);
    });

    $results[] = e2eBsyncScenario('manual_restore_active_branch_is_blocked', function () use ($branchConn, $branchConfig) {
        $service = new BranchRestoreFromHostedService();
        $restore = $service->restore($branchConn, $branchConfig, [
            'apply' => false,
            'limit' => 25,
            'max_pages_per_phase' => 10,
            'phases' => RestoreEventPhase::all(),
        ]);
        $blocked = false;
        try {
            $service->restore($branchConn, $branchConfig, [
                'apply' => true,
                'limit' => 25,
                'max_pages_per_phase' => 10,
                'phases' => RestoreEventPhase::all(),
            ]);
        } catch (Throwable $e) {
            $blocked = strpos($e->getMessage(), 'Restore apply blocked:') === 0;
        }
        $checks = [
            (int) ($restore['fetched'] ?? 0) > 0,
            empty($restore['safety']['business_database_empty']),
            empty($restore['safety']['apply_allowed']),
            $blocked,
        ];

        return e2eBsyncResult($checks, ['restore' => $restore]);
    });

    $recoveryConfig = e2eBsyncBranchConfig($branchUuid, $branchSecret, $cloudUrl);
    $results[] = e2eBsyncScenario('guarded_manual_restore_to_empty_recovery', function () use (
        $recoveryConn,
        $recoveryConfig,
        $seed,
        $branchConn,
        $cloudConn,
        $tmpRoot,
        $tag
    ) {
        $service = new BranchRestoreFromHostedService();
        $plan = $service->restore($recoveryConn, $recoveryConfig, [
            'apply' => false,
            'limit' => 25,
            'max_pages_per_phase' => 50,
            'phases' => RestoreEventPhase::all(),
        ]);
        $backup = $tmpRoot . '/disposable-recovery-backup.sql';
        file_put_contents($backup, "-- disposable empty recovery database backup evidence\n");
        $applied = $service->restore($recoveryConn, $recoveryConfig, [
            'apply' => true,
            'scope' => 'empty',
            'workers_stopped' => true,
            'expected_events' => (int) ($plan['safety']['expected_events'] ?? -1),
            'dry_run_manifest' => (string) ($plan['safety']['manifest_hash'] ?? ''),
            'confirmation_token' => (string) ($plan['safety']['confirmation_token'] ?? ''),
            'backup_file' => $backup,
            'limit' => 25,
            'max_pages_per_phase' => 50,
            'phases' => RestoreEventPhase::all(),
        ]);
        $reconciliation = e2eBsyncReconcileSeed($branchConn, $cloudConn, $recoveryConn, $seed, $tag);
        $exclusions = e2eBsyncRestoreExclusions($recoveryConn);

        return e2eBsyncResult([
            !empty($plan['safety']['apply_allowed']),
            !empty($plan['safety']['business_database_empty']),
            (int) ($plan['fetched'] ?? 0) > 0,
            !empty($applied['reconciliation']['ok']),
            (int) ($applied['failed'] ?? 1) === 0,
            (int) ($applied['skipped'] ?? 1) === 0,
            !empty($reconciliation['ok']),
            !empty($exclusions['ok']),
        ], [
            'dry_run' => $plan,
            'apply' => $applied,
            'business_reconciliation' => $reconciliation,
            'restore_exclusions' => $exclusions,
        ]);
    });

    $outboxHealth = e2eBsyncOutboxHealth($branchConn);
    $coverage = e2eBsyncCertificationCoverage();

    $summary = [
        'certification_contract' => 'posmain_lean_offline_cloud_sync_v1',
        'run_id' => $runId,
        'branch_uuid' => $branchUuid,
        'direction_policy' => [
            'branch_to_hosted' => 'automatic',
            'hosted_to_branch' => 'manual_guarded_empty_recovery_only',
            'automatic_cloud_pull_enabled' => false,
        ],
        'databases' => ['branch' => $branchDb, 'hosted' => $cloudDb, 'recovery' => $recoveryDb],
        'databases_disposable' => true,
        'databases_kept' => $keepDatabases,
        'cloud_url' => $cloudUrl,
        'seed' => $seed,
        'results' => $results,
        'outbox_health' => $outboxHealth,
        'coverage' => $coverage,
        'disposable_certification_pass' => !e2eBsyncHasFailures($results) && !empty($outboxHealth['ok']),
        'production_ready' => false,
    ];
    $summary['pass'] = $summary['disposable_certification_pass'];
    $report = $tmpRoot . '/report.json';
    file_put_contents($report, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo json_encode($summary + ['report_path' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $exitCode = $summary['pass'] ? 0 : 1;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 2;
} finally {
    foreach ($children as $child) {
        e2eBsyncStopServer($child);
    }
    foreach ([$branchConn, $cloudConn, $recoveryConn] as $conn) {
        if ($conn instanceof mysqli) {
            $conn->close();
        }
    }
    if (!$keepDatabases) {
        e2eBsyncDropDatabases($databaseNames);
    }
}
exit($exitCode);

function e2eBsyncScenario(string $name, callable $runner): array
{
    try {
        $result = $runner();
        $result['name'] = $name;
        return $result;
    } catch (Throwable $e) {
        return [
            'name' => $name,
            'pass' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function e2eBsyncResult(array $checks, array $details = []): array
{
    return [
        'pass' => !in_array(false, $checks, true),
        'checks' => array_values($checks),
        'details' => $details,
    ];
}

function e2eBsyncHasFailures(array $results): bool
{
    foreach ($results as $result) {
        if (empty($result['pass'])) {
            return true;
        }
    }
    return false;
}

function e2eBsyncDbHost(): string
{
    return getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
}

function e2eBsyncDbPort(): int
{
    return (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
}

function e2eBsyncConnect(string $db): mysqli
{
    $conn = new mysqli(e2eBsyncDbHost(), getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root', getenv('POSMAIN_TEST_MYSQL_PASS') ?: '', $db, e2eBsyncDbPort());
    $conn->set_charset('utf8mb4');
    return $conn;
}

function e2eBsyncCloneSchema(array $databaseNames): void
{
    $source = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';
    if ($databaseNames === []) {
        throw new InvalidArgumentException('At least one disposable database is required.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $source)) {
        throw new InvalidArgumentException('Unsafe source database name.');
    }
    $statements = [];
    foreach ($databaseNames as $databaseName) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $databaseName)) {
            throw new InvalidArgumentException('Unsafe disposable database name.');
        }
        $statements[] = 'DROP DATABASE IF EXISTS ' . $databaseName;
        $statements[] = 'CREATE DATABASE ' . $databaseName;
    }

    $runtime = strtolower(trim((string) (getenv('POSMAIN_TEST_MYSQL_RUNTIME') ?: '')));
    if ($runtime === '') {
        $runtime = trim((string) shell_exec('command -v docker 2>/dev/null')) !== '' ? 'docker' : 'native';
    }
    if ($runtime === 'native') {
        e2eBsyncCloneSchemaNative($source, $databaseNames, $statements);
        return;
    }
    if ($runtime !== 'docker') {
        throw new RuntimeException('Unsupported POSMAIN_TEST_MYSQL_RUNTIME: ' . $runtime);
    }

    $sql = implode('; ', $statements) . ';';
    $initCmd = sprintf(
        'docker exec posmain-mysql mariadb -uroot -e %s',
        escapeshellarg($sql)
    );
    exec($initCmd, $out1, $code1);
    if ($code1 !== 0) {
        throw new RuntimeException('Failed to create e2e databases: ' . implode("\n", $out1));
    }

    foreach ($databaseNames as $targetDb) {
        $dumpCmd = sprintf(
            'docker exec posmain-mysql sh -c %s',
            escapeshellarg('mariadb-dump -uroot --no-data ' . $source . ' | mariadb -uroot ' . $targetDb)
        );
        exec($dumpCmd, $out2, $code2);
        if ($code2 !== 0) {
            throw new RuntimeException('Failed to clone schema into ' . $targetDb . ': ' . implode("\n", $out2));
        }
    }
}

function e2eBsyncCloneSchemaNative(string $source, array $databaseNames, array $statements): void
{
    $password = (string) (getenv('POSMAIN_TEST_MYSQL_PASS') ?: '');
    if ($password !== '') {
        throw new RuntimeException('Native MariaDB staging proof requires socket authentication without a CLI password.');
    }

    $admin = new mysqli(
        e2eBsyncDbHost(),
        getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        '',
        '',
        e2eBsyncDbPort()
    );
    foreach ($statements as $statement) {
        $admin->query($statement);
    }
    $admin->close();

    $host = escapeshellarg(e2eBsyncDbHost());
    $port = e2eBsyncDbPort();
    $user = escapeshellarg(getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root');
    $connectionArgs = e2eBsyncDbHost() === 'localhost'
        ? '--protocol=socket --user=' . $user
        : '--host=' . $host . ' --port=' . $port . ' --user=' . $user;
    foreach ($databaseNames as $targetDb) {
        $schemaFile = tempnam(sys_get_temp_dir(), 'posmain-e2e-schema-');
        $errorFile = tempnam(sys_get_temp_dir(), 'posmain-e2e-schema-error-');
        if (!is_string($schemaFile) || !is_string($errorFile)) {
            throw new RuntimeException('Unable to create native MariaDB schema-clone fixtures.');
        }
        $dumpCmd = sprintf(
            'mariadb-dump --no-data %s %s > %s 2> %s',
            $connectionArgs,
            $source,
            escapeshellarg($schemaFile),
            escapeshellarg($errorFile)
        );
        $importCmd = sprintf(
            'mariadb %s %s < %s 2> %s',
            $connectionArgs,
            $targetDb,
            escapeshellarg($schemaFile),
            escapeshellarg($errorFile)
        );
        try {
            exec($dumpCmd, $dumpOutput, $dumpCode);
            if ($dumpCode !== 0) {
                throw new RuntimeException('Failed to dump source schema: ' . trim((string) file_get_contents($errorFile)));
            }
            exec($importCmd, $importOutput, $importCode);
            if ($importCode !== 0) {
                throw new RuntimeException('Failed to clone schema into ' . $targetDb . ': ' . trim((string) file_get_contents($errorFile)));
            }
        } finally {
            @unlink($schemaFile);
            @unlink($errorFile);
        }
    }
}

function e2eBsyncDropDatabases(array $databaseNames): void
{
    try {
        $admin = new mysqli(
            e2eBsyncDbHost(),
            getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
            getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
            '',
            e2eBsyncDbPort()
        );
        foreach ($databaseNames as $databaseName) {
            if (preg_match('/^posmain_e2e_bsync_(branch|cloud|recovery)_[0-9]+$/', (string) $databaseName)) {
                $admin->query('DROP DATABASE IF EXISTS `' . $databaseName . '`');
            }
        }
        $admin->close();
    } catch (Throwable $e) {
        fwrite(STDERR, '[e2e] cleanup failed: ' . $e->getMessage() . PHP_EOL);
    }
}

function e2eBsyncRegisterPairing(mysqli $branchConn, mysqli $cloudConn, string $branchUuid, string $secret, string $tag): void
{
    $hash = hash('sha256', $secret);
    $name = $tag . ' Branch';
    $cloudUrlPlaceholder = 'http://127.0.0.1:1';

    $stmt = $cloudConn->prepare("
        INSERT INTO cloud_branches (branch_uuid, branch_name, status, sync_secret_hash)
        VALUES (?, ?, 'active', ?)
        ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name), status = 'active', sync_secret_hash = VALUES(sync_secret_hash)
    ");
    $stmt->bind_param('sss', $branchUuid, $name, $hash);
    $stmt->execute();
    $stmt->close();

    $branchConn->query('DELETE FROM sync_branch_identity WHERE id = 1');
    $stmt = $branchConn->prepare("
        INSERT INTO sync_branch_identity (id, branch_uuid, branch_name, pos_tenant, pos_branch, cloud_base_url, current_menu_version)
        VALUES (1, ?, ?, 0, 0, ?, 0)
    ");
    $stmt->bind_param('sss', $branchUuid, $name, $cloudUrlPlaceholder);
    $stmt->execute();
    $stmt->close();
}

function e2eBsyncConfigureBranchIdentity(mysqli $conn, string $branchUuid, string $name, string $cloudUrl): void
{
    $conn->query('DELETE FROM sync_branch_identity WHERE id = 1');
    $stmt = $conn->prepare("
        INSERT INTO sync_branch_identity (
            id, branch_uuid, branch_name, pos_tenant, pos_branch, cloud_base_url, current_menu_version
        ) VALUES (1, ?, ?, 0, 0, ?, 0)
    ");
    $stmt->bind_param('sss', $branchUuid, $name, $cloudUrl);
    $stmt->execute();
    $stmt->close();
}

function e2eBsyncBranchConfig(string $branchUuid, string $secret, string $cloudUrl): array
{
    return posmain_app_config([
        'role' => 'branch',
        'branch' => [
            'uuid' => $branchUuid,
            'name' => 'E2E Branch',
            'cloud_base_url' => $cloudUrl,
        ],
        'sync' => [
            'branch_secret' => $secret,
            'branch_sync_enabled' => true,
            'worker_enabled' => true,
            'outbox_enabled' => true,
            'operational_sync_enabled' => true,
            'cloud_pull_enabled' => false,
            'cloud_apply_enabled' => true,
            'legacy_pos_mirror_enabled' => true,
            'http_timeout_seconds' => 2,
            'image_sync_enabled' => false,
        ],
    ]);
}

function e2eBsyncSeedBranch(mysqli $conn, string $tag): array
{
    $seed = [];

    $categoryId = e2eBsyncInsertRow($conn, 'item_group', ['gname' => $tag . '-cat', 'isdeleted' => 0]);
    $seed['item_category'] = ['table' => 'item_group', 'id' => $categoryId, 'marker_column' => 'gname'];

    $itemId = e2eBsyncInsertRow($conn, 'myitems', [
        'iname' => $tag . '-item',
        'barcode' => 'E2E' . random_int(10000, 99999),
        'group1' => $categoryId,
        'price1' => 12.5,
        'price2' => 12.5,
        'price3' => 12.5,
        'cost_price' => 4.0,
        'market_price' => 12.5,
        'last_price' => 0,
        'isdeleted' => 0,
    ]);
    $seed['menu_item'] = ['table' => 'myitems', 'id' => $itemId, 'marker_column' => 'iname'];

    $tableId = e2eBsyncInsertRow($conn, 'tables', ['tname' => $tag . '-table', 'isdeleted' => 0]);
    $seed['table'] = ['table' => 'tables', 'id' => $tableId, 'marker_column' => 'tname'];

    $zoneId = e2eBsyncInsertRow($conn, 'delivery_zones', [
        'name' => $tag . '-zone',
        'fee' => '5.000',
        'is_active' => 1,
        'sort_order' => 1,
        'tenant' => 0,
        'branch' => 0,
    ]);
    $seed['delivery_zone'] = ['table' => 'delivery_zones', 'id' => $zoneId, 'marker_column' => 'name'];

    $clientId = e2eBsyncInsertRow($conn, 'delivery_clients', [
        'client_name' => $tag . '-client',
        'phone' => '0598' . random_int(100000, 999999),
        'address' => 'local street',
        'isdeleted' => 0,
    ]);
    $seed['delivery_client'] = ['table' => 'delivery_clients', 'id' => $clientId, 'marker_column' => 'client_name'];

    if (e2eBsyncTableExists($conn, 'payment_methods')) {
        $pmId = e2eBsyncInsertRow($conn, 'payment_methods', [
            'code' => 'e2e_' . random_int(1000, 9999),
            'name_ar' => $tag . '-cash',
            'type' => 'cash',
            'is_active' => 1,
            'sort_order' => 1,
        ]);
        $seed['payment_method'] = ['table' => 'payment_methods', 'id' => $pmId, 'marker_column' => 'name_ar'];
    }

    if (e2eBsyncTableExists($conn, 'modifier_groups')) {
        $groupId = e2eBsyncInsertRow($conn, 'modifier_groups', [
            'name_ar' => $tag . '-mod',
            'name_en' => $tag . '-mod-en',
            'selection_min' => 0,
            'selection_max' => 2,
            'is_required' => 0,
            'is_active' => 1,
            'tenant' => 0,
            'branch' => 0,
            'sort_order' => 1,
        ]);
        $seed['modifier_group'] = ['table' => 'modifier_groups', 'id' => $groupId, 'marker_column' => 'name_ar'];
    }

    if (e2eBsyncTableExists($conn, 'settings')) {
        $existing = (int) ($conn->query('SELECT COUNT(*) AS c FROM settings WHERE id = 1')->fetch_assoc()['c'] ?? 0);
        if ($existing === 0) {
            e2eBsyncInsertRow($conn, 'settings', ['id' => 1, 'company_name' => $tag . '-shop']);
        } else {
            $conn->query("UPDATE settings SET company_name = '" . $conn->real_escape_string($tag . '-shop') . "' WHERE id = 1");
        }
        $seed['shop_settings'] = ['table' => 'settings', 'id' => 1, 'marker_column' => 'company_name'];
    }

    if (e2eBsyncTableExists($conn, 'moova_pos_shop_links')) {
        $linkId = e2eBsyncInsertRow($conn, 'moova_pos_shop_links', [
            'moova_shop_id' => $tag . '-shop-id',
            'moova_branch_id' => $tag . '-branch-id',
            'moova_device_token_hash' => hash('sha256', $tag . '-token'),
            'status' => 'active',
        ]);
        $seed['moova_shop_link'] = ['table' => 'moova_pos_shop_links', 'id' => $linkId, 'marker_column' => 'moova_shop_id'];
    }

    if (
        e2eBsyncTableExists($conn, 'drawer_sessions')
        && e2eBsyncTableExists($conn, 'drawer_session_close_summaries')
    ) {
        $drawerUuidHex = md5($tag . '-drawer');
        $drawerUuid = substr($drawerUuidHex, 0, 8) . '-' . substr($drawerUuidHex, 8, 4) . '-4'
            . substr($drawerUuidHex, 13, 3) . '-a' . substr($drawerUuidHex, 17, 3) . '-' . substr($drawerUuidHex, 20, 12);
        $drawerId = e2eBsyncInsertRow($conn, 'drawer_sessions', [
            'uuid' => $drawerUuid,
            'user_id' => 1,
            'tenant' => 1,
            'branch' => 1,
            'opened_at' => date('Y-m-d') . ' 08:00:00',
            'business_day' => date('Y-m-d'),
            'opened_by' => 1,
            'opening_cash' => 100,
            'closed_at' => date('Y-m-d') . ' 16:00:00',
            'closed_by' => 1,
            'expected_cash' => 100,
            'counted_cash' => 100,
            'difference' => 0,
            'status' => 'closed',
        ]);
        $summaryUuidHex = md5($tag . '-summary');
        $summaryUuid = substr($summaryUuidHex, 0, 8) . '-' . substr($summaryUuidHex, 8, 4) . '-4'
            . substr($summaryUuidHex, 13, 3) . '-a' . substr($summaryUuidHex, 17, 3) . '-' . substr($summaryUuidHex, 20, 12);
        $closeId = e2eBsyncInsertRow($conn, 'drawer_session_close_summaries', [
            'uuid' => $summaryUuid,
            'drawer_session_id' => $drawerId,
            'shift_number' => $tag,
            'total_sales' => 500,
            'cash_sales' => 100,
            'non_cash_sales' => 400,
            'close_path' => 'e2e_bidirectional_operational_sync',
        ]);
        $seed['shift_close'] = [
            'table' => 'drawer_session_close_summaries',
            'id' => $closeId,
            'marker_column' => 'shift_number',
        ];
    }

    $orderId = e2eBsyncInsertRow($conn, 'ot_head', [
        'pro_tybe' => 9,
        'pro_date' => date('Y-m-d'),
        'isdeleted' => 0,
    ]);
    $seed['order'] = ['table' => 'ot_head', 'id' => $orderId];

    return $seed;
}

function e2eBsyncInsertRow(mysqli $conn, string $table, array $row): int
{
    $columns = e2eBsyncTableColumns($conn, $table);
    $fields = [];
    $values = [];
    foreach ($row as $column => $value) {
        if (!in_array($column, $columns, true)) {
            continue;
        }
        $fields[] = '`' . $column . '`';
        $values[] = $value;
    }
    if ($fields === []) {
        throw new RuntimeException('No valid columns for insert into ' . $table);
    }
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')';
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($values));
    $refs = [];
    foreach ($values as $k => &$v) {
        $refs[$k] = &$v;
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();
    return $id;
}

function e2eBsyncTableColumns(mysqli $conn, string $table): array
{
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    while ($row = $result->fetch_assoc()) {
        $columns[] = (string) $row['Field'];
    }
    return $columns;
}

function e2eBsyncTableExists(mysqli $conn, string $table): bool
{
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    return $result && $result->num_rows > 0;
}

function e2eBsyncFetchRow(mysqli $conn, string $table, int $id): ?array
{
    if ($id <= 0 || !e2eBsyncTableExists($conn, $table)) {
        return null;
    }
    return $conn->query('SELECT * FROM `' . $table . '` WHERE id = ' . $id)->fetch_assoc() ?: null;
}

function e2eBsyncRowExists(mysqli $conn, string $table, int $id, string $tag, ?string $markerColumn): bool
{
    $row = e2eBsyncFetchRow($conn, $table, $id);
    if (!$row) {
        return false;
    }
    if ($markerColumn && array_key_exists($markerColumn, $row)) {
        return strpos((string) $row[$markerColumn], $tag) !== false;
    }
    return true;
}

function e2eBsyncCount(mysqli $conn, string $sql): int
{
    $row = $conn->query($sql)->fetch_assoc();
    return (int) ($row['c'] ?? $row['COUNT(*)'] ?? 0);
}

function e2eBsyncEventFromOutboxRow(array $row): array
{
    if ($row === []) {
        throw new RuntimeException('Expected outbox row is missing.');
    }

    return [
        'event_uuid' => (string) $row['event_uuid'],
        'idempotency_key' => (string) $row['idempotency_key'],
        'aggregate_type' => (string) $row['aggregate_type'],
        'aggregate_uuid' => (string) $row['aggregate_uuid'],
        'aggregate_local_id' => (int) $row['aggregate_local_id'],
        'aggregate_id' => (string) $row['aggregate_id'],
        'entity_type' => (string) $row['entity_type'],
        'entity_uuid' => (string) $row['entity_uuid'],
        'entity_local_id' => (int) $row['entity_local_id'],
        'event_type' => (string) $row['event_type'],
        'event_version' => (int) $row['event_version'],
        'source_system' => (string) $row['source_system'],
        'payload_hash' => (string) $row['payload_hash'],
        'payload' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
    ];
}

function e2eBsyncGiveEventNewIdentity(array &$event, string $suffix): void
{
    $event['event_uuid'] = SyncBranchIdentity::generateUuidV4();
    $event['idempotency_key'] = hash('sha256', 'e2e:' . $suffix . ':' . $event['event_uuid']);
}

function e2eBsyncRehashEvent(array &$event): void
{
    unset($event['payload']['payload_hash']);
    $event['payload']['payload_hash'] = hash('sha256', e2eBsyncJson($event['payload']));
    $event['payload_hash'] = hash('sha256', e2eBsyncJson($event['payload']));
}

function e2eBsyncJson($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function e2eBsyncReconcileSeed(
    mysqli $branchConn,
    mysqli $cloudConn,
    mysqli $recoveryConn,
    array $seed,
    string $tag
): array {
    $rows = [];
    $ok = true;
    foreach ($seed as $domain => $meta) {
        $table = (string) ($meta['table'] ?? '');
        $id = (int) ($meta['id'] ?? 0);
        $marker = isset($meta['marker_column']) ? (string) $meta['marker_column'] : null;
        if ($table === '' || $id < 1) {
            $ok = false;
            $rows[$domain] = ['ok' => false, 'reason' => 'invalid_fixture_identity'];
            continue;
        }

        $branch = e2eBsyncFetchRow($branchConn, $table, $id);
        $hosted = e2eBsyncFetchRow($cloudConn, $table, $id);
        $recovery = e2eBsyncFetchRow($recoveryConn, $table, $id);
        $columns = e2eBsyncCanonicalColumns($table, $branch, $hosted, $recovery, $marker);
        $hashes = [
            'branch' => e2eBsyncCanonicalHash($branch, $columns),
            'hosted' => e2eBsyncCanonicalHash($hosted, $columns),
            'recovery' => e2eBsyncCanonicalHash($recovery, $columns),
        ];
        $markerOk = $marker === null
            || (
                $branch && strpos((string) ($branch[$marker] ?? ''), $tag) !== false
                && $hosted && strpos((string) ($hosted[$marker] ?? ''), $tag) !== false
                && $recovery && strpos((string) ($recovery[$marker] ?? ''), $tag) !== false
            );
        $rowOk = $branch !== null
            && $hosted !== null
            && $recovery !== null
            && count(array_unique(array_values($hashes))) === 1
            && $markerOk;
        $ok = $ok && $rowOk;
        $rows[$domain] = [
            'ok' => $rowOk,
            'table' => $table,
            'id' => $id,
            'columns' => $columns,
            'hashes' => $hashes,
        ];
    }

    return ['ok' => $ok, 'fixtures' => $rows];
}

function e2eBsyncCanonicalColumns(
    string $table,
    ?array $branch,
    ?array $hosted,
    ?array $recovery,
    ?string $marker
): array {
    $preferred = [
        'item_group' => ['id', 'gname', 'isdeleted'],
        'myitems' => ['id', 'iname', 'barcode', 'group1', 'price1', 'cost_price', 'isdeleted'],
        'tables' => ['id', 'tname', 'isdeleted'],
        'delivery_zones' => ['id', 'name', 'fee', 'is_active', 'sort_order'],
        'delivery_clients' => ['id', 'client_name', 'phone', 'address', 'isdeleted'],
        'payment_methods' => ['id', 'code', 'name_ar', 'type', 'is_active'],
        'modifier_groups' => ['id', 'name_ar', 'name_en', 'selection_min', 'selection_max', 'is_active'],
        'settings' => ['id', 'company_name'],
        'moova_pos_shop_links' => ['id', 'moova_shop_id', 'moova_branch_id', 'status'],
        'drawer_session_close_summaries' => ['id', 'shift_number', 'total_sales', 'cash_sales', 'non_cash_sales'],
        'ot_head' => ['id', 'pro_tybe', 'pro_date', 'isdeleted'],
    ];
    $candidates = $preferred[$table] ?? array_values(array_filter(['id', $marker]));
    $rows = array_values(array_filter([$branch, $hosted, $recovery], 'is_array'));
    if (count($rows) !== 3) {
        return $candidates;
    }
    return array_values(array_filter($candidates, static function (string $column) use ($rows): bool {
        foreach ($rows as $row) {
            if (!array_key_exists($column, $row)) {
                return false;
            }
        }
        return true;
    }));
}

function e2eBsyncCanonicalHash(?array $row, array $columns): ?string
{
    if ($row === null || $columns === []) {
        return null;
    }
    $canonical = [];
    foreach ($columns as $column) {
        $value = $row[$column] ?? null;
        if (is_string($value) && is_numeric($value)) {
            $value = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        }
        $canonical[$column] = $value;
    }
    return hash('sha256', e2eBsyncJson($canonical));
}

function e2eBsyncRestoreExclusions(mysqli $conn): array
{
    $checks = [];
    foreach (['sync_outbox', 'sync_inbox', 'sync_worker_logs', 'cloud_branch_events', 'cloud_branches'] as $table) {
        if (!e2eBsyncTableExists($conn, $table)) {
            continue;
        }
        $count = e2eBsyncCount($conn, 'SELECT COUNT(*) AS c FROM `' . $table . '`');
        $checks[$table . '_rows'] = ['count' => $count, 'ok' => $count === 0];
    }
    foreach (['sessions', 'user_sessions', 'remember_tokens', 'password_reset_tokens'] as $table) {
        if (!e2eBsyncTableExists($conn, $table)) {
            continue;
        }
        $count = e2eBsyncCount($conn, 'SELECT COUNT(*) AS c FROM `' . $table . '`');
        $checks[$table . '_rows'] = ['count' => $count, 'ok' => $count === 0];
    }
    if (e2eBsyncTableExists($conn, 'employees') && in_array('password', e2eBsyncTableColumns($conn, 'employees'), true)) {
        $count = e2eBsyncCount($conn, "SELECT COUNT(*) AS c FROM employees WHERE COALESCE(password, '') <> ''");
        $checks['employee_password_values'] = ['count' => $count, 'ok' => $count === 0];
    }
    if (e2eBsyncTableExists($conn, 'moova_pos_shop_links') && in_array('moova_device_token_hash', e2eBsyncTableColumns($conn, 'moova_pos_shop_links'), true)) {
        $count = e2eBsyncCount($conn, "SELECT COUNT(*) AS c FROM moova_pos_shop_links WHERE COALESCE(moova_device_token_hash, '') <> ''");
        $checks['moova_device_token_hash_values'] = ['count' => $count, 'ok' => $count === 0];
    }
    $ok = true;
    foreach ($checks as $check) {
        $ok = $ok && !empty($check['ok']);
    }
    return ['ok' => $ok, 'checks' => $checks];
}

function e2eBsyncOutboxHealth(mysqli $conn): array
{
    $counts = [];
    foreach (['pending', 'syncing', 'failed', 'dead', 'synced'] as $status) {
        $counts[$status] = e2eBsyncCount(
            $conn,
            "SELECT COUNT(*) AS c FROM sync_outbox WHERE status = '" . $conn->real_escape_string($status) . "'"
        );
    }
    $expiredLocks = e2eBsyncCount(
        $conn,
        "SELECT COUNT(*) AS c FROM sync_outbox
         WHERE status = 'syncing'
           AND locked_until IS NOT NULL
           AND locked_until < NOW(6)"
    );
    return [
        'ok' => $counts['pending'] === 0
            && $counts['syncing'] === 0
            && $counts['failed'] === 0
            && $counts['dead'] === 0
            && $expiredLocks === 0,
        'counts' => $counts,
        'expired_locks' => $expiredLocks,
    ];
}

function e2eBsyncCertificationCoverage(): array
{
    return [
        'end_to_end_transport_and_restore' => [
            'menu_item',
            'table',
            'order',
            'item_category',
            'delivery_client',
            'delivery_zone',
            'payment_method',
            'modifier_group',
            'shop_settings',
            'moova_shop_link_sanitized',
            'shift_close_and_drawer_session',
        ],
        'required_focused_typed_gates' => [
            'tests/sync/branch_restore_financial_bundle_test.php',
            'tests/sync/branch_restore_customer_bundle_test.php',
            'tests/sync/branch_restore_order_fulfillment_test.php',
            'tests/sync/branch_restore_inventory_accounting_test.php',
            'tests/sync/branch_restore_inventory_count_test.php',
            'tests/sync/branch_restore_production_batch_test.php',
            'tests/sync/branch_restore_purchase_receipt_test.php',
            'tests/sync/branch_restore_purchase_order_test.php',
            'tests/sync/cloud_shift_snapshot_test.php',
        ],
        'deployment_blockers_or_operator_prerequisites' => [
            'cross_branch_transfer_document_requires_source_destination_handoff_policy',
            'manual_legacy_journal_writers_require_separate_non_duplicate_ownership_audit',
            'enabled_operational_masters_require_writer_coverage_inventory',
            'sanitized_user_role_grant_recovery_requires_secret_free_contract',
            'hosted_code_schema_parity_backup_secret_service_and_live_smoke_not_proven_by_disposable_run',
        ],
        'intentionally_excluded_from_restore' => [
            'passwords_pins_tokens_and_secret_hashes',
            'login_sessions_and_password_reset_state',
            'worker_leases_locks_and_runtime_logs',
            'caches_device_state_and_raw_provider_payloads',
        ],
    ];
}

function e2eBsyncStartCloudServer(int $port, string $cloudDb, string $branchUuid, string $secret, string $tmpRoot): array
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Could not fork cloud server');
    }
    if ($pid === 0) {
        $env = [
            'POSMAIN_ROLE' => 'fake_cloud',
            'POSMAIN_DB_HOST' => e2eBsyncDbHost(),
            'POSMAIN_DB_PORT' => (string) e2eBsyncDbPort(),
            'POSMAIN_DB_NAME' => $cloudDb,
            'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
            'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
            'POSMAIN_CLOUD_APPLY_ENABLED' => '1',
            'POSMAIN_CLOUD_LEGACY_POS_MIRROR_ENABLED' => '1',
            'POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED' => '0',
            'POSMAIN_OPERATIONAL_SYNC_ENABLED' => '1',
            'POSMAIN_BRANCH_UUID' => $branchUuid,
            'POSMAIN_CLOUD_BRANCH_SECRETS' => $branchUuid . '=' . $secret,
            'POSMAIN_DISABLE_BRANCH_ENV_FILE_FALLBACK' => '1',
        ];
        foreach ($env as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
        chdir(dirname(__DIR__));
        $router = __DIR__ . '/e2e_pos_sync_router.php';
        $logPath = $tmpRoot . '/cloud-server.log';
        cli_set_process_title('posmain-e2e-cloud');
        fclose(STDOUT);
        fclose(STDERR);
        $stdout = fopen($logPath, 'a');
        $stderr = fopen($logPath, 'a');
        pcntl_exec(PHP_BINARY, [
            '-d',
            'display_errors=0',
            '-S',
            '127.0.0.1:' . $port,
            $router,
        ]);
        fwrite($stderr, "Failed to exec disposable cloud server.\n");
        fclose($stdout);
        fclose($stderr);
        exit(1);
    }

    e2eBsyncWaitForPort($port);
    return ['pid' => $pid, 'port' => $port];
}

function e2eBsyncStopServer(array $server): void
{
    if (empty($server['pid'])) {
        return;
    }
    @posix_kill((int) $server['pid'], SIGTERM);
    $deadline = microtime(true) + 2;
    do {
        $waited = pcntl_waitpid((int) $server['pid'], $status, WNOHANG);
        if ($waited === (int) $server['pid'] || $waited === -1) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    @posix_kill((int) $server['pid'], SIGKILL);
    pcntl_waitpid((int) $server['pid'], $status);
}

function e2eBsyncFreePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($server, false);
    fclose($server);
    return (int) substr(strrchr($name, ':'), 1);
}

function e2eBsyncWaitForPort(int $port): void
{
    $deadline = microtime(true) + 5;
    do {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($socket) {
            fclose($socket);
            return;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Cloud server did not start on port ' . $port);
}

function e2eBsyncLog(string $tmpRoot, string $event, array $context = []): void
{
    file_put_contents(
        $tmpRoot . '/trace.jsonl',
        json_encode(['ts' => gmdate('c'), 'event' => $event] + $context, JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND
    );
}

function assertE2eBidirectionalRequirements(): void
{
    $missing = [];
    foreach (['curl', 'mysqli', 'pcntl', 'posix'] as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    if ($missing) {
        fwrite(STDERR, 'Missing PHP extensions: ' . implode(', ', $missing) . PHP_EOL);
        exit(2);
    }
}
