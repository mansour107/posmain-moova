<?php

class BranchWorkerAutoDispatcher
{
    private const DEFAULT_INTERVAL_SECONDS = 20;

    public static function maybeDispatchFromWebRequest(array $config): void
    {
        if (PHP_SAPI === 'cli' || !self::shouldAutoDispatch($config)) {
            return;
        }

        register_shutdown_function(static function () use ($config): void {
            self::dispatchIfDue($config);
        });
    }

    public static function shouldAutoDispatch(array $config): bool
    {
        if (self::isDisabledByEnv()) {
            return false;
        }

        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            return false;
        }

        if (empty($config['sync']['worker_enabled']) || empty($config['sync']['branch_sync_enabled'])) {
            return false;
        }

        if (self::enabledJobs($config) === []) {
            return false;
        }

        $branchUuid = trim((string) ($config['branch']['uuid'] ?? ''));
        $cloudBaseUrl = trim((string) ($config['branch']['cloud_base_url'] ?? ''));
        $branchSecret = (string) ($config['sync']['branch_secret'] ?? '');

        return $branchUuid !== '' && $cloudBaseUrl !== '' && $branchSecret !== '';
    }

    public static function enabledJobs(array $config): array
    {
        $jobs = [];

        if (!empty($config['sync']['outbox_enabled'])) {
            $jobs[] = 'sync_outbox';
        }
        if (!empty($config['sync']['cloud_pull_enabled'])) {
            $jobs[] = 'cloud_sync_poller';
        }
        if (!empty($config['sync']['moova_poller_enabled'])) {
            $jobs[] = 'moova_poller';
            $jobs[] = 'moova_ack';
        }
        if (!empty($config['sync']['moova_apply_enabled'])) {
            $jobs[] = 'moova_apply';
        }

        return array_values(array_unique($jobs));
    }

    private static function dispatchIfDue(array $config): void
    {
        if (!self::shouldAutoDispatch($config)) {
            return;
        }

        $statePath = self::statePath();
        $intervalSeconds = self::intervalSeconds();
        $now = time();

        $handle = @fopen($statePath, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }

            $state = self::readState($handle);
            $lastDispatchAt = (int) ($state['last_dispatch_at'] ?? 0);
            if ($lastDispatchAt > 0 && ($now - $lastDispatchAt) < $intervalSeconds) {
                return;
            }

            if (!self::spawnWorker($config)) {
                return;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode([
                'last_dispatch_at' => $now,
                'jobs' => self::enabledJobs($config),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function spawnWorker(array $config): bool
    {
        $projectRoot = dirname(__DIR__, 2);
        $script = $projectRoot . '/cli/branch_worker_daemon.php';
        if (!is_file($script)) {
            return false;
        }

        $jobs = self::enabledJobs($config);
        if ($jobs === []) {
            return false;
        }

        $php = self::phpBinary();
        $logDir = $projectRoot . '/var/branch_worker_autodispatch';
        if (!is_dir($logDir) && !@mkdir($logDir, 0750, true) && !is_dir($logDir)) {
            $logDir = $projectRoot . '/logs';
        }

        $logFile = $logDir . '/worker-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.log';
        $command = escapeshellarg($php)
            . ' ' . escapeshellarg($script)
            . ' --once --only=' . escapeshellarg(implode(',', $jobs))
            . ' >> ' . escapeshellarg($logFile) . ' 2>&1';

        if (DIRECTORY_SEPARATOR === '\\') {
            $command = 'start /B "" ' . $command;
        } else {
            $command = $command . ' &';
        }

        @exec($command);

        return true;
    }

    private static function phpBinary(): string
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

    private static function intervalSeconds(): int
    {
        $seconds = (int) (getenv('POSMAIN_BRANCH_WORKER_AUTODISPATCH_INTERVAL_SECONDS') ?: self::DEFAULT_INTERVAL_SECONDS);

        return max(5, $seconds);
    }

    private static function isDisabledByEnv(): bool
    {
        return in_array(
            strtolower(trim((string) getenv('POSMAIN_BRANCH_WORKER_AUTODISPATCH'))),
            ['0', 'false', 'no', 'off'],
            true
        );
    }

    private static function statePath(): string
    {
        $dir = dirname(__DIR__, 2) . '/var';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $dir = sys_get_temp_dir();
        }

        return $dir . '/branch_worker_autodispatch.state.json';
    }

    private static function readState($handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
