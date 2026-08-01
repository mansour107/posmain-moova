<?php

class PosmainUpdateJobStore
{
    private const ACTIVE_STATUSES = [
        'queued',
        'starting',
        'running',
        'recovery_required',
        'recovery_starting',
        'recovering',
    ];

    private $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?: dirname(__DIR__, 2) . '/var/update_jobs';
    }

    public function create(array $request): array
    {
        $this->ensureDirectory();

        $lock = $this->openLock();
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('UPDATE_LOCK_UNAVAILABLE');
        }

        try {
            $this->expireStaleJobsLocked();
            $active = $this->activeJobLocked();
            if ($active !== null) {
                throw new RuntimeException('UPDATE_ALREADY_RUNNING:' . (string) ($active['id'] ?? 'unknown'));
            }

            $now = $this->now();
            $action = $this->normalizeAction($request['action'] ?? 'apply');
            $job = [
                'id' => $this->newJobId(),
                'status' => 'queued',
                'phase' => 'created',
                'action' => $action,
                'target_version' => $this->normalizeTargetVersion($request['target_version'] ?? null),
                'requested_by_user_id' => isset($request['requested_by_user_id']) ? (int) $request['requested_by_user_id'] : 0,
                'created_at_utc' => $now,
                'updated_at_utc' => $now,
                'installed_version' => null,
                'published_version' => null,
                'backup_set' => null,
                'backup_deleted' => false,
                'code_commit_before' => null,
                'code_commit_target' => null,
                'recovery_status' => null,
                'maintenance_enabled' => false,
                'steps' => $this->initialSteps($action),
                'warnings' => [],
                'errors' => [],
            ];

            $this->writeJobLocked($job);

            return $job;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function find(string $jobId): ?array
    {
        $jobId = $this->normalizeJobId($jobId);
        $path = $this->jobPath($jobId);
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    public function activeJob(): ?array
    {
        $this->ensureDirectory();

        $lock = $this->openLock();
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('UPDATE_LOCK_UNAVAILABLE');
        }

        try {
            $this->expireStaleJobsLocked();
            return $this->activeJobLocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function markDispatching(string $jobId): array
    {
        return $this->mutate($jobId, static function (array $job): array {
            if ((string) ($job['status'] ?? '') !== 'queued') {
                throw new RuntimeException('UPDATE_JOB_NOT_QUEUED');
            }
            $job['status'] = 'starting';
            $job['phase'] = 'worker_dispatch';
            $job['dispatch_started_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');

            return $job;
        });
    }

    public function markDispatchFailed(string $jobId, string $message): array
    {
        return $this->mutate($jobId, static function (array $job) use ($message): array {
            if (!in_array((string) ($job['status'] ?? ''), ['queued', 'starting'], true)) {
                return $job;
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $job['status'] = 'failed';
            $job['phase'] = 'worker_dispatch';
            $job['finished_at_utc'] = $now;
            $job['errors'][] = [
                'step' => 'worker_dispatch',
                'message' => $message !== '' ? $message : 'UPDATE_WORKER_DISPATCH_FAILED',
                'at_utc' => $now,
            ];
            foreach ($job['steps'] ?? [] as $index => $step) {
                if (($step['name'] ?? '') === 'worker_dispatch') {
                    $job['steps'][$index]['status'] = 'failed';
                    $job['steps'][$index]['at_utc'] = $now;
                    $job['steps'][$index]['message'] = $message;
                }
            }

            return $job;
        });
    }

    public function claim(string $jobId, ?int $workerPid = null): array
    {
        return $this->mutate($jobId, static function (array $job) use ($workerPid): array {
            $status = (string) ($job['status'] ?? '');
            if (!in_array($status, ['queued', 'starting'], true)) {
                throw new RuntimeException('UPDATE_JOB_NOT_CLAIMABLE:' . $status);
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $job['status'] = 'running';
            $job['phase'] = 'worker_dispatch';
            $job['started_at_utc'] = $job['started_at_utc'] ?? $now;
            $job['heartbeat_at_utc'] = $now;
            $job['worker_pid'] = $workerPid ?? getmypid();
            $job['claim_token'] = bin2hex(random_bytes(16));
            $job['attempt'] = max(0, (int) ($job['attempt'] ?? 0)) + 1;

            return $job;
        });
    }

    public function markRecoveryDispatching(string $jobId): array
    {
        return $this->mutate($jobId, static function (array $job): array {
            if ((string) ($job['status'] ?? '') !== 'recovery_required') {
                throw new RuntimeException('UPDATE_RECOVERY_NOT_REQUIRED');
            }
            $job['status'] = 'recovery_starting';
            $job['phase'] = 'stale_recovery_dispatch';
            $job['recovery_dispatch_started_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');

            return $job;
        });
    }

    public function markRecoveryDispatchFailed(string $jobId, string $message): array
    {
        return $this->mutate($jobId, static function (array $job) use ($message): array {
            if ((string) ($job['status'] ?? '') !== 'recovery_starting') {
                return $job;
            }
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $job['status'] = 'recovery_required';
            $job['phase'] = 'stale_recovery';
            $job['recovery_dispatch_failed_at_utc'] = $now;
            $job['errors'][] = [
                'step' => 'stale_recovery_dispatch',
                'message' => $message !== '' ? $message : 'UPDATE_RECOVERY_WORKER_DISPATCH_FAILED',
                'at_utc' => $now,
            ];

            return $job;
        });
    }

    public function claimRecovery(string $jobId, ?int $workerPid = null): array
    {
        return $this->mutate($jobId, static function (array $job) use ($workerPid): array {
            $status = (string) ($job['status'] ?? '');
            if (!in_array($status, ['recovery_required', 'recovery_starting'], true)) {
                throw new RuntimeException('UPDATE_JOB_NOT_RECOVERABLE:' . $status);
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $job['status'] = 'recovering';
            $job['phase'] = 'rollback';
            $job['recovery_started_at_utc'] = $now;
            $job['heartbeat_at_utc'] = $now;
            $job['recovery_worker_pid'] = $workerPid ?? getmypid();
            $job['recovery_claim_token'] = bin2hex(random_bytes(16));
            $job['recovery_attempt'] = max(0, (int) ($job['recovery_attempt'] ?? 0)) + 1;

            return $job;
        });
    }

    public function heartbeat(string $jobId): array
    {
        return $this->mutate($jobId, static function (array $job): array {
            if (!in_array((string) ($job['status'] ?? ''), ['running', 'recovering'], true)) {
                throw new RuntimeException('UPDATE_JOB_NOT_RUNNING');
            }
            $job['heartbeat_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');

            return $job;
        });
    }

    public function expireStaleJobs(): array
    {
        $this->ensureDirectory();
        $lock = $this->openLock();
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('UPDATE_LOCK_UNAVAILABLE');
        }

        try {
            return $this->expireStaleJobsLocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /**
     * @param callable(array):array $mutator
     */
    public function mutate(string $jobId, callable $mutator): array
    {
        $this->ensureDirectory();

        $lock = $this->openLock();
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('UPDATE_LOCK_UNAVAILABLE');
        }

        try {
            $job = $this->readJobLocked($jobId);
            if ($job === null) {
                throw new InvalidArgumentException('INVALID_UPDATE_JOB_ID');
            }

            $job = $mutator($job);
            $job['updated_at_utc'] = $this->now();
            $this->writeJobLocked($job);

            return $job;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function initialSteps(string $action): array
    {
        $steps = [
            ['name' => 'job_created', 'status' => 'completed'],
            ['name' => 'worker_dispatch', 'status' => 'pending'],
            ['name' => 'version_check', 'status' => 'pending'],
        ];

        if ($action === 'plan') {
            $steps[] = ['name' => 'database_migrations_plan', 'status' => 'pending'];
        }

        if ($action === 'apply') {
            $steps = array_merge($steps, [
                ['name' => 'preflight', 'status' => 'pending'],
                ['name' => 'database_migrations_plan', 'status' => 'pending'],
                ['name' => 'maintenance_on', 'status' => 'pending'],
                ['name' => 'drain_requests', 'status' => 'pending'],
                ['name' => 'backup', 'status' => 'pending'],
                ['name' => 'code_activation', 'status' => 'pending'],
                ['name' => 'database_migrations', 'status' => 'pending'],
                ['name' => 'runtime_restart', 'status' => 'pending'],
                ['name' => 'database_verification', 'status' => 'pending'],
                ['name' => 'release_verification', 'status' => 'pending'],
                ['name' => 'health_check', 'status' => 'pending'],
                ['name' => 'maintenance_off', 'status' => 'pending'],
                ['name' => 'backup_cleanup', 'status' => 'pending'],
            ]);
        }

        return $steps;
    }

    private function readJobLocked(string $jobId): ?array
    {
        $path = $this->jobPath($this->normalizeJobId($jobId));
        if (!is_file($path)) {
            return null;
        }

        $job = json_decode((string) file_get_contents($path), true);

        return is_array($job) ? $job : null;
    }

    private function activeJobLocked(): ?array
    {
        $jobs = glob($this->baseDir . '/upd_*.json') ?: [];
        rsort($jobs);

        foreach ($jobs as $path) {
            $job = json_decode((string) file_get_contents($path), true);
            if (!is_array($job)) {
                continue;
            }

            if (in_array((string) ($job['status'] ?? ''), self::ACTIVE_STATUSES, true)) {
                return $job;
            }
        }

        return null;
    }

    private function expireStaleJobsLocked(): array
    {
        $expired = [];
        foreach (glob($this->baseDir . '/upd_*.json') ?: [] as $path) {
            $job = json_decode((string) file_get_contents($path), true);
            if (!is_array($job) || !in_array((string) ($job['status'] ?? ''), self::ACTIVE_STATUSES, true)) {
                continue;
            }
            if (!$this->isStale($job)) {
                continue;
            }

            $now = $this->now();
            $phase = (string) ($job['phase'] ?? '');
            $recoveryPhase = in_array($phase, [
                'maintenance_on',
                'drain_requests',
                'backup',
                'code_activation',
                'database_migrations',
                'runtime_restart',
                'database_verification',
                'release_verification',
                'health_check',
                'maintenance_off',
                'backup_cleanup',
                'rollback',
            ], true);
            $requiresRecovery = is_array($job['backup_set'] ?? null)
                || !empty($job['maintenance_enabled'])
                || $recoveryPhase;
            $job['status'] = $requiresRecovery ? 'recovery_required' : 'failed';
            $job['phase'] = $requiresRecovery ? 'stale_recovery' : 'stale';
            if (!$requiresRecovery) {
                $job['finished_at_utc'] = $now;
            }
            $job['errors'][] = [
                'step' => 'stale_job_recovery',
                'message' => $requiresRecovery ? 'UPDATE_JOB_STALE_RECOVERY_REQUIRED' : 'UPDATE_JOB_STALE',
                'at_utc' => $now,
            ];
            $job['updated_at_utc'] = $now;
            $this->writeJobLocked($job);
            $expired[] = (string) ($job['id'] ?? '');
        }

        return $expired;
    }

    private function isStale(array $job): bool
    {
        $status = (string) ($job['status'] ?? '');
        $pidField = $status === 'recovering' ? 'recovery_worker_pid' : 'worker_pid';
        $pid = (int) ($job[$pidField] ?? 0);
        if (in_array($status, ['running', 'recovering'], true) && $pid > 0 && function_exists('posix_kill')) {
            $processExists = @posix_kill($pid, 0);
            $permissionDenied = false;
            if (!$processExists && function_exists('posix_get_last_error')) {
                $permissionDenied = posix_get_last_error() === 1; // EPERM: process exists under another user.
            }
            if ($processExists || $permissionDenied) {
                return false;
            }
        }

        $defaultTimeout = in_array($status, ['running', 'recovering'], true) ? 7200 : 300;
        $configured = (int) (getenv('POSMAIN_UPDATE_JOB_STALE_SECONDS') ?: 0);
        $timeout = $configured > 0 ? max(60, $configured) : $defaultTimeout;
        $timestamp = (string) (
            $job['heartbeat_at_utc']
            ?? $job['updated_at_utc']
            ?? $job['created_at_utc']
            ?? ''
        );
        if ($timestamp === '') {
            return true;
        }

        $updatedAt = strtotime($timestamp);
        if ($updatedAt === false) {
            return true;
        }

        return time() - $updatedAt > $timeout;
    }

    private function writeJobLocked(array $job): void
    {
        $path = $this->jobPath((string) $job['id']);
        $json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('UPDATE_JOB_JSON_ENCODE_FAILED');
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('UPDATE_JOB_WRITE_FAILED');
        }
        if (!chmod($tmp, 0644)) {
            @unlink($tmp);
            throw new RuntimeException('UPDATE_JOB_PERMISSION_FAILED');
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('UPDATE_JOB_COMMIT_FAILED');
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->baseDir)) {
            return;
        }

        if (!mkdir($this->baseDir, 0750, true) && !is_dir($this->baseDir)) {
            throw new RuntimeException('UPDATE_JOB_DIRECTORY_UNAVAILABLE');
        }
    }

    private function openLock()
    {
        $this->ensureDirectory();
        $lockPath = $this->baseDir . '/update.lock';
        if (!is_file($lockPath)) {
            $created = @fopen($lockPath, 'x');
            if (is_resource($created)) {
                fclose($created);
                if (!chmod($lockPath, 0644)) {
                    @unlink($lockPath);
                    throw new RuntimeException('UPDATE_LOCK_PERMISSION_FAILED');
                }
            } elseif (!is_file($lockPath)) {
                throw new RuntimeException('UPDATE_LOCK_UNAVAILABLE');
            }
        }

        // The lock carries no data. Read-only opening allows the web and deploy
        // users to coordinate without requiring either user to own the inode.
        $lock = @fopen($lockPath, 'r');
        if (!$lock) {
            throw new RuntimeException('UPDATE_LOCK_UNAVAILABLE');
        }

        return $lock;
    }

    private function newJobId(): string
    {
        return 'upd_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
    }

    private function normalizeJobId(string $jobId): string
    {
        $jobId = trim($jobId);
        if (preg_match('/^upd_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $jobId) !== 1) {
            throw new InvalidArgumentException('INVALID_UPDATE_JOB_ID');
        }

        return $jobId;
    }

    private function jobPath(string $jobId): string
    {
        return $this->baseDir . '/' . $this->normalizeJobId($jobId) . '.json';
    }

    private function normalizeAction($action): string
    {
        $action = strtolower(trim((string) $action));
        if ($action === '') {
            return 'apply';
        }

        if (!in_array($action, ['check', 'plan', 'apply'], true)) {
            throw new InvalidArgumentException('INVALID_UPDATE_ACTION');
        }

        return $action;
    }

    private function normalizeTargetVersion($version): ?string
    {
        if ($version === null) {
            return null;
        }

        $version = trim((string) $version);
        if ($version === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._+-]{1,64}$/', $version) !== 1) {
            throw new InvalidArgumentException('INVALID_TARGET_VERSION');
        }

        return $version;
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
