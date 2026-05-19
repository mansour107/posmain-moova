<?php

if (!class_exists('SyncBranchIdentity')) {
    require_once __DIR__ . '/BranchIdentity.php';
}
if (!class_exists('CloudAuthService')) {
    require_once __DIR__ . '/CloudAuthService.php';
}
if (!class_exists('CloudBranchSyncEventService')) {
    require_once __DIR__ . '/CloudBranchSyncEventService.php';
}
if (!class_exists('CloudLegacyPosMirrorService')) {
    require_once __DIR__ . '/CloudLegacyPosMirrorService.php';
}

class BranchCloudSyncPollWorker
{
    private const STREAM_NAME = 'cloud_sync';

    private CloudLegacyPosMirrorService $legacyMirror;

    public function __construct(?CloudLegacyPosMirrorService $legacyMirror = null)
    {
        $this->legacyMirror = $legacyMirror ?: new CloudLegacyPosMirrorService();
    }

    public function runOnce(mysqli $conn, array $config = [], array $options = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $runUuid = $this->uuid();
        $metrics = [
            'worker' => 'cloud_sync_poller',
            'run_uuid' => $runUuid,
            'fetched' => 0,
            'applied' => 0,
            'stale' => 0,
            'duplicates' => 0,
            'unsupported' => 0,
            'acked' => 0,
            'failed' => 0,
            'checkpoint' => null,
            'skipped' => null,
        ];

        $this->logWorker($conn, $runUuid, 'started', 'Cloud sync poller started', $metrics);

        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            $metrics['skipped'] = 'not_branch_role';
            $this->logWorker($conn, $runUuid, 'success', 'Cloud sync poller skipped outside branch role', $metrics);
            return $metrics;
        }

        if (
            empty($config['sync']['branch_sync_enabled'])
            || empty($config['sync']['worker_enabled'])
            || empty($config['sync']['cloud_pull_enabled'])
        ) {
            $metrics['skipped'] = 'cloud_pull_disabled';
            $this->logWorker($conn, $runUuid, 'success', 'Cloud sync poller disabled by config', $metrics);
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
            $error = $response['error'] ?: 'Cloud sync response unavailable';
            $this->logWorker($conn, $runUuid, 'failed', $error, $metrics);
            return $metrics + ['http_status' => $response['status'], 'error' => $error];
        }

        $events = $response['json']['events'] ?? [];
        if (!is_array($events)) {
            $events = [];
        }

        $metrics['fetched'] = count($events);
        if (!$events) {
            $metrics['checkpoint'] = $afterCursor;
            $this->logWorker($conn, $runUuid, 'success', 'No cloud sync events pending', $metrics);
            return $metrics + ['http_status' => $response['status']];
        }

        $acks = [];
        $lastProcessedCursor = $afterCursor;
        foreach ($events as $event) {
            if (!is_array($event)) {
                $metrics['failed']++;
                break;
            }

            $cursor = max(0, (int) ($event['cursor'] ?? 0));
            $event['branch_uuid'] = $branchUuid;

            try {
                $result = $this->recordAndApply($conn, $branchUuid, $event);
            } catch (Throwable $e) {
                $metrics['failed']++;
                $this->logWorker($conn, $runUuid, 'failed', $e->getMessage(), $metrics);
                break;
            }

            $status = (string) ($result['status'] ?? 'failed');
            if ($status === 'applied') {
                $metrics['applied']++;
            } elseif ($status === 'stale') {
                $metrics['stale']++;
            } elseif ($status === 'duplicate') {
                $metrics['duplicates']++;
            } elseif ($status === 'unsupported') {
                $metrics['unsupported']++;
            } else {
                $metrics['failed']++;
            }

            $acks[] = [
                'event_uuid' => (string) ($event['event_uuid'] ?? ''),
                'idempotency_key' => (string) ($event['idempotency_key'] ?? ''),
                'ack_status' => (string) ($result['ack_status'] ?? 'ack_failed'),
                'message' => $result['message'] ?? null,
            ];
            $lastProcessedCursor = max($lastProcessedCursor, $cursor);
        }

