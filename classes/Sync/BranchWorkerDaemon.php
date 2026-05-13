<?php

if (!class_exists('BranchSyncWorker')) {
    require_once __DIR__ . '/BranchIdentity.php';
    require_once __DIR__ . '/CloudAuthService.php';
    require_once __DIR__ . '/OutboxWorker.php';
    require_once __DIR__ . '/SyncDeliveryResultHandler.php';
    require_once __DIR__ . '/SyncHttpClient.php';
    require_once __DIR__ . '/BranchSyncWorker.php';
}
if (!class_exists('BranchMoovaPollWorker')) {
    require_once __DIR__ . '/CloudMoovaEventService.php';
    require_once __DIR__ . '/MoovaInboundQueueService.php';
    require_once __DIR__ . '/../Moova/MoovaLocalIngestService.php';
    require_once __DIR__ . '/BranchMoovaPollWorker.php';
}
if (!class_exists('BranchMoovaApplyWorker')) {
    require_once __DIR__ . '/../MoovaPosIntegration.php';
    require_once __DIR__ . '/../PosOrderService.php';
    require_once __DIR__ . '/BranchMoovaApplyWorker.php';
}
if (!class_exists('BranchMoovaAckWorker')) {
    require_once __DIR__ . '/BranchMoovaAckWorker.php';
}
if (!class_exists('SyncSchemaManager')) {
    require_once __DIR__ . '/SchemaManager.php';
}

class BranchWorkerDaemon
{
    private array $jobs;

    public function __construct(?array $jobs = null)
    {
        $this->jobs = $jobs ?: $this->defaultJobs();
    }

    public function jobNames(): array
    {
        return array_map(fn (array $job): string => (string) $job['name'], $this->jobs);
    }

    public function describeJobs(): array
    {
        return array_map(function (array $job): array {
            return [
                'name' => (string) $job['name'],
                'worker_class' => is_object($job['worker']) ? get_class($job['worker']) : gettype($job['worker']),
                'default_batch_size' => (int) $job['default_batch_size'],
                'purpose' => (string) ($job['purpose'] ?? ''),
            ];
        }, $this->jobs);
    }

    public function preflight(mysqli $conn, array $config = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $schema = new SyncSchemaManager();
        $pending = $schema->pendingStatements($conn);
        $role = (string) ($config['role'] ?? 'branch');
        $branchUuid = (string) ($config['branch']['uuid'] ?? '');
        $cloudBaseUrl = (string) ($config['branch']['cloud_base_url'] ?? '');
        $branchSecretConfigured = (string) ($config['sync']['branch_secret'] ?? '') !== '';
        $warnings = [];

        if ($role !== 'branch') {
            $warnings[] = 'role_is_not_branch';
        }
        if ($branchUuid === '') {
            $warnings[] = 'branch_uuid_missing';
        }
        if ($cloudBaseUrl === '') {
            $warnings[] = 'cloud_base_url_missing';
        }
        if (!$branchSecretConfigured) {
            $warnings[] = 'branch_sync_secret_missing';
        }

        return [
            'ok' => empty($pending),
            'role' => $role,
            'jobs' => $this->describeJobs(),
            'schema_pending' => array_keys($pending),
            'warnings' => $warnings,
            'config' => [
                'branch_uuid_configured' => $branchUuid !== '',
                'cloud_base_url_configured' => $cloudBaseUrl !== '',
                'branch_sync_secret_configured' => $branchSecretConfigured,
                'branch_sync_enabled' => !empty($config['sync']['branch_sync_enabled']) && !empty($config['sync']['worker_enabled']),
                'moova_poller_enabled' => !empty($config['sync']['moova_poller_enabled']),
                'moova_apply_enabled' => !empty($config['sync']['moova_apply_enabled']),
            ],
        ];
    }

