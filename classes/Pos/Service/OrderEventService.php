<?php

require_once __DIR__ . '/../../Sync/OperationalSyncEventService.php';
require_once __DIR__ . '/SideEffectPolicy.php';

class OrderEventService
{
    public function recordIfAvailable(mysqli $conn, int $orderId, string $eventType, string $eventSource, array $options = []): ?array
    {
        if (!$this->tableExists($conn, 'order_events')) {
            return null;
        }

        return $this->record($conn, $orderId, $eventType, $eventSource, $options);
    }

    public function record(mysqli $conn, int $orderId, string $eventType, string $eventSource, array $options = []): array
    {
        if ($orderId <= 0) {
            throw new InvalidArgumentException('ORDER_ID_REQUIRED');
        }

        $eventType = trim($eventType);
        $eventSource = trim($eventSource);
        if ($eventType === '') {
            throw new InvalidArgumentException('EVENT_TYPE_REQUIRED');
        }
        if ($eventSource === '') {
            throw new InvalidArgumentException('EVENT_SOURCE_REQUIRED');
        }

        $actorUserId = array_key_exists('actor_user_id', $options) && $options['actor_user_id'] !== null
            ? (int) $options['actor_user_id']
            : null;
        $tenant = (int) ($options['tenant'] ?? $options['pos_tenant'] ?? 0);
        $branch = (int) ($options['branch'] ?? $options['pos_branch'] ?? 0);
        $beforeJson = $this->nullableJson($options['before_state'] ?? $options['before_state_json'] ?? null);
        $afterJson = $this->nullableJson($options['after_state'] ?? $options['after_state_json'] ?? null);
        $metadataJson = $this->nullableJson($options['metadata'] ?? $options['metadata_json'] ?? null);

        $stmt = $conn->prepare("
            INSERT INTO order_events (
                order_id, event_type, event_source, actor_user_id, tenant, branch,
                before_state_json, after_state_json, metadata_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'issiiisss',
            $orderId,
            $eventType,
            $eventSource,
            $actorUserId,
            $tenant,
            $branch,
            $beforeJson,
            $afterJson,
            $metadataJson
        );
        $stmt->execute();
        $eventId = (int) $conn->insert_id;
        $stmt->close();

        $syncOptions = [
            'source_system' => 'pos_order_event',
            'event_type' => 'order_event.saved',
        ];
        if (isset($options['sync_config']) && is_array($options['sync_config'])) {
            $syncOptions['config'] = $options['sync_config'];
        }
        $savepoint = $this->connectionInTransaction($conn);
        if ($savepoint) {
            $conn->query('SAVEPOINT posmain_order_event_sync');
        }
        try {
            (new OperationalSyncEventService())->recordRowSnapshot(
                $conn,
                'order_event',
                $eventId,
                $syncOptions
            );
            if ($savepoint) {
                $conn->query('RELEASE SAVEPOINT posmain_order_event_sync');
            }
        } catch (Throwable $exception) {
            if ($savepoint) {
                $conn->query('ROLLBACK TO SAVEPOINT posmain_order_event_sync');
                $conn->query('RELEASE SAVEPOINT posmain_order_event_sync');
            }
            if (SideEffectPolicy::orderEventShouldRollback($exception)) {
                throw $exception;
            }
            error_log('POS order event operational sync skipped: ' . $exception->getMessage());
        }

        return [
            'id' => $eventId,
            'order_id' => $orderId,
            'event_type' => $eventType,
            'event_source' => $eventSource,
        ];
    }

    private function nullableJson($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $trimmed;
            }
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new InvalidArgumentException('JSON_ENCODE_FAILED');
        }

        return $json;
    }

    private function tableExists(mysqli $conn, string $tableName): bool
    {
        $tableName = $conn->real_escape_string($tableName);
        $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");

        return $result && $result->num_rows > 0;
    }

    private function connectionInTransaction(mysqli $conn): bool
    {
        $result = $conn->query('SELECT @@session.in_transaction AS active_transaction');
        $row = $result->fetch_assoc() ?: [];

        return !empty($row['active_transaction']);
    }
}
