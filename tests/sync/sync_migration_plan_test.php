<?php

require_once __DIR__ . '/../../classes/Sync/SyncMigrationPlan.php';

$planner = new SyncMigrationPlan();

syncMigrationPlanAssert(
    $planner->classify('CREATE TABLE child (parent_id BIGINT, CONSTRAINT fk FOREIGN KEY (parent_id) REFERENCES parent(id) ON DELETE CASCADE)')
        === SyncMigrationPlan::ADDITIVE,
    'CREATE TABLE with ON DELETE must remain additive'
);
syncMigrationPlanAssert(
    $planner->classify('ALTER TABLE inventory_counts ADD COLUMN sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 0')
        === SyncMigrationPlan::ADDITIVE,
    'ADD COLUMN must be additive'
);
syncMigrationPlanAssert(
    $planner->classify('ALTER TABLE acc_head MODIFY balance DECIMAL(24,6) NOT NULL DEFAULT 0')
        === SyncMigrationPlan::REWRITE,
    'ALTER MODIFY must be rewrite'
);
syncMigrationPlanAssert(
    $planner->classify('ALTER TABLE myitems CHANGE old_name new_name VARCHAR(20)') === SyncMigrationPlan::REWRITE,
    'ALTER CHANGE must be rewrite'
);
syncMigrationPlanAssert(
    $planner->classify('ALTER TABLE myitems RENAME COLUMN old_name TO new_name') === SyncMigrationPlan::REWRITE,
    'ALTER RENAME must be rewrite'
);
syncMigrationPlanAssert(
    $planner->classify('UPDATE ot_head SET fat_total = 0 WHERE fat_total IS NULL') === SyncMigrationPlan::REWRITE,
    'UPDATE must be rewrite'
);
syncMigrationPlanAssert(
    $planner->requiresDestructiveOptIn('UPDATE ot_head SET fat_total = 0 WHERE fat_total IS NULL'),
    'UPDATE must preserve the migration CLI destructive opt-in'
);
syncMigrationPlanAssert(
    $planner->classify('INSERT INTO migration_seed (id) VALUES (1)') === SyncMigrationPlan::REWRITE,
    'INSERT must be rewrite'
);
syncMigrationPlanAssert(
    !$planner->requiresDestructiveOptIn('INSERT INTO migration_seed (id) VALUES (1)'),
    'INSERT rewrite must retain the backup gate without gaining the destructive opt-in'
);
syncMigrationPlanAssert(
    $planner->classify('ALTER TABLE myitems DROP COLUMN legacy_value') === SyncMigrationPlan::DESTRUCTIVE,
    'ALTER DROP must be destructive'
);
syncMigrationPlanAssert(
    $planner->classify('TRUNCATE TABLE myitems') === SyncMigrationPlan::DESTRUCTIVE,
    'TRUNCATE must be destructive'
);
syncMigrationPlanAssert(
    $planner->requiresDestructiveOptIn('TRUNCATE TABLE myitems'),
    'destructive classification must require explicit destructive opt-in'
);
syncMigrationPlanAssert(
    $planner->classify('SELECT 1') === SyncMigrationPlan::AMBIGUOUS,
    'unknown statement must be ambiguous'
);

$pending = [
    'sync_projection_versions' => 'CREATE TABLE sync_projection_versions (id BIGINT PRIMARY KEY)',
    'inventory_counts.add_sync_revision' => 'ALTER TABLE inventory_counts ADD COLUMN sync_revision BIGINT NOT NULL DEFAULT 0',
    'acc_head.modify_balance' => 'ALTER TABLE acc_head MODIFY balance DECIMAL(24,6)',
    'legacy.cleanup' => 'DELETE FROM legacy_rows WHERE retired = 1',
    'unknown.statement' => 'SELECT 1',
];

$all = $planner->select($pending);
syncMigrationPlanAssert(array_keys($all['selected']) === array_keys($pending), 'default all scope must preserve current selection');
syncMigrationPlanAssert($all['deferred'] === [], 'default all scope must defer nothing');

