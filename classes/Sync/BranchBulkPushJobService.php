<?php

require_once __DIR__ . '/BranchCatalogPushService.php';
require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/SchemaManager.php';

class BranchBulkPushJobService
{
    private const QUEUE_WEIGHT = 0.4;
    private const DISPATCH_WEIGHT = 0.6;
    private const DISPATCH_SAFETY_LIMIT = 5000;

    private BranchCatalogPushService $pushService;

    public function __construct(?BranchCatalogPushService $pushService = null)
    {
        $this->pushService = $pushService ?: new BranchCatalogPushService();
    }

    public function start(mysqli $conn, array $config, int $shopId = 0, string $shopDbName = ''): array
    {
        (new SyncSchemaManager())->apply($conn);

        $active = $this->findActiveJob($conn);
        if ($active) {
            return $this->presentJob($active, false);
        }

        $shopDbName = trim($shopDbName);
        if ($shopDbName === '') {
            $row = $conn->query('SELECT DATABASE() AS db_name')->fetch_assoc();
            $shopDbName = trim((string) ($row['db_name'] ?? ''));
        }

        $jobUuid = SyncBranchIdentity::generateUuidV4();
        $stmt = $conn->prepare("
            INSERT INTO sync_bulk_push_jobs (
                job_uuid,
                shop_id,
                shop_db_name,
                status,
                phase,
                message,
                progress_percent
            ) VALUES (?, ?, ?, 'queued', 'queued', ?, 0)
        ");
        $message = 'Preparing background sync...';
        $stmt->bind_param('siss', $jobUuid, $shopId, $shopDbName, $message);
        $stmt->execute();
        $stmt->close();

        $spawned = $this->spawnBackground($jobUuid, $shopId, $shopDbName);
        if (!$spawned) {
            $this->markFailed($conn, $jobUuid, 'Unable to start the background sync process on this server.');
            throw new RuntimeException('Unable to start the background sync process on this server.');
        }

        $job = $this->findJob($conn, $jobUuid);

        return $this->presentJob($job, true);
    }

    public function getLatestJob(mysqli $conn, ?string $jobUuid = null): ?array
    {
        (new SyncSchemaManager())->apply($conn);

        if ($jobUuid !== null && trim($jobUuid) !== '') {
            $job = $this->findJob($conn, trim($jobUuid));

            return $job ? $this->presentJob($job, false) : null;
        }

        $job = $this->findLatestJob($conn);

        return $job ? $this->presentJob($job, false) : null;
    }

    public function runToCompletion(mysqli $conn, array $config, string $jobUuid): void
    {
        (new SyncSchemaManager())->apply($conn);

        $jobUuid = trim($jobUuid);
        if ($jobUuid === '') {
            throw new InvalidArgumentException('Bulk push job UUID is required.');
        }

        $lockPath = $this->lockPath($jobUuid);
        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }

            return;
        }

