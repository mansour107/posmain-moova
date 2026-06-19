<?php

require_once __DIR__ . '/../Sync/SchemaManager.php';

class PosmainSchemaMigrationRunner
{
    public function pending(mysqli $conn): array
    {
        $manager = new SyncSchemaManager();

        return $manager->pendingStatements($conn);
    }

    public function apply(mysqli $conn, string $backupFile = ''): array
    {
        $manager = new SyncSchemaManager();
        $pending = $manager->pendingStatements($conn);
        if ($pending === []) {
            return [];
        }

        if ($this->containsDestructiveStatement($pending) && $backupFile === '') {
            throw new RuntimeException('SCHEMA_MIGRATIONS_REQUIRE_BACKUP');
        }

        $this->ensureTrackingTable($conn);
        $applied = $manager->apply($conn);
        if ($applied !== []) {
            $this->record($conn, $manager->plannedStatements(), $backupFile);
        }

        return $applied;
    }

    private function ensureTrackingTable(mysqli $conn): void
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

    private function record(mysqli $conn, array $statements, string $backupFile): void
    {
        $filename = 'classes/Sync/SchemaManager.php';
        $checksum = hash('sha256', json_encode($statements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $appliedBy = getenv('USER') ?: getenv('USERNAME') ?: 'update_worker';
        $metadata = json_encode([
            'statement_count' => count($statements),
            'backup_file' => $backupFile !== '' ? $backupFile : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare("
            INSERT INTO schema_migrations (version, filename, checksum, applied_by, metadata_json)
            VALUES ('sync_schema_manager', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                checksum = VALUES(checksum),
                applied_at = CURRENT_TIMESTAMP,
                applied_by = VALUES(applied_by),
                metadata_json = VALUES(metadata_json)
        ");
        $stmt->bind_param('ssss', $filename, $checksum, $appliedBy, $metadata);
        $stmt->execute();
        $stmt->close();
    }

    private function containsDestructiveStatement(array $statements): bool
    {
        foreach ($statements as $sql) {
            if (preg_match('/\b(DROP|TRUNCATE|DELETE\s+FROM|UPDATE\s+\w+\s+SET)\b/i', (string) $sql) === 1) {
                return true;
            }
        }

        return false;
    }
}