$additive = $planner->select($pending, SyncMigrationPlan::SCOPE_ADDITIVE);
syncMigrationPlanAssert(
    array_keys($additive['selected']) === ['sync_projection_versions', 'inventory_counts.add_sync_revision'],
    'additive scope must select only create/add statements'
);
syncMigrationPlanAssert(
    array_keys($additive['deferred']) === ['acc_head.modify_balance', 'legacy.cleanup', 'unknown.statement'],
    'additive scope must defer rewrite, destructive and ambiguous statements'
);

$allowlisted = $planner->select(
    $pending,
    SyncMigrationPlan::SCOPE_ADDITIVE,
    ['inventory_counts.add_sync_revision']
);
syncMigrationPlanAssert(
    array_keys($allowlisted['selected']) === ['inventory_counts.add_sync_revision'],
    'additive label list must narrow the executable set'
);
syncMigrationPlanExpectBlocked(
    static fn () => $planner->select($pending, SyncMigrationPlan::SCOPE_ADDITIVE, ['acc_head.modify_balance']),
    'MIGRATION_LABEL_NOT_ADDITIVE'
);
syncMigrationPlanExpectBlocked(
    static fn () => $planner->select($pending, SyncMigrationPlan::SCOPE_ADDITIVE, ['missing.label']),
    'MIGRATION_LABEL_NOT_PENDING'
);
syncMigrationPlanExpectBlocked(
    static fn () => $planner->select($pending, SyncMigrationPlan::SCOPE_ALL, ['sync_projection_versions']),
    'MIGRATION_LABELS_REQUIRE_ADDITIVE_SCOPE'
);
syncMigrationPlanExpectBlocked(
    static fn () => $planner->select($pending, SyncMigrationPlan::SCOPE_REVIEWED),
    'MIGRATION_LABELS_REQUIRED_FOR_REVIEWED_SCOPE'
);
syncMigrationPlanExpectBlocked(
    static fn () => $planner->select($pending, SyncMigrationPlan::SCOPE_REVIEWED, ['missing.label']),
    'MIGRATION_LABEL_NOT_PENDING'
);
syncMigrationPlanExpectBlocked(
    static fn () => $planner->select($pending, SyncMigrationPlan::SCOPE_REVIEWED, ['unknown.statement']),
    'MIGRATION_LABEL_AMBIGUOUS'
);
syncMigrationPlanExpectBlocked(
    static fn () => $planner->select($pending, SyncMigrationPlan::SCOPE_ADDITIVE, ['sync_projection_versions', 'sync_projection_versions']),
    'MIGRATION_LABEL_DUPLICATE'
);
syncMigrationPlanExpectBlocked(
    static fn () => $planner->select($pending, SyncMigrationPlan::SCOPE_REVIEWED, ['acc_head.modify_balance', 'acc_head.modify_balance']),
    'MIGRATION_LABEL_DUPLICATE'
);

$reviewed = $planner->select(
    $pending,
    SyncMigrationPlan::SCOPE_REVIEWED,
    ['legacy.cleanup', 'sync_projection_versions', 'acc_head.modify_balance']
);
syncMigrationPlanAssert(
    array_keys($reviewed['selected']) === ['sync_projection_versions', 'acc_head.modify_balance', 'legacy.cleanup'],
    'reviewed scope must select additive, rewrite and destructive labels in canonical pending order'
);
syncMigrationPlanAssert(
    array_keys($reviewed['deferred']) === ['inventory_counts.add_sync_revision', 'unknown.statement'],
    'reviewed scope must defer every unrequested label'
);

