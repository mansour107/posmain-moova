<?php

class DatabaseSessionHandler implements SessionHandlerInterface
{
    private $connectionFactory;
    private string $tableName;
    private int $lifetimeSeconds;
    private int $lockTimeoutSeconds;
    private ?mysqli $conn = null;
    private ?string $lockName = null;
    private bool $lockAcquired = false;
    private bool $schemaEnsured = false;

    public function __construct(callable $connectionFactory, string $tableName, int $lifetimeSeconds, int $lockTimeoutSeconds = 5)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
            throw new InvalidArgumentException('Invalid session table name.');
        }

        $this->connectionFactory = $connectionFactory;
        $this->tableName = $tableName;
        $this->lifetimeSeconds = max(1, $lifetimeSeconds);
        $this->lockTimeoutSeconds = max(0, $lockTimeoutSeconds);
    }

    public static function schemaSql(string $tableName = 'app_sessions'): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
            throw new InvalidArgumentException('Invalid session table name.');
        }

        return "
CREATE TABLE IF NOT EXISTS `" . $tableName . "` (
  id VARCHAR(128) NOT NULL,
  payload MEDIUMBLOB NOT NULL,
  last_activity INT UNSIGNED NOT NULL,
  expires_at INT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_app_sessions_expires_at (expires_at),
  KEY idx_app_sessions_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        $this->connection()->query(self::schemaSql($this->tableName));
        $this->schemaEnsured = true;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->releaseLock();

        if ($this->conn instanceof mysqli) {
            $this->conn->close();
            $this->conn = null;
        }

        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $this->ensureSchema();
            $this->acquireLock($id);

            $stmt = $this->connection()->prepare('SELECT payload, expires_at FROM ' . $this->quotedTableName() . ' WHERE id = ? LIMIT 1');
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$row) {
                return '';
            }

            if ((int) $row['expires_at'] < time()) {
                $this->deleteSession($id);
                return '';
            }

            return (string) $row['payload'];
        } catch (Throwable $e) {
            error_log('POSMAIN database session read failed: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->ensureSchema();

            $now = time();
            $expiresAt = $now + $this->lifetimeSeconds;
            $stmt = $this->connection()->prepare(
                'INSERT INTO ' . $this->quotedTableName() . ' (id, payload, last_activity, expires_at) VALUES (?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE payload = VALUES(payload), last_activity = VALUES(last_activity), expires_at = VALUES(expires_at)'
            );
            $stmt->bind_param('ssii', $id, $data, $now, $expiresAt);
            $stmt->execute();
            $stmt->close();

            return true;
        } catch (Throwable $e) {
            error_log('POSMAIN database session write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->ensureSchema();
            $this->deleteSession($id);
            return true;
        } catch (Throwable $e) {
            error_log('POSMAIN database session destroy failed: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $this->ensureSchema();

            $expiresBefore = time();
            $stmt = $this->connection()->prepare('DELETE FROM ' . $this->quotedTableName() . ' WHERE expires_at < ?');
            $stmt->bind_param('i', $expiresBefore);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            return max(0, $affected);
        } catch (Throwable $e) {
            error_log('POSMAIN database session GC failed: ' . $e->getMessage());
            return false;
        }
    }

    private function connection(): mysqli
    {
        if (!$this->conn instanceof mysqli) {
            $factory = $this->connectionFactory;
            $this->conn = $factory();
        }

        return $this->conn;
    }

    private function quotedTableName(): string
    {
        return '`' . $this->tableName . '`';
    }

    private function deleteSession(string $id): void
    {
        $stmt = $this->connection()->prepare('DELETE FROM ' . $this->quotedTableName() . ' WHERE id = ?');
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $stmt->close();
    }

    private function acquireLock(string $id): void
    {
        if ($this->lockAcquired || $this->lockTimeoutSeconds <= 0) {
            return;
        }

        $this->lockName = 'posmain_sess_' . substr(hash('sha256', $id), 0, 48);
        $stmt = $this->connection()->prepare('SELECT GET_LOCK(?, ?) AS acquired');
        $stmt->bind_param('si', $this->lockName, $this->lockTimeoutSeconds);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->lockAcquired = ((int) ($row['acquired'] ?? 0)) === 1;
        if (!$this->lockAcquired) {
            error_log('POSMAIN database session lock not acquired: ' . $this->lockName);
        }
    }

    private function releaseLock(): void
    {
        if (!$this->lockAcquired || $this->lockName === null || !$this->conn instanceof mysqli) {
            return;
        }

        try {
            $stmt = $this->conn->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->bind_param('s', $this->lockName);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('POSMAIN database session lock release failed: ' . $e->getMessage());
        }

        $this->lockAcquired = false;
        $this->lockName = null;
    }
}
