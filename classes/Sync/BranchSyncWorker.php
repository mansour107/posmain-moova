<?php

class BranchSyncWorker
{
    private OutboxWorker $outboxWorker;
    private SyncHttpClient $httpClient;

    public function __construct(?OutboxWorker $outboxWorker = null, ?SyncHttpClient $httpClient = null)
    {
        $this->outboxWorker = $outboxWorker ?: new OutboxWorker();
        $this->httpClient = $httpClient ?: new SyncHttpClient();
    }

    public function runOnce(mysqli $conn, array $config = [], array $options = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $runUuid = $this->uuid();
        $workerId = (string) ($options['worker_id'] ?? (gethostname() . '-' . getmypid() . '-' . substr($runUuid, 0, 8)));
        $metrics = [
            'worker_id' => $workerId,
            'run_uuid' => $runUuid,
            'claimed' => 0,
            'synced' => 0,
            'failed' => 0,
            'dead' => 0,
            'skipped' => null,
        ];

        $this->logWorker($conn, $runUuid, 'started', 'sync worker started', $metrics);

        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            $metrics['skipped'] = 'not_branch_role';
            $this->logWorker($conn, $runUuid, 'success', 'sync worker skipped outside branch role', $metrics);
            return $metrics;
        }

        if (empty($config['sync']['branch_sync_enabled']) || empty($config['sync']['worker_enabled'])) {
            $metrics['skipped'] = 'worker_disabled';
            $this->logWorker($conn, $runUuid, 'success', 'sync worker disabled by config', $metrics);
            return $metrics;
        }

        $identity = (new SyncBranchIdentity())->ensure($conn, $config);
        $branchUuid = (string) $identity['branch_uuid'];
        $cloudBaseUrl = (string) ($identity['cloud_base_url'] ?: ($config['branch']['cloud_base_url'] ?? ''));
        $branchSecret = (string) ($config['sync']['branch_secret'] ?? '');

        if ($cloudBaseUrl === '' || $branchSecret === '') {
            $metrics['skipped'] = 'cloud_url_or_secret_missing';
            $this->logWorker($conn, $runUuid, 'failed', 'missing cloud URL or branch sync secret', $metrics);
            return $metrics;
        }

        $batchSize = max(1, (int) ($options['batch_size'] ?? 50));
        $lockSeconds = max(1, (int) ($options['lock_seconds'] ?? 120));
        $claimed = $this->outboxWorker->claimBatch($conn, $workerId, $batchSize, $lockSeconds, $branchUuid);
        $metrics['claimed'] = count($claimed);
        if (!$claimed) {
            $this->logWorker($conn, $runUuid, 'success', 'no outbox rows to sync', $metrics);
            return $metrics;
        }

        $body = $this->buildPayload($claimed, $branchUuid, $runUuid);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $signature = CloudAuthService::sign($branchSecret, $timestamp, $nonce, $body);
        $headers = [
            'Content-Type: application/json',
            'X-POSMAIN-Branch-UUID: ' . $branchUuid,
            'X-POSMAIN-Timestamp: ' . $timestamp,
            'X-POSMAIN-Nonce: ' . $nonce,
            'X-POSMAIN-Signature: ' . $signature,
        ];

        $response = $this->postBatch($this->receiveUrl($cloudBaseUrl), $body, $headers, $config, $options);
        if (!$response['ok'] || !is_array($response['json'])) {
            $error = $response['error'] ?: 'cloud response unavailable';
            $this->markRowsFailed($conn, $claimed, $error);
            $metrics['failed'] = count($claimed);
            $this->logWorker($conn, $runUuid, 'failed', $error, $metrics);
            return $metrics + ['http_status' => $response['status'], 'error' => $error];
        }

        $this->applyCloudResults($conn, $claimed, $response['json']['results'] ?? [], $metrics);
        $status = $metrics['failed'] > 0 || $metrics['dead'] > 0 ? 'failed' : 'success';
        $this->logWorker($conn, $runUuid, $status, 'sync worker batch finished', $metrics);

