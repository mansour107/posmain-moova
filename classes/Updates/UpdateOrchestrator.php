<?php

require_once __DIR__ . '/UpdateJobStore.php';
require_once __DIR__ . '/UpdateMaintenance.php';
require_once __DIR__ . '/../Stepwise.php';
require_once __DIR__ . '/../../includes/pos_update_git.php';

class PosmainUpdateOrchestrator
{
    private PosmainUpdateJobStore $store;
    private PosmainUpdateMaintenance $maintenance;
    private string $projectRoot;

    public function __construct(?PosmainUpdateJobStore $store = null, ?PosmainUpdateMaintenance $maintenance = null, ?string $projectRoot = null)
    {
        $this->store = $store ?: new PosmainUpdateJobStore();
        $this->maintenance = $maintenance ?: new PosmainUpdateMaintenance();
        $this->projectRoot = rtrim($projectRoot ?: dirname(__DIR__, 2), '/\\');
    }

    public function run(string $jobId): array
    {
        $job = $this->store->find($jobId);
        if ($job === null) {
            throw new InvalidArgumentException('INVALID_UPDATE_JOB_ID');
        }

        $this->store->mutate($jobId, static function (array $current): array {
            $current['status'] = 'running';
            $current['phase'] = 'running';

            return $current;
        });

        try {
            $job = $this->runAction($jobId, (string) ($job['action'] ?? 'apply'));
            $this->store->mutate($jobId, static function (array $current): array {
                $current['status'] = 'completed';
                $current['phase'] = 'completed';

                return $current;
            });

            return $this->store->find($jobId) ?: $job;
        } catch (Throwable $exception) {
            $this->failJob($jobId, $exception);
            throw $exception;
        }
    }

