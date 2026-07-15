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
            if ($this->readableBackup($backupFile) && $this->trackingTableExists($conn)) {
                $this->ensureTrackingTable($conn);
                $this->reconcileStartedMigrations($conn, []);
            }
            return [];
        }

        if (!$this->readableBackup($backupFile)) {
            throw new RuntimeException('SCHEMA_MIGRATIONS_REQUIRE_BACKUP');
        }

        $this->ensureTrackingTable($conn);
        $this->reconcileStartedMigrations($conn, $pending);
        $applied = [];
        foreach ($pending as $label => $sql) {
            $this->assertChecksumCompatible($conn, (string) $label, (string) $sql);
            $this->record($conn, (string) $label, (string) $sql, $backupFile, 'started');
            $conn->query($sql);
            $this->record($conn, (string) $label, (string) $sql, $backupFile, 'applied');
            $applied[] = (string) $label;
        }
        $manager->apply($conn);

        return $applied;
    }

    private function ensureTrackingTable(mysqli $conn): void
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
        if (!$this->columnExists($conn, 'schema_migrations', 'status')) {
            $conn->query("ALTER TABLE schema_migrations ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'applied' AFTER applied_by");
        }
    }

    private function record(mysqli $conn, string $version, string $statement, string $backupFile, string $status): void
    {
        $filename = 'classes/Sync/SchemaManager.php';
        $checksum = hash('sha256', $statement);
        $appliedBy = getenv('USER') ?: getenv('USERNAME') ?: 'update_worker';
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

    private function assertChecksumCompatible(mysqli $conn, string $version, string $statement): void
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

    private function readableBackup(string $backupFile): bool
    {
        return $backupFile !== '' && is_file($backupFile) && is_readable($backupFile) && filesize($backupFile) > 0;
    }

    private function reconcileStartedMigrations(mysqli $conn, array $pending): void
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

    private function trackingTableExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'schema_migrations'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $table = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

}