        if ($acks) {
            $ackResponse = $this->postAcks($cloudBaseUrl, $branchUuid, $branchSecret, $acks, $config, $options);
            if (!$ackResponse['ok'] || !is_array($ackResponse['json']) || empty($ackResponse['json']['ok'])) {
                $metrics['failed']++;
                $error = $ackResponse['error'] ?: 'Cloud sync ack response unavailable';
                $this->logWorker($conn, $runUuid, 'failed', $error, $metrics);
                return $metrics + ['http_status' => $response['status'], 'ack_http_status' => $ackResponse['status'], 'error' => $error];
            }

            $ackResults = $ackResponse['json']['acks'] ?? [];
            foreach ($ackResults as $ackResult) {
                if (is_array($ackResult) && !empty($ackResult['acknowledged'])) {
                    $metrics['acked']++;
                }
            }

            if ($metrics['acked'] === count($acks)) {
                $this->updateCheckpoint($conn, $branchUuid, $lastProcessedCursor);
            } else {
                $metrics['failed']++;
            }
        }

        $metrics['checkpoint'] = $metrics['acked'] === count($acks) ? $lastProcessedCursor : $afterCursor;
        $status = $metrics['failed'] > 0 ? 'failed' : 'success';
        $this->logWorker($conn, $runUuid, $status, 'Cloud sync poller batch finished', $metrics);

