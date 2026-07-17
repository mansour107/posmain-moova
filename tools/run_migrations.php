<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../classes/Sync/SyncMigrationPlan.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['dry-run', 'apply', 'backup-file:', 'confirm-no-backup', 'allow-destructive', 'scope:', 'labels:', 'help']);
if (isset($options['help'])) {
    syncMigrationUsage();
    exit(0);
}

$dryRun = isset($options['dry-run']);
$apply = isset($options['apply']);

if ($dryRun === $apply) {
    syncMigrationUsage(STDERR);
    exit(1);
}

$backupFile = isset($options['backup-file']) ? trim((string) $options['backup-file']) : '';
$hasBackup = $backupFile !== '' && is_file($backupFile) && is_readable($backupFile) && filesize($backupFile) > 0;
$scope = isset($options['scope']) ? strtolower(trim((string) $options['scope'])) : SyncMigrationPlan::SCOPE_ALL;
$labels = [];
if (isset($options['labels'])) {
    $labels = array_values(array_filter(array_map('trim', explode(',', (string) $options['labels'])), 'strlen'));
}
if ($apply && $scope === SyncMigrationPlan::SCOPE_ADDITIVE && $labels === []) {
    fwrite(STDERR, "MIGRATION_LABELS_REQUIRED_FOR_ADDITIVE_APPLY\n");
    exit(1);
}
if ($scope === SyncMigrationPlan::SCOPE_REVIEWED && $labels === []) {
    fwrite(STDERR, "MIGRATION_LABELS_REQUIRED_FOR_REVIEWED_SCOPE\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = syncMigrationConnect();
$manager = new SyncSchemaManager();
$pending = $manager->pendingStatements($conn);
$tracking = syncMigrationTrackingStatus($conn);
$planner = new SyncMigrationPlan();
try {
    $plan = $planner->select($pending, $scope, $labels);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$selected = $plan['selected'];
$deferred = $plan['deferred'];

if ($dryRun) {
    echo "Migration tracking: " . ($tracking['exists'] ? 'ready' : 'missing; will be created on apply') . ".\n";
    if ($scope === SyncMigrationPlan::SCOPE_ALL) {
        echo "Dry run: " . count($pending) . " pending sync schema change(s).\n";
    } else {
        echo "Dry run scope: {$scope}; pending=" . count($pending)
            . ", selected=" . count($selected) . ", deferred=" . count($deferred) . ".\n";
    }
    foreach ($selected as $table => $sql) {
        echo "\n-- {$table}\n{$sql};\n";
    }
    if ($scope !== SyncMigrationPlan::SCOPE_ALL && $deferred !== []) {
        echo "\nDeferred statements (not executable in this scope):\n";
        foreach (array_keys($deferred) as $label) {
            echo "- {$label} [" . ($plan['classifications'][$label] ?? SyncMigrationPlan::AMBIGUOUS) . "]\n";
        }
    }
    exit(0);
}

if ($selected === []) {
    echo "Applied 0 selected sync schema change(s): none\n";
    exit(0);
}

$hasRewrite = syncMigrationContainsDataRewriteStatement($selected);
$production = in_array(strtolower(trim((string) getenv('POSMAIN_PRODUCTION_MODE'))), ['1', 'true', 'yes', 'on'], true);
if (!$hasBackup && ($production || $hasRewrite || !isset($options['confirm-no-backup']))) {
    fwrite(STDERR, "--apply requires a readable --backup-file in production and for every destructive or data-rewrite statement. Additive local/dev apply also requires --confirm-no-backup.\n");
    exit(1);
}
if (syncMigrationContainsDestructiveStatement($selected) && (!isset($options['allow-destructive']) || !$hasBackup)) {
    fwrite(STDERR, "Pending statements include destructive operations. Re-run with --allow-destructive and a readable --backup-file.\n");
    exit(1);
}

syncMigrationEnsureTrackingTable($conn);
syncMigrationReconcileStarted($conn, $pending);
foreach ($selected as $label => $sql) {
    syncMigrationAssertChecksumCompatible($conn, (string) $label, (string) $sql);
}
$applied = [];
foreach ($selected as $label => $sql) {
    syncMigrationRecord($conn, (string) $label, (string) $sql, $backupFile, 'started');
    $conn->query($sql);
    syncMigrationRecord($conn, (string) $label, (string) $sql, $backupFile, 'applied');
    $applied[] = (string) $label;
}
if ($scope === SyncMigrationPlan::SCOPE_ALL) {
    // All pending statements were already tracked and applied above. This call
    // now only performs the manager's existing post-migration seed behavior.
    $manager->apply($conn);
}
echo "Applied " . count($applied) . ($scope === SyncMigrationPlan::SCOPE_ALL ? ' sync' : ' selected sync')
    . " schema change(s): " . ($applied ? implode(', ', $applied) : 'none') . "\n";

function syncMigrationConnect()
{
    return posmain_db_connect();
}

function syncMigrationUsage($stream = null): void
{
    $stream = $stream ?: STDOUT;
    fwrite($stream, "Usage: php tools/run_migrations.php --dry-run | --apply --backup-file=/absolute/path/to/recent.sql\n");
    fwrite($stream, "Additive discovery/subset: add --scope=additive [--labels=label.one,label.two].\n");
    fwrite($stream, "Reviewed exact subset: add --scope=reviewed --labels=label.one,label.two.\n");
    fwrite($stream, "Local/dev override: php tools/run_migrations.php --apply --confirm-no-backup\n");
    fwrite($stream, "Destructive statements, if ever present, also require --allow-destructive and a readable --backup-file.\n");
}

function syncMigrationTrackingStatus(mysqli $conn): array
{
    $dbResult = $conn->query('SELECT DATABASE() AS db_name');
    $dbName = (string) (($dbResult->fetch_assoc()['db_name'] ?? '') ?: '');
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS table_count
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'schema_migrations'
    ");
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'exists' => ((int) ($row['table_count'] ?? 0)) > 0,
        'database' => $dbName,
    ];
}

function syncMigrationEnsureTrackingTable(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(191) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_by VARCHAR(100) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'applied',
            metadata_json JSON NULL,
            UNIQUE KEY uq_schema_migrations_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM schema_migrations');
    while ($row = $result->fetch_assoc()) {
        $columns[] = (string) ($row['Field'] ?? '');
    }
    foreach (['version', 'filename', 'checksum', 'applied_at', 'applied_by'] as $required) {
        if (!in_array($required, $columns, true)) {
            throw new RuntimeException('SCHEMA_MIGRATION_TRACKING_INCOMPATIBLE:missing_' . $required);
        }
    }
    if (!in_array('status', $columns, true)) {
        $conn->query("ALTER TABLE schema_migrations ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'applied' AFTER applied_by");
        $columns[] = 'status';
    }
    if (!in_array('metadata_json', $columns, true)) {
        $conn->query('ALTER TABLE schema_migrations ADD COLUMN metadata_json JSON NULL AFTER status');
    }
}

function syncMigrationRecord(mysqli $conn, string $version, string $statement, string $backupFile, string $status): void
{
    $filename = 'classes/Sync/SchemaManager.php';
    $checksum = hash('sha256', $statement);
    $appliedBy = getenv('USER') ?: getenv('USERNAME') ?: 'cli';
    $metadata = json_encode([
        'statement_label' => $version,
        'backup_file' => $backupFile !== '' ? $backupFile : null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("
        INSERT INTO schema_migrations (version, filename, checksum, applied_by, status, metadata_json)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            checksum = VALUES(checksum),
            applied_at = CURRENT_TIMESTAMP,
            applied_by = VALUES(applied_by),
            status = VALUES(status),
            metadata_json = VALUES(metadata_json)
    ");
    $stmt->bind_param('ssssss', $version, $filename, $checksum, $appliedBy, $status, $metadata);
    $stmt->execute();
    $stmt->close();
}

function syncMigrationReconcileStarted(mysqli $conn, array $pending): void
{
    $pendingLabels = array_fill_keys(array_map('strval', array_keys($pending)), true);
    $result = $conn->query("SELECT version FROM schema_migrations WHERE status = 'started'");
    while ($row = $result->fetch_assoc()) {
        $version = (string) ($row['version'] ?? '');
        if ($version !== '' && !isset($pendingLabels[$version])) {
            $stmt = $conn->prepare("UPDATE schema_migrations SET status = 'applied', applied_at = CURRENT_TIMESTAMP WHERE version = ? AND status = 'started'");
            $stmt->bind_param('s', $version);
            $stmt->execute();
            $stmt->close();
        }
    }
}

function syncMigrationAssertChecksumCompatible(mysqli $conn, string $version, string $statement): void
{
    $checksum = hash('sha256', $statement);
    $stmt = $conn->prepare('SELECT checksum FROM schema_migrations WHERE version = ? LIMIT 1');
    $stmt->bind_param('s', $version);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && !hash_equals((string) $row['checksum'], $checksum)) {
        throw new RuntimeException('SCHEMA_MIGRATION_CHECKSUM_MISMATCH:' . $version);
    }
}

function syncMigrationContainsDestructiveStatement(array $statements): bool
{
    $planner = new SyncMigrationPlan();
    foreach ($statements as $sql) {
        if ($planner->requiresDestructiveOptIn((string) $sql)) {
            return true;
        }
    }

    return false;
}

function syncMigrationContainsDataRewriteStatement(array $statements): bool
{
    $planner = new SyncMigrationPlan();
    foreach ($statements as $sql) {
        if (in_array($planner->classify((string) $sql), [SyncMigrationPlan::REWRITE, SyncMigrationPlan::DESTRUCTIVE], true)) {
            return true;
        }
    }

    return false;
}
