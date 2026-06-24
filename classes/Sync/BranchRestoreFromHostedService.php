<?php

require_once __DIR__ . '/CloudAuthService.php';
require_once __DIR__ . '/CloudBranchRestoreEventService.php';
require_once __DIR__ . '/BranchRestoreEventApplyService.php';
require_once __DIR__ . '/RestoreEventPhase.php';
require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/BranchImageSyncService.php';
require_once __DIR__ . '/ItemImageSyncQueueService.php';
require_once __DIR__ . '/SyncRuntimeSettings.php';

class BranchRestoreFromHostedService
{
    private BranchRestoreEventApplyService $applyService;

    public function __construct(?BranchRestoreEventApplyService $applyService = null)
    {
        $this->applyService = $applyService ?: new BranchRestoreEventApplyService();
    }

    public static function localNeedsRestore(mysqli $conn): bool
    {
        $items = (int) ($conn->query("SELECT COUNT(*) AS c FROM myitems WHERE isdeleted = 0")->fetch_assoc()['c'] ?? 0);
        if ($items > 0) {
            return false;
        }

        if (self::tableExists($conn, 'ot_head')) {
            $orders = (int) ($conn->query('SELECT COUNT(*) AS c FROM ot_head')->fetch_assoc()['c'] ?? 0);
            if ($orders > 0) {
                return false;
            }
        }

        return true;
    }