        return $metrics + [
            'http_status' => $response['status'],
        ];
    }

    private function recordAndApply(mysqli $conn, string $branchUuid, array $event): array
    {
        $eventUuid = trim((string) ($event['event_uuid'] ?? ''));
        $idempotencyKey = trim((string) ($event['idempotency_key'] ?? ''));
        if ($eventUuid === '' || $idempotencyKey === '') {
            throw new InvalidArgumentException('event_uuid and idempotency_key are required.');
        }

        $payloadHash = $this->payloadHash($event);
        $payloadJson = $this->encodeJson($event);
        $sourceSystem = $this->stringOrDefault($event['source_system'] ?? null, 'cloud_pos', 40);

        $conn->begin_transaction();
        try {
            $existing = $this->findInboxForUpdate($conn, $branchUuid, $idempotencyKey);
            if ($existing && (string) $existing['payload_hash'] !== $payloadHash) {
                $result = $this->applyResult($eventUuid, $idempotencyKey, 'failed', 'ack_failed', 'idempotency hash mismatch');
                $this->insertConflict($conn, $branchUuid, $event, (string) $existing['payload_json'], $payloadJson);
                $this->updateInboxResult($conn, (int) $existing['id'], 'conflict', $result, 'idempotency hash mismatch');
                $conn->commit();
                return $result;
            }

            if ($existing && in_array((string) $existing['status'], ['processed', 'duplicate'], true)) {
                $conn->commit();
                return $this->applyResult($eventUuid, $idempotencyKey, 'duplicate', 'ack_applied', 'duplicate cloud event');
            }

            $inboxId = $existing ? (int) $existing['id'] : $this->insertInbox($conn, $branchUuid, $eventUuid, $sourceSystem, $idempotencyKey, $payloadHash, $payloadJson);
            if ($existing) {
                $this->updateInboxResult($conn, $inboxId, 'processing', [], null);
            }

            $mirror = $this->legacyMirror->mirrorFromBranchEvent($conn, $branchUuid, $event);
            if (!$mirror) {
                $result = $this->applyResult($eventUuid, $idempotencyKey, 'unsupported', 'ack_declined', 'unsupported cloud sync event type');
                $this->updateInboxResult($conn, $inboxId, 'processed', $result, null);
                $conn->commit();
                return $result;
            }

            if (!empty($mirror['stale'])) {
                $result = $this->applyResult($eventUuid, $idempotencyKey, 'stale', 'ack_declined', 'local value is newer than cloud event');
                $this->updateInboxResult($conn, $inboxId, 'processed', $result + ['mirror' => $mirror], null);
                $conn->commit();
                return $result;
            }

            $result = $this->applyResult(
                $eventUuid,
                $idempotencyKey,
                'applied',
                'ack_applied',
                'cloud event applied to local legacy POS'
            ) + ['mirror' => $mirror];
            $this->updateInboxResult($conn, $inboxId, 'processed', $result, null);
            $conn->commit();

            return $result;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
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
        $signatureBody = CloudBranchSyncEventService::branchEventsSignatureBody($branchUuid, $afterCursor, $limit);
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

    private function postAcks(
        string $cloudBaseUrl,
        string $branchUuid,
        string $branchSecret,
        array $acks,
        array $config,
        array $options
    ): array {
        $body = $this->encodeJson([
            'branch_uuid' => $branchUuid,
            'acks' => $acks,
        ]);
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

    private function branchEventsUrl(string $cloudBaseUrl, string $branchUuid, int $afterCursor, int $limit): string
    {
        $base = preg_match('#/api/sync/branch_events\.php$#', $cloudBaseUrl)
            ? $cloudBaseUrl
            : rtrim($cloudBaseUrl, '/') . '/api/sync/branch_events.php';

        return $base . '?' . http_build_query([
            'branch_uuid' => $branchUuid,
            'after_cursor' => $afterCursor,
            'limit' => $limit,
        ]);
    }

    private function ackUrl(string $cloudBaseUrl): string
    {
        return preg_match('#/api/sync/ack_branch_events\.php$#', $cloudBaseUrl)
            ? $cloudBaseUrl
            : rtrim($cloudBaseUrl, '/') . '/api/sync/ack_branch_events.php';
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

    private function findInboxForUpdate(mysqli $conn, string $branchUuid, string $idempotencyKey): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM sync_inbox
            WHERE branch_uuid = ?
              AND direction = 'cloud_to_branch'
              AND idempotency_key = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('ss', $branchUuid, $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function insertInbox(
        mysqli $conn,
        string $branchUuid,
        string $eventUuid,
        string $sourceSystem,
        string $idempotencyKey,
        string $payloadHash,
        string $payloadJson
    ): int {
        $stmt = $conn->prepare("
            INSERT INTO sync_inbox (
                event_uuid,
                branch_uuid,
                direction,
                source_system,
                idempotency_key,
                payload_hash,
                payload_json,
                status
            ) VALUES (?, ?, 'cloud_to_branch', ?, ?, ?, ?, 'processing')
        ");
        $stmt->bind_param('ssssss', $eventUuid, $branchUuid, $sourceSystem, $idempotencyKey, $payloadHash, $payloadJson);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        return $id;
    }

    private function updateInboxResult(mysqli $conn, int $inboxId, string $status, array $result, ?string $errorMessage): void
    {
        $resultJson = $this->encodeJson($result);
        $processedAtSql = in_array($status, ['processed', 'failed', 'duplicate', 'conflict', 'dead'], true) ? 'NOW(6)' : 'NULL';

        $stmt = $conn->prepare("
            UPDATE sync_inbox
            SET status = ?,
                result_json = ?,
                error_message = ?,
                processed_at = {$processedAtSql}
            WHERE id = ?
        ");
        $stmt->bind_param('sssi', $status, $resultJson, $errorMessage, $inboxId);
        $stmt->execute();
        $stmt->close();
    }

    private function insertConflict(
        mysqli $conn,
        string $branchUuid,
        array $event,
        string $localPayloadJson,
        string $remotePayloadJson
    ): void {
        $aggregateType = $this->nullableString($event['aggregate_type'] ?? null);
        $aggregateUuid = $this->nullableString($event['aggregate_uuid'] ?? null);
        $remoteEntityId = $this->nullableString($event['event_uuid'] ?? null);
        $entityLocalId = $this->intOrNull($event['entity_local_id'] ?? $event['aggregate_local_id'] ?? null);

        $stmt = $conn->prepare("
            INSERT INTO sync_conflicts (
                branch_uuid,
                conflict_type,
                aggregate_type,
                aggregate_uuid,
                local_entity_id,
                remote_entity_id,
                local_payload_json,
                remote_payload_json,
                resolution_status
            ) VALUES (?, 'cloud_idempotency_hash_mismatch', ?, ?, ?, ?, ?, ?, 'open')
        ");
        $stmt->bind_param(
            'sssisss',
            $branchUuid,
            $aggregateType,
            $aggregateUuid,
            $entityLocalId,
            $remoteEntityId,
            $localPayloadJson,
            $remotePayloadJson
        );
        $stmt->execute();
        $stmt->close();
    }

    private function applyResult(string $eventUuid, string $idempotencyKey, string $status, string $ackStatus, string $message): array
    {
        return [
            'event_uuid' => $eventUuid,
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
            'ack_status' => $ackStatus,
            'message' => $message,
        ];
    }

    private function payloadHash(array $event): string
    {
        $hash = trim((string) ($event['payload_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }

        return hash('sha256', $this->encodeJson($event['payload'] ?? $event));
    }

    private function logWorker(mysqli $conn, string $runUuid, string $status, string $message, array $metrics): void
    {
        try {
            $workerName = 'cloud_sync_poller';
            $metricsJson = json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $stmt = $conn->prepare("
                INSERT INTO sync_worker_logs (worker_name, run_uuid, status, message, metrics_json)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('sssss', $workerName, $runUuid, $status, $message, $metricsJson);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            // Worker logs must never block cloud-to-branch recovery.
        }
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode cloud sync JSON.');
        }

        return $json;
    }

    private function stringOrDefault($value, string $default, int $maxLength): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            $value = $default;
        }

        return substr($value, 0, $maxLength);
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function intOrNull($value): ?int
    {
        if ($value === null || $value === false || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
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
