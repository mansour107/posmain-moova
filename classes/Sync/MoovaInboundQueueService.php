<?php

class MoovaInboundQueueService
{
    private const RESULT_STATUSES = ['applied', 'declined', 'failed'];

    public function recordPollerEvent(mysqli $conn, array $event, array $ctx): array
    {
        return $this->record($conn, $event, $ctx + ['delivery_path' => 'poller']);
    }

    public function record(mysqli $conn, array $event, array $ctx): array
    {
        $normalized = $this->normalize($event, $ctx);

        $conn->begin_transaction();
        try {
            $existing = $this->findExistingForUpdate(
                $conn,
                $normalized['pos_tenant'],
                $normalized['pos_branch'],
                $normalized['idempotency_key'],
                $normalized['event_uuid']
            );

            if ($existing) {
                if ((string) $existing['request_hash'] === $normalized['request_hash']) {
                    $result = [
                        'status' => 'duplicate',
                        'recorded' => false,
                        'inbound_event_id' => (int) $existing['id'],
                        'event_uuid' => $normalized['event_uuid'],
                        'idempotency_key' => $normalized['idempotency_key'],
                        'message' => 'duplicate Moova inbound event',
                    ];
                    $conn->commit();
                    return $result;
                }

                $this->insertConflict($conn, $normalized, (string) $existing['payload_json']);
                $conn->commit();
                return [
                    'status' => 'conflict',
                    'recorded' => false,
                    'inbound_event_id' => (int) $existing['id'],
                    'event_uuid' => $normalized['event_uuid'],
                    'idempotency_key' => $normalized['idempotency_key'],
                    'message' => 'Moova inbound idempotency hash mismatch',
                ];
            }

            $stmt = $conn->prepare("
                INSERT INTO moova_pos_inbound_events (
                    event_uuid,
                    moova_order_id,
                    moova_branch_id,
                    pos_tenant,
                    pos_branch,
                    branch_uuid,
                    idempotency_key,
                    request_hash,
                    event_type,
                    delivery_path,
                    payload_json,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'received')
            ");
            $stmt->bind_param(
                'sssiissssss',
                $normalized['event_uuid'],
                $normalized['moova_order_id'],
                $normalized['moova_branch_id'],
                $normalized['pos_tenant'],
                $normalized['pos_branch'],
                $normalized['branch_uuid'],
                $normalized['idempotency_key'],
                $normalized['request_hash'],
                $normalized['event_type'],
                $normalized['delivery_path'],
                $normalized['payload_json']
            );
            $stmt->execute();
            $id = (int) $conn->insert_id;
            $stmt->close();

            $conn->commit();
            return [
                'status' => 'received',
                'recorded' => true,
                'inbound_event_id' => $id,
                'event_uuid' => $normalized['event_uuid'],
                'idempotency_key' => $normalized['idempotency_key'],
                'message' => 'Moova inbound event recorded',
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    public function claimPending(mysqli $conn, array $ctx, array $options = []): array
    {
        $scope = $this->normalizeScope($ctx);
        $workerName = $this->nullableString($options['worker_name'] ?? null, 100) ?: 'moova-inbound-worker';
        $limit = $this->boundedPositiveInt($options['limit'] ?? 25, 1, 100);
        $lockTtlSeconds = $this->boundedPositiveInt($options['lock_ttl_seconds'] ?? 60, 5, 3600);
        $eventTypes = $this->normalizeEventTypeFilter($options['event_types'] ?? null);

        $conn->begin_transaction();
        try {
            $ids = $this->selectClaimableIdsForUpdate($conn, $scope, $limit, $eventTypes);
            if (!$ids) {
                $conn->commit();
                return [];
            }

            $idList = implode(',', array_map('intval', $ids));
            $stmt = $conn->prepare("
                UPDATE moova_pos_inbound_events
                   SET status = 'processing',
                       locked_by = ?,
                       locked_until = DATE_ADD(NOW(6), INTERVAL ? SECOND),
                       attempt_count = attempt_count + 1,
                       last_attempt_at = NOW(6),
                       notified_at = CASE WHEN notified_at IS NULL THEN NOW(6) ELSE notified_at END
                 WHERE id IN ({$idList})
            ");
            $stmt->bind_param('si', $workerName, $lockTtlSeconds);
            $stmt->execute();
            $stmt->close();

            $rows = $this->fetchRowsByIds($conn, $ids);
            $conn->commit();

            return $rows;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    public function markProcessingResult(mysqli $conn, int $id, string $status, array $result, array $options = []): array
    {
        $conn->begin_transaction();
        try {
            $updated = $this->markProcessingResultInTransaction($conn, $id, $status, $result, $options);
            $conn->commit();

            return $updated;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    public function markProcessingResultInTransaction(mysqli $conn, int $id, string $status, array $result, array $options = []): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('inbound event id must be positive.');
        }

        if (!in_array($status, self::RESULT_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid Moova inbound result status.');
        }

        $resultJson = $this->encodeJson($result);
        $posOrderId = isset($options['pos_order_id']) && is_numeric($options['pos_order_id'])
            ? (int) $options['pos_order_id']
            : null;
        $posOrderUuid = $this->nullableString($options['pos_order_uuid'] ?? null, 36);
        $errorMessage = $this->nullableString($options['error_message'] ?? null, 2000);

        $stmt = $conn->prepare("
            SELECT id
            FROM moova_pos_inbound_events
            WHERE id = ?
              AND status = 'processing'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('Moova inbound event must be claimed before marking a processing result.');
        }

        $stmt = $conn->prepare("
            UPDATE moova_pos_inbound_events
               SET status = ?,
                   pos_order_id = ?,
                   pos_order_uuid = ?,
                   result_json = ?,
                   error_message = ?,
                   locked_by = NULL,
                   locked_until = NULL,
                   applied_at = CASE WHEN ? IN ('applied','declined') THEN NOW(6) ELSE applied_at END
             WHERE id = ?
        ");
        $stmt->bind_param(
            'sissssi',
            $status,
            $posOrderId,
            $posOrderUuid,
            $resultJson,
            $errorMessage,
            $status,
            $id
        );
        $stmt->execute();
        $stmt->close();

        return $this->fetchRowById($conn, $id);
    }

    public function pendingCloudAckRows(mysqli $conn, array $ctx, int $limit = 25): array
    {
        $scope = $this->normalizeScope($ctx);
        $limit = $this->boundedPositiveInt($limit, 1, 100);
        $posTenant = $scope['pos_tenant'];
        $posBranch = $scope['pos_branch'];
        $branchUuid = $scope['branch_uuid'];

        $stmt = $conn->prepare("
            SELECT *
            FROM moova_pos_inbound_events
            WHERE pos_tenant = ?
              AND pos_branch = ?
              AND branch_uuid = ?
              AND status IN ('applied','declined','failed')
              AND (cloud_ack_status IS NULL OR cloud_ack_status = 'failed')
            ORDER BY COALESCE(applied_at, last_attempt_at, received_at) ASC, id ASC
            LIMIT ?
        ");
        $stmt->bind_param('iisi', $posTenant, $posBranch, $branchUuid, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->hydrateInboundRow($row);
        }
        $stmt->close();

        return $rows;
    }

    public function markCloudAckResult(mysqli $conn, int $id, string $cloudAckStatus, ?string $errorMessage = null): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('inbound event id must be positive.');
        }

        if (!in_array($cloudAckStatus, ['ack_applied', 'ack_declined', 'ack_failed', 'failed'], true)) {
            throw new InvalidArgumentException('Invalid Moova cloud ack status.');
        }

        $errorMessage = $this->nullableString($errorMessage, 2000);
        $acknowledged = $cloudAckStatus === 'failed' ? 0 : 1;

        $stmt = $conn->prepare("
            UPDATE moova_pos_inbound_events
               SET cloud_ack_status = ?,
                   cloud_ack_error = ?,
                   cloud_ack_attempt_count = cloud_ack_attempt_count + 1,
                   cloud_ack_last_attempt_at = NOW(6),
                   cloud_acknowledged_at = CASE WHEN ? = 1 THEN NOW(6) ELSE cloud_acknowledged_at END
             WHERE id = ?
               AND status IN ('applied','declined','failed')
        ");
        $stmt->bind_param('ssii', $cloudAckStatus, $errorMessage, $acknowledged, $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            throw new RuntimeException('Moova inbound event must be terminal before cloud ack can be marked.');
        }

        return $this->fetchRowById($conn, $id);
    }

    private function normalize(array $event, array $ctx): array
    {
        $eventUuid = $this->requiredString($event['event_uuid'] ?? null, 'event_uuid');
        $idempotencyKey = $this->requiredString($event['idempotency_key'] ?? null, 'idempotency_key');
        $eventType = $this->requiredString($event['event_type'] ?? null, 'event_type');
        if (!in_array($eventType, ['new_order', 'edit_order', 'cancel_order'], true)) {
            throw new InvalidArgumentException('Invalid Moova inbound event_type.');
        }

        $payload = $event['payload'] ?? $event;
        $payloadJson = $this->encodeJson($payload);
        $requestHash = $this->nullableString($event['payload_hash'] ?? null, 64);
        if ($requestHash === null) {
            $requestHash = hash('sha256', $payloadJson);
        }

        $deliveryPath = $this->nullableString($ctx['delivery_path'] ?? null, 20) ?: 'poller';
        if (!in_array($deliveryPath, ['widget', 'poller', 'manual', 'test'], true)) {
            throw new InvalidArgumentException('Invalid Moova inbound delivery_path.');
        }

        return [
            'event_uuid' => $eventUuid,
            'moova_order_id' => $this->requiredString($event['moova_order_id'] ?? ($payload['moova_order_id'] ?? null), 'moova_order_id'),
            'moova_branch_id' => $this->nullableString($event['moova_branch_id'] ?? ($payload['moova_branch_id'] ?? null), 191),
            'pos_tenant' => $this->intOrZero($ctx['pos_tenant'] ?? null),
            'pos_branch' => $this->intOrZero($ctx['pos_branch'] ?? null),
            'branch_uuid' => $this->nullableString($ctx['branch_uuid'] ?? ($event['branch_uuid'] ?? null), 36),
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'event_type' => $eventType,
            'delivery_path' => $deliveryPath,
            'payload_json' => $payloadJson,
        ];
    }

    private function findExistingForUpdate(
        mysqli $conn,
        int $posTenant,
        int $posBranch,
        string $idempotencyKey,
        string $eventUuid
    ): ?array {
        $stmt = $conn->prepare("
            SELECT *
            FROM moova_pos_inbound_events
            WHERE (pos_tenant = ? AND pos_branch = ? AND idempotency_key = ?)
               OR event_uuid = ?
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('iiss', $posTenant, $posBranch, $idempotencyKey, $eventUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function selectClaimableIdsForUpdate(mysqli $conn, array $scope, int $limit, array $eventTypes = []): array
    {
        $posTenant = $scope['pos_tenant'];
        $posBranch = $scope['pos_branch'];
        $branchUuid = $scope['branch_uuid'];
        $eventTypeSql = '';
        if ($eventTypes) {
            $quoted = array_map(static function (string $eventType): string {
                return "'" . $eventType . "'";
            }, $eventTypes);
            $eventTypeSql = ' AND event_type IN (' . implode(',', $quoted) . ')';
        }

        $stmt = $conn->prepare("
            SELECT id
            FROM moova_pos_inbound_events
            WHERE pos_tenant = ?
              AND pos_branch = ?
              AND branch_uuid = ?
              AND (
                    status = 'received'
                    OR (
                        status = 'processing'
                        AND locked_until IS NOT NULL
                        AND locked_until < NOW(6)
                    )
              )
              {$eventTypeSql}
            ORDER BY received_at ASC, id ASC
            LIMIT ?
            FOR UPDATE
        ");
        $stmt->bind_param('iisi', $posTenant, $posBranch, $branchUuid, $limit);
        $stmt->execute();

        $ids = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $stmt->close();

        return $ids;
    }

    private function fetchRowsByIds(mysqli $conn, array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $idList = implode(',', array_map('intval', $ids));
        $result = $conn->query("
            SELECT *
            FROM moova_pos_inbound_events
            WHERE id IN ({$idList})
            ORDER BY received_at ASC, id ASC
        ");

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $this->hydrateInboundRow($row);
        }

        return $rows;
    }

    private function fetchRowById(mysqli $conn, int $id): array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM moova_pos_inbound_events
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('Moova inbound event was not found after update.');
        }

        return $this->hydrateInboundRow($row);
    }

    private function hydrateInboundRow(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['pos_tenant'] = (int) $row['pos_tenant'];
        $row['pos_branch'] = (int) $row['pos_branch'];
        $row['pos_order_id'] = $row['pos_order_id'] === null ? null : (int) $row['pos_order_id'];
        $row['attempt_count'] = isset($row['attempt_count']) ? (int) $row['attempt_count'] : 0;
        $row['cloud_ack_attempt_count'] = isset($row['cloud_ack_attempt_count']) ? (int) $row['cloud_ack_attempt_count'] : 0;
        $row['payload'] = $this->decodeJson((string) $row['payload_json']);
        $row['result'] = $row['result_json'] === null ? null : $this->decodeJson((string) $row['result_json']);

        return $row;
    }

    private function insertConflict(mysqli $conn, array $event, string $existingPayloadJson): void
    {
        $remoteEntityId = $event['event_uuid'];
        $localPayloadJson = $existingPayloadJson;
        $remotePayloadJson = $event['payload_json'];
        $branchUuid = $event['branch_uuid'] ?: '00000000-0000-0000-0000-000000000000';
        $aggregateType = 'moova_order';

        $stmt = $conn->prepare("
            INSERT INTO sync_conflicts (
                branch_uuid,
                conflict_type,
                aggregate_type,
                remote_entity_id,
                local_payload_json,
                remote_payload_json,
                resolution_status
            ) VALUES (?, 'moova_inbound_idempotency_hash_mismatch', ?, ?, ?, ?, 'open')
        ");
        $stmt->bind_param('sssss', $branchUuid, $aggregateType, $remoteEntityId, $localPayloadJson, $remotePayloadJson);
        $stmt->execute();
        $stmt->close();
    }

    private function requiredString($value, string $field): string
    {
        $value = $this->nullableString($value, 191);
        if ($value === null) {
            throw new InvalidArgumentException($field . ' is required.');
        }

        return $value;
    }

    private function nullableString($value, int $maxLength): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return substr($value, 0, $maxLength);
    }

    private function intOrZero($value): int
    {
        if ($value === null || $value === false || $value === '' || !is_numeric($value)) {
            return 0;
        }

        return (int) $value;
    }

    private function boundedPositiveInt($value, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $min;
        }

        $value = (int) $value;
        if ($value < $min) {
            return $min;
        }

        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    private function normalizeScope(array $ctx): array
    {
        $scope = [
            'pos_tenant' => $this->intOrZero($ctx['pos_tenant'] ?? null),
            'pos_branch' => $this->intOrZero($ctx['pos_branch'] ?? null),
            'branch_uuid' => $this->nullableString($ctx['branch_uuid'] ?? null, 36),
        ];

        if ($scope['pos_tenant'] < 0 || $scope['pos_branch'] < 0 || $scope['branch_uuid'] === null) {
            throw new InvalidArgumentException('pos_tenant, pos_branch, and branch_uuid are required to claim Moova inbound events.');
        }

        return $scope;
    }

    private function normalizeEventTypeFilter($eventTypes): array
    {
        if ($eventTypes === null || $eventTypes === []) {
            return [];
        }

        if (!is_array($eventTypes)) {
            $eventTypes = [$eventTypes];
        }

        $normalized = [];
        foreach ($eventTypes as $eventType) {
            $eventType = trim((string) $eventType);
            if ($eventType === '') {
                continue;
            }
            if (!in_array($eventType, ['new_order', 'edit_order', 'cancel_order'], true)) {
                throw new InvalidArgumentException('Invalid Moova inbound event_type filter.');
            }
            $normalized[$eventType] = $eventType;
        }

        return array_values($normalized);
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode Moova inbound payload JSON.');
        }

        return $json;
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}
