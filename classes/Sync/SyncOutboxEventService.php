<?php

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/PosOrderSnapshotBuilder.php';

class SyncOutboxEventService
{
    private PosOrderSnapshotBuilder $snapshotBuilder;
    private SyncBranchIdentity $branchIdentity;

    public function __construct(?PosOrderSnapshotBuilder $snapshotBuilder = null, ?SyncBranchIdentity $branchIdentity = null)
    {
        $this->snapshotBuilder = $snapshotBuilder ?: new PosOrderSnapshotBuilder();
        $this->branchIdentity = $branchIdentity ?: new SyncBranchIdentity();
    }

    public function recordOrderSnapshot(mysqli $conn, int $orderId, array $options = []): ?array
    {
        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        if (!$this->outboxEnabled($config)) {
            return null;
        }

        $this->assertOutboxTableExists($conn);

        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $posTenant = $this->intOrZero($branch['pos_tenant'] ?? ($config['branch']['pos_tenant'] ?? 0));
        $posBranch = $this->intOrZero($branch['pos_branch'] ?? ($config['branch']['pos_branch'] ?? 0));
        $eventType = $this->eventType($options['event_type'] ?? null);
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? null);

        $payload = $this->snapshotBuilder->build($conn, $branchUuid, $orderId, [
            'source_system' => $sourceSystem,
            'branch_timezone' => $config['timezone'] ?? null,
        ]);
        $payloadJson = $this->encodeJson($payload);
        $payloadHash = hash('sha256', $payloadJson);
        $orderUuid = (string) $payload['order_uuid'];
        $eventUuid = SyncBranchIdentity::generateUuidV4();
        $aggregateId = 'ot_head:' . $orderId;
        $idempotencyKey = $this->idempotencyKey($branchUuid, $orderId, $eventType, $payloadHash);

        $stmt = $conn->prepare("
            INSERT INTO sync_outbox (
                event_uuid,
                branch_uuid,
                pos_tenant,
                pos_branch,
                aggregate_type,
                aggregate_uuid,
                aggregate_local_id,
                aggregate_id,
                entity_type,
                entity_uuid,
                entity_local_id,
                event_type,
                event_version,
                source_system,
                source_event_uuid,
                idempotency_key,
                payload_json,
                payload_hash,
                status,
                attempts
            ) VALUES (?, ?, ?, ?, 'order', ?, ?, ?, 'order', ?, ?, ?, 1, ?, NULL, ?, ?, ?, 'pending', 0)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                payload_json = VALUES(payload_json),
                payload_hash = VALUES(payload_hash),
                updated_at = CURRENT_TIMESTAMP
        ");

        $params = [
            $eventUuid,
            $branchUuid,
            $posTenant,
            $posBranch,
            $orderUuid,
            $orderId,
            $aggregateId,
            $orderUuid,
            $orderId,
            $eventType,
            $sourceSystem,
            $idempotencyKey,
            $payloadJson,
            $payloadHash,
        ];
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $outboxId = (int) $conn->insert_id;
        $stmt->close();

        return [
            'outbox_id' => $outboxId,
            'event_uuid' => $eventUuid,
            'branch_uuid' => $branchUuid,
            'order_uuid' => $orderUuid,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
        ];
    }

    public function recordTableSnapshot(mysqli $conn, int $tableId, array $options = []): ?array
    {
        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        if (!$this->outboxEnabled($config)) {
            return null;
        }

        if ($tableId <= 0) {
            throw new InvalidArgumentException('Table id must be positive.');
        }

        $this->assertOutboxTableExists($conn);

        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $posTenant = $this->intOrZero($branch['pos_tenant'] ?? ($config['branch']['pos_tenant'] ?? 0));
        $posBranch = $this->intOrZero($branch['pos_branch'] ?? ($config['branch']['pos_branch'] ?? 0));
        $eventType = $this->eventType($options['event_type'] ?? 'table.updated');
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? null);

        $payload = $this->buildTablePayload($conn, $branchUuid, $tableId, [
            'source_system' => $sourceSystem,
            'active_order_id' => array_key_exists('active_order_id', $options) ? $options['active_order_id'] : '__auto__',
        ]);
        $payloadJson = $this->encodeJson($payload);
        $payloadHash = hash('sha256', $payloadJson);
        $tableUuid = (string) $payload['table_uuid'];
        $eventUuid = SyncBranchIdentity::generateUuidV4();
        $aggregateId = 'tables:' . $tableId;
        $idempotencyKey = $this->tableIdempotencyKey($branchUuid, $tableId, $eventType, $payloadHash);