$runner = file_get_contents(__DIR__ . '/../../tools/run_migrations.php');
syncMigrationPlanAssert(is_string($runner) && $runner !== '', 'migration runner source must be readable');
syncMigrationPlanAssert(strpos($runner, "'scope:'") !== false, 'runner must expose explicit scope option');
syncMigrationPlanAssert(strpos($runner, "'labels:'") !== false, 'runner must expose explicit label option');
syncMigrationPlanAssert(
    strpos($runner, 'MIGRATION_LABELS_REQUIRED_FOR_ADDITIVE_APPLY') !== false,
    'additive apply must require an explicit reviewed label list'
);
syncMigrationPlanAssert(
    strpos($runner, 'MIGRATION_LABELS_REQUIRED_FOR_REVIEWED_SCOPE') !== false,
    'reviewed dry-run and apply must require an explicit label list'
);
$checksumPosition = strpos($runner, 'syncMigrationAssertChecksumCompatible($conn');
$recordPosition = strpos($runner, 'syncMigrationRecord($conn');
syncMigrationPlanAssert(
    $checksumPosition !== false && $recordPosition !== false && $checksumPosition < $recordPosition,
    'all selected checksum assertions must be positioned before the first migration record/write'
);
syncMigrationPlanAssert(
    preg_match('/if \(\$scope === SyncMigrationPlan::SCOPE_ALL\) \{\s*\/\/[^}]*\$manager->apply\(\$conn\);/s', $runner) === 1,
    'all-pending manager apply must be guarded by full scope'
);
syncMigrationPlanAssert(
    strpos($runner, "ADD COLUMN metadata_json JSON NULL AFTER status") !== false,
    'legacy tracking compatibility must ensure metadata_json'
);
syncMigrationPlanAssert(
    strpos($runner, "ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'applied'") !== false,
    'legacy tracking compatibility must ensure status'
);

