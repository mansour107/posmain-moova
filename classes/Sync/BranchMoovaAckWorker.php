<?php

if (!class_exists('SyncBranchIdentity')) {
    require_once __DIR__ . '/BranchIdentity.php';
}
if (!class_exists('CloudAuthService')) {
    require_once __DIR__ . '/CloudAuthService.php';
}
if (!class_exists('MoovaInboundQueueService')) {
    require_once __DIR__ . '/MoovaInboundQueueService.php';
}

class BranchMoovaAckWorker
{
    private MoovaInboundQueueService $inboundQueue;

    public function __construct(?MoovaInboundQueueService $inboundQueue = null)
    {
        $this->inboundQueue = $inboundQueue ?: new MoovaInboundQueueService();
    }

    public function runOnce(mysqli $conn, array $config = [], array $options = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $runUuid = $this->uuid();
        $metrics = [
            'worker' => 'moova_ack',
            'run_uuid' => $runUuid,
            'candidates' => 0,
            'posted' => 0,
            'acked' => 0,
            'failed' => 0,
            'skipped' => null,
        ];

        $this->logWorker($conn, $runUuid, 'started', 'Moova ack worker started', $metrics);

        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            $metrics['skipped'] = 'not_branch_role';
            $this->logWorker($conn, $runUuid, 'success', 'Moova ack worker skipped outside branch role', $metrics);
            return $metrics;
        }

        if (empty($config['sync']['moova_poller_enabled'])) {
            $metrics['skipped'] = 'moova_poller_disabled';
            $this->logWorker($conn, $runUuid, 'success', 'Moova ack worker disabled by config', $metrics);
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
        $rows = $this->inboundQueue->pendingCloudAckRows($conn, [
            'branch_uuid' => $branchUuid,
            'pos_tenant' => $identity['pos_tenant'] ?? 0,
            'pos_branch' => $identity['pos_branch'] ?? 0,
        ], $limit);

        $metrics['candidates'] = count($rows);
        if (!$rows) {
            $this->logWorker($conn, $runUuid, 'success', 'No Moova cloud acks pending', $metrics);
            return $metrics;
        }

        $ackByInboundId = [];
        $acks = [];
        foreach ($rows as $row) {
            $ackStatus = $this->ackStatusForInboundStatus((string) $row['status']);
            $ackByInboundId[(int) $row['id']] = [
                'event_uuid' => (string) $row['event_uuid'],
                'idempotency_key' => (string) $row['idempotency_key'],
                'ack_status' => $ackStatus,
            ];
            $acks[] = [
                'event_uuid' => (string) $row['event_uuid'],
                'idempotency_key' => (string) $row['idempotency_key'],
                'ack_status' => $ackStatus,
                'message' => $row['error_message'] ?: null,
            ];
        }

        $body = json_encode([
            'branch_uuid' => $branchUuid,
            'acks' => $acks,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            throw new RuntimeException('Unable to encode Moova ack body.');
        }

        $response = $this->postAcks($cloudBaseUrl, $branchUuid, $branchSecret, $body, $config, $options);
        if (!$response['ok'] || !is_array($response['json']) || empty($response['json']['ok'])) {
            $error = $response['error'] ?: 'Moova ack cloud response unavailable';
            foreach (array_keys($ackByInboundId) as $inboundId) {
                $this->inboundQueue->markCloudAckResult($conn, (int) $inboundId, 'failed', $error);
                $metrics['failed']++;
            }
            $this->logWorker($conn, $runUuid, 'failed', $error, $metrics);
            return $metrics + ['http_status' => $response['status'], 'error' => $error];
        }

        $metrics['posted'] = count($acks);
        $resultsByKey = [];
        foreach (($response['json']['acks'] ?? []) as $ackResult) {
            if (!is_array($ackResult)) {
                continue;
            }
            $key = (string) ($ackResult['event_uuid'] ?? '') . "\n" . (string) ($ackResult['idempotency_key'] ?? '');
            $resultsByKey[$key] = $ackResult;
        }

        foreach ($ackByInboundId as $inboundId => $ack) {
            $key = $ack['event_uuid'] . "\n" . $ack['idempotency_key'];
            $remote = $resultsByKey[$key] ?? null;
            if ($remote && !empty($remote['acknowledged'])) {
                $this->inboundQueue->markCloudAckResult($conn, (int) $inboundId, $ack['ack_status'], null);
                $metrics['acked']++;
                continue;
            }

            $message = is_array($remote)
                ? (string) ($remote['message'] ?? 'cloud did not acknowledge event')
                : 'cloud ack result missing';
            $this->inboundQueue->markCloudAckResult($conn, (int) $inboundId, 'failed', $message);
            $metrics['failed']++;
        }

        $status = $metrics['failed'] > 0 ? 'failed' : 'success';
        $this->logWorker($conn, $runUuid, $status, 'Moova ack batch finished', $metrics);

        return $metrics + [
            'http_status' => $response['status'],
        ];
    }

    private function ackStatusForInboundStatus(string $status): string
    {
        if ($status === 'applied') {
            return 'ack_applied';
        }
        if ($status === 'declined') {
            return 'ack_declined';
        }

        return 'ack_failed';
    }

    private function postAcks(
        string $cloudBaseUrl,
        string $branchUuid,
        string $branchSecret,
        string $body,
        array $config,
        array $options
    ): array {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-POSMAIN-Branch-UUID: ' . $branchUuid,
            'X-POSMAIN-Timestamp: ' . $timestamp,
            'X-POSMAIN-Nonce: ' . $nonce,
            'X-POSMAIN-Signature: ' . CloudAuthService::sign($branchSecret, $timestamp, $nonce, $body),
        ];
        $url = $this->ackUrl($cloudBaseUrl);

        if (isset($options['http_post']) && is_callable($options['http_post'])) {
            return $options['http_post']($url, $body, $headers, $config);
        }

        if (function_exists('curl_init')) {
            return $this->postWithCurl($url, $body, $headers, $config);
        }

        return $this->postWithStreams($url, $body, $headers, $config);
    }

    private function ackUrl(string $cloudBaseUrl): string
    {
        return preg_match('#/api/moova/ack_branch_events\.php$#', $cloudBaseUrl)
            ? $cloudBaseUrl
            : rtrim($cloudBaseUrl, '/') . '/api/moova/ack_branch_events.php';
    }

    private function postWithCurl(string $url, string $body, array $headers, array $config): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
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

    private function postWithStreams(string $url, string $body, array $headers, array $config): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
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

    private function logWorker(mysqli $conn, string $runUuid, string $status, string $message, array $metrics): void
    {
        try {
            $metricsJson = json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $workerName = 'moova_ack';
            $stmt = $conn->prepare("
                INSERT INTO sync_worker_logs (worker_name, run_uuid, status, message, metrics_json)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('sssss', $workerName, $runUuid, $status, $message, $metricsJson);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $ignored) {
        }
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
