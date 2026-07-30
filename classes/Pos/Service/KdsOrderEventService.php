<?php

/**
 * Append-only authority for kitchen-visible changes made after the first send.
 *
 * Ticket rows remain the operational preparation view. These rows preserve the
 * exact before/after snapshots and acknowledgement lifecycle independently.
 */
class KdsOrderEventService
{
    public function tableExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'kds_order_events'");

        return $result && $result->num_rows > 0;
    }

    public function record(
        mysqli $conn,
        int $orderId,
        int $stationId,
        ?int $ticketId,
        int $revision,
        string $eventType,
        array $before,
        array $after,
        int $actorUserId,
        array $metadata = []
    ): array {
        if (!$this->tableExists($conn)) {
            throw new RuntimeException('KDS_EVENT_SCHEMA_NOT_READY');
        }
        if ($orderId < 1 || $stationId < 1 || $actorUserId < 1) {
            throw new InvalidArgumentException('KDS_EVENT_CONTEXT_INVALID');
        }
        if (!in_array($eventType, ['change', 'line_cancel', 'order_cancel'], true)) {
            throw new InvalidArgumentException('KDS_EVENT_TYPE_INVALID');
        }
        if ($before === [] && $after === []) {
            throw new InvalidArgumentException('KDS_EVENT_SNAPSHOT_EMPTY');
        }

        $beforeJson = $this->encodeSnapshot($before);
        $afterJson = $this->encodeSnapshot($after);
        $reason = trim((string) ($metadata['reason'] ?? $metadata['cancellation_reason'] ?? ''));
        $approvalId = (int) ($metadata['manager_approval_id'] ?? $metadata['approval_id'] ?? 0);
        $scope = $this->scope($metadata);
        $idempotencyKey = hash('sha256', implode('|', [
            $orderId,
            $stationId,
            max(0, $revision),
            $eventType,
            hash('sha256', $beforeJson),
            hash('sha256', $afterJson),
            $reason,
        ]));

        $existing = $this->findByIdempotencyKey($conn, $idempotencyKey);
        if ($existing) {
            return $existing;
        }

        $uuid = $this->uuid();
        $nullableTicketId = $ticketId && $ticketId > 0 ? $ticketId : null;
        $nullableApprovalId = $approvalId > 0 ? $approvalId : null;
        $stmt = $conn->prepare("
            INSERT INTO kds_order_events (
                uuid, idempotency_key, order_id, station_id, ticket_id, kitchen_revision,
                event_type, status, before_snapshot_json, after_snapshot_json, reason,
                actor_user_id, approval_id, tenant, branch
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'ssiiiissssiiii',
            $uuid,
            $idempotencyKey,
            $orderId,
            $stationId,
            $nullableTicketId,
            $revision,
            $eventType,
            $beforeJson,
            $afterJson,
            $reason,
            $actorUserId,
            $nullableApprovalId,
            $scope[0],
            $scope[1]
        );
        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $exception) {
            $stmt->close();
            if ((int) $exception->getCode() === 1062) {
                $concurrent = $this->findByIdempotencyKey($conn, $idempotencyKey);
                if ($concurrent) {
                    return $concurrent;
                }
            }
            throw $exception;
        }
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $this->findById($conn, $id) ?: [];
    }

    public function pendingForStation(mysqli $conn, int $stationId): array
    {
        if (!$this->tableExists($conn) || $stationId < 1) {
            return [];
        }
        $stmt = $conn->prepare("
            SELECT *
            FROM kds_order_events
            WHERE station_id = ? AND status IN ('pending','delivered')
            ORDER BY id ASC
        ");
        $stmt->bind_param('i', $stationId);
        $stmt->execute();
        $result = $stmt->get_result();
        $events = [];
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
            $events[] = $this->publicState($row);
        }
        $stmt->close();

        if ($ids) {
            $idList = implode(',', array_map('intval', $ids));
            $conn->query("
                UPDATE kds_order_events
                SET status = 'delivered', delivered_at = COALESCE(delivered_at, NOW())
                WHERE id IN ($idList) AND status = 'pending'
            ");
            foreach ($events as &$event) {
                if ($event['status'] === 'pending') {
                    $event['status'] = 'delivered';
                }
            }
            unset($event);
        }

        return $events;
    }

    public function stationIdForEvent(mysqli $conn, int $eventId): int
    {
        $row = $this->findById($conn, $eventId);

        return (int) ($row['station_id'] ?? 0);
    }

    public function acknowledge(mysqli $conn, int $eventId, int $expectedVersion, int $userId): array
    {
        if ($eventId < 1 || $expectedVersion < 1 || $userId < 1) {
            throw new InvalidArgumentException('KDS_EVENT_ACK_INVALID');
        }

        $stmt = $conn->prepare("SELECT * FROM kds_order_events WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new InvalidArgumentException('KDS_EVENT_NOT_FOUND');
        }
        if ((string) $row['status'] === 'acknowledged') {
            if (!in_array($expectedVersion, [(int) $row['version'], (int) $row['version'] - 1], true)) {
                throw new RuntimeException('KDS_EVENT_ACK_STALE');
            }
            return ['applied' => false, 'event' => $this->publicState($row)];
        }
        if ((int) $row['version'] !== $expectedVersion) {
            throw new RuntimeException('KDS_EVENT_ACK_STALE');
        }
        if (!in_array((string) $row['status'], ['pending', 'delivered'], true)) {
            throw new RuntimeException('KDS_EVENT_ACK_STATE_INVALID');
        }

        $stmt = $conn->prepare("
            UPDATE kds_order_events
            SET status = 'acknowledged', acknowledged_at = NOW(), acknowledged_by = ?, version = version + 1
            WHERE id = ? AND version = ? AND status IN ('pending','delivered')
        ");
        $stmt->bind_param('iii', $userId, $eventId, $expectedVersion);
        $stmt->execute();
        $applied = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$applied) {
            throw new RuntimeException('KDS_EVENT_ACK_CONFLICT');
        }

        return ['applied' => true, 'event' => $this->publicState($this->findById($conn, $eventId) ?: $row)];
    }

    private function findById(mysqli $conn, int $id): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM kds_order_events WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function findByIdempotencyKey(mysqli $conn, string $key): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM kds_order_events WHERE idempotency_key = ? LIMIT 1");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function publicState(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'order_id' => (int) $row['order_id'],
            'station_id' => (int) $row['station_id'],
            'ticket_id' => isset($row['ticket_id']) ? (int) $row['ticket_id'] : null,
            'kitchen_revision' => (int) $row['kitchen_revision'],
            'event_type' => (string) $row['event_type'],
            'status' => (string) $row['status'],
            'before' => $this->decodeSnapshot((string) $row['before_snapshot_json']),
            'after' => $this->decodeSnapshot((string) $row['after_snapshot_json']),
            'reason' => (string) ($row['reason'] ?? ''),
            'actor_user_id' => (int) $row['actor_user_id'],
            'approval_id' => isset($row['approval_id']) ? (int) $row['approval_id'] : null,
            'version' => (int) $row['version'],
            'created_at' => (string) $row['created_at'],
            'delivered_at' => (string) ($row['delivered_at'] ?? ''),
            'acknowledged_at' => (string) ($row['acknowledged_at'] ?? ''),
            'acknowledged_by' => isset($row['acknowledged_by']) ? (int) $row['acknowledged_by'] : null,
        ];
    }

    private function encodeSnapshot(array $snapshot): string
    {
        $json = json_encode(array_values($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('KDS_EVENT_SNAPSHOT_INVALID');
        }

        return $json;
    }

    private function decodeSnapshot(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('KDS_EVENT_SNAPSHOT_INVALID');
        }

        return array_values($decoded);
    }

    private function scope(array $metadata = []): array
    {
        return [
            max(0, (int) ($metadata['tenant'] ?? $_SESSION['pos_tenant'] ?? 0)),
            max(0, (int) ($metadata['branch'] ?? $_SESSION['pos_branch'] ?? 0)),
        ];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
