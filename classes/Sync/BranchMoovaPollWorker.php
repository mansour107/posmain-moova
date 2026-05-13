<?php

if (!class_exists('MoovaLocalIngestService')) {
    require_once __DIR__ . '/../Moova/MoovaLocalIngestService.php';
}

class BranchMoovaPollWorker
{
    private const STREAM_NAME = 'moova_orders';

    private MoovaLocalIngestService $localIngest;

    public function __construct(?MoovaInboundQueueService $inboundQueue = null, ?MoovaLocalIngestService $localIngest = null)
    {
        $this->localIngest = $localIngest ?: new MoovaLocalIngestService($inboundQueue);
    }

    public function runOnce(mysqli $conn, array $config = [], array $options = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $runUuid = $this->uuid();
        $metrics = [
            'worker' => 'moova_poller',
            'run_uuid' => $runUuid,
            'fetched' => 0,
            'recorded' => 0,
            'duplicates' => 0,
            'conflicts' => 0,
            'failed' => 0,
            'checkpoint' => null,
            'ack_deferred' => 0,
            'skipped' => null,
        ];

        $this->logWorker($conn, $runUuid, 'started', 'Moova poller started', $metrics);

        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            $metrics['skipped'] = 'not_branch_role';
            $this->logWorker($conn, $runUuid, 'success', 'Moova poller skipped outside branch role', $metrics);
            return $metrics;
        }

        if (empty($config['sync']['moova_poller_enabled'])) {
            $metrics['skipped'] = 'moova_poller_disabled';
            $this->logWorker($conn, $runUuid, 'success', 'Moova poller disabled by config', $metrics);
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

        $limit = max(1, min(100, (int) ($options['batch_size'] ?? 25)));
        $afterCursor = $this->checkpoint($conn, $branchUuid);
        $response = $this->getEvents($cloudBaseUrl, $branchUuid, $afterCursor, $limit, $branchSecret, $config, $options);
        if (!$response['ok'] || !is_array($response['json']) || empty($response['json']['ok'])) {
            $metrics['failed'] = 1;
            $error = $response['error'] ?: 'Moova cloud response unavailable';
            $this->logWorker($conn, $runUuid, 'failed', $error, $metrics);
            return $metrics + ['http_status' => $response['status'], 'error' => $error];
        }

        $events = $response['json']['events'] ?? [];
        if (!is_array($events)) {
            $events = [];
        }

        $metrics['fetched'] = count($events);
        $lastSafeCursor = $afterCursor;
        foreach ($events as $event) {
            if (!is_array($event)) {
                $metrics['failed']++;
                break;
            }

            $cursor = max(0, (int) ($event['cursor'] ?? 0));
            try {
                $result = $this->recordWithLocalIngest($conn, $event, $identity, $branchUuid);
            } catch (Throwable $e) {
                $metrics['failed']++;
                $this->logWorker($conn, $runUuid, 'failed', $e->getMessage(), $metrics);
                break;
            }

            if ($result['status'] === 'received') {
                $metrics['recorded']++;
                $lastSafeCursor = max($lastSafeCursor, $cursor);
                continue;
            }

            if ($result['status'] === 'duplicate') {
                $metrics['duplicates']++;
                $lastSafeCursor = max($lastSafeCursor, $cursor);
                continue;
            }

            if ($result['status'] === 'conflict') {
                $metrics['conflicts']++;
                $metrics['failed']++;
                break;
            }

            $metrics['failed']++;
            break;
        }

        if ($lastSafeCursor > $afterCursor) {
            $this->updateCheckpoint($conn, $branchUuid, $lastSafeCursor);
        }

        $metrics['checkpoint'] = $lastSafeCursor;
        $metrics['ack_deferred'] = $metrics['recorded'] + $metrics['duplicates'];
        $status = $metrics['failed'] > 0 || $metrics['conflicts'] > 0 ? 'failed' : 'success';
        $this->logWorker($conn, $runUuid, $status, 'Moova poller batch finished', $metrics);

        return $metrics + [
            'http_status' => $response['status'],
            'next_cursor' => $response['json']['next_cursor'] ?? $lastSafeCursor,
        ];
    }

    private function recordWithLocalIngest(mysqli $conn, array $event, array $identity, string $branchUuid): array
    {
        $eventType = (string) ($event['event_type'] ?? '');
        $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
        foreach ([
            'event_uuid',
            'idempotency_key',
            'moova_order_id',
            'moova_branch_id',
            'provider_order_id',
            'provider_reference_id',
            'revision',
        ] as $key) {
            if (array_key_exists($key, $event) && !array_key_exists($key, $payload)) {
                $payload[$key] = $event[$key];
            }
        }
        $payload['event_type'] = $eventType;

        $ctx = [
            'branch_uuid' => $branchUuid,
            'pos_tenant' => $identity['pos_tenant'] ?? 0,
            'pos_branch' => $identity['pos_branch'] ?? 0,
            'delivery_path' => 'poller',
            'moova_branch_id' => $payload['moova_branch_id'] ?? null,
        ];

        if ($eventType === 'new_order') {
            return $this->localIngest->ingestNewOrder($conn, $payload, $ctx);
        }

        return $this->localIngest->ingestChange($conn, $payload, $ctx);
    }

