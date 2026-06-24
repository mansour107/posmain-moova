<?php

require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/CloudAuthService.php';
require_once __DIR__ . '/ItemImagePathService.php';
require_once __DIR__ . '/ItemImageSyncQueueService.php';
require_once __DIR__ . '/CloudBranchImageExportService.php';
require_once __DIR__ . '/SyncHttpClient.php';

class BranchImageSyncService
{
    private ItemImageSyncQueueService $queueService;
    private SyncHttpClient $httpClient;

    public function __construct(?ItemImageSyncQueueService $queueService = null, ?SyncHttpClient $httpClient = null)
    {
        $this->queueService = $queueService ?: new ItemImageSyncQueueService();
        $this->httpClient = $httpClient ?: new SyncHttpClient();
    }

    public function throttleConfig(array $config): array
    {
        return [
            'max_files_per_run' => max(1, min(10, (int) ($config['sync']['image_sync_max_files_per_run'] ?? 3))),
            'max_bytes_per_run' => max(262144, min(52428800, (int) ($config['sync']['image_sync_max_bytes_per_run'] ?? 5242880))),
            'delay_ms' => max(0, min(5000, (int) ($config['sync']['image_sync_delay_ms'] ?? 300))),
            'lock_seconds' => max(60, (int) ($config['sync']['image_sync_lock_seconds'] ?? 180)),
            'connect_timeout_ms' => max(1000, (int) ($config['sync']['http_connect_timeout_ms'] ?? 1000)),
            'timeout_ms' => max(10000, (int) ($config['sync']['image_sync_http_timeout_ms'] ?? 60000)),
        ];
    }

    public function isEnabled(array $config): bool
    {
        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            return false;
        }