        try {
            if (!$this->markRunning($conn, $jobUuid)) {
                return;
            }

            $pushOptions = [
                'catalog' => true,
                'tables' => true,
                'orders' => true,
                'operational' => true,
            ];

            $plan = $this->pushService->planPushToHosted($conn, $pushOptions);
            $phases = $plan['phases'] ?? [];
            $queueRowTotal = max(1, (int) ($plan['queue_row_total'] ?? 1));
            $completedQueueRows = 0;
            $aggregatedQueue = $this->emptyQueueTotals();
            $aggregatedDispatch = $this->emptyDispatchTotals();
            $totalQueued = 0;

            $this->updateJob($conn, $jobUuid, [
                'phase' => 'planning',
                'message' => $this->formatProgressMessage('preparing', 0),
                'progress_percent' => 0,
            ]);

            foreach ($phases as $phase) {
                $phaseId = (string) ($phase['id'] ?? '');
                $phaseLabel = (string) ($phase['label'] ?? $phaseId);
                $phaseStartPercent = ($completedQueueRows / $queueRowTotal) * self::QUEUE_WEIGHT * 100;

                $this->updateJob($conn, $jobUuid, [
                    'phase' => $phaseId !== '' ? $phaseId : 'queue',
                    'message' => $this->formatProgressMessage($phaseLabel, $phaseStartPercent),
                    'progress_percent' => (int) round($phaseStartPercent),
                ]);

                $phaseResult = $this->pushService->runPushPhase($conn, $config, $phaseId, $pushOptions);
                $this->mergeQueueTotals($aggregatedQueue, $phaseResult['queue'] ?? []);
                $totalQueued += (int) (($phaseResult['queue']['queued'] ?? 0));
                $completedQueueRows += (int) ($phase['total'] ?? 0);

                $phaseDonePercent = ($completedQueueRows / $queueRowTotal) * self::QUEUE_WEIGHT * 100;
                $this->updateJob($conn, $jobUuid, [
                    'phase' => $phaseId !== '' ? $phaseId : 'queue',
                    'message' => $this->formatProgressMessage($phaseLabel, $phaseDonePercent),
                    'progress_percent' => (int) round($phaseDonePercent),
                    'queue_json' => $aggregatedQueue,
                ]);
            }

            $dispatchTotal = max(1, $totalQueued > 0 ? $totalQueued : (int) ($aggregatedQueue['queued'] ?? 0));
            $syncedSoFar = 0;
            $pendingOutbox = 0;
            $dispatchDone = false;
            $dispatchSafety = 0;

            while (!$dispatchDone && $dispatchSafety < self::DISPATCH_SAFETY_LIMIT) {
                $dispatchSafety++;
                $dispatchPercent = (self::QUEUE_WEIGHT * 100) + (($syncedSoFar / $dispatchTotal) * self::DISPATCH_WEIGHT * 100);

                $this->updateJob($conn, $jobUuid, [
                    'phase' => 'dispatch',
                    'message' => $this->formatProgressMessage('sending queued events to hosted', $dispatchPercent),
                    'progress_percent' => (int) round($dispatchPercent),
                    'queue_json' => $aggregatedQueue,
                    'dispatch_json' => $aggregatedDispatch,
                ]);

                $dispatchResult = $this->pushService->runPushDispatchBatch($conn, $config, ['batch_size' => 50]);
                $dispatch = $dispatchResult['dispatch'] ?? [];
                $this->mergeDispatchTotals($aggregatedDispatch, $dispatch);
                $syncedSoFar = (int) ($aggregatedDispatch['synced'] ?? 0);
                $pendingOutbox = (int) ($dispatchResult['pending_outbox'] ?? 0);
                $dispatchDone = !empty($dispatchResult['done']);
            }

            $failed = (int) ($aggregatedDispatch['failed'] ?? 0);
            $finalMessage = $this->buildFinalMessage($aggregatedQueue, $aggregatedDispatch, $pendingOutbox);
            $result = [
                'queue' => $aggregatedQueue,
                'dispatch' => $aggregatedDispatch,
                'pending_outbox' => $pendingOutbox,
            ];

            $this->updateJob($conn, $jobUuid, [
                'status' => $failed > 0 ? 'failed' : 'completed',
                'phase' => 'finished',
                'message' => $finalMessage,
                'progress_percent' => 100,
                'queue_json' => $aggregatedQueue,
                'dispatch_json' => $aggregatedDispatch,
                'result_json' => $result,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $this->markFailed($conn, $jobUuid, $e->getMessage());
            throw $e;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function spawnBackground(string $jobUuid, int $shopId, string $shopDbName): bool
    {
        $projectRoot = dirname(__DIR__, 2);
        $script = $projectRoot . '/cli/sync_bulk_push.php';
        if (!is_file($script)) {
            return false;
        }

        $php = $this->phpBinary();
        $logDir = $projectRoot . '/var/sync_bulk_push';
        if (!is_dir($logDir) && !@mkdir($logDir, 0750, true) && !is_dir($logDir)) {
            $logDir = $projectRoot . '/logs';
        }

        $command = escapeshellarg($php)
            . ' ' . escapeshellarg($script)
            . ' --job-uuid=' . escapeshellarg($jobUuid);
        if ($shopId > 0) {
            $command .= ' --shop-id=' . (int) $shopId;
        }
        if (trim($shopDbName) !== '') {
            $command .= ' --shop-db=' . escapeshellarg(trim($shopDbName));
        }
        $logFile = $logDir . '/bulk-push-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.log';
        $command .= ' >> ' . escapeshellarg($logFile) . ' 2>&1';

        if (DIRECTORY_SEPARATOR === '\\') {
            $command = 'start /B "" ' . $command;
        } else {
            $command = $command . ' &';
        }

        @exec($command);

        return true;
    }

    private function findActiveJob(mysqli $conn): ?array
    {
        $result = $conn->query("
            SELECT *
            FROM sync_bulk_push_jobs
            WHERE status IN ('queued', 'running')
            ORDER BY id DESC
            LIMIT 1
        ");

        $row = $result ? $result->fetch_assoc() : null;

        return $row ?: null;
    }

    private function findLatestJob(mysqli $conn): ?array
    {
        $result = $conn->query("
            SELECT *
            FROM sync_bulk_push_jobs
            ORDER BY id DESC
            LIMIT 1
        ");

        $row = $result ? $result->fetch_assoc() : null;

        return $row ?: null;
    }

    private function findJob(mysqli $conn, string $jobUuid): ?array
    {
        $stmt = $conn->prepare('SELECT * FROM sync_bulk_push_jobs WHERE job_uuid = ? LIMIT 1');
        $stmt->bind_param('s', $jobUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function markRunning(mysqli $conn, string $jobUuid): bool
    {
        $stmt = $conn->prepare("
            UPDATE sync_bulk_push_jobs
            SET status = 'running',
                started_at = COALESCE(started_at, NOW(6)),
                phase = 'planning',
                message = 'Starting background sync...'
            WHERE job_uuid = ?
              AND status IN ('queued', 'running')
        ");
        $stmt->bind_param('s', $jobUuid);
        $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();

        return $changed;
    }

    private function markFailed(mysqli $conn, string $jobUuid, string $message): void
    {
        $stmt = $conn->prepare("
            UPDATE sync_bulk_push_jobs
            SET status = 'failed',
                phase = 'finished',
                message = ?,
                error_message = ?,
                progress_percent = 100,
                finished_at = NOW(6)
            WHERE job_uuid = ?
        ");
        $stmt->bind_param('sss', $message, $message, $jobUuid);
        $stmt->execute();
        $stmt->close();
    }

    private function updateJob(mysqli $conn, string $jobUuid, array $fields): void
    {
        $allowed = [
            'status' => 's',
            'phase' => 's',
            'message' => 's',
            'progress_percent' => 'i',
            'queue_json' => 's',
            'dispatch_json' => 's',
            'result_json' => 's',
            'error_message' => 's',
            'finished_at' => 's',
        ];

        $sets = [];
        $types = '';
        $values = [];
        foreach ($fields as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            if (in_array($key, ['queue_json', 'dispatch_json', 'result_json'], true) && is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $sets[] = $key . ' = ?';
            $types .= $allowed[$key];
            $values[] = $value;
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE sync_bulk_push_jobs SET ' . implode(', ', $sets) . ' WHERE job_uuid = ?';
        $types .= 's';
        $values[] = $jobUuid;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    }

    private function presentJob(array $row, bool $started): array
    {
        return [
            'job_uuid' => (string) ($row['job_uuid'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'phase' => (string) ($row['phase'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'progress_percent' => (int) ($row['progress_percent'] ?? 0),
            'queue' => $this->decodeJsonField($row['queue_json'] ?? null),
            'dispatch' => $this->decodeJsonField($row['dispatch_json'] ?? null),
            'result' => $this->decodeJsonField($row['result_json'] ?? null),
            'error_message' => (string) ($row['error_message'] ?? ''),
            'started' => $started,
            'running' => in_array((string) ($row['status'] ?? ''), ['queued', 'running'], true),
            'ok' => (string) ($row['status'] ?? '') === 'completed',
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'finished_at' => (string) ($row['finished_at'] ?? ''),
        ];
    }

    private function decodeJsonField($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function emptyQueueTotals(): array
    {
        return [
            'catalog' => 0,
            'tables' => 0,
            'orders' => 0,
            'queued' => 0,
            'skipped' => 0,
            'resent' => 0,
        ];
    }

    private function emptyDispatchTotals(): array
    {
        return [
            'batches' => 0,
            'claimed' => 0,
            'synced' => 0,
            'failed' => 0,
            'dead' => 0,
        ];
    }

    private function mergeQueueTotals(array &$target, array $source): void
    {
        foreach ($source as $key => $value) {
            if (is_numeric($value)) {
                $target[$key] = (int) ($target[$key] ?? 0) + (int) $value;
            }
        }
    }

    private function mergeDispatchTotals(array &$target, array $source): void
    {
        foreach (['batches', 'claimed', 'synced', 'failed', 'dead'] as $key) {
            $target[$key] = (int) ($target[$key] ?? 0) + (int) ($source[$key] ?? 0);
        }
    }

    private function formatProgressMessage(string $label, float $percent): string
    {
        $safePercent = min(100, max(0, (int) round($percent)));

        return 'Syncing ' . $label . '... ' . $safePercent . '%';
    }

    private function buildFinalMessage(array $queue, array $dispatch, int $pending): string
    {
        $failed = (int) ($dispatch['failed'] ?? 0);
        $counted = 'Items: ' . (int) ($queue['catalog'] ?? 0)
            . ', tables: ' . (int) ($queue['tables'] ?? 0)
            . ', orders: ' . (int) ($queue['orders'] ?? 0)
            . '. ';

        if ($failed > 0) {
            return 'Sync finished with errors. ' . $counted
                . 'Queued: ' . (int) ($queue['queued'] ?? 0)
                . ', synced: ' . (int) ($dispatch['synced'] ?? 0)
                . ', failed: ' . $failed
                . ', still pending: ' . $pending . '.';
        }

        $suffix = $pending > 0 ? ', still pending: ' . $pending . '.' : '.';

        return 'Sync finished. ' . $counted
            . 'Queued: ' . (int) ($queue['queued'] ?? 0)
            . ', synced: ' . (int) ($dispatch['synced'] ?? 0)
            . $suffix;
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

    private function lockPath(string $jobUuid): string
    {
        $dir = dirname(__DIR__, 2) . '/var/sync_bulk_push';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $dir = sys_get_temp_dir();
        }

        return $dir . '/job-' . preg_replace('/[^a-zA-Z0-9\-]/', '', $jobUuid) . '.lock';
    }
}
