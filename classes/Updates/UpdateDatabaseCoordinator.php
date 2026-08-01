<?php

require_once __DIR__ . '/DatabaseBackupManager.php';
require_once __DIR__ . '/SchemaMigrationRunner.php';
require_once __DIR__ . '/../Stepwise.php';
require_once __DIR__ . '/../Router/ShopRouter.php';

/**
 * Coordinates update recovery and migrations across the default database,
 * router metadata database, and every active router shop.
 */
class PosmainUpdateDatabaseCoordinator
{
    private string $projectRoot;
    private array $config;
    private $targetProvider;
    private $backupManagerFactory;
    private $targetExecutor;
    private ?array $targets = null;

    public function __construct(
        ?string $projectRoot = null,
        ?array $config = null,
        ?callable $targetProvider = null,
        ?callable $backupManagerFactory = null,
        ?callable $targetExecutor = null
    ) {
        $this->projectRoot = rtrim($projectRoot ?: dirname(__DIR__, 2), '/\\');
        $this->config = $config ?? posmain_app_config();
        $this->targetProvider = $targetProvider;
        $this->backupManagerFactory = $backupManagerFactory;
        $this->targetExecutor = $targetExecutor;
    }

    /**
     * @return array<int, array{key:string,kind:string,label:string,database:array}>
     */
    public function targets(): array
    {
        if ($this->targets !== null) {
            return $this->targets;
        }

        $rawTargets = is_callable($this->targetProvider)
            ? call_user_func($this->targetProvider, $this->config)
            : $this->discoverTargets();
        if (!is_array($rawTargets) || $rawTargets === []) {
            throw new RuntimeException('UPDATE_DATABASE_TARGETS_EMPTY');
        }

        $targets = [];
        $seen = [];
        foreach ($rawTargets as $index => $target) {
            if (!is_array($target)) {
                throw new RuntimeException('UPDATE_DATABASE_TARGET_INVALID');
            }
            $database = $this->normalizeDatabase((array) ($target['database'] ?? []));
            $identity = strtolower($database['host']) . ':' . $database['port'] . '/' . $database['name'];
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $kind = (string) ($target['kind'] ?? 'shop');
            if (!in_array($kind, ['default', 'router', 'shop'], true)) {
                throw new RuntimeException('UPDATE_DATABASE_TARGET_KIND_INVALID');
            }
            $targets[] = [
                'key' => substr(hash('sha256', $identity), 0, 24),
                'kind' => $kind,
                'label' => trim((string) ($target['label'] ?? '')) ?: ($kind . '-' . ($index + 1)),
                'database' => $database,
            ];
        }
        if ($targets === []) {
            throw new RuntimeException('UPDATE_DATABASE_TARGETS_EMPTY');
        }

        return $this->targets = $targets;
    }

    public function publicTargets(): array
    {
        return array_map(fn(array $target): array => $this->publicTarget($target), $this->targets());
    }

    public function preflight(): array
    {
        $results = [];
        foreach ($this->targets() as $target) {
            $result = $this->manager($target)->preflight();
            $results[] = [
                'target' => $this->publicTarget($target),
                'ok' => !empty($result['ok']),
                'mysqldump' => $result['mysqldump'] ?? null,
                'mysql' => $result['mysql'] ?? null,
                'recovery_privileges_verified' => !empty($result['recovery_privileges_verified']),
            ];
        }

        return ['ok' => true, 'target_count' => count($results), 'targets' => $results];
    }