        return !empty($config['sync']['image_sync_enabled']) && !empty($config['sync']['branch_sync_enabled']);
    }

    public function runUploadBatch(mysqli $conn, array $config, array $options = []): array
    {
        return $this->runDirectionBatch($conn, $config, 'branch_to_cloud', $options);
    }

    public function runDownloadBatch(mysqli $conn, array $config, array $options = []): array
    {
        return $this->runDirectionBatch($conn, $config, 'cloud_to_branch', $options);
    }

    public function runDirectionBatch(mysqli $conn, array $config, string $direction, array $options = []): array
    {
        $metrics = [
            'direction' => $direction,
            'claimed' => 0,
            'synced' => 0,
            'failed' => 0,
            'bytes' => 0,
            'skipped' => null,
        ];

        if (!$this->isEnabled($config)) {
            $metrics['skipped'] = 'image_sync_disabled';

            return $metrics;
        }

        $identity = (new SyncBranchIdentity())->ensure($conn, $config);
        $branchUuid = strtolower(trim((string) ($identity['branch_uuid'] ?? '')));
        $cloudBaseUrl = rtrim(trim((string) ($identity['cloud_base_url'] ?? ($config['branch']['cloud_base_url'] ?? ''))), '/');
        $branchSecret = (string) ($config['sync']['branch_secret'] ?? '');
        if ($branchUuid === '' || $cloudBaseUrl === '' || $branchSecret === '') {
            $metrics['skipped'] = 'cloud_credentials_missing';

            return $metrics;
        }

        $this->queueService->releaseStaleLocks($conn);
        $throttle = $this->throttleConfig($config);
        $workerId = (string) ($options['worker_id'] ?? (gethostname() . '-' . getmypid() . '-img'));
        $claimed = $this->queueService->claimBatch(
            $conn,
            $branchUuid,
            $direction,
            $workerId,
            $throttle['max_files_per_run'],
            $throttle['lock_seconds']
        );
        $metrics['claimed'] = count($claimed);
        if ($claimed === []) {
            return $metrics;
        }

        $bytesBudget = $throttle['max_bytes_per_run'];
        foreach ($claimed as $row) {
            if ($bytesBudget <= 0) {
                $this->releaseClaim($conn, (int) $row['id']);
                continue;
            }

            $fileSize = max(0, (int) ($row['file_size'] ?? 0));
            if ($fileSize > $bytesBudget) {
                $this->releaseClaim($conn, (int) $row['id']);
                continue;
            }

            try {
                if ($direction === 'branch_to_cloud') {
                    $result = $this->uploadOne($conn, $cloudBaseUrl, $branchUuid, $branchSecret, $row, $config, $throttle);
                } else {
                    $result = $this->downloadOne($conn, $cloudBaseUrl, $branchUuid, $branchSecret, $row, $config, $throttle);
                }

                if (!empty($result['ok'])) {
                    $this->queueService->markSynced($conn, (int) $row['id'], $result['sha256'] ?? null);
                    $metrics['synced']++;
                    $metrics['bytes'] += (int) ($result['bytes'] ?? $fileSize);
                    $bytesBudget -= (int) ($result['bytes'] ?? $fileSize);
                } else {
                    $this->queueService->markFailed($conn, (int) $row['id'], (string) ($result['error'] ?? 'sync_failed'));
                    $metrics['failed']++;
                }
            } catch (Throwable $e) {
                $this->queueService->markFailed($conn, (int) $row['id'], $e->getMessage());
                $metrics['failed']++;
            }

            if ($throttle['delay_ms'] > 0) {
                usleep($throttle['delay_ms'] * 1000);
            }
        }

        return $metrics;
    }

    public function spawnBackgroundWorker(int $shopId = 0, string $shopDbName = ''): bool
    {
        $projectRoot = ItemImagePathService::projectRoot();
        $script = $projectRoot . '/cli/sync_image_worker.php';
        if (!is_file($script)) {
            return false;
        }

        $php = PHP_BINARY;
        if (!is_file($php)) {
            $php = 'php';
        }

        $logDir = $projectRoot . '/var/sync_image_worker';
        if (!is_dir($logDir) && !@mkdir($logDir, 0750, true) && !is_dir($logDir)) {
            $logDir = $projectRoot . '/logs';
        }

        $command = escapeshellarg($php)
            . ' ' . escapeshellarg($script)
            . ' --loop --max-runtime=1800 --sleep=8';
        if ($shopId > 0) {
            $command .= ' --shop-id=' . (int) $shopId;
        }
        if (trim($shopDbName) !== '') {
            $command .= ' --shop-db=' . escapeshellarg(trim($shopDbName));
        }
        $logFile = $logDir . '/image-worker-' . gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.log';
        $command .= ' >> ' . escapeshellarg($logFile) . ' 2>&1';

        if (DIRECTORY_SEPARATOR === '\\') {
            $command = 'start /B "" ' . $command;
        } else {
            $command = $command . ' &';
        }

        @exec($command);

        return true;
    }

    private function uploadOne(
        mysqli $conn,
        string $cloudBaseUrl,
        string $branchUuid,
        string $branchSecret,
        array $row,
        array $config,
        array $throttle
    ): array {
        $fileName = ItemImagePathService::sanitizeFileName((string) ($row['file_name'] ?? ''));
        if ($fileName === null) {
            return ['ok' => false, 'error' => 'invalid_file_name'];
        }

        $absolutePath = ItemImagePathService::absolutePath($fileName);
        if ($absolutePath === null) {
            return ['ok' => false, 'error' => 'local_file_missing'];
        }

        $sha256 = ItemImagePathService::fileSha256($absolutePath);
        if ($sha256 === null) {
            return ['ok' => false, 'error' => 'hash_failed'];
        }

        $metadata = json_encode([
            'branch_uuid' => $branchUuid,
            'imgs_id' => (int) ($row['imgs_id'] ?? 0),
            'item_id' => (int) ($row['item_id'] ?? 0),
            'file_name' => $fileName,
            'file_size' => (int) filesize($absolutePath),
            'file_sha256' => $sha256,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metadata)) {
            return ['ok' => false, 'error' => 'metadata_encode_failed'];
        }

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $signature = CloudAuthService::sign($branchSecret, $timestamp, $nonce, $metadata);
        $headers = [
            'X-POSMAIN-Branch-UUID: ' . $branchUuid,
            'X-POSMAIN-Timestamp: ' . $timestamp,
            'X-POSMAIN-Nonce: ' . $nonce,
            'X-POSMAIN-Signature: ' . $signature,
        ];

        $url = rtrim($cloudBaseUrl, '/') . '/api/sync/receive_branch_image.php';
        $response = $this->httpClient->postMultipart(
            $url,
            ['metadata' => $metadata],
            ['file' => $absolutePath],
            $headers,
            $throttle['connect_timeout_ms'],
            $throttle['timeout_ms']
        );

        if (!$response['ok'] || !is_array($response['json']) || empty($response['json']['ok'])) {
            $reason = is_array($response['json']) ? (string) ($response['json']['reason'] ?? $response['json']['message'] ?? 'upload_failed') : (string) ($response['error'] ?? 'upload_failed');

            return ['ok' => false, 'error' => $reason];
        }

        return [
            'ok' => true,
            'sha256' => $sha256,
            'bytes' => (int) filesize($absolutePath),
        ];
    }

    private function downloadOne(
        mysqli $conn,
        string $cloudBaseUrl,
        string $branchUuid,
        string $branchSecret,
        array $row,
        array $config,
        array $throttle
    ): array {
        $fileName = ItemImagePathService::sanitizeFileName((string) ($row['file_name'] ?? ''));
        if ($fileName === null) {
            return ['ok' => false, 'error' => 'invalid_file_name'];
        }

        $signatureBody = CloudBranchImageExportService::exportSignatureBody($branchUuid, $fileName);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $signature = CloudAuthService::sign($branchSecret, $timestamp, $nonce, $signatureBody);
        $headers = [
            'Accept: application/octet-stream',
            'X-POSMAIN-Branch-UUID: ' . $branchUuid,
            'X-POSMAIN-Timestamp: ' . $timestamp,
            'X-POSMAIN-Nonce: ' . $nonce,
            'X-POSMAIN-Signature: ' . $signature,
        ];

        $url = rtrim($cloudBaseUrl, '/') . '/api/sync/export_branch_image.php?'
            . http_build_query([
                'branch_uuid' => $branchUuid,
                'file_name' => $fileName,
            ]);

        $response = $this->httpClient->get(
            $url,
            $headers,
            $throttle['connect_timeout_ms'],
            $throttle['timeout_ms']
        );

        if (!$response['ok'] || $response['body'] === '') {
            $reason = is_array($response['json']) ? (string) ($response['json']['reason'] ?? 'download_failed') : (string) ($response['error'] ?? 'download_failed');

            return ['ok' => false, 'error' => $reason];
        }

        $uploadsDir = ItemImagePathService::uploadsDir();
        if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
            return ['ok' => false, 'error' => 'uploads_dir_unavailable'];
        }

        $target = $uploadsDir . '/' . $fileName;
        $bytes = strlen((string) $response['body']);
        if ($bytes <= 0 || $bytes > ItemImagePathService::maxUploadBytes($config)) {
            return ['ok' => false, 'error' => 'invalid_download_size'];
        }

        if (@file_put_contents($target, $response['body']) === false) {
            return ['ok' => false, 'error' => 'write_failed'];
        }

        $sha256 = ItemImagePathService::fileSha256($target);
        $expected = trim((string) ($row['file_sha256'] ?? ''));
        if ($expected !== '' && $sha256 !== null && !hash_equals($expected, $sha256)) {
            @unlink($target);

            return ['ok' => false, 'error' => 'hash_mismatch'];
        }

        return [
            'ok' => true,
            'sha256' => $sha256,
            'bytes' => $bytes,
        ];
    }

    private function releaseClaim(mysqli $conn, int $queueId): void
    {
        $stmt = $conn->prepare("
            UPDATE sync_image_queue
            SET status = 'pending',
                locked_until = NULL,
                locked_by = NULL,
                updated_at = UTC_TIMESTAMP(6)
            WHERE id = ?
        ");
        $stmt->bind_param('i', $queueId);
        $stmt->execute();
        $stmt->close();
    }
}