        $stmt = $conn->prepare("
            INSERT INTO sync_outbox (
                event_uuid,
                branch_uuid,
                pos_tenant,
                pos_branch,
                aggregate_type,
                aggregate_uuid,
                aggregate_local_id,
                aggregate_id,
                entity_type,
                entity_uuid,
                entity_local_id,
                event_type,
                event_version,
                source_system,
                source_event_uuid,
                idempotency_key,
                payload_json,
                payload_hash,
                status,
                attempts
            ) VALUES (?, ?, ?, ?, 'table', ?, ?, ?, 'table', ?, ?, ?, 1, ?, NULL, ?, ?, ?, 'pending', 0)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                payload_json = VALUES(payload_json),
                payload_hash = VALUES(payload_hash),
                updated_at = CURRENT_TIMESTAMP
        ");

        $params = [
            $eventUuid,
            $branchUuid,
            $posTenant,
            $posBranch,
            $tableUuid,
            $tableId,
            $aggregateId,
            $tableUuid,
            $tableId,
            $eventType,
            $sourceSystem,
            $idempotencyKey,
            $payloadJson,
            $payloadHash,
        ];
        $this->bindParams($stmt, str_repeat('s', count($params)), $params);
        $stmt->execute();
        $outboxId = (int) $conn->insert_id;
        $stmt->close();

        return [
            'outbox_id' => $outboxId,
            'event_uuid' => $eventUuid,
            'branch_uuid' => $branchUuid,
            'table_uuid' => $tableUuid,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
        ];
    }

    private function outboxEnabled(array $config): bool
    {
        return (bool) ($config['sync']['outbox_enabled'] ?? true);
    }

    private function assertOutboxTableExists(mysqli $conn): void
    {
        $result = $conn->query("SHOW TABLES LIKE 'sync_outbox'");
        if (!$result || $result->num_rows < 1) {
            throw new RuntimeException('sync_outbox table is missing. Run tools/run_migrations.php --apply before enabling branch sync.');
        }
    }

    private function idempotencyKey(string $branchUuid, int $orderId, string $eventType, string $payloadHash): string
    {
        return 'pos:order:' . $orderId . ':' . $eventType . ':' . substr(hash('sha256', $branchUuid . ':' . $payloadHash), 0, 32);
    }

    private function tableIdempotencyKey(string $branchUuid, int $tableId, string $eventType, string $payloadHash): string
    {
        return 'pos:table:' . $tableId . ':' . $eventType . ':' . substr(hash('sha256', $branchUuid . ':' . $payloadHash), 0, 32);
    }

    private function buildTablePayload(mysqli $conn, string $branchUuid, int $tableId, array $options): array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM tables
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $table = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$table) {
            throw new RuntimeException('POS table was not found for sync snapshot: ' . $tableId);
        }

        $activeOrderId = $options['active_order_id'] ?? '__auto__';
        if ($activeOrderId === '__auto__') {
            $activeOrderId = $this->activeTableOrderId($conn, $tableId);
        }
        $activeOrderId = $this->intOrNull($activeOrderId);
        $tableUuid = PosOrderSnapshotBuilder::deterministicUuid('pos-table', (string) $tableId);
        $activeOrderUuid = $activeOrderId
            ? PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'ot_head:' . $activeOrderId)
            : null;
        $revision = $this->revisionFromTable($table);

        $tablePayload = [
            'table_uuid' => $tableUuid,
            'local_table_id' => $tableId,
            'table_id' => $tableId,
            'tname' => $this->nullableString($table['tname'] ?? null),
            'table_name' => $this->nullableString($table['tname'] ?? null),
            'table_case' => $this->intOrZero($table['table_case'] ?? 0),
            'isdeleted' => $this->intOrZero($table['isdeleted'] ?? 0),
            'active_order_uuid' => $activeOrderUuid,
            'active_order_local_id' => $activeOrderId,
            'sync_revision' => $revision,
        ];

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'pos_table',
            'source_system' => $this->sourceSystem($options['source_system'] ?? null),
            'branch_uuid' => $branchUuid,
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'table_uuid' => $tableUuid,
            'local_table_id' => $tableId,
            'table' => $tablePayload,
        ];
        $payload['payload_hash'] = hash('sha256', $this->encodeJson($payload));

        return $payload;
    }

    private function activeTableOrderId(mysqli $conn, int $tableId): ?int
    {
        $stmt = $conn->prepare("
            SELECT id
            FROM ot_head
            WHERE table_id = ?
              AND pro_tybe = 9
              AND COALESCE(isdeleted, 0) = 0
              AND COALESCE(order_status, 'active') = 'active'
              AND COALESCE(payment_status, 'unpaid') IN ('unpaid', 'partial')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int) $row['id'] : null;
    }

    private function revisionFromTable(array $table): int
    {
        foreach (['mdtime', 'updated_at', 'crtime', 'created_at'] as $key) {
            if (empty($table[$key])) {
                continue;
            }

            $timestamp = strtotime((string) $table[$key]);
            if ($timestamp !== false) {
                return max(1, (int) $timestamp);
            }
        }

        return max(1, (int) ($table['id'] ?? 1));
    }

    private function eventType($value): string
    {
        $value = trim((string) $value);
        return $value === '' ? 'order.saved' : substr($value, 0, 80);
    }

    private function sourceSystem($value): string
    {
        $value = trim((string) $value);
        return $value === '' ? 'pos' : substr($value, 0, 40);
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode sync outbox payload JSON.');
        }

        return $json;
    }

    private function intOrZero($value): int
    {
        if ($value === null || $value === '' || $value === false || !is_numeric($value)) {
            return 0;
        }

        return (int) $value;
    }

    private function intOrNull($value): ?int
    {
        if ($value === null || $value === '' || $value === false || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }

        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}