    private function getEvents(
        string $cloudBaseUrl,
        string $branchUuid,
        int $afterCursor,
        int $limit,
        string $branchSecret,
        array $config,
        array $options
    ): array {
        $signatureBody = CloudMoovaEventService::branchEventsSignatureBody($branchUuid, $afterCursor, $limit);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $headers = [
            'Accept: application/json',
            'X-POSMAIN-Branch-UUID: ' . $branchUuid,
            'X-POSMAIN-Timestamp: ' . $timestamp,
            'X-POSMAIN-Nonce: ' . $nonce,
            'X-POSMAIN-Signature: ' . CloudAuthService::sign($branchSecret, $timestamp, $nonce, $signatureBody),
        ];
        $url = $this->branchEventsUrl($cloudBaseUrl, $branchUuid, $afterCursor, $limit);

        if (isset($options['http_get']) && is_callable($options['http_get'])) {
            return $options['http_get']($url, $headers, $config);
        }

        if (function_exists('curl_init')) {
            return $this->getWithCurl($url, $headers, $config);
        }

        return $this->getWithStreams($url, $headers, $config);
    }

    private function branchEventsUrl(string $cloudBaseUrl, string $branchUuid, int $afterCursor, int $limit): string
    {
        $base = preg_match('#/api/moova/branch_events\.php$#', $cloudBaseUrl)
            ? $cloudBaseUrl
            : rtrim($cloudBaseUrl, '/') . '/api/moova/branch_events.php';

        return $base . '?' . http_build_query([
            'branch_uuid' => $branchUuid,
            'after_cursor' => $afterCursor,
            'limit' => $limit,
        ]);
    }

    private function getWithCurl(string $url, array $headers, array $config): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) ($config['sync']['http_connect_timeout_ms'] ?? 1000)),
            CURLOPT_TIMEOUT_MS => max(1, (int) ($config['sync']['http_timeout_ms'] ?? 5000)),
        ]);

        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $this->formatResponse($responseBody, $status, $error);
    }

    private function getWithStreams(string $url, array $headers, array $config): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => max(1, ((int) ($config['sync']['http_timeout_ms'] ?? 5000)) / 1000),
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $status = 0;
        $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : [];
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $match)) {
            $status = (int) $match[1];
        }

        return $this->formatResponse($responseBody, $status, $responseBody === false ? 'HTTP request failed' : '');
    }

    private function formatResponse($responseBody, int $status, string $error): array
    {
        $json = is_string($responseBody) ? json_decode($responseBody, true) : null;

        return [
            'ok' => $responseBody !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $responseBody === false ? '' : (string) $responseBody,
            'json' => is_array($json) ? $json : null,
            'error' => $responseBody === false ? $error : ($status >= 200 && $status < 300 ? '' : (string) $responseBody),
        ];
    }

    private function checkpoint(mysqli $conn, string $branchUuid): int
    {
        $stmt = $conn->prepare("
            SELECT last_cursor
            FROM sync_checkpoints
            WHERE branch_uuid = ?
              AND stream_name = ?
            LIMIT 1
        ");
        $stream = self::STREAM_NAME;
        $stmt->bind_param('ss', $branchUuid, $stream);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return max(0, (int) ($row['last_cursor'] ?? 0));
    }

    private function updateCheckpoint(mysqli $conn, string $branchUuid, int $cursor): void
    {
        $stream = self::STREAM_NAME;
        $cursorValue = (string) $cursor;
        $stmt = $conn->prepare("
            INSERT INTO sync_checkpoints (branch_uuid, stream_name, last_cursor, last_event_time)
            VALUES (?, ?, ?, NOW(6))
            ON DUPLICATE KEY UPDATE
                last_cursor = VALUES(last_cursor),
                last_event_time = VALUES(last_event_time)
        ");
        $stmt->bind_param('sss', $branchUuid, $stream, $cursorValue);
        $stmt->execute();
        $stmt->close();
    }

    private function logWorker(mysqli $conn, string $runUuid, string $status, string $message, array $metrics): void
    {
        try {
            $workerName = 'moova_poller';
            $metricsJson = json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $conn->prepare("
                INSERT INTO sync_worker_logs (worker_name, run_uuid, status, message, metrics_json)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('sssss', $workerName, $runUuid, $status, $message, $metricsJson);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            // Worker logs must never block Moova event recovery.
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
