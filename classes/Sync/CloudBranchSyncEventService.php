<?php

require_once __DIR__ . '/CloudAuthService.php';
require_once __DIR__ . '/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/CloudBranchSyncEventCursor.php';

class CloudBranchSyncEventService
{
    public function handleBranchEvents(mysqli $conn, array $headers, array $query, array $config = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        if (!$this->isCloudRole($config)) {
            return $this->response(403, ['ok' => false, 'reason' => 'invalid_role']);
        }

        $branchUuid = $this->branchUuid($headers, $query);
        if ($branchUuid === '') {
            return $this->response(400, ['ok' => false, 'reason' => 'branch_uuid_required']);
        }

        $afterCursor = max(0, (int) ($query['after_cursor'] ?? 0));
        $limit = max(1, min(100, (int) ($query['limit'] ?? 25)));
        $signatureBody = self::branchEventsSignatureBody($branchUuid, $afterCursor, $limit);
        $auth = $this->verify($conn, $config, $headers, $branchUuid, $signatureBody);
        if (!$auth['ok']) {
            return $this->response(401, ['ok' => false, 'reason' => $auth['reason']]);
        }

        $cursor = new CloudBranchSyncEventCursor();
        $rows = $cursor->fetchPendingAfter($conn, $branchUuid, $afterCursor, $limit);
        $events = [];
        $nextCursor = $afterCursor;
        foreach ($rows as $row) {
            $events[] = $this->eventResponse($row);
            $nextCursor = max($nextCursor, (int) $row['cursor']);
        }

        return $this->response(200, [
            'ok' => true,
            'branch_uuid' => $branchUuid,
            'after_cursor' => $afterCursor,
            'next_cursor' => $nextCursor,
            'count' => count($events),
            'events' => $events,
        ]);
    }

    public function handleAck(mysqli $conn, array $headers, string $rawBody, array $config = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        if (!$this->isCloudRole($config)) {
            return $this->response(403, ['ok' => false, 'reason' => 'invalid_role']);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->response(400, ['ok' => false, 'reason' => 'invalid_json']);
        }

        $branchUuid = $this->branchUuid($headers, $payload);
        if ($branchUuid === '') {
            return $this->response(400, ['ok' => false, 'reason' => 'branch_uuid_required']);
        }

        $acks = $payload['acks'] ?? $payload['events'] ?? null;
        if (!is_array($acks)) {
            return $this->response(400, ['ok' => false, 'reason' => 'acks_required']);
        }

        $auth = $this->verify($conn, $config, $headers, $branchUuid, $rawBody);
        if (!$auth['ok']) {
            return $this->response(401, ['ok' => false, 'reason' => $auth['reason']]);
        }

        $cursor = new CloudBranchSyncEventCursor();
        $results = [];
        foreach ($acks as $ack) {
            if (!is_array($ack)) {
                continue;
            }

            $eventUuid = trim((string) ($ack['event_uuid'] ?? ''));
            $idempotencyKey = trim((string) ($ack['idempotency_key'] ?? ''));
            $ackStatus = trim((string) ($ack['ack_status'] ?? ($ack['status'] ?? '')));
            $error = $this->nullableString($ack['error'] ?? ($ack['message'] ?? null));

            if ($eventUuid === '' || $idempotencyKey === '' || $ackStatus === '') {
                $results[] = [
                    'event_uuid' => $eventUuid,
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'invalid',
                    'acknowledged' => false,
                    'message' => 'event_uuid, idempotency_key, and ack_status are required',
                ];
                continue;
            }

            try {
                $affected = $cursor->ackByEventForBranch($conn, $branchUuid, $eventUuid, $idempotencyKey, $ackStatus, $error);
                $results[] = [
                    'event_uuid' => $eventUuid,
                    'idempotency_key' => $idempotencyKey,
                    'status' => $ackStatus,
                    'acknowledged' => $affected > 0,
                    'message' => $affected > 0 ? 'ack stored' : 'event not found for branch',
                ];
            } catch (InvalidArgumentException $e) {
                $results[] = [
                    'event_uuid' => $eventUuid,
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'invalid',
                    'acknowledged' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $this->response(200, [
            'ok' => true,
            'branch_uuid' => $branchUuid,
            'acks' => $results,
        ]);
    }

    public static function branchEventsSignatureBody(string $branchUuid, int $afterCursor, int $limit): string
    {
        return json_encode([
            'branch_uuid' => $branchUuid,
            'after_cursor' => max(0, $afterCursor),
            'limit' => max(1, min(100, $limit)),
        ], JSON_UNESCAPED_SLASHES);
    }

    public static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }

        return $headers;
    }

    private function verify(mysqli $conn, array $config, array $headers, string $branchUuid, string $signatureBody): array
    {
        $provider = DatabaseBranchSecretProvider::fromConfig($conn, $config);
        $auth = (new CloudAuthService())->verifyRequest(
            $provider,
            $branchUuid,
            $this->header($headers, ['x-posmain-timestamp', 'x-timestamp']),
            $this->header($headers, ['x-posmain-nonce', 'x-nonce']),
            $signatureBody,
            $this->header($headers, ['x-posmain-signature', 'x-signature'])
        );

        if ($auth['ok']) {
            $provider->touchLastSeen($branchUuid);
        }

        return $auth;
    }

    private function eventResponse(array $row): array
    {
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        return [
            'cursor' => (int) ($row['cursor'] ?? $row['id'] ?? 0),
            'event_uuid' => (string) ($row['event_uuid'] ?? ''),
            'idempotency_key' => (string) ($row['idempotency_key'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'event_version' => (int) ($row['event_version'] ?? 1),
            'source_system' => (string) ($row['source_system'] ?? 'cloud_pos'),
            'aggregate_type' => (string) ($row['aggregate_type'] ?? ''),
            'aggregate_uuid' => $this->nullableString($row['aggregate_uuid'] ?? null),
            'aggregate_local_id' => $row['aggregate_local_id'] === null ? null : (int) $row['aggregate_local_id'],
            'aggregate_id' => $this->nullableString($row['aggregate_id'] ?? null),
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'entity_uuid' => $this->nullableString($row['entity_uuid'] ?? null),
            'entity_local_id' => $row['entity_local_id'] === null ? null : (int) $row['entity_local_id'],
            'payload_hash' => (string) ($row['payload_hash'] ?? ''),
            'payload' => $payload,
        ];
    }

    private function isCloudRole(array $config): bool
    {
        return in_array((string) ($config['role'] ?? 'branch'), ['cloud', 'fake_cloud'], true);
    }

    private function branchUuid(array $headers, array $payloadOrQuery): string
    {
        $branchUuid = $this->header($headers, ['x-posmain-branch-uuid', 'x-branch-uuid']);
        if ($branchUuid !== '') {
            return $branchUuid;
        }

        return trim((string) ($payloadOrQuery['branch_uuid'] ?? ''));
    }

    private function header(array $headers, array $names): string
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }

        foreach ($names as $name) {
            if (isset($normalized[$name])) {
                return trim($normalized[$name]);
            }
        }

        return '';
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function response(int $statusCode, array $body): array
    {
        return [
            'status_code' => $statusCode,
            'body' => $body,
        ];
    }
}