    public function plan(): array
    {
        $plans = [];
        foreach ($this->targets() as $target) {
            $conn = $this->connect($target);
            try {
                if ($target['kind'] === 'router') {
                    $router = new PosmainShopRouter();
                    $missing = $this->missingRouterTables($conn);
                    $upgrades = $router->upgradeStatements($conn);
                    $plans[] = [
                        'target' => $this->publicTarget($target),
                        'pending_count' => count($missing) + count($upgrades),
                        'router_missing_tables' => $missing,
                        'router_upgrade_labels' => array_keys($upgrades),
                        'stepwise_pending' => [],
                        'stepwise_drift' => [],
                        'schema_pending' => [],
                    ];
                    continue;
                }

                $stepwise = new Stepwise($conn, $this->projectRoot . '/update', [
                    'ledger_table' => 'stepwise_ledger',
                ]);
                $stepwisePlan = $stepwise->plan(false);
                $schemaPending = (new PosmainSchemaMigrationRunner())->pending($conn);
                $plans[] = [
                    'target' => $this->publicTarget($target),
                    'pending_count' => count($stepwisePlan['pending']) + count($schemaPending),
                    'stepwise_pending' => array_map(
                        static fn(array $step): string => (string) $step['step_key'],
                        $stepwisePlan['pending']
                    ),
                    'stepwise_drift' => $stepwisePlan['drift'],
                    'schema_pending' => array_keys($schemaPending),
                ];
            } finally {
                $conn->close();
            }
        }

        return [
            'ok' => true,
            'target_count' => count($plans),
            'pending_count' => array_sum(array_column($plans, 'pending_count')),
            'targets' => $plans,
        ];
    }

    public function backupAll(string $jobId): array
    {
        if (preg_match('/^upd_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $jobId) !== 1) {
            throw new InvalidArgumentException('INVALID_UPDATE_JOB_ID');
        }
        $directory = $this->projectRoot . '/backup/updates/' . $jobId;
        if (file_exists($directory)) {
            throw new RuntimeException('UPDATE_BACKUP_SET_ALREADY_EXISTS');
        }
        if (!mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('BACKUP_DIRECTORY_UNAVAILABLE');
        }

        $artifacts = [];
        try {
            foreach ($this->targets() as $index => $target) {
                $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $target['database']['name']);
                $file = $directory . '/' . sprintf('%02d-%s-%s.sql', $index + 1, $target['kind'], $slug);
                $backup = $this->manager($target)->create($file);
                $artifacts[] = [
                    'target' => $this->publicTarget($target),
                    'file' => (string) $backup['file'],
                    'bytes' => (int) $backup['bytes'],
                    'sha256' => (string) $backup['sha256'],
                ];
            }

            $manifest = [
                'format' => 1,
                'job_id' => $jobId,
                'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'artifacts' => $artifacts,
            ];
            $manifestFile = $directory . '/manifest.json';
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($json) || file_put_contents($manifestFile, $json . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('UPDATE_BACKUP_MANIFEST_WRITE_FAILED');
            }
            @chmod($manifestFile, 0600);

            return $manifest + [
                'directory' => $directory,
                'manifest_file' => $manifestFile,
            ];
        } catch (Throwable $exception) {
            $this->removeDirectoryFiles($directory);
            throw $exception;
        }
    }

    public function applyAll(array $backupSet): array
    {
        $artifacts = $this->artifactsByTarget($backupSet);
        $results = [];
        foreach ($this->targets() as $target) {
            $artifact = $artifacts[$target['key']] ?? null;
            if (!is_array($artifact)) {
                throw new RuntimeException('UPDATE_BACKUP_ARTIFACT_MISSING:' . $target['key']);
            }
            $this->manager($target)->verify((string) $artifact['file']);
            $result = $this->executeTarget('apply', $target, (string) $artifact['file']);
            if (empty($result['ok']) || empty($result['verification']['ok'])) {
                throw new RuntimeException('UPDATE_TARGET_MIGRATION_FAILED:' . $target['key']);
            }
            $results[] = $result;
        }

        $verification = $this->verifyAllFresh();
        if (!$verification['ok']) {
            throw new RuntimeException('UPDATE_DATABASE_POST_MIGRATION_VERIFICATION_FAILED');
        }

        return [
            'ok' => true,
            'targets' => $results,
            'verification' => $verification,
        ];
    }

