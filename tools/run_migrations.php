<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Sync/SchemaManager.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['dry-run', 'apply', 'backup-file:', 'confirm-no-backup', 'allow-destructive', 'help']);
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

if ($apply && !$hasBackup && !isset($options['confirm-no-backup'])) {
    fwrite(STDERR, "--apply requires --backup-file=/absolute/path/to/recent.sql, or --confirm-no-backup for local/dev only.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = syncMigrationConnect();
$manager = new SyncSchemaManager();
$pending = $manager->pendingStatements($conn);
$tracking = syncMigrationTrackingStatus($conn);

if (syncMigrationContainsDestructiveStatement($pending) && (!isset($options['allow-destructive']) || !$hasBackup)) {
    fwrite(STDERR, "Pending statements include destructive operations. Re-run with --allow-destructive and --backup-file=/absolute/path/to/recent.sql.\n");
    exit(1);
}

if ($dryRun) {
    echo "Migration tracking: " . ($tracking['exists'] ? 'ready' : 'missing; will be created on apply') . ".\n";
    echo "Dry run: " . count($pending) . " pending sync schema change(s).\n";
    foreach ($pending as $table => $sql) {
        echo "\n-- {$table}\n{$sql};\n";
    }
    exit(0);
}

syncMigrationEnsureTrackingTable($conn);
$applied = $manager->apply($conn);
if ($applied) {
    syncMigrationRecord($conn, 'sync_schema_manager', $manager->plannedStatements(), $backupFile);
}
echo "Applied " . count($applied) . " sync schema change(s): " . ($applied ? implode(', ', $applied) : 'none') . "\n";

function syncMigrationConnect()
{
    return posmain_db_connect();
}

function syncMigrationUsage($stream = null): void
{
    $stream = $stream ?: STDOUT;
    fwrite($stream, "Usage: php tools/run_migrations.php --dry-run | --apply --backup-file=/absolute/path/to/recent.sql\n");
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
            version VARCHAR(100) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_by VARCHAR(100) NULL,
            metadata_json JSON NULL,
            UNIQUE KEY uq_schema_migrations_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function syncMigrationRecord(mysqli $conn, string $version, array $statements, string $backupFile): void
{
    $filename = 'classes/Sync/SchemaManager.php';
    $checksum = hash('sha256', json_encode($statements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $appliedBy = getenv('USER') ?: getenv('USERNAME') ?: 'cli';
    $metadata = json_encode([
        'statement_count' => count($statements),
        'backup_file' => $backupFile !== '' ? $backupFile : null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("
        INSERT INTO schema_migrations (version, filename, checksum, applied_by, metadata_json)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            checksum = VALUES(checksum),
            applied_at = CURRENT_TIMESTAMP,
            applied_by = VALUES(applied_by),
            metadata_json = VALUES(metadata_json)
    ");
    $stmt->bind_param('sssss', $version, $filename, $checksum, $appliedBy, $metadata);
    $stmt->execute();
    $stmt->close();
}

function syncMigrationContainsDestructiveStatement(array $statements): bool
{
    foreach ($statements as $sql) {
        if (preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', (string) $sql) === 1) {
            return true;
        }
    }

    return false;
}
