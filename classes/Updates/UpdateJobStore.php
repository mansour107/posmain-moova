<?php

class PosmainUpdateJobStore
{
    private const ACTIVE_STATUSES = ['queued', 'starting', 'running'];

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
                'backup_file' => null,
                'code_commit_before' => null,
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
        if (!flock($lock, LOCK_SH)) {
            fclose($lock);
            throw new RuntimeException('UPDATE_LOCK_UNAVAILABLE');
        }

        try {
            return $this->activeJobLocked();
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

        if ($action === 'plan' || $action === 'apply') {
            $steps[] = ['name' => 'file_migrations_plan', 'status' => 'pending'];
        }

        if ($action === 'apply') {
            $steps = array_merge($steps, [
                ['name' => 'maintenance_on', 'status' => 'pending'],
                ['name' => 'drain_requests', 'status' => 'pending'],
                ['name' => 'backup', 'status' => 'pending'],
                ['name' => 'file_migrations', 'status' => 'pending'],
                ['name' => 'code_pull', 'status' => 'pending'],
                ['name' => 'runtime_restart', 'status' => 'pending'],
                ['name' => 'health_check', 'status' => 'pending'],
                ['name' => 'write_version', 'status' => 'pending'],
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

    private function writeJobLocked(array $job): void
    {
        $path = $this->jobPath((string) $job['id']);
        $json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('UPDATE_JOB_JSON_ENCODE_FAILED');
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('UPDATE_JOB_WRITE_FAILED');
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
        $lock = fopen($this->baseDir . '/update.lock', 'c+');
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