    public function verifyAllFresh(): array
    {
        $results = [];
        $ok = true;
        foreach ($this->targets() as $target) {
            try {
                $result = $this->executeTarget('verify', $target);
                $targetOk = !empty($result['ok']) && !empty($result['verification']['ok']);
                $ok = $ok && $targetOk;
                $results[] = $result;
            } catch (Throwable $exception) {
                $ok = false;
                $results[] = [
                    'target' => $this->publicTarget($target),
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [
            'ok' => $ok,
            'target_count' => count($results),
            'targets' => $results,
        ];
    }

    public function verifyAll(): array
    {
        $results = [];
        $ok = true;
        foreach ($this->targets() as $target) {
            try {
                $conn = $this->connect($target);
                try {
                    if ($target['kind'] === 'router') {
                        $missing = $this->missingRouterTables($conn);
                        $upgrades = (new PosmainShopRouter())->upgradeStatements($conn);
                        $targetOk = $missing === [] && $upgrades === [];
                        $result = [
                            'target' => $this->publicTarget($target),
                            'ok' => $targetOk,
                            'router_missing_tables' => $missing,
                            'router_upgrade_labels' => array_keys($upgrades),
                        ];
                    } else {
                        $stepwisePlan = (new Stepwise($conn, $this->projectRoot . '/update', [
                            'ledger_table' => 'stepwise_ledger',
                        ]))->plan(false);
                        $schemaPending = (new PosmainSchemaMigrationRunner())->pending($conn);
                        $targetOk = $stepwisePlan['pending'] === []
                            && $stepwisePlan['drift'] === []
                            && $schemaPending === [];
                        $result = [
                            'target' => $this->publicTarget($target),
                            'ok' => $targetOk,
                            'stepwise_pending' => array_map(
                                static fn(array $step): string => (string) $step['step_key'],
                                $stepwisePlan['pending']
                            ),
                            'stepwise_drift' => $stepwisePlan['drift'],
                            'schema_pending' => array_keys($schemaPending),
                        ];
                    }
                    $results[] = $result;
                    $ok = $ok && $targetOk;
                } finally {
                    $conn->close();
                }
            } catch (Throwable $exception) {
                $ok = false;
                $results[] = [
                    'target' => $this->publicTarget($target),
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return ['ok' => $ok, 'target_count' => count($results), 'targets' => $results];
    }

    public function restoreAll(array $backupSet): array
    {
        $artifacts = $this->artifactsByTarget($backupSet);
        $results = [];
        $ok = true;
        foreach (array_reverse($this->targets()) as $target) {
            $artifact = $artifacts[$target['key']] ?? null;
            if (!is_array($artifact)) {
                $ok = false;
                $results[] = [
                    'target' => $this->publicTarget($target),
                    'ok' => false,
                    'error' => 'UPDATE_BACKUP_ARTIFACT_MISSING',
                ];
                continue;
            }
            try {
                $restored = $this->manager($target)->restore((string) $artifact['file']);
                $results[] = [
                    'target' => $this->publicTarget($target),
                    'ok' => true,
                    'verification' => $restored['verification'] ?? null,
                ];
            } catch (Throwable $exception) {
                $ok = false;
                $results[] = [
                    'target' => $this->publicTarget($target),
                    'ok' => false,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return ['ok' => $ok, 'targets' => $results];
    }

    public function deleteBackupSet(array $backupSet): bool
    {
        $configuredDirectory = rtrim((string) ($backupSet['directory'] ?? ''), '/\\');
        $configuredRoot = rtrim($this->projectRoot . '/backup/updates', '/\\');
        if (
            $configuredDirectory === ''
            || dirname($configuredDirectory) !== $configuredRoot
            || preg_match('/^upd_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', basename($configuredDirectory)) !== 1
        ) {
            return false;
        }
        if (!file_exists($configuredDirectory)) {
            return true;
        }

        $directory = realpath($configuredDirectory);
        $root = realpath($configuredRoot);
        if ($directory === false || $root === false || strpos($directory, $root . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }

        return $this->removeDirectoryFiles($directory);
    }

    private function discoverTargets(): array
    {
        $targets = [];
        if (!empty($this->config['router']['enabled'])) {
            $routerDatabase = (array) ($this->config['router']['database'] ?? []);
            $targets[] = ['kind' => 'router', 'label' => 'router', 'database' => $routerDatabase];
            $router = new PosmainShopRouter();
            $routerConn = PosmainShopRouter::connectDatabase($routerDatabase);
            try {
                foreach ($router->listActiveShops($routerConn) as $shop) {
                    $full = $router->findShopById($routerConn, (int) $shop['id']);
                    if (!$full) {
                        throw new RuntimeException('UPDATE_ROUTER_SHOP_DISAPPEARED:' . (int) $shop['id']);
                    }
                    $targets[] = [
                        'kind' => 'shop',
                        'label' => 'shop:' . (string) ($shop['slug'] ?? $shop['id']),
                        'database' => $router->databaseConfigFromShop($full),
                    ];
                }
            } finally {
                $routerConn->close();
            }
        }

        $defaultDatabase = (array) ($this->config['database'] ?? []);
        if (trim((string) ($defaultDatabase['name'] ?? '')) !== '') {
            $targets[] = ['kind' => 'default', 'label' => 'default', 'database' => $defaultDatabase];
        }

        return $targets;
    }

    private function artifactsByTarget(array $backupSet): array
    {
        $artifacts = [];
        foreach ($backupSet['artifacts'] ?? [] as $artifact) {
            $key = (string) ($artifact['target']['key'] ?? '');
            if ($key !== '') {
                $artifacts[$key] = $artifact;
            }
        }
        return $artifacts;
    }

    private function manager(array $target)
    {
        if (is_callable($this->backupManagerFactory)) {
            return call_user_func($this->backupManagerFactory, $target['database'], $target);
        }
        return new PosmainDatabaseBackupManager($target['database']);
    }

    private function executeTarget(string $mode, array $target, string $backupFile = ''): array
    {
        if (is_callable($this->targetExecutor)) {
            $result = call_user_func(
                $this->targetExecutor,
                $mode,
                $target,
                $backupFile,
                $this->projectRoot
            );
            if (!is_array($result)) {
                throw new RuntimeException('UPDATE_TARGET_EXECUTOR_INVALID_RESULT');
            }
            return $result;
        }

        $script = $this->projectRoot . '/cli/update_database_target.php';
        if (!is_file($script)) {
            throw new RuntimeException('UPDATE_TARGET_EXECUTOR_MISSING');
        }
        $php = trim((string) (getenv('POSMAIN_UPDATE_PHP_BIN') ?: ''));
        if ($php === '') {
            $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? (string) PHP_BINARY : 'php';
        }
        $command = [
            $php,
            $script,
            '--mode=' . $mode,
            '--kind=' . $target['kind'],
            '--steps=' . $this->projectRoot . '/update',
        ];
        if ($backupFile !== '') {
            $command[] = '--backup-file=' . $backupFile;
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('UPDATE_TARGET_EXECUTOR_START_FAILED');
        }
        $payload = json_encode(['database' => $target['database']], JSON_UNESCAPED_SLASHES);
        fwrite($pipes[0], is_string($payload) ? $payload : '');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException(
                'UPDATE_TARGET_EXECUTOR_FAILED:' . substr(trim((string) ($stderr ?: $stdout)), 0, 4000)
            );
        }
        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('UPDATE_TARGET_EXECUTOR_INVALID_JSON');
        }
        $decoded['target'] = $this->publicTarget($target);

        return $decoded;
    }

    private function connect(array $target): mysqli
    {
        return PosmainShopRouter::connectDatabase($target['database']);
    }

    private function publicTarget(array $target): array
    {
        return [
            'key' => $target['key'],
            'kind' => $target['kind'],
            'label' => $target['label'],
            'database' => $target['database']['name'],
        ];
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
                throw new RuntimeException('UPDATE_DATABASE_TARGET_MISSING:' . $required);
            }
        }
        return $normalized;
    }

    private function missingRouterTables(mysqli $conn): array
    {
        $missing = [];
        foreach (['app_sessions', 'security_audit_log', 'failed_login_attempts', 'router_shops', 'router_login_aliases', 'router_branch_routes'] as $table) {
            $escaped = $conn->real_escape_string($table);
            if ($conn->query("SHOW TABLES LIKE '{$escaped}'")->num_rows === 0) {
                $missing[] = $table;
            }
        }
        return $missing;
    }

    private function removeDirectoryFiles(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) || !@unlink($path)) {
                return false;
            }
        }
        return @rmdir($directory);
    }
}