$runtime = syncMigrationPlanRuntimeConnection();
if ($runtime instanceof mysqli) {
    $runtimeDb = 'posmain_migration_plan_' . getmypid();
    $checksumDb = $runtimeDb . '_checksum';
    try {
        foreach ([$runtimeDb, $checksumDb] as $database) {
            $runtime->query("DROP DATABASE IF EXISTS `{$database}`");
            $runtime->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            syncMigrationPlanCreateLegacyTracking($runtime, $database);
        }

        $discovery = syncMigrationPlanRunCli($runtimeDb, [
            '--dry-run',
            '--scope=additive',
        ]);
        syncMigrationPlanAssert($discovery['exit_code'] === 0, 'additive discovery dry-run must allow omitted labels');
        syncMigrationPlanAssert(
            strpos($discovery['stdout'], 'Dry run scope: additive;') !== false,
            'additive discovery dry-run must report selected/deferred scope'
        );
        $missingLabels = syncMigrationPlanRunCli($runtimeDb, [
            '--apply',
            '--scope=additive',
            '--confirm-no-backup',
        ]);
        syncMigrationPlanAssert($missingLabels['exit_code'] !== 0, 'additive apply without labels must fail');
        syncMigrationPlanAssert(
            strpos($missingLabels['stderr'], 'MIGRATION_LABELS_REQUIRED_FOR_ADDITIVE_APPLY') !== false,
            'missing-label failure must be explicit'
        );
        $runtime->select_db($runtimeDb);
        $statusBeforeReviewedApply = $runtime->query("SHOW COLUMNS FROM schema_migrations LIKE 'status'");
        syncMigrationPlanAssert(
            $statusBeforeReviewedApply->num_rows === 0,
            'missing-label apply must fail before tracking-table mutation'
        );

        $apply = syncMigrationPlanRunCli($runtimeDb, [
            '--apply',
            '--scope=additive',
            '--labels=sync_projection_versions',
            '--confirm-no-backup',
        ]);
        syncMigrationPlanAssert($apply['exit_code'] === 0, 'additive runtime apply failed: ' . $apply['stderr']);
        syncMigrationPlanAssert(
            syncMigrationPlanTableExists($runtime, $runtimeDb, 'sync_projection_versions'),
            'selected additive table must be created'
        );
        syncMigrationPlanAssert(
            !syncMigrationPlanTableExists($runtime, $runtimeDb, 'sync_outbox'),
            'deferred all-plan table must not be created by subset apply'
        );
        $runtime->select_db($runtimeDb);
        $statusColumn = $runtime->query("SHOW COLUMNS FROM schema_migrations LIKE 'status'");
        syncMigrationPlanAssert($statusColumn->num_rows === 1, 'legacy tracking table must gain status');
        $metadataColumn = $runtime->query("SHOW COLUMNS FROM schema_migrations LIKE 'metadata_json'");
        syncMigrationPlanAssert($metadataColumn->num_rows === 1, 'legacy tracking metadata_json must remain usable');
        $appliedRow = $runtime->query(
            "SELECT status FROM schema_migrations WHERE version = 'sync_projection_versions' LIMIT 1"
        )->fetch_assoc();
        syncMigrationPlanAssert(($appliedRow['status'] ?? '') === 'applied', 'selected migration must be recorded applied');

        $runtime->query(
            'CREATE TABLE acc_head ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'balance DECIMAL(10,2) NOT NULL DEFAULT 0.00'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $missingReviewedLabels = syncMigrationPlanRunCli($runtimeDb, [
            '--dry-run',
            '--scope=reviewed',
        ]);
        syncMigrationPlanAssert($missingReviewedLabels['exit_code'] !== 0, 'reviewed dry-run without labels must fail');
        syncMigrationPlanAssert(
            strpos($missingReviewedLabels['stderr'], 'MIGRATION_LABELS_REQUIRED_FOR_REVIEWED_SCOPE') !== false,
            'reviewed missing-label failure must be explicit'
        );
        $reviewedDryRun = syncMigrationPlanRunCli($runtimeDb, [
            '--dry-run',
            '--scope=reviewed',
            '--labels=acc_head.modify_balance_decimal24_6',
        ]);
        syncMigrationPlanAssert($reviewedDryRun['exit_code'] === 0, 'reviewed rewrite dry-run failed: ' . $reviewedDryRun['stderr']);
        syncMigrationPlanAssert(
            strpos($reviewedDryRun['stdout'], 'Dry run scope: reviewed;') !== false,
            'reviewed dry-run must report selected/deferred scope'
        );
        $reviewedWithoutBackup = syncMigrationPlanRunCli($runtimeDb, [
            '--apply',
            '--scope=reviewed',
            '--labels=acc_head.modify_balance_decimal24_6',
            '--confirm-no-backup',
        ]);
        syncMigrationPlanAssert($reviewedWithoutBackup['exit_code'] !== 0, 'reviewed rewrite apply without backup must fail');
        syncMigrationPlanAssert(
            strpos($reviewedWithoutBackup['stderr'], '--apply requires a readable --backup-file') !== false,
            'reviewed rewrite must retain the backup gate'
        );
        $backupFile = tempnam(sys_get_temp_dir(), 'posmain-reviewed-migration-');
        if ($backupFile === false) {
            throw new RuntimeException('Could not create reviewed migration backup fixture.');
        }
        file_put_contents($backupFile, "reviewed migration backup fixture\n");
        try {
            $reviewedApply = syncMigrationPlanRunCli($runtimeDb, [
                '--apply',
                '--scope=reviewed',
                '--labels=acc_head.modify_balance_decimal24_6',
                '--backup-file=' . $backupFile,
            ]);
            syncMigrationPlanAssert($reviewedApply['exit_code'] === 0, 'reviewed rewrite apply failed: ' . $reviewedApply['stderr']);
        } finally {
            unlink($backupFile);
        }
        $runtime->select_db($runtimeDb);
        $balanceColumn = $runtime->query("SHOW COLUMNS FROM acc_head LIKE 'balance'")->fetch_assoc();
        syncMigrationPlanAssert(
            strtolower((string) ($balanceColumn['Type'] ?? '')) === 'decimal(24,6)',
            'reviewed exact rewrite must apply the selected target definition'
        );
        syncMigrationPlanAssert(
            !syncMigrationPlanTableExists($runtime, $runtimeDb, 'sync_outbox'),
            'reviewed exact rewrite must not apply deferred additive statements'
        );
        $reviewedRow = $runtime->query(
            "SELECT status FROM schema_migrations WHERE version = 'acc_head.modify_balance_decimal24_6' LIMIT 1"
        )->fetch_assoc();
        syncMigrationPlanAssert(($reviewedRow['status'] ?? '') === 'applied', 'reviewed rewrite must be recorded applied');

        $runtime->select_db($checksumDb);
        $runtime->query(
            "INSERT INTO schema_migrations (version, filename, checksum, applied_by, metadata_json) "
            . "VALUES ('sync_projection_versions', 'legacy.php', REPEAT('0', 64), 'test', NULL)"
        );
        $mismatch = syncMigrationPlanRunCli($checksumDb, [
            '--apply',
            '--scope=additive',
            '--labels=sync_projection_versions',
            '--confirm-no-backup',
        ]);
        syncMigrationPlanAssert($mismatch['exit_code'] !== 0, 'stored checksum mismatch must fail');
        syncMigrationPlanAssert(
            strpos($mismatch['stderr'], 'SCHEMA_MIGRATION_CHECKSUM_MISMATCH:sync_projection_versions') !== false,
            'checksum failure must identify the selected label'
        );
        syncMigrationPlanAssert(
            !syncMigrationPlanTableExists($runtime, $checksumDb, 'sync_projection_versions'),
            'checksum mismatch must stop before the selected business-schema write'
        );
        echo "sync-migration-plan-runtime-ok\n";
    } finally {
        foreach ([$runtimeDb, $checksumDb] as $database) {
            $runtime->query("DROP DATABASE IF EXISTS `{$database}`");
        }
        $runtime->close();
    }
} else {
    echo "sync-migration-plan-runtime-skipped\n";
}

