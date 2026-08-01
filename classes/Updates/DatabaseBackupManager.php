<?php

/**
 * Creates, verifies, and restores an exact single-database recovery artifact.
 *
 * Dumps include DROP/CREATE DATABASE statements so a failed DDL migration can
 * be rolled back even when it created new tables. Commands are executed without
 * a shell and credentials are passed only through MYSQL_PWD.
 */
class PosmainDatabaseBackupManager
{
    private array $database;
    private $processRunner;
    private $databaseVerifier;

    public function __construct(array $database, ?callable $processRunner = null, ?callable $databaseVerifier = null)
    {
        $this->database = $this->normalizeDatabase($database);
        $this->processRunner = $processRunner;
        $this->databaseVerifier = $databaseVerifier;
    }

    public function preflight(): array
    {
        $dumpVersion = $this->runProcess([$this->binary('POSMAIN_MYSQLDUMP_BIN', 'mysqldump'), '--version']);
        if ($dumpVersion['exit_code'] !== 0) {
            throw new RuntimeException('MYSQLDUMP_UNAVAILABLE:' . $this->processError($dumpVersion));
        }
        $mysqlVersion = $this->runProcess([$this->binary('POSMAIN_MYSQL_BIN', 'mysql'), '--version']);
        if ($mysqlVersion['exit_code'] !== 0) {
            throw new RuntimeException('MYSQL_CLIENT_UNAVAILABLE:' . $this->processError($mysqlVersion));
        }

        $privileges = $this->recoveryPrivileges();
        $required = [
            'SELECT',
            'INSERT',
            'UPDATE',
            'DELETE',
            'CREATE',
            'DROP',
            'ALTER',
            'INDEX',
            'TRIGGER',
            'EVENT',
            'CREATE ROUTINE',
            'ALTER ROUTINE',
            'EXECUTE',
            'CREATE VIEW',
            'SHOW VIEW',
            'LOCK TABLES',
        ];
        $missing = array_values(array_diff($required, $privileges));
        if ($missing !== []) {
            throw new RuntimeException('DATABASE_RECOVERY_PRIVILEGES_MISSING:' . implode(',', $missing));
        }

        return [
            'ok' => true,
            'database' => $this->database['name'],
            'mysqldump' => trim($dumpVersion['stdout'] ?: $dumpVersion['stderr']),
            'mysql' => trim($mysqlVersion['stdout'] ?: $mysqlVersion['stderr']),
            'recovery_privileges_verified' => true,
        ];
    }

    private function recoveryPrivileges(): array
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(
            $this->database['host'],
            $this->database['user'],
            $this->database['pass'],
            $this->database['name'],
            $this->database['port']
        );
        try {
            $result = $conn->query('SHOW GRANTS FOR CURRENT_USER');
            $grants = [];
            while ($row = $result->fetch_row()) {
                $grants[] = strtoupper((string) ($row[0] ?? ''));
            }
            $databasePattern = preg_quote(strtoupper($this->database['name']), '/');
            $privileges = [];
            foreach ($grants as $grant) {
                if (preg_match('/^GRANT (.+) ON (`?' . $databasePattern . '`?|\*)\.\* TO /', $grant, $matches) !== 1) {
                    continue;
                }
                $listed = array_map('trim', explode(',', $matches[1]));
                if (in_array('ALL PRIVILEGES', $listed, true)) {
                    return ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'TRIGGER', 'EVENT', 'CREATE ROUTINE', 'ALTER ROUTINE', 'EXECUTE', 'CREATE VIEW', 'SHOW VIEW', 'LOCK TABLES'];
                }
                $privileges = array_merge($privileges, $listed);
            }