        return $metrics + ['http_status' => $response['status'], 'mode' => $response['json']['mode'] ?? null];
    }

    private function buildPayload(array $rows, string $branchUuid, string $runUuid): string
    {
        $events = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            $events[] = [
                'event_uuid' => (string) $row['event_uuid'],
                'idempotency_key' => (string) $row['idempotency_key'],
                'payload_hash' => (string) $row['payload_hash'],
                'event_type' => (string) $row['event_type'],
                'event_version' => (int) $row['event_version'],
                'source_system' => (string) $row['source_system'],
                'aggregate_type' => (string) $row['aggregate_type'],
                'aggregate_uuid' => $row['aggregate_uuid'],
                'aggregate_id' => $row['aggregate_id'] ?? null,
                'entity_type' => (string) $row['entity_type'],
                'entity_uuid' => $row['entity_uuid'],
                'payload' => is_array($payload) ? $payload : ['raw' => (string) $row['payload_json']],
            ];
        }

        return json_encode([
            'schema_version' => 1,
            'branch_uuid' => $branchUuid,
            'sent_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'batch_uuid' => $runUuid,
            'events' => $events,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function postBatch(string $url, string $body, array $headers, array $config, array $options): array
    {
        if (isset($options['http_post']) && is_callable($options['http_post'])) {
            return $options['http_post']($url, $body, $headers, $config);
        }

        return $this->httpClient->postJson(
            $url,
            $body,
            $headers,
            (int) ($config['sync']['http_connect_timeout_ms'] ?? 1000),
            (int) ($config['sync']['http_timeout_ms'] ?? 5000)
        );
    }

    private function applyCloudResults(mysqli $conn, array $rows, array $results, array &$metrics): void
    {
        $byKey = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $key = (string) ($result['event_uuid'] ?? '') . '|' . (string) ($result['idempotency_key'] ?? '');
            $byKey[$key] = $result;
        }

        foreach ($rows as $row) {
            $key = (string) $row['event_uuid'] . '|' . (string) $row['idempotency_key'];
            $result = $byKey[$key] ?? null;
            if (!$result) {
                $this->markRowFailed($conn, (int) $row['id'], 'cloud response missing event result', (int) $row['attempts']);
                $metrics['failed']++;
                continue;
            }

            $outboxStatus = SyncDeliveryResultHandler::outboxStatusForResult($result);
            if ($outboxStatus === 'synced') {
                $this->markRowSynced($conn, (int) $row['id']);
                $metrics['synced']++;
            } elseif ($outboxStatus === 'dead') {
                $this->markRowDead($conn, (int) $row['id'], (string) ($result['message'] ?? 'conflict'));
                $metrics['dead']++;
            } else {
                $this->markRowFailed($conn, (int) $row['id'], (string) ($result['message'] ?? 'cloud returned failed'), (int) $row['attempts']);
                $metrics['failed']++;
            }
        }
    }

    private function markRowsFailed(mysqli $conn, array $rows, string $error): void
    {
        foreach ($rows as $row) {
            $this->markRowFailed($conn, (int) $row['id'], $error, (int) $row['attempts']);
        }
    }

    private function markRowSynced(mysqli $conn, int $id): void
    {
        $stmt = $conn->prepare("
            UPDATE sync_outbox
            SET status = 'synced',
                locked_by = NULL,
                locked_until = NULL,
                next_retry_at = NULL,
                last_error = NULL,
                synced_at = NOW(6)
            WHERE id = ?
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    private function markRowDead(mysqli $conn, int $id, string $error): void
    {
        $stmt = $conn->prepare("
            UPDATE sync_outbox
            SET status = 'dead',
                locked_by = NULL,
                locked_until = NULL,
                last_error = ?
            WHERE id = ?
        ");
        $stmt->bind_param('si', $error, $id);
        $stmt->execute();
        $stmt->close();
    }

    private function markRowFailed(mysqli $conn, int $id, string $error, int $attempts): void
    {
        $retryDelaySeconds = $this->retryDelaySeconds($attempts);
        $stmt = $conn->prepare("
            UPDATE sync_outbox
            SET status = 'failed',
                locked_by = NULL,
                locked_until = NULL,
                last_error = ?,
                next_retry_at = DATE_ADD(NOW(6), INTERVAL {$retryDelaySeconds} SECOND)
            WHERE id = ?
        ");
        $stmt->bind_param('si', $error, $id);
        $stmt->execute();
        $stmt->close();
    }

    private function retryDelaySeconds(int $attempts): int
    {
        if ($attempts <= 1) {
            return 10;
        }
        if ($attempts === 2) {
            return 30;
        }
        if ($attempts === 3) {
            return 120;
        }
        if ($attempts === 4) {
            return 300;
        }
        if ($attempts === 5) {
            return 900;
        }

        return 3600;
    }

    private function receiveUrl(string $cloudBaseUrl): string
    {
        if (preg_match('#/api/sync/receive_branch_events\.php$#', $cloudBaseUrl)) {
            return $cloudBaseUrl;
        }

        return rtrim($cloudBaseUrl, '/') . '/api/sync/receive_branch_events.php';
    }

    private function logWorker(mysqli $conn, string $runUuid, string $status, string $message, array $metrics): void
    {
        try {
            $workerName = 'sync_worker';
            $metricsJson = json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $conn->prepare("
                INSERT INTO sync_worker_logs (worker_name, run_uuid, status, message, metrics_json)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('sssss', $workerName, $runUuid, $status, $message, $metricsJson);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            // Worker logs must never block cashier outbox delivery.
        }
    }

    private function uuid(): string
    {
        if (class_exists('SyncBranchIdentity')) {
            return SyncBranchIdentity::generateUuidV4();
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