    public function runCycle(mysqli $conn, array $config = [], array $options = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $started = microtime(true);
        $selected = $this->selectedJobs($options['only'] ?? null);
        $metrics = [
            'daemon' => 'branch_worker_daemon',
            'run_uuid' => $this->uuid(),
            'started_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'jobs' => [],
            'ok' => true,
            'success' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($selected as $job) {
            $jobResult = $this->runJob($conn, $config, $job, $options);
            $metrics['jobs'][] = $jobResult;
            if ($jobResult['status'] === 'success') {
                $metrics['success']++;
            } elseif ($jobResult['status'] === 'skipped') {
                $metrics['skipped']++;
            } else {
                $metrics['failed']++;
                $metrics['ok'] = false;
            }
        }

        $metrics['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

        return $metrics;
    }

    private function runJob(mysqli $conn, array $config, array $job, array $options): array
    {
        $started = microtime(true);
        $name = (string) $job['name'];
        $worker = $job['worker'];
        $batchSize = $this->batchSizeForJob($job, $options);

        try {
            $workerMetrics = $worker->runOnce($conn, $config, [
                'batch_size' => $batchSize,
            ]);
            $status = $this->statusFromWorkerMetrics($workerMetrics);

            return [
                'name' => $name,
                'status' => $status,
                'batch_size' => $batchSize,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'metrics' => $workerMetrics,
            ];
        } catch (Throwable $e) {
            return [
                'name' => $name,
                'status' => 'failed',
                'batch_size' => $batchSize,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => $e->getMessage(),
            ];
        }
    }

    private function statusFromWorkerMetrics(array $metrics): string
    {
        if (!empty($metrics['skipped'])) {
            return 'skipped';
        }

        foreach (['failed', 'dead', 'conflicts'] as $key) {
            if ((int) ($metrics[$key] ?? 0) > 0) {
                return 'failed';
            }
        }

        return 'success';
    }

    private function selectedJobs($only): array
    {
        if ($only === null || $only === '') {
            return $this->jobs;
        }

        $wanted = is_array($only) ? $only : explode(',', (string) $only);
        $wanted = array_values(array_filter(array_map(fn ($name): string => trim((string) $name), $wanted)));
        if (!$wanted) {
            return $this->jobs;
        }

        $known = array_flip($this->jobNames());
        foreach ($wanted as $name) {
            if (!isset($known[$name])) {
                throw new InvalidArgumentException('Unknown branch worker daemon job: ' . $name);
            }
        }

        return array_values(array_filter($this->jobs, fn (array $job): bool => in_array((string) $job['name'], $wanted, true)));
    }

    private function batchSizeForJob(array $job, array $options): int
    {
        $name = (string) $job['name'];
        if ($name === 'sync_outbox' && isset($options['sync_batch_size'])) {
            return max(1, (int) $options['sync_batch_size']);
        }
        if (strpos($name, 'moova_') === 0 && isset($options['moova_batch_size'])) {
            return max(1, (int) $options['moova_batch_size']);
        }

        return max(1, (int) ($options['batch_size'] ?? $job['default_batch_size']));
    }

    private function defaultJobs(): array
    {
        return [
            [
                'name' => 'sync_outbox',
                'worker' => new BranchSyncWorker(),
                'default_batch_size' => 50,
                'purpose' => 'Deliver local sync_outbox events to the cloud receive API.',
            ],
            [
                'name' => 'moova_poller',
                'worker' => new BranchMoovaPollWorker(),
                'default_batch_size' => 25,
                'purpose' => 'Pull cloud Moova events into the local inbound queue.',
            ],
            [
                'name' => 'moova_apply',
                'worker' => new BranchMoovaApplyWorker(),
                'default_batch_size' => 25,
                'purpose' => 'Apply local inbound Moova events to POS orders.',
            ],
            [
                'name' => 'moova_ack',
                'worker' => new BranchMoovaAckWorker(),
                'default_batch_size' => 25,
                'purpose' => 'Acknowledge terminal Moova inbound results back to the cloud.',
            ],
        ];
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}