            return array_values(array_unique($privileges));
        } finally {
            $conn->close();
        }
    }

    /**
     * @return array{ok:bool,file:string,database:string,bytes:int,sha256:string}
     */
    public function create(string $output): array
    {
        $output = $this->absolutePath($output, 'BACKUP_OUTPUT_MUST_BE_ABSOLUTE');
        $directory = dirname($output);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('BACKUP_DIRECTORY_UNAVAILABLE:' . $directory);
        }
        if (file_exists($output)) {
            throw new RuntimeException('BACKUP_OUTPUT_ALREADY_EXISTS:' . $output);
        }

        $partial = $output . '.partial-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $command = $this->dumpCommand($partial);
        try {
            $result = $this->runProcess($command);
            if ($result['exit_code'] !== 0) {
                throw new RuntimeException('BACKUP_FAILED:' . $this->processError($result));
            }

            $this->verify($partial);
            if (!@chmod($partial, 0600)) {
                throw new RuntimeException('BACKUP_PERMISSIONS_FAILED:' . $partial);
            }
            if (!@rename($partial, $output)) {
                throw new RuntimeException('BACKUP_PUBLISH_FAILED:' . $output);
            }

            return $this->verify($output);
        } catch (Throwable $exception) {
            if (is_file($partial)) {
                @unlink($partial);
            }
            throw $exception;
        }
    }

    /**
     * @return array{ok:bool,file:string,database:string,bytes:int,sha256:string}
     */
    public function verify(string $backupFile): array
    {
        $backupFile = $this->absolutePath($backupFile, 'BACKUP_FILE_MUST_BE_ABSOLUTE');
        if (!is_file($backupFile) || !is_readable($backupFile)) {
            throw new RuntimeException('BACKUP_FILE_UNREADABLE:' . $backupFile);
        }

        $bytes = filesize($backupFile);
        if (!is_int($bytes) || $bytes < 256) {
            throw new RuntimeException('BACKUP_FILE_TOO_SMALL:' . $backupFile);
        }

        $head = $this->readHead($backupFile, 131072);
        $tail = $this->readTail($backupFile, 131072);
        if (preg_match('/^-- (?:MySQL|MariaDB) dump /m', $head) !== 1) {
            throw new RuntimeException('BACKUP_HEADER_INVALID:' . $backupFile);
        }
        if (strpos($tail, '-- Dump completed on ') === false) {
            throw new RuntimeException('BACKUP_COMPLETION_MARKER_MISSING:' . $backupFile);
        }

        $database = preg_quote($this->database['name'], '/');
        if (preg_match('/^-- Current Database: `' . $database . '`$/m', $head) !== 1) {
            throw new RuntimeException('BACKUP_DATABASE_MISMATCH:' . $this->database['name']);
        }
        if (preg_match('/^CREATE DATABASE .*`' . $database . '`.*;$/m', $head) !== 1) {
            throw new RuntimeException('BACKUP_CREATE_DATABASE_MISSING:' . $this->database['name']);
        }
        if (preg_match('/^USE `' . $database . '`;$/m', $head) !== 1) {
            throw new RuntimeException('BACKUP_USE_DATABASE_MISSING:' . $this->database['name']);
        }

        $sha256 = hash_file('sha256', $backupFile);
        if (!is_string($sha256) || strlen($sha256) !== 64) {
            throw new RuntimeException('BACKUP_CHECKSUM_FAILED:' . $backupFile);
        }

        return [
            'ok' => true,
            'file' => $backupFile,
            'database' => $this->database['name'],
            'bytes' => $bytes,
            'sha256' => $sha256,
        ];
    }

    /**
     * @return array{ok:bool,file:string,database:string,bytes:int,sha256:string,verification:array}
     */
    public function restore(string $backupFile): array
    {
        $backup = $this->verify($backupFile);
        $result = $this->runProcess($this->restoreCommand(), $backupFile);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('DATABASE_RESTORE_FAILED:' . $this->processError($result));
        }

        $verification = $this->verifyRestoredDatabase();
        if (empty($verification['ok'])) {
            throw new RuntimeException('DATABASE_RESTORE_VERIFICATION_FAILED');
        }

        return $backup + ['verification' => $verification];
    }

    /**
     * @return array<int, string>
     */
    public function dumpCommand(string $output): array
    {
        $output = $this->absolutePath($output, 'BACKUP_OUTPUT_MUST_BE_ABSOLUTE');

        return [
            $this->binary('POSMAIN_MYSQLDUMP_BIN', 'mysqldump'),
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--default-character-set=' . $this->database['charset'],
            '--host=' . $this->database['host'],
            '--port=' . (string) $this->database['port'],
            '--user=' . $this->database['user'],
            '--add-drop-database',
            '--databases',
            $this->database['name'],
            '--result-file=' . $output,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function restoreCommand(): array
    {
        return [
            $this->binary('POSMAIN_MYSQL_BIN', 'mysql'),
            '--default-character-set=' . $this->database['charset'],
            '--host=' . $this->database['host'],
            '--port=' . (string) $this->database['port'],
            '--user=' . $this->database['user'],
        ];
    }

    public function printableDumpCommand(string $output): string
    {
        return 'MYSQL_PWD=******** ' . implode(' ', array_map('escapeshellarg', $this->dumpCommand($output)));
    }

    public function printableRestoreCommand(string $backupFile): string
    {
        return 'MYSQL_PWD=******** '
            . implode(' ', array_map('escapeshellarg', $this->restoreCommand()))
            . ' < ' . escapeshellarg($backupFile);
    }

    private function normalizeDatabase(array $database): array
    {
        $normalized = [
            'host' => trim((string) ($database['host'] ?? '')),
            'port' => (int) ($database['port'] ?? 3306),
            'name' => trim((string) ($database['name'] ?? '')),
            'user' => trim((string) ($database['user'] ?? '')),
            'pass' => (string) ($database['pass'] ?? ''),
            'charset' => trim((string) ($database['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
        ];
        foreach (['host', 'name', 'user'] as $required) {
            if ($normalized[$required] === '') {
                throw new InvalidArgumentException('DATABASE_CONFIG_MISSING:' . $required);
            }
        }
        if ($normalized['port'] < 1 || $normalized['port'] > 65535) {
            throw new InvalidArgumentException('DATABASE_CONFIG_INVALID:port');
        }
        if (preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $normalized['name']) !== 1) {
            throw new InvalidArgumentException('DATABASE_CONFIG_INVALID:name');
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $normalized['charset']) !== 1) {
            throw new InvalidArgumentException('DATABASE_CONFIG_INVALID:charset');
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runProcess(array $command, ?string $stdinFile = null): array
    {
        $environment = $this->processEnvironment();
        if (is_callable($this->processRunner)) {
            $result = call_user_func($this->processRunner, $command, $environment, $stdinFile);
            if (!is_array($result) || !isset($result['exit_code'])) {
                throw new RuntimeException('DATABASE_PROCESS_RUNNER_INVALID_RESULT');
            }

            return [
                'exit_code' => (int) $result['exit_code'],
                'stdout' => (string) ($result['stdout'] ?? ''),
                'stderr' => (string) ($result['stderr'] ?? ''),
            ];
        }

        $descriptors = [
            0 => $stdinFile === null ? ['file', '/dev/null', 'r'] : ['file', $stdinFile, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes, null, $environment);
        if (!is_resource($process)) {
            $error = error_get_last();
            return [
                'exit_code' => 127,
                'stdout' => '',
                'stderr' => trim((string) ($error['message'] ?? 'database process could not be started')),
            ];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => (int) $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function processEnvironment(): array
    {
        $password = $this->database['pass'];
        if (strpos($password, "\0") !== false) {
            throw new RuntimeException('DATABASE_PASSWORD_ENV_INVALID');
        }

        $environment = [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'MYSQL_PWD' => $password,
        ];
        foreach (['HOME', 'TMPDIR', 'TMP', 'TEMP', 'LANG', 'LC_ALL'] as $key) {
            $value = getenv($key);
            if (!is_string($value) || $value === '' || strpos($value, "\0") !== false) {
                continue;
            }
            $environment[$key] = $value;
        }

        return $environment;
    }

    private function verifyRestoredDatabase(): array
    {
        if (is_callable($this->databaseVerifier)) {
            $result = call_user_func($this->databaseVerifier, $this->database);
            return is_array($result) ? $result : ['ok' => (bool) $result];
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(
            $this->database['host'],
            $this->database['user'],
            $this->database['pass'],
            $this->database['name'],
            $this->database['port']
        );
        try {
            $conn->set_charset($this->database['charset']);
            $database = (string) ($conn->query('SELECT DATABASE() AS database_name')->fetch_assoc()['database_name'] ?? '');
            $tables = (int) ($conn->query('SELECT COUNT(*) AS table_count FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_assoc()['table_count'] ?? 0);

            return [
                'ok' => $database === $this->database['name'] && $tables > 0,
                'database' => $database,
                'table_count' => $tables,
            ];
        } finally {
            $conn->close();
        }
    }

    private function absolutePath(string $path, string $error): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException($error);
        }

        return $path;
    }

    private function binary(string $environmentKey, string $fallback): string
    {
        $configured = trim((string) (getenv($environmentKey) ?: ''));
        return $configured !== '' ? $configured : $fallback;
    }

    private function readHead(string $path, int $length): string
    {
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('BACKUP_FILE_UNREADABLE:' . $path);
        }
        try {
            $contents = fread($handle, $length);
            return is_string($contents) ? $contents : '';
        } finally {
            fclose($handle);
        }
    }

    private function readTail(string $path, int $length): string
    {
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if (!is_int($size) || !is_resource($handle)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('BACKUP_FILE_UNREADABLE:' . $path);
        }
        try {
            fseek($handle, max(0, $size - $length));
            $contents = stream_get_contents($handle);
            return is_string($contents) ? $contents : '';
        } finally {
            fclose($handle);
        }
    }

    private function processError(array $result): string
    {
        $message = trim((string) (($result['stderr'] ?? '') ?: ($result['stdout'] ?? '')));
        return substr($message !== '' ? $message : 'unknown database command failure', 0, 4000);
    }
}
