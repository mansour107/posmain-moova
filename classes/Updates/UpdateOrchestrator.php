<?php

require_once __DIR__ . '/UpdateJobStore.php';
require_once __DIR__ . '/UpdateMaintenance.php';
require_once __DIR__ . '/UpdateDatabaseCoordinator.php';
require_once __DIR__ . '/../../includes/pos_update_git.php';

class PosmainUpdateOrchestrator
{
    private PosmainUpdateJobStore $store;
    private PosmainUpdateMaintenance $maintenance;
    private string $projectRoot;
    private $databases;
    private array $hooks;

    public function __construct(
        ?PosmainUpdateJobStore $store = null,
        ?PosmainUpdateMaintenance $maintenance = null,
        ?string $projectRoot = null,
        $databases = null,
        array $hooks = []
    ) {
        $this->store = $store ?: new PosmainUpdateJobStore();
        $this->maintenance = $maintenance ?: new PosmainUpdateMaintenance();
        $this->projectRoot = rtrim($projectRoot ?: dirname(__DIR__, 2), '/\\');
        $this->databases = $databases ?: new PosmainUpdateDatabaseCoordinator($this->projectRoot);
        $this->hooks = $hooks;
    }

    public function run(string $jobId): array
    {
        if ($this->store->find($jobId) === null) {
            throw new InvalidArgumentException('INVALID_UPDATE_JOB_ID');
        }

        $this->store->claim($jobId, getmypid());

        try {
            $job = $this->runAction($jobId, (string) (($this->store->find($jobId)['action'] ?? 'apply')));
            $job = $this->store->mutate($jobId, function (array $current): array {
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $current['status'] = 'completed';
                $current['phase'] = 'completed';
                $current['finished_at_utc'] = $now;
                $current['heartbeat_at_utc'] = $now;

                return $current;
            });

            return $job;
        } catch (Throwable $exception) {
            $this->failJob($jobId, $exception);
            throw $exception;
        }
    }