    private function runAction(string $jobId, string $action): array
    {
        $job = $this->runStep($jobId, 'worker_dispatch', function (array $job): array {
            $job['worker_pid'] = getmypid();

            return $job;
        });

        $job = $this->runVersionCheck($jobId);

        if ($action === 'check') {
            return $job;
        }

        if ($action === 'plan') {
            $job = $this->runFileMigrationsPlan($jobId);

            return $job;
        }

        return $this->runApply($jobId, $job);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function runApply(string $jobId, array $job): array
    {
        $targetVersion = (string) ($job['target_version'] ?: ($job['published_version'] ?? ''));
        if ($targetVersion === '') {
            throw new RuntimeException('UPDATE_TARGET_VERSION_MISSING');
        }

        $comparison = posmainCompareVersions(
            (string) ($job['installed_version'] ?? ''),
            $targetVersion
        );
        $gitSync = is_array($job['git_sync'] ?? null)
            ? $job['git_sync']
            : posmainUpdateGitSyncState($this->projectRoot);
        if ($comparison <= 0 && empty($gitSync['git_behind'])) {
            throw new RuntimeException('UPDATE_NOT_REQUIRED');
        }

        $backupFile = '';

        try {
            $job = $this->runStep($jobId, 'maintenance_on', function (array $job): array {
                $this->maintenance->enable([
                    'job_id' => $job['id'],
                    'message' => 'System update in progress.',
                ]);

                return $job;
            });

            $job = $this->runStep($jobId, 'drain_requests', function (array $job): array {
                sleep(max(1, (int) getenv('POSMAIN_UPDATE_DRAIN_SECONDS') ?: 5));

                return $job;
            });

            $job = $this->runStep($jobId, 'backup', function (array $job) use ($jobId): array {
                $backupFile = $this->createBackup($jobId);
                $job['backup_file'] = $backupFile;

                return $job;
            });
            $backupFile = (string) ($job['backup_file'] ?? '');

            $job = $this->runStep($jobId, 'code_pull', function (array $job): array {
                $job['code_commit_before'] = $this->currentGitCommit();
                $job['code_pull'] = $this->gitPull();

                return $job;
            });

            $job = $this->runFileMigrationsPlan($jobId);

            $job = $this->runStep($jobId, 'file_migrations', function (array $job) use ($backupFile): array {
                $conn = posmain_db_connect();
                try {
                    $runner = new Stepwise($conn, $this->projectRoot . '/update', [
                        'ledger_table' => 'stepwise_ledger',
                    ]);
                    $result = $runner->apply('update_worker', true);
                    $job['file_migrations'] = $result;
                } finally {
                    $conn->close();
                }

                if ($backupFile === '') {
                    $job['warnings'][] = 'Backup path missing while applying file migrations.';
                }

                return $job;
            });

            $job = $this->runStep($jobId, 'runtime_restart', function (array $job): array {
                $job['runtime_restart'] = $this->restartRuntime();

                return $job;
            });

            $job = $this->runStep($jobId, 'health_check', function (array $job): array {
                $job['health_check'] = $this->healthCheck();

                if (empty($job['health_check']['ok'])) {
                    throw new RuntimeException('HEALTH_CHECK_FAILED');
                }

                return $job;
            });

            $job = $this->runStep($jobId, 'write_version', function (array $job) use ($targetVersion): array {
                $this->writeInstalledVersion($targetVersion);
                $job['installed_version'] = $targetVersion;

                return $job;
            });

            $job = $this->runStep($jobId, 'maintenance_off', function (array $job): array {
                $this->maintenance->disable();

                return $job;
            });

            return $this->runStep($jobId, 'backup_cleanup', function (array $job): array {
                if (!$this->shouldDeleteBackupOnSuccess()) {
                    $job['backup_deleted'] = false;

                    return $job;
                }

                $backupPath = (string) ($job['backup_file'] ?? '');
                $deleted = $this->deleteUpdateBackupFile($backupPath);
                $job['backup_deleted'] = $deleted;
                if ($deleted) {
                    $job['backup_file'] = null;
                } elseif ($backupPath !== '') {
                    $job['warnings'][] = 'Failed to delete update backup file: ' . $backupPath;
                }

                $this->pruneCompletedUpdateBackups();

                return $job;
            });
        } catch (Throwable $exception) {
            $this->attemptRollback($jobId, $backupFile, (string) ($job['code_commit_before'] ?? ''));
            $this->maintenance->disable();
            throw $exception;
        }
    }

    private function runVersionCheck(string $jobId): array
    {
        return $this->runStep($jobId, 'version_check', function (array $job): array {
            $installed = posmainInstalledVersion($this->projectRoot);
            $published = posmainFetchPublishedVersion();
            $gitSync = posmainUpdateGitSyncState($this->projectRoot);
            $gitPublished = is_array($gitSync['remote_version'] ?? null) ? $gitSync['remote_version'] : null;
            if ($gitPublished !== null) {
                if ($published === null || posmainCompareVersions((string) ($published['version'] ?? ''), (string) $gitPublished['version']) < 0) {
                    $published = $gitPublished;
                }
            }

            if ($installed === null) {
                throw new RuntimeException('INSTALLED_VERSION_UNAVAILABLE');
            }
            if ($published === null && empty($gitSync['git_behind'])) {
                throw new RuntimeException('PUBLISHED_VERSION_UNAVAILABLE');
            }

            $target = (string) ($job['target_version'] ?: ($published['version'] ?? $installed));
            $job['installed_version'] = $installed;
            $job['published_version'] = (string) ($published['version'] ?? $installed);
            $job['target_version'] = $target;
            $versionUpdateAvailable = $published !== null && posmainCompareVersions($installed, $target) > 0;
            $job['update_available'] = $versionUpdateAvailable || !empty($gitSync['git_behind']);
            $job['git_sync'] = $gitSync;
            $job['version_check'] = [
                'installed_version' => $installed,
                'published_version' => $job['published_version'],
                'target_version' => $target,
                'update_available' => $job['update_available'],
                'published' => $published,
                'git_sync' => $gitSync,
            ];

            if (($job['action'] ?? '') === 'apply' && !$job['update_available']) {
                throw new RuntimeException('UPDATE_NOT_REQUIRED');
            }

            return $job;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function runFileMigrationsPlan(string $jobId): array
    {
        return $this->runStep($jobId, 'file_migrations_plan', function (array $job): array {
            $conn = posmain_db_connect();
            try {
                $runner = new Stepwise($conn, $this->projectRoot . '/update', [
                    'ledger_table' => 'stepwise_ledger',
                ]);
                $plan = $runner->plan();
                $job['file_migrations_plan'] = [
                    'pending_count' => count($plan['pending']),
                    'pending' => array_map(static function (array $step): array {
                        return [
                            'step_key' => $step['step_key'],
                            'source_file' => $step['source_file'],
                        ];
                    }, $plan['pending']),
                    'drift' => $plan['drift'],
                ];
            } finally {
                $conn->close();
            }

            return $job;
        });
    }

    /**
     * @param callable(array<string, mixed>):array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function runStep(string $jobId, string $stepName, callable $callback): array
    {
        $this->store->mutate($jobId, function (array $job) use ($stepName): array {
            $job['phase'] = $stepName;
            $job['steps'] = $this->markStep($job['steps'] ?? [], $stepName, 'running');

            return $job;
        });

        try {
            $job = $this->store->find($jobId);
            if ($job === null) {
                throw new RuntimeException('UPDATE_JOB_NOT_FOUND');
            }

            $job = $callback($job);

            return $this->store->mutate($jobId, function (array $current) use ($stepName, $job): array {
                foreach (['installed_version', 'published_version', 'target_version', 'backup_file', 'backup_deleted', 'code_commit_before', 'update_available', 'version_check', 'file_migrations_plan', 'file_migrations', 'code_pull', 'runtime_restart', 'health_check', 'worker_pid', 'warnings'] as $field) {
                    if (array_key_exists($field, $job)) {
                        $current[$field] = $job[$field];
                    }
                }

                $current['phase'] = $stepName;
                $current['steps'] = $this->markStep($current['steps'] ?? [], $stepName, 'completed');

                return $current;
            });
        } catch (Throwable $exception) {
            $this->store->mutate($jobId, function (array $job) use ($stepName, $exception): array {
                $job['phase'] = $stepName;
                $job['steps'] = $this->markStep($job['steps'] ?? [], $stepName, 'failed', $exception->getMessage());
                $job['errors'][] = [
                    'step' => $stepName,
                    'message' => $exception->getMessage(),
                    'at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                ];

                return $job;
            });

            throw $exception;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     * @return array<int, array<string, mixed>>
     */
    private function markStep(array $steps, string $name, string $status, ?string $message = null): array
    {
        $found = false;
        foreach ($steps as $index => $step) {
            if (($step['name'] ?? '') !== $name) {
                continue;
            }

            $found = true;
            $steps[$index]['status'] = $status;
            if ($status === 'completed' || $status === 'failed' || $status === 'running') {
                $steps[$index]['at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
            }
            if ($message !== null) {
                $steps[$index]['message'] = $message;
            }
        }

        if (!$found) {
            $steps[] = [
                'name' => $name,
                'status' => $status,
                'at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'message' => $message,
            ];
        }

        return $steps;
    }

    private function createBackup(string $jobId): string
    {
        $backupDir = $this->projectRoot . '/backup/updates';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
            throw new RuntimeException('BACKUP_DIRECTORY_UNAVAILABLE');
        }

        $output = $backupDir . '/pre-update-' . $jobId . '.sql';
        $php = $this->phpBinary();
        $script = $this->projectRoot . '/tools/backup_database.php';
        $command = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --output=' . escapeshellarg($output);
        $result = $this->runCommand($command, $this->projectRoot);

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('BACKUP_FAILED: ' . trim($result['stderr'] ?: $result['stdout']));
        }
        if (!is_file($output) || filesize($output) <= 0) {
            throw new RuntimeException('BACKUP_FILE_INVALID');
        }

        return $output;
    }

    private function shouldDeleteBackupOnSuccess(): bool
    {
        if (function_exists('posmain_bool')) {
            return !posmain_bool(getenv('POSMAIN_UPDATE_KEEP_BACKUP') ?: '0', false);
        }

        $value = strtolower(trim((string) (getenv('POSMAIN_UPDATE_KEEP_BACKUP') ?: '0')));

        return !in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function deleteUpdateBackupFile(string $backupFile): bool
    {
        $backupFile = trim($backupFile);
        if ($backupFile === '') {
            return false;
        }

        $backupDir = realpath($this->projectRoot . '/backup/updates');
        if ($backupDir === false) {
            return false;
        }

        $candidate = realpath($backupFile);
        if ($candidate === false || !is_file($candidate)) {
            return false;
        }

        if (strpos($candidate, $backupDir . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }

        if (preg_match('/^pre-update-upd_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}\.sql$/', basename($candidate)) !== 1) {
            return false;
        }

        return @unlink($candidate);
    }

    private function pruneCompletedUpdateBackups(): void
    {
        $backupDir = realpath($this->projectRoot . '/backup/updates');
        if ($backupDir === false) {
            return;
        }

        foreach (glob($backupDir . '/pre-update-upd_*.sql') ?: [] as $path) {
            if (!preg_match('/^pre-update-(upd_[0-9]{8}_[0-9]{6}_[a-f0-9]{6})\.sql$/', basename($path), $matches)) {
                continue;
            }

            $job = $this->store->find($matches[1]);
            if ($job === null || (string) ($job['status'] ?? '') !== 'completed') {
                continue;
            }

            $this->deleteUpdateBackupFile($path);
        }
    }

    private function gitPull(): array
    {
        $branch = trim((string) (getenv('POSMAIN_UPDATE_GIT_BRANCH') ?: 'main'));
        $remote = trim((string) (getenv('POSMAIN_UPDATE_GIT_REMOTE') ?: 'origin'));
        $command = 'git pull ' . escapeshellarg($remote) . ' ' . escapeshellarg($branch);
        $result = $this->runCommand($command, $this->projectRoot);

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('GIT_PULL_FAILED: ' . trim($result['stderr'] ?: $result['stdout']));
        }

        return [
            'branch' => $branch,
            'remote' => $remote,
            'stdout' => trim($result['stdout']),
        ];
    }

    private function currentGitCommit(): ?string
    {
        $result = $this->runCommand('git rev-parse HEAD', $this->projectRoot);
        if ($result['exit_code'] !== 0) {
            return null;
        }

        $commit = trim($result['stdout']);

        return $commit !== '' ? $commit : null;
    }

    private function restartRuntime(): array
    {
        $command = trim((string) (getenv('POSMAIN_UPDATE_PHP_FPM_RELOAD_CMD') ?: ''));
        if ($command === '') {
            return [
                'skipped' => true,
                'message' => 'POSMAIN_UPDATE_PHP_FPM_RELOAD_CMD is not configured.',
            ];
        }

        $result = $this->runCommand($command, $this->projectRoot);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('RUNTIME_RESTART_FAILED: ' . trim($result['stderr'] ?: $result['stdout']));
        }

        return [
            'skipped' => false,
            'command' => $command,
            'stdout' => trim($result['stdout']),
        ];
    }

    /**
     * @return array{ok:bool,healthy?:bool,http_code?:int,body?:string}
     */
    private function healthCheck(): array
    {
        $baseUrl = rtrim((string) (posmain_app_config()['public_base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            $php = $this->phpBinary();
            $script = $this->projectRoot . '/api/health.php';
            $command = 'QUERY_STRING=' . escapeshellarg('scope=update')
                . ' ' . escapeshellarg($php) . ' ' . escapeshellarg($script);
            $result = $this->runCommand($command, $this->projectRoot);
            $decoded = json_decode($result['stdout'], true);

            return [
                'ok' => is_array($decoded) && !empty($decoded['healthy']),
                'mode' => 'cli',
                'scope' => 'update',
                'body' => $result['stdout'],
            ];
        }

        $url = $baseUrl . '/api/health.php?scope=update';
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $decoded = is_string($body) ? json_decode($body, true) : null;

        return [
            'ok' => is_array($decoded) && !empty($decoded['healthy']),
            'mode' => 'http',
            'scope' => 'update',
            'url' => $url,
            'body' => is_string($body) ? $body : '',
        ];
    }

    private function writeInstalledVersion(string $version): void
    {
        $path = $this->projectRoot . '/version.txt';
        if (file_put_contents($path, $version . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('VERSION_WRITE_FAILED');
        }
    }

    private function attemptRollback(string $jobId, string $backupFile, string $commitBefore): void
    {
        if ($commitBefore !== '') {
            $result = $this->runCommand('git reset --hard ' . escapeshellarg($commitBefore), $this->projectRoot);
            if ($result['exit_code'] !== 0) {
                $this->store->mutate($jobId, function (array $job) use ($result): array {
                    $job['warnings'][] = 'Code rollback failed: ' . trim($result['stderr'] ?: $result['stdout']);

                    return $job;
                });
            }
        }

        if ($backupFile !== '' && posmain_bool(getenv('POSMAIN_UPDATE_AUTO_RESTORE') ?: '0', false)) {
            $this->store->mutate($jobId, function (array $job): array {
                $job['warnings'][] = 'Automatic database restore is not implemented yet; use backup file manually.';

                return $job;
            });
        }
    }

    private function failJob(string $jobId, Throwable $exception): void
    {
        $this->store->mutate($jobId, function (array $job) use ($exception): array {
            $job['status'] = 'failed';
            $job['phase'] = 'failed';
            $job['errors'][] = [
                'step' => 'orchestrator',
                'message' => $exception->getMessage(),
                'at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            ];

            return $job;
        });
    }

    /**
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function runCommand(string $command, string $cwd): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new RuntimeException('COMMAND_START_FAILED');
        }

        fclose($pipes[0]);
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

    private function phpBinary(): string
    {
        if (function_exists('posmainUpdatePhpBinary')) {
            return posmainUpdatePhpBinary();
        }

        $configured = trim((string) (getenv('POSMAIN_UPDATE_PHP_BIN') ?: ''));
        if ($configured !== '') {
            return $configured;
        }

        if (defined('PHP_BINARY') && PHP_BINARY !== '' && stripos((string) PHP_BINARY, 'fpm') === false) {
            return (string) PHP_BINARY;
        }

        return 'php';
    }
}