echo "sync-migration-plan-ok\n";

function syncMigrationPlanAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function syncMigrationPlanExpectBlocked(callable $callback, string $reason): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        syncMigrationPlanAssert(strpos($e->getMessage(), $reason) !== false, 'expected ' . $reason . ', got ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected migration plan blocker: ' . $reason);
}

function syncMigrationPlanRuntimeConnection(): ?mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = @new mysqli(
        getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        '',
        (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307)
    );
    if ($connection->connect_error) {
        return null;
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    return $connection;
}

function syncMigrationPlanCreateLegacyTracking(mysqli $connection, string $database): void
{
    $connection->select_db($database);
    $connection->query("
        CREATE TABLE schema_migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(191) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_by VARCHAR(100) NULL,
            metadata_json JSON NULL,
            UNIQUE KEY uq_schema_migrations_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function syncMigrationPlanRunCli(string $database, array $arguments): array
{
    $command = array_merge([
        PHP_BINARY,
        __DIR__ . '/../../tools/run_migrations.php',
    ], $arguments);
    $environment = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
        'POSMAIN_DB_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
        'POSMAIN_DB_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
        'POSMAIN_DB_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
        'POSMAIN_DB_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
        'POSMAIN_DB_NAME' => $database,
        'POSMAIN_PRODUCTION_MODE' => '0',
        'POSMAIN_ROUTER_ENABLED' => '0',
        'POSMAIN_RUNTIME_CONFIG_DISABLED' => '1',
    ];
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'rb'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, __DIR__ . '/../..', $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start migration runner subprocess.');
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

function syncMigrationPlanTableExists(mysqli $connection, string $database, string $table): bool
{
    $stmt = $connection->prepare(
        'SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->bind_param('ss', $database, $table);
    $stmt->execute();
    $exists = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0) > 0;
    $stmt->close();
    return $exists;
}