    public function recover(string $jobId): array
    {
        $staleJob = $this->store->find($jobId);
        if ($staleJob === null) {
            throw new InvalidArgumentException('INVALID_UPDATE_JOB_ID');
        }
        $this->store->claimRecovery($jobId, getmypid());
        try {
            $recoveryCause = new RuntimeException('UPDATE_WORKER_STALE');
            if ($this->canFinalizeSuccessfulStaleUpdate($staleJob)) {
                try {
                    return $this->finalizeSuccessfulStaleUpdate($jobId);
                } catch (Throwable $finalizationError) {
                    $recoveryCause = $finalizationError;
                }
            }
            $this->recoverFailedUpdate($jobId, $recoveryCause);
        } catch (Throwable $exception) {
            $this->store->mutate($jobId, static function (array $job) use ($exception): array {
                $job['recovery_status'] = 'recovery_failed';
                $job['errors'][] = [
                    'step' => 'stale_recovery',
                    'message' => $exception->getMessage(),
                    'at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                ];

                return $job;
            });
        }

        return $this->store->mutate($jobId, static function (array $job): array {
            $job['status'] = 'failed';
            $job['phase'] = 'failed';
            $job['finished_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');

            return $job;
        });
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
            return $this->runDatabasePlan($jobId);
        }

        return $this->runApply($jobId, $job);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function runApply(string $jobId, array $job): array
    {
        $targetVersion = trim((string) ($job['target_version'] ?? ''));
        if ($targetVersion === '') {
            throw new RuntimeException('UPDATE_TARGET_VERSION_MISSING');
        }

        $job = $this->runStep($jobId, 'preflight', function (array $job) use ($targetVersion): array {
            $git = posmainUpdateGitPreflight($this->projectRoot, $targetVersion);
            $job['git_sync'] = $git;
            $job['code_commit_before'] = (string) ($git['local_commit'] ?? '');
            $job['code_commit_target'] = (string) ($git['remote_commit'] ?? '');
            $job['database_targets'] = $this->databases->publicTargets();
            $job['database_preflight'] = $this->databases->preflight();

            if (
                preg_match('/^[a-f0-9]{40}$/', $job['code_commit_before']) !== 1
                || preg_match('/^[a-f0-9]{40}$/', $job['code_commit_target']) !== 1
            ) {
                throw new RuntimeException('UPDATE_GIT_COMMIT_PREFLIGHT_INVALID');
            }

            return $job;
        });
        $job = $this->runDatabasePlan($jobId);

        try {
            $job = $this->runStep($jobId, 'maintenance_on', function (array $job): array {
                $this->maintenance->enable([
                    'job_id' => $job['id'],
                    'message' => 'System update in progress.',
                ]);
                $job['maintenance_enabled'] = true;

                return $job;
            });

            $job = $this->runStep($jobId, 'drain_requests', function (array $job): array {
                $seconds = max(0, (int) (getenv('POSMAIN_UPDATE_DRAIN_SECONDS') ?: 5));
                if ($seconds > 0) {
                    sleep($seconds);
                }
                $job['drain_seconds'] = $seconds;

                return $job;
            });

            $job = $this->runStep($jobId, 'backup', function (array $job) use ($jobId): array {
                $job['backup_set'] = $this->databases->backupAll($jobId);
                $job['backup_deleted'] = false;

                return $job;
            });

            $job = $this->runStep($jobId, 'code_activation', function (array $job): array {
                $targetCommit = (string) ($job['code_commit_target'] ?? '');
                $job['code_activation'] = posmainUpdateGitFastForward(
                    $this->projectRoot,
                    $targetCommit,
                    (string) ($job['code_commit_before'] ?? '')
                );

                return $job;
            });

            $job = $this->runStep($jobId, 'database_migrations', function (array $job): array {
                $backupSet = is_array($job['backup_set'] ?? null) ? $job['backup_set'] : [];
                if ($backupSet === []) {
                    throw new RuntimeException('UPDATE_BACKUP_SET_MISSING');
                }
                $job['database_migrations'] = $this->databases->applyAll($backupSet);

                return $job;
            });

            $job = $this->runStep($jobId, 'runtime_restart', function (array $job): array {
                $job['runtime_restart'] = $this->restartRuntime();

                return $job;
            });

            $job = $this->runStep($jobId, 'database_verification', function (array $job): array {
                $job['database_verification'] = $this->databases->verifyAllFresh();
                if (empty($job['database_verification']['ok'])) {
                    throw new RuntimeException('UPDATE_DATABASE_VERIFICATION_FAILED');
                }

                return $job;
            });

            $job = $this->runStep($jobId, 'release_verification', function (array $job) use ($targetVersion): array {
                $job['release_verification'] = $this->verifyRelease(
                    $targetVersion,
                    (string) ($job['code_commit_target'] ?? '')
                );

                return $job;
            });

            $job = $this->runStep($jobId, 'health_check', function (array $job): array {
                $job['health_check'] = $this->healthCheck();
                if (empty($job['health_check']['ok'])) {
                    throw new RuntimeException('HEALTH_CHECK_FAILED');
                }

                return $job;
            });

            $job = $this->runStep($jobId, 'maintenance_off', function (array $job): array {
                $this->maintenance->disable();
                $job['maintenance_enabled'] = false;

                return $job;
            });

            return $this->runStep($jobId, 'backup_cleanup', function (array $job): array {
                if (!$this->shouldDeleteBackupOnSuccess()) {
                    $job['backup_deleted'] = false;
                    $job['warnings'][] = 'Update backup retained by POSMAIN_UPDATE_KEEP_BACKUP.';

                    return $job;
                }

                $backupSet = is_array($job['backup_set'] ?? null) ? $job['backup_set'] : [];
                $deleted = $backupSet !== [] && $this->databases->deleteBackupSet($backupSet);
                $job['backup_deleted'] = $deleted;
                if ($deleted) {
                    $job['backup_set'] = null;
                } else {
                    $job['warnings'][] = 'The update completed, but its backup set could not be deleted.';
                }

                return $job;
            });
        } catch (Throwable $exception) {
            $this->recoverFailedUpdate($jobId, $exception);
            throw $exception;
        }
    }

    private function runVersionCheck(string $jobId): array
    {
        return $this->runStep($jobId, 'version_check', function (array $job): array {
            $installed = $this->installedVersion();
            $gitSync = posmainUpdateGitSyncState($this->projectRoot);
            if ($installed === null) {
                throw new RuntimeException('INSTALLED_VERSION_UNAVAILABLE');
            }
            if (empty($gitSync['ok'])) {
                throw new RuntimeException('UPDATE_GIT_UNAVAILABLE:' . (string) ($gitSync['error'] ?? 'unknown'));
            }

            $published = is_array($gitSync['remote_version'] ?? null) ? $gitSync['remote_version'] : null;
            $publishedVersion = trim((string) ($published['version'] ?? ''));
            if ($published === null || $publishedVersion === '') {
                throw new RuntimeException('PUBLISHED_VERSION_UNAVAILABLE');
            }

            $requestedTarget = trim((string) ($job['target_version'] ?? ''));
            if ($requestedTarget !== '' && !hash_equals($publishedVersion, $requestedTarget)) {
                throw new RuntimeException('UPDATE_TARGET_VERSION_MISMATCH');
            }

            $job['installed_version'] = $installed;
            $job['published_version'] = $publishedVersion;
            $job['target_version'] = $publishedVersion;
            $job['update_available'] = ($gitSync['state'] ?? '') === 'behind';
            $job['git_sync'] = $gitSync;
            $job['version_check'] = [
                'installed_version' => $installed,
                'published_version' => $publishedVersion,
                'target_version' => $publishedVersion,
                'update_available' => $job['update_available'],
                'published' => $published,
                'git_sync' => $gitSync,
            ];

            if (($job['action'] ?? '') === 'apply' && !$job['update_available']) {
                throw new RuntimeException('UPDATE_NOT_REQUIRED:' . (string) ($gitSync['state'] ?? 'unknown'));
            }

            return $job;
        });
    }

    private function runDatabasePlan(string $jobId): array
    {
        return $this->runStep($jobId, 'database_migrations_plan', function (array $job): array {
            $job['database_targets'] = $this->databases->publicTargets();
            $job['database_migrations_plan'] = $this->databases->plan();

            return $job;
        });
    }

    /**
     * @param callable(array<string, mixed>):array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function runStep(string $jobId, string $stepName, callable $callback): array
    {
        $this->store->heartbeat($jobId);
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
                foreach ($this->persistedFields() as $field) {
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

    private function persistedFields(): array
    {
        return [
            'installed_version',
            'published_version',
            'target_version',
            'update_available',
            'version_check',
            'git_sync',
            'code_commit_before',
            'code_commit_target',
            'code_activation',
            'database_targets',
            'database_preflight',
            'database_migrations_plan',
            'database_migrations',
            'database_verification',
            'backup_set',
            'backup_deleted',
            'runtime_restart',
            'release_verification',
            'health_check',
            'maintenance_enabled',
            'drain_seconds',
            'rollback',
            'recovery_status',
            'worker_pid',
            'warnings',
        ];
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
            $steps[$index]['at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
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

    private function recoverFailedUpdate(string $jobId, Throwable $cause): void
    {
        $job = $this->store->find($jobId) ?: [];
        $backupSet = is_array($job['backup_set'] ?? null) ? $job['backup_set'] : [];
        $commitBefore = trim((string) ($job['code_commit_before'] ?? ''));
        $rollback = [
            'attempted' => $backupSet !== [],
            'cause' => $cause->getMessage(),
            'started_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'database_restore' => null,
            'code_restore' => null,
            'runtime_restart' => null,
            'health_check' => null,
            'maintenance_disabled' => false,
        ];

        if ($backupSet !== [] && !$this->maintenance->isEnabled()) {
            try {
                $this->maintenance->enable([
                    'job_id' => $jobId,
                    'message' => 'Automatic update recovery in progress.',
                ]);
                $rollback['maintenance_enabled_for_recovery'] = true;
            } catch (Throwable $maintenanceError) {
                $rollback['maintenance_error'] = $maintenanceError->getMessage();
                $rollback['ok'] = false;
                $rollback['finished_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
                $this->recordRecovery($jobId, $rollback, 'recovery_failed');
                return;
            }
        }

        if ($backupSet === []) {
            try {
                $this->maintenance->disable();
                $rollback['maintenance_disabled'] = true;
                $status = 'failed_before_backup';
            } catch (Throwable $maintenanceError) {
                $rollback['maintenance_error'] = $maintenanceError->getMessage();
                $status = 'recovery_failed';
            }
            $rollback['ok'] = $status !== 'recovery_failed';
            $rollback['finished_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
            $this->recordRecovery($jobId, $rollback, $status);

            return;
        }

        try {
            $rollback['database_restore'] = $this->databases->restoreAll($backupSet);
        } catch (Throwable $restoreError) {
            $rollback['database_restore'] = ['ok' => false, 'error' => $restoreError->getMessage()];
        }

        try {
            $rollback['code_restore'] = $this->restoreCode($commitBefore);
        } catch (Throwable $codeError) {
            $rollback['code_restore'] = ['ok' => false, 'error' => $codeError->getMessage()];
        }

        try {
            $rollback['runtime_restart'] = $this->restartRuntime();
        } catch (Throwable $runtimeError) {
            $rollback['runtime_restart'] = ['ok' => false, 'error' => $runtimeError->getMessage()];
        }

        try {
            $rollback['health_check'] = $this->healthCheck();
        } catch (Throwable $healthError) {
            $rollback['health_check'] = ['ok' => false, 'error' => $healthError->getMessage()];
        }

        $recovered = !empty($rollback['database_restore']['ok'])
            && !empty($rollback['code_restore']['ok'])
            && !empty($rollback['health_check']['ok'])
            && !empty($rollback['runtime_restart']['ok']);
        if ($recovered) {
            try {
                $this->maintenance->disable();
                $rollback['maintenance_disabled'] = true;
            } catch (Throwable $maintenanceError) {
                $rollback['maintenance_error'] = $maintenanceError->getMessage();
                $recovered = false;
            }
        }

        $rollback['ok'] = $recovered;
        $rollback['finished_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->recordRecovery($jobId, $rollback, $recovered ? 'recovered' : 'recovery_failed');
    }

    private function recordRecovery(string $jobId, array $rollback, string $status): void
    {
        $this->store->mutate($jobId, function (array $job) use ($rollback, $status): array {
            $job['rollback'] = $rollback;
            $job['recovery_status'] = $status;
            $job['maintenance_enabled'] = $this->maintenance->isEnabled();
            $job['steps'] = $this->markStep(
                $job['steps'] ?? [],
                'rollback',
                !empty($rollback['ok']) ? 'completed' : 'failed',
                !empty($rollback['ok']) ? null : 'Automatic recovery was incomplete; maintenance mode remains enabled.'
            );

            return $job;
        });
    }

    private function canFinalizeSuccessfulStaleUpdate(array $job): bool
    {
        if (!empty($job['maintenance_enabled']) || $this->maintenance->isEnabled()) {
            return false;
        }

        foreach (['database_verification', 'release_verification', 'health_check', 'maintenance_off'] as $required) {
            $completed = false;
            foreach ($job['steps'] ?? [] as $step) {
                if (($step['name'] ?? '') === $required && ($step['status'] ?? '') === 'completed') {
                    $completed = true;
                    break;
                }
            }
            if (!$completed) {
                return false;
            }
        }

        return true;
    }

    private function finalizeSuccessfulStaleUpdate(string $jobId): array
    {
        $job = $this->store->find($jobId);
        if ($job === null) {
            throw new RuntimeException('UPDATE_JOB_NOT_FOUND');
        }

        $databaseVerification = $this->databases->verifyAllFresh();
        if (empty($databaseVerification['ok'])) {
            throw new RuntimeException('UPDATE_STALE_FINALIZATION_DATABASE_VERIFICATION_FAILED');
        }
        $releaseVerification = $this->verifyRelease(
            (string) ($job['target_version'] ?? ''),
            (string) ($job['code_commit_target'] ?? '')
        );
        $healthCheck = $this->healthCheck();
        if (empty($healthCheck['ok'])) {
            throw new RuntimeException('UPDATE_STALE_FINALIZATION_HEALTH_CHECK_FAILED');
        }
        if ($this->maintenance->isEnabled()) {
            throw new RuntimeException('UPDATE_STALE_FINALIZATION_MAINTENANCE_ENABLED');
        }

        $backupSet = is_array($job['backup_set'] ?? null) ? $job['backup_set'] : [];
        $backupDeleted = !empty($job['backup_deleted']);
        $warnings = is_array($job['warnings'] ?? null) ? $job['warnings'] : [];
        if ($backupSet !== [] && $this->shouldDeleteBackupOnSuccess()) {
            $backupDeleted = $this->databases->deleteBackupSet($backupSet);
            if (!$backupDeleted) {
                $warnings[] = 'The update completed after worker recovery, but its backup set could not be deleted.';
            }
        } elseif ($backupSet !== [] && !$this->shouldDeleteBackupOnSuccess()) {
            $warnings[] = 'Update backup retained by POSMAIN_UPDATE_KEEP_BACKUP.';
        }

        return $this->store->mutate($jobId, function (array $current) use (
            $databaseVerification,
            $releaseVerification,
            $healthCheck,
            $backupDeleted,
            $warnings
        ): array {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $current['status'] = 'completed';
            $current['phase'] = 'completed';
            $current['finished_at_utc'] = $now;
            $current['heartbeat_at_utc'] = $now;
            $current['database_verification'] = $databaseVerification;
            $current['release_verification'] = $releaseVerification;
            $current['health_check'] = $healthCheck;
            $current['backup_deleted'] = $backupDeleted;
            if ($backupDeleted) {
                $current['backup_set'] = null;
            }
            $current['warnings'] = array_values(array_unique($warnings));
            $current['recovery_status'] = 'completed_after_stale_cleanup';
            $current['steps'] = $this->markStep(
                $current['steps'] ?? [],
                'backup_cleanup',
                $backupDeleted ? 'completed' : 'failed',
                $backupDeleted ? 'Completed by stale update recovery.' : 'Backup cleanup needs operator review.'
            );

            return $current;
        });
    }

    private function restoreCode(string $commit): array
    {
        if (preg_match('/^[a-f0-9]{40}$/', $commit) !== 1) {
            throw new RuntimeException('UPDATE_ROLLBACK_COMMIT_INVALID');
        }
        $result = posmainUpdateRunGitCommand(
            $this->projectRoot,
            'git reset --hard ' . escapeshellarg($commit)
        );
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('UPDATE_CODE_ROLLBACK_FAILED:' . trim($result['stderr'] ?: $result['stdout']));
        }
        $head = posmainUpdateRunGitCommand($this->projectRoot, 'git rev-parse HEAD');
        if ($head['exit_code'] !== 0 || !hash_equals($commit, trim($head['stdout']))) {
            throw new RuntimeException('UPDATE_CODE_ROLLBACK_VERIFICATION_FAILED');
        }

        return [
            'ok' => true,
            'commit' => $commit,
            'stdout' => trim($result['stdout']),
        ];
    }

    private function verifyRelease(string $targetVersion, string $targetCommit): array
    {
        $head = posmainUpdateRunGitCommand($this->projectRoot, 'git rev-parse HEAD');
        if ($head['exit_code'] !== 0 || !hash_equals($targetCommit, trim($head['stdout']))) {
            throw new RuntimeException('UPDATE_RELEASE_COMMIT_MISMATCH');
        }
        $installed = $this->installedVersion();
        if ($installed === null || !hash_equals($targetVersion, $installed)) {
            throw new RuntimeException('UPDATE_RELEASE_VERSION_TXT_MISMATCH');
        }

        $manifestFile = $this->projectRoot . '/version.json';
        $manifest = is_file($manifestFile)
            ? json_decode((string) file_get_contents($manifestFile), true)
            : null;
        if (!is_array($manifest) || !hash_equals($targetVersion, trim((string) ($manifest['version'] ?? '')))) {
            throw new RuntimeException('UPDATE_RELEASE_VERSION_JSON_MISMATCH');
        }

        return [
            'ok' => true,
            'version' => $targetVersion,
            'commit' => $targetCommit,
        ];
    }

    private function installedVersion(): ?string
    {
        $path = $this->projectRoot . '/version.txt';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $version = trim((string) file_get_contents($path));

        return $version !== '' && preg_match('/^[A-Za-z0-9._+-]{1,64}$/', $version) === 1
            ? $version
            : null;
    }

    private function shouldDeleteBackupOnSuccess(): bool
    {
        $value = strtolower(trim((string) (getenv('POSMAIN_UPDATE_KEEP_BACKUP') ?: '0')));

        return !in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function restartRuntime(): array
    {
        if (isset($this->hooks['restart_runtime']) && is_callable($this->hooks['restart_runtime'])) {
            $result = call_user_func($this->hooks['restart_runtime']);

            $result = is_array($result) ? $result : ['ok' => (bool) $result];
            if (empty($result['ok'])) {
                throw new RuntimeException('RUNTIME_RESTART_FAILED');
            }
            return $result;
        }

        $command = trim((string) (getenv('POSMAIN_UPDATE_PHP_FPM_RELOAD_CMD') ?: ''));
        if ($command === '') {
            return [
                'ok' => true,
                'skipped' => true,
                'message' => 'POSMAIN_UPDATE_PHP_FPM_RELOAD_CMD is not configured.',
            ];
        }

        $result = $this->runCommand($command, $this->projectRoot);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('RUNTIME_RESTART_FAILED: ' . trim($result['stderr'] ?: $result['stdout']));
        }

        return [
            'ok' => true,
            'skipped' => false,
            'command' => $command,
            'stdout' => trim($result['stdout']),
        ];
    }

    private function healthCheck(): array
    {
        if (isset($this->hooks['health_check']) && is_callable($this->hooks['health_check'])) {
            $result = call_user_func($this->hooks['health_check']);

            return is_array($result) ? $result : ['ok' => (bool) $result];
        }

        $baseUrl = '';
        if (function_exists('posmain_app_config')) {
            $baseUrl = rtrim((string) (posmain_app_config()['public_base_url'] ?? ''), '/');
        }
        if ($baseUrl === '') {
            $php = $this->phpBinary();
            $script = $this->projectRoot . '/api/health.php';
            $command = 'QUERY_STRING=' . escapeshellarg('scope=update')
                . ' ' . escapeshellarg($php) . ' ' . escapeshellarg($script);
            $result = $this->runCommand($command, $this->projectRoot);
            $decoded = json_decode($result['stdout'], true);

            return [
                'ok' => $result['exit_code'] === 0 && is_array($decoded) && !empty($decoded['healthy']),
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

    private function failJob(string $jobId, Throwable $exception): void
    {
        $this->store->mutate($jobId, function (array $job) use ($exception): array {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $job['status'] = 'failed';
            $job['phase'] = 'failed';
            $job['finished_at_utc'] = $now;
            $job['heartbeat_at_utc'] = $now;
            $job['errors'][] = [
                'step' => 'orchestrator',
                'message' => $exception->getMessage(),
                'at_utc' => $now,
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
