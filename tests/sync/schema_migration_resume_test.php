<?php

declare(strict_types=1);

require_once __DIR__ . '/../../classes/Updates/SchemaMigrationRunner.php';

mysqli_report(MYSQLI_REPORT_OFF);
$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_migration_resume_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    fwrite(STDERR, "schema-migration-resume-failed-db-unavailable\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$backup = tempnam(sys_get_temp_dir(), 'posmain-migration-backup-');
if (!is_string($backup) || file_put_contents($backup, '-- verified test backup') === false) {
    throw new RuntimeException('unable to create migration backup fixture');
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query("
        CREATE TABLE schema_migrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            version VARCHAR(191) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            applied_by VARCHAR(100) NULL,
            UNIQUE KEY uq_schema_migrations_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $runner = new PosmainSchemaMigrationRunner();
    $applied = $runner->apply($conn, $backup);
    migrationResumeAssert($applied !== [], 'empty database must be provisioned');
    migrationResumeAssert($conn->query("SHOW COLUMNS FROM schema_migrations LIKE 'status'")->num_rows === 1, 'older tracking table must gain status');
    migrationResumeAssert($conn->query("SHOW COLUMNS FROM schema_migrations LIKE 'metadata_json'")->num_rows === 1, 'older tracking table must gain metadata_json');
    $ledgerCount = (int) $conn->query("SELECT COUNT(*) AS c FROM schema_migrations WHERE status = 'applied'")->fetch_assoc()['c'];
    migrationResumeAssert($ledgerCount === count($applied), 'every applied statement must have its own completed ledger row');

    $conn->query("UPDATE schema_migrations SET status = 'started' WHERE version = 'app_sessions'");
    migrationResumeAssert($runner->apply($conn, $backup) === [], 'resume must not repeat already completed DDL');
    $resumedStatus = (string) $conn->query("SELECT status FROM schema_migrations WHERE version = 'app_sessions'")->fetch_assoc()['status'];
    migrationResumeAssert($resumedStatus === 'applied', 'completed DDL with an interrupted ledger write must reconcile on resume');

    $conn->query('DROP TABLE app_sessions');
    try {
        $runner->apply($conn);
        throw new RuntimeException('pending migration without backup must fail');
    } catch (RuntimeException $exception) {
        migrationResumeAssert($exception->getMessage() === 'SCHEMA_MIGRATIONS_REQUIRE_BACKUP', 'pending apply must require a readable backup');
    }

    $conn->query("UPDATE schema_migrations SET checksum = REPEAT('0', 64) WHERE version = 'app_sessions'");
    try {
        $runner->apply($conn, $backup);
        throw new RuntimeException('changed migration checksum must fail');
    } catch (RuntimeException $exception) {
        migrationResumeAssert(str_starts_with($exception->getMessage(), 'SCHEMA_MIGRATION_CHECKSUM_MISMATCH:app_sessions'), 'checksum mismatch must block replay');
    }
    migrationResumeAssert($conn->query("SHOW TABLES LIKE 'app_sessions'")->num_rows === 0, 'checksum rejection must happen before DDL');

    echo "schema-migration-resume-ok\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
    @unlink($backup);
}

function migrationResumeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
