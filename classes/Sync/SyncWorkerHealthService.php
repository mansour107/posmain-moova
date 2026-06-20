<?php

require_once __DIR__ . '/BranchWorkerDaemon.php';
require_once __DIR__ . '/BranchWorkerAutoDispatcher.php';

class SyncWorkerHealthService
{
    public function report(mysqli $conn, array $config, int $limit = 5, int $recentMinutes = 60): array
    {
        $workerEnabled = !empty($config['sync']['worker_enabled']);
        $status = $this->branchWorkerStatus($conn, $limit, $recentMinutes);
        $preflight = $this->daemonPreflight($conn, $config);
        $process = $this->processHint($status, $workerEnabled, $recentMinutes, $config);

        $healthy = $workerEnabled
            ? empty($status['problems'] ?? []) && empty($preflight['warnings'] ?? []) && !empty($process['appears_running'])
            : true;

        return [
            'worker_enabled' => $workerEnabled,
            'healthy' => $healthy,
            'status' => $status,
            'preflight' => $preflight,
            'process' => $process,
            'recommended_command' => 'php ' . dirname(__DIR__, 2) . '/cli/branch_worker_daemon.php --loop --sleep=5 --max-runtime=300',
            'service_template' => 'deploy/branch-worker/systemd/posmain-branch-worker.service.example',
        ];
    }

    private function branchWorkerStatus(mysqli $conn, int $limit, int $recentMinutes): array
    {
        if (!function_exists('branchWorkerStatusReport')) {
            define('POSMAIN_BRANCH_WORKER_STATUS_LIBRARY', true);
            require_once dirname(__DIR__, 2) . '/tools/branch_worker_status.php';
        }

        return branchWorkerStatusReport($conn, $limit, $recentMinutes);
    }

    private function daemonPreflight(mysqli $conn, array $config): array
    {
        try {
            return (new BranchWorkerDaemon())->preflight($conn, $config);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'warnings' => ['preflight_failed'],
                'message' => $e->getMessage(),
            ];
        }
    }

    private function processHint(array $status, bool $workerEnabled, int $recentMinutes, array $config = []): array
    {
        if (!$workerEnabled) {
            return [
                'appears_running' => false,
                'message' => 'Background worker is disabled in sync settings.',
            ];
        }

        $latest = $status['checks']['worker_logs']['latest'] ?? [];
        $recentFailed = $status['checks']['worker_logs']['recent_failed'] ?? [];
        $newest = null;
        foreach ($latest as $row) {
            $createdAt = strtotime((string) ($row['created_at'] ?? ''));
            if ($createdAt && ($newest === null || $createdAt > $newest)) {
                $newest = $createdAt;
            }
        }

        $threshold = time() - max(300, $recentMinutes * 60);
        $appearsRunning = $newest !== null && $newest >= $threshold;

        $autoDispatch = BranchWorkerAutoDispatcher::shouldAutoDispatch($config);

        return [
            'appears_running' => $appearsRunning,
            'auto_dispatch_enabled' => $autoDispatch,
            'last_worker_activity_at' => $newest ? gmdate('Y-m-d\TH:i:s\Z', $newest) : null,
            'recent_failed_count' => count($recentFailed),
            'message' => $appearsRunning
                ? 'Background worker activity detected recently.'
                : ($autoDispatch
                    ? 'Worker auto-starts in the background while the local app is open. Open another page or wait a few seconds, then refresh.'
                    : 'Worker is enabled but no recent worker log activity was found. Start the branch worker daemon on this machine.'),
        ];
    }
}