    public function restore(mysqli $conn, array $config = [], array $options = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $apply = !empty($options['apply']);
        $limit = max(1, min(100, (int) ($options['limit'] ?? 50)));
        $phases = $options['phases'] ?? RestoreEventPhase::all();
        if (!is_array($phases) || $phases === []) {
            $phases = RestoreEventPhase::all();
        }

        $identity = (new SyncBranchIdentity())->ensure($conn, $config);
        $branchUuid = strtolower(trim((string) ($identity['branch_uuid'] ?? '')));
        $cloudBaseUrl = rtrim(trim((string) ($identity['cloud_base_url'] ?? ($config['branch']['cloud_base_url'] ?? ''))), '/');
        $branchSecret = (string) ($config['sync']['branch_secret'] ?? '');

        if ($branchUuid === '' || $cloudBaseUrl === '' || $branchSecret === '') {
            throw new InvalidArgumentException('Branch UUID, cloud base URL, and sync secret are required to restore from hosted.');
        }

        $summary = [
            'apply' => $apply,
            'branch_uuid' => $branchUuid,
            'cloud_base_url' => $cloudBaseUrl,
            'phases' => [],
            'fetched' => 0,
            'mirrored' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $source = 'auto';
        foreach ($phases as $phaseName) {
            $phase = RestoreEventPhase::normalize((string) $phaseName);
            $phaseSummary = [
                'phase' => $phase,
                'source' => null,
                'pages' => 0,
                'fetched' => 0,
                'mirrored' => 0,
                'skipped' => 0,
                'failed' => 0,
            ];
            $afterId = 0;
            $maxPages = max(0, (int) ($options['max_pages_per_phase'] ?? 0));

            do {
                $response = $this->fetchPage(
                    $cloudBaseUrl,
                    $branchUuid,
                    $branchSecret,
                    $phase,
                    $afterId,
                    $limit,
                    $source,
                    $config,
                    $options
                );

                if (!$response['ok'] || !is_array($response['json']) || empty($response['json']['ok'])) {
                    $reason = is_array($response['json']) ? (string) ($response['json']['reason'] ?? 'export_failed') : 'export_failed';
                    throw new RuntimeException('Hosted restore export failed: ' . $reason);
                }

                $page = $response['json'];
                if ($phaseSummary['source'] === null) {
                    $phaseSummary['source'] = (string) ($page['source'] ?? 'auto');
                    $source = (string) ($page['source'] ?? $source);
                }

                $phaseSummary['pages']++;
                $events = is_array($page['events'] ?? null) ? $page['events'] : [];

                foreach ($events as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $event = $entry['event'] ?? null;
                    if (!is_array($event)) {
                        $phaseSummary['skipped']++;
                        $summary['skipped']++;
                        continue;
                    }

                    $phaseSummary['fetched']++;
                    $summary['fetched']++;

                    if (!$apply) {
                        $phaseSummary['mirrored']++;
                        $summary['mirrored']++;
                        continue;
                    }

                    try {
                        $conn->begin_transaction();
                        $result = $this->applyService->apply($conn, $branchUuid, $event);
                        $conn->commit();
                        if ($result) {
                            $phaseSummary['mirrored']++;
                            $summary['mirrored']++;
                        } else {
                            $phaseSummary['skipped']++;
                            $summary['skipped']++;
                        }
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $phaseSummary['failed']++;
                        $summary['failed']++;
                        if (count($summary['errors']) < 10) {
                            $summary['errors'][] = [
                                'phase' => $phase,
                                'restore_id' => $entry['restore_id'] ?? null,
                                'message' => $e->getMessage(),
                            ];
                        }
                    }
                }

                $afterId = max($afterId, (int) ($page['next_after_id'] ?? $afterId));
                $hasMore = !empty($page['has_more']);
                if ($maxPages > 0 && $phaseSummary['pages'] >= $maxPages) {
                    $hasMore = false;
                }
            } while ($hasMore);

            $summary['phases'][] = $phaseSummary;
        }

        if ($apply) {
            $this->recordRestoreCheckpoint($conn, $branchUuid, $summary);
            $summary['images'] = $this->startBackgroundImageDownload($conn, $config, $branchUuid);
        }

        return $summary;
    }

    private function fetchPage(
        string $cloudBaseUrl,
        string $branchUuid,
        string $branchSecret,
        string $phase,
        int $afterId,
        int $limit,
        string $source,
        array $config,
        array $options
    ): array {
        $signatureBody = CloudBranchRestoreEventService::exportSignatureBody(
            $branchUuid,
            $phase,
            $afterId,
            $limit,
            $source
        );
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $headers = [
            'Accept: application/json',
            'X-POSMAIN-Branch-UUID: ' . $branchUuid,
            'X-POSMAIN-Timestamp: ' . $timestamp,
            'X-POSMAIN-Nonce: ' . $nonce,
            'X-POSMAIN-Signature: ' . CloudAuthService::sign($branchSecret, $timestamp, $nonce, $signatureBody),
        ];
        $url = $this->exportUrl($cloudBaseUrl, $branchUuid, $phase, $afterId, $limit, $source);

        if (isset($options['http_get']) && is_callable($options['http_get'])) {
            return $options['http_get']($url, $headers, $config);
        }

        if (function_exists('curl_init')) {
            return $this->getWithCurl($url, $headers, $config);
        }

        return $this->getWithStreams($url, $headers, $config);
    }

    private function exportUrl(
        string $cloudBaseUrl,
        string $branchUuid,
        string $phase,
        int $afterId,
        int $limit,
        string $source
    ): string {
        $base = preg_match('#/api/sync/export_branch_restore\.php$#', $cloudBaseUrl)
            ? $cloudBaseUrl
            : rtrim($cloudBaseUrl, '/') . '/api/sync/export_branch_restore.php';

        return $base . '?' . http_build_query([
            'branch_uuid' => $branchUuid,
            'phase' => $phase,
            'after_id' => max(0, $afterId),
            'limit' => max(1, min(100, $limit)),
            'source' => $source !== '' ? $source : 'auto',
        ]);
    }

    private function getWithCurl(string $url, array $headers, array $config): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => max(5, (int) ($config['sync']['http_timeout_seconds'] ?? 30)),
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'status' => $status, 'error' => $error !== '' ? $error : 'curl_failed'];
        }

        $json = json_decode($body, true);

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'json' => is_array($json) ? $json : null,
            'body' => $body,
        ];
    }

    private function getWithStreams(string $url, array $headers, array $config): array
    {
        $headerLines = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headerLines . "\r\n",
                'timeout' => max(5, (int) ($config['sync']['http_timeout_seconds'] ?? 30)),
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', (string) $http_response_header[0], $matches)) {
            $status = (int) $matches[1];
        }
        $json = is_string($body) ? json_decode($body, true) : null;

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'json' => is_array($json) ? $json : null,
            'body' => is_string($body) ? $body : '',
        ];
    }

    private function recordRestoreCheckpoint(mysqli $conn, string $branchUuid, array $summary): void
    {
        if (!self::tableExists($conn, 'sync_checkpoints')) {
            return;
        }

        $payload = json_encode([
            'restored_at' => gmdate('c'),
            'mirrored' => (int) ($summary['mirrored'] ?? 0),
            'failed' => (int) ($summary['failed'] ?? 0),
        ], JSON_UNESCAPED_SLASHES);

        $stream = 'branch_restore_from_hosted';
        $stmt = $conn->prepare("
            INSERT INTO sync_checkpoints (branch_uuid, stream_name, last_cursor, last_event_time)
            VALUES (?, ?, ?, NOW(6))
            ON DUPLICATE KEY UPDATE
                last_cursor = VALUES(last_cursor),
                last_event_time = NOW(6)
        ");
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('sss', $branchUuid, $stream, $payload);
        $stmt->execute();
        $stmt->close();
    }

    private function startBackgroundImageDownload(mysqli $conn, array $config, string $branchUuid): array
    {
        if (empty($config['sync']['image_sync_enabled'])) {
            return ['enabled' => false];
        }

        $queueService = new ItemImageSyncQueueService();
        $queued = $queueService->scanBranchDownloadQueue($conn, $branchUuid);
        $counts = $queueService->countByStatus($conn, $branchUuid, 'cloud_to_branch');
        $pending = (int) ($counts['pending'] ?? 0) + (int) ($counts['failed'] ?? 0);
        $spawned = false;
        if ($pending > 0) {
            $spawned = (new BranchImageSyncService())->spawnBackgroundWorker();
        }

        return [
            'enabled' => true,
            'queued' => $queued,
            'pending' => $pending,
            'spawned' => $spawned,
        ];
    }

    private static function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        $exists = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }

        return $exists;
    }
}
