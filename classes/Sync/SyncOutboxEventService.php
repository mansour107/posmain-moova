<?php

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/CloudBranchSyncPublisher.php';
require_once __DIR__ . '/DocumentCounterService.php';
require_once __DIR__ . '/PosOrderSnapshotBuilder.php';
require_once __DIR__ . '/../Pos/Service/ItemVariantService.php';
require_once __DIR__ . '/../Recipe/RecipeFeatureFlags.php';
require_once __DIR__ . '/../Recipe/RecipeScopeResolver.php';
require_once __DIR__ . '/../Recipe/RecipeSyncPayloadService.php';

class SyncOutboxEventService
{
    private PosOrderSnapshotBuilder $snapshotBuilder;
    private SyncBranchIdentity $branchIdentity;

    public function __construct(?PosOrderSnapshotBuilder $snapshotBuilder = null, ?SyncBranchIdentity $branchIdentity = null)
    {
        $this->snapshotBuilder = $snapshotBuilder ?: new PosOrderSnapshotBuilder();
        $this->branchIdentity = $branchIdentity ?: new SyncBranchIdentity();
    }

    /**
     * Reconciles configured branch identity before a caller opens its business
     * transaction. Identity rotation intentionally cannot run inside a caller
     * transaction because it migrates historical sync references atomically.
     */
    public function prepareBranchIdentity(mysqli $conn, array $options = []): ?array
    {
        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        if (!$this->outboxEnabled($config) && !$this->cloudToBranchPublishEnabled($config)) {
            return null;
        }

        return $this->branchIdentity->ensure($conn, $config);
    }

    public function recordOrderSnapshot(mysqli $conn, int $orderId, array $options = []): ?array
    {
        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $outboxEnabled = $this->outboxEnabled($config);
        $cloudPublishEnabled = $this->cloudToBranchPublishEnabled($config);
        if (!$outboxEnabled && !$cloudPublishEnabled) {
            return null;
        }

        if ($outboxEnabled) {
            $this->assertOutboxTableExists($conn);
        }

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
        $orderUuid = (string) $payload['order_uuid'];
        $baseRevision = max(1, (int) ($payload['order']['sync_revision'] ?? 1));
        $sourceTransactionId = $this->sourceTransactionId(
            $options['source_transaction_id'] ?? null,
            'order',
            $orderId,
            $eventType,
            'mutation:' . $baseRevision
        );
        $idempotencyKey = $this->idempotencyKey($branchUuid, 'order', $orderId, $sourceTransactionId);
        $existing = $outboxEnabled
            ? $this->findOutboxByIdempotencyKey($conn, $branchUuid, $idempotencyKey)
            : null;
        if ($existing !== null) {
            $this->assertImmutableReplayMatches($existing, $payload, 'order');

            return $this->existingOrderResult($existing, $branchUuid, $orderUuid);
        }

        if ($outboxEnabled) {
            $existingRevision = $this->highestOrderEventVersion($conn, $orderUuid);
            $counter = new DocumentCounterService();
            $counterKey = 'order:' . $branchUuid . ':' . $orderId;
            $counter->ensureCounterRow(
                $conn,
                $posTenant,
                $posBranch,
                'order_sync',
                $counterKey,
                max(0, $baseRevision - 1, $existingRevision)
            );
            $payload['order']['sync_revision'] = $counter->nextCounter(
                $conn,
                $posTenant,
                $posBranch,
                'order_sync',
                $counterKey
            );
            unset($payload['payload_hash']);
            $payload['payload_hash'] = hash('sha256', $this->encodeJson($payload));
        }
        $payloadJson = $this->encodeJson($payload);
        $payloadHash = hash('sha256', $payloadJson);
        $eventUuid = SyncBranchIdentity::generateUuidV4();
        $aggregateId = 'ot_head:' . $orderId;
        $eventVersion = max(1, (int) ($payload['order']['sync_revision'] ?? 1));
        $deliveryStatus = $this->deliveryStatus($config);

        $outboxId = null;
        if ($outboxEnabled) {
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
                source_transaction_id,
                idempotency_key,
                payload_json,
                payload_hash,
                status,
                attempts
            ) VALUES (?, ?, ?, ?, 'order', ?, ?, ?, 'order', ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 0)
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
                $eventVersion,
                $sourceSystem,
                $sourceTransactionId,
                $idempotencyKey,
                $payloadJson,
                $payloadHash,
                $deliveryStatus,
            ];
            try {
                $this->bindParams($stmt, str_repeat('s', count($params)), $params);
                $stmt->execute();
                $outboxId = (int) $conn->insert_id;
                $stmt->close();
            } catch (mysqli_sql_exception $exception) {
                $stmt->close();
                if ((int) $exception->getCode() !== 1062) {
                    throw $exception;
                }
                $existing = $this->findOutboxByIdempotencyKey($conn, $branchUuid, $idempotencyKey);
                if ($existing === null) {
                    throw $exception;
                }
                $this->assertImmutableReplayMatches($existing, $payload, 'order');

                return $this->existingOrderResult($existing, $branchUuid, $orderUuid);
            }
        }

        $cloudBranchEvents = $this->publishCloudBranchEvent($conn, $config, [
            'branch_uuid' => $branchUuid,
            'event_type' => $eventType,
            'event_version' => $eventVersion,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'order',
            'aggregate_uuid' => $orderUuid,
            'aggregate_local_id' => $orderId,
            'aggregate_id' => $aggregateId,
            'entity_type' => 'order',
            'entity_uuid' => $orderUuid,
            'entity_local_id' => $orderId,
            'payload_hash' => $payloadHash,
            'payload' => $payload,
        ]);

        return [
            'outbox_id' => $outboxId,
            'event_uuid' => $eventUuid,
            'branch_uuid' => $branchUuid,
            'order_uuid' => $orderUuid,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'event_version' => $eventVersion,
            'source_transaction_id' => $sourceTransactionId,
            'status' => $deliveryStatus,
            'cloud_branch_events' => $cloudBranchEvents,
        ];
    }

    /**
     * Certified POS mutations must never commit without a durable local outbox
     * row. Sync delivery may be held, but persistence of the mutation envelope
     * is mandatory.
     */
    public function recordRequiredOrderSnapshot(mysqli $conn, int $orderId, array $options = []): array
    {
        $result = $this->recordOrderSnapshot($conn, $orderId, $options);
        if (!is_array($result) || (int) ($result['outbox_id'] ?? 0) < 1) {
            throw new RuntimeException('ORDER_OUTBOX_REQUIRED');
        }

        return $result;
    }

    private function highestOrderEventVersion(mysqli $conn, string $orderUuid): int
    {
        $stmt = $conn->prepare("
            SELECT COALESCE(MAX(event_version), 0) AS max_version
            FROM sync_outbox
            WHERE aggregate_type = 'order'
              AND aggregate_uuid = ?
        ");
        $stmt->bind_param('s', $orderUuid);
        $stmt->execute();
        $version = (int) ($stmt->get_result()->fetch_assoc()['max_version'] ?? 0);
        $stmt->close();

        return $version;
    }

    public function recordTableSnapshot(mysqli $conn, int $tableId, array $options = []): ?array
    {
        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $outboxEnabled = $this->outboxEnabled($config);
        $cloudPublishEnabled = $this->cloudToBranchPublishEnabled($config);
        if (!$outboxEnabled && !$cloudPublishEnabled) {
            return null;
        }

        if ($tableId <= 0) {
            throw new InvalidArgumentException('Table id must be positive.');
        }

        if ($outboxEnabled) {
            $this->assertOutboxTableExists($conn);
        }

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
        $baseRevision = max(1, (int) ($payload['table']['sync_revision'] ?? 1));
        $sourceTransactionId = $this->sourceTransactionId(
            $options['source_transaction_id'] ?? null,
            'table',
            $tableId,
            $eventType,
            'revision:' . $baseRevision . ':payload:' . substr($this->semanticPayloadHash($payload, 'table'), 0, 24)
        );
        $idempotencyKey = $this->idempotencyKey($branchUuid, 'table', $tableId, $sourceTransactionId);
        $tableUuid = (string) $payload['table_uuid'];
        $existing = $outboxEnabled
            ? $this->findOutboxByIdempotencyKey($conn, $branchUuid, $idempotencyKey)
            : null;
        if ($existing !== null) {
            $this->assertImmutableReplayMatches($existing, $payload, 'table');

            return $this->existingEntityResult($existing, $branchUuid, 'table_uuid', $tableUuid);
        }
        if ($outboxEnabled) {
            $payload['table']['sync_revision'] = $this->nextAggregateEventVersion(
                $conn,
                $posTenant,
                $posBranch,
                'table',
                $branchUuid,
                $tableId,
                $baseRevision
            );
            unset($payload['payload_hash']);
            $payload['payload_hash'] = hash('sha256', $this->encodeJson($payload));
        }
        $payloadJson = $this->encodeJson($payload);
        $payloadHash = hash('sha256', $payloadJson);
        $eventUuid = SyncBranchIdentity::generateUuidV4();
        $aggregateId = 'tables:' . $tableId;
        $eventVersion = max(1, (int) ($payload['table']['sync_revision'] ?? 1));
        $deliveryStatus = $this->deliveryStatus($config);

        $outboxId = null;
        if ($outboxEnabled) {
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
                source_transaction_id,
                idempotency_key,
                payload_json,
                payload_hash,
                status,
                attempts
            ) VALUES (?, ?, ?, ?, 'table', ?, ?, ?, 'table', ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 0)
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
                $eventVersion,
                $sourceSystem,
                $sourceTransactionId,
                $idempotencyKey,
                $payloadJson,
                $payloadHash,
                $deliveryStatus,
            ];
            try {
                $this->bindParams($stmt, str_repeat('s', count($params)), $params);
                $stmt->execute();
                $outboxId = (int) $conn->insert_id;
                $stmt->close();
            } catch (mysqli_sql_exception $exception) {
                $stmt->close();
                if ((int) $exception->getCode() !== 1062) {
                    throw $exception;
                }
                $existing = $this->findOutboxByIdempotencyKey($conn, $branchUuid, $idempotencyKey);
                if ($existing === null) {
                    throw $exception;
                }
                $this->assertImmutableReplayMatches($existing, $payload, 'table');

                return $this->existingEntityResult($existing, $branchUuid, 'table_uuid', $tableUuid);
            }
        }

        $cloudBranchEvents = $this->publishCloudBranchEvent($conn, $config, [
            'branch_uuid' => $branchUuid,
            'event_type' => $eventType,
            'event_version' => $eventVersion,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'table',
            'aggregate_uuid' => $tableUuid,
            'aggregate_local_id' => $tableId,
            'aggregate_id' => $aggregateId,
            'entity_type' => 'table',
            'entity_uuid' => $tableUuid,
            'entity_local_id' => $tableId,
            'payload_hash' => $payloadHash,
            'payload' => $payload,
        ]);

        return [
            'outbox_id' => $outboxId,
            'event_uuid' => $eventUuid,
            'branch_uuid' => $branchUuid,
            'table_uuid' => $tableUuid,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'event_version' => $eventVersion,
            'source_transaction_id' => $sourceTransactionId,
            'status' => $deliveryStatus,
            'cloud_branch_events' => $cloudBranchEvents,
        ];
    }

    public function recordRequiredTableSnapshot(mysqli $conn, int $tableId, array $options = []): array
    {
        $result = $this->recordTableSnapshot($conn, $tableId, $options);
        if (!is_array($result) || (int) ($result['outbox_id'] ?? 0) < 1) {
            throw new RuntimeException('TABLE_OUTBOX_REQUIRED');
        }

        return $result;
    }

    public function recordMenuItemSnapshot(mysqli $conn, int $itemId, array $options = []): ?array
    {
        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $outboxEnabled = $this->outboxEnabled($config);
        $cloudPublishEnabled = $this->cloudToBranchPublishEnabled($config);
        if ((!$outboxEnabled && !$cloudPublishEnabled) || empty($config['sync']['menu_sync_enabled'])) {
            return null;
        }

        if ($itemId <= 0) {
            throw new InvalidArgumentException('Menu item id must be positive.');
        }

        if ($outboxEnabled) {
            $this->assertOutboxTableExists($conn);
        }

        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $posTenant = $this->intOrZero($branch['pos_tenant'] ?? ($config['branch']['pos_tenant'] ?? 0));
        $posBranch = $this->intOrZero($branch['pos_branch'] ?? ($config['branch']['pos_branch'] ?? 0));
        $eventType = $this->eventType($options['event_type'] ?? 'menu.item_saved');
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? null);

        $payload = $this->buildMenuItemPayload($conn, $branchUuid, $itemId, [
            'source_system' => $sourceSystem,
            'config' => $config,
            'pos_tenant' => $posTenant,
            'pos_branch' => $posBranch,
        ]);
        $baseRevision = max(1, (int) ($payload['menu_item']['menu_version'] ?? 1));
        $sourceTransactionId = $this->sourceTransactionId(
            $options['source_transaction_id'] ?? null,
            'menu_item',
            $itemId,
            $eventType,
            'revision:' . $baseRevision . ':payload:' . substr($this->semanticPayloadHash($payload, 'menu_item'), 0, 24)
        );
        $idempotencyKey = $this->idempotencyKey($branchUuid, 'menu_item', $itemId, $sourceTransactionId);
        $itemUuid = (string) $payload['item_uuid'];
        $existing = $outboxEnabled
            ? $this->findOutboxByIdempotencyKey($conn, $branchUuid, $idempotencyKey)
            : null;
        if ($existing !== null) {
            $this->assertImmutableReplayMatches($existing, $payload, 'menu_item');

            return $this->existingEntityResult($existing, $branchUuid, 'item_uuid', $itemUuid);
        }
        if ($outboxEnabled) {
            $payload['menu_item']['menu_version'] = $this->nextAggregateEventVersion(
                $conn,
                $posTenant,
                $posBranch,
                'menu_item',
                $branchUuid,
                $itemId,
                $baseRevision
            );
            unset($payload['payload_hash']);
            $payload['payload_hash'] = hash('sha256', $this->encodeJson($payload));
        }
        $payloadJson = $this->encodeJson($payload);
        $payloadHash = hash('sha256', $payloadJson);
        $eventUuid = SyncBranchIdentity::generateUuidV4();
        $aggregateId = 'myitems:' . $itemId;
        $eventVersion = max(1, (int) ($payload['menu_item']['menu_version'] ?? 1));
        $deliveryStatus = $this->deliveryStatus($config);

        $outboxId = null;
        if ($outboxEnabled) {
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
                source_transaction_id,
                idempotency_key,
                payload_json,
                payload_hash,
                status,
                attempts
            ) VALUES (?, ?, ?, ?, 'menu_item', ?, ?, ?, 'menu_item', ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, 0)
        ");

            $params = [
                $eventUuid,
                $branchUuid,
                $posTenant,
                $posBranch,
                $itemUuid,
                $itemId,
                $aggregateId,
                $itemUuid,
                $itemId,
                $eventType,
                $eventVersion,
                $sourceSystem,
                $sourceTransactionId,
                $idempotencyKey,
                $payloadJson,
                $payloadHash,
                $deliveryStatus,
            ];
            try {
                $this->bindParams($stmt, str_repeat('s', count($params)), $params);
                $stmt->execute();
                $outboxId = (int) $conn->insert_id;
                $stmt->close();
            } catch (mysqli_sql_exception $exception) {
                $stmt->close();
                if ((int) $exception->getCode() !== 1062) {
                    throw $exception;
                }
                $existing = $this->findOutboxByIdempotencyKey($conn, $branchUuid, $idempotencyKey);
                if ($existing === null) {
                    throw $exception;
                }
                $this->assertImmutableReplayMatches($existing, $payload, 'menu_item');

                return $this->existingEntityResult($existing, $branchUuid, 'item_uuid', $itemUuid);
            }
        }

        $cloudBranchEvents = $this->publishCloudBranchEvent($conn, $config, [
            'branch_uuid' => $branchUuid,
            'event_type' => $eventType,
            'event_version' => $eventVersion,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'menu_item',
            'aggregate_uuid' => $itemUuid,
            'aggregate_local_id' => $itemId,
            'aggregate_id' => $aggregateId,
            'entity_type' => 'menu_item',
            'entity_uuid' => $itemUuid,
            'entity_local_id' => $itemId,
            'payload_hash' => $payloadHash,
            'payload' => $payload,
        ]);

        return [
            'outbox_id' => $outboxId,
            'event_uuid' => $eventUuid,
            'branch_uuid' => $branchUuid,
            'item_uuid' => $itemUuid,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'event_version' => $eventVersion,
            'source_transaction_id' => $sourceTransactionId,
            'status' => $deliveryStatus,
            'cloud_branch_events' => $cloudBranchEvents,
        ];
    }

    private function outboxEnabled(array $config): bool
    {
        return (bool) ($config['sync']['outbox_enabled'] ?? true);
    }

    private function cloudToBranchPublishEnabled(array $config): bool
    {
        return in_array((string) ($config['role'] ?? 'branch'), ['cloud', 'fake_cloud'], true)
            && !empty($config['sync']['cloud_to_branch_publish_enabled']);
    }

    private function deliveryStatus(array $config): string
    {
        return !empty($config['sync']['branch_sync_enabled']) && !empty($config['sync']['worker_enabled'])
            ? 'pending'
            : 'held';
    }

    private function publishCloudBranchEvent(mysqli $conn, array $config, array $event): array
    {
        if (!$this->cloudToBranchPublishEnabled($config)) {
            return [];
        }

        return (new CloudBranchSyncPublisher())->publish($conn, $event, $config);
    }

    private function assertOutboxTableExists(mysqli $conn): void
    {
        $result = $conn->query("SHOW TABLES LIKE 'sync_outbox'");
        if (!$result || $result->num_rows < 1) {
            throw new RuntimeException('sync_outbox table is missing. Run tools/run_migrations.php --apply before enabling branch sync.');
        }
    }

    private function sourceTransactionId(
        $requested,
        string $aggregateType,
        int $localId,
        string $eventType,
        string $fallbackRevision
    ): string {
        $value = trim((string) $requested);
        if ($value === '') {
            $value = $aggregateType . ':' . $localId . ':' . $eventType . ':' . $fallbackRevision;
        }
        if (strlen($value) > 191) {
            $value = substr($value, 0, 150) . ':' . hash('sha256', $value);
        }

        return $value;
    }

    private function idempotencyKey(
        string $branchUuid,
        string $aggregateType,
        int $localId,
        string $sourceTransactionId
    ): string
    {
        return 'pos:' . $aggregateType . ':' . $localId . ':tx:' .
            substr(hash('sha256', $branchUuid . ':' . $sourceTransactionId), 0, 40);
    }

    private function findOutboxByIdempotencyKey(mysqli $conn, string $branchUuid, string $idempotencyKey): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM sync_outbox
            WHERE branch_uuid = ?
              AND idempotency_key = ?
            LIMIT 1
        ");
        $stmt->bind_param('ss', $branchUuid, $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function assertImmutableReplayMatches(array $existing, array $currentPayload, string $aggregateType): void
    {
        $storedPayload = json_decode((string) ($existing['payload_json'] ?? ''), true);
        if (
            !is_array($storedPayload)
            || !hash_equals(
                $this->semanticPayloadHash($storedPayload, $aggregateType),
                $this->semanticPayloadHash($currentPayload, $aggregateType)
            )
        ) {
            throw new RuntimeException('OUTBOX_IDEMPOTENCY_PAYLOAD_CONFLICT');
        }
    }

    private function semanticPayloadHash(array $payload, string $aggregateType): string
    {
        unset($payload['captured_at_utc'], $payload['payload_hash']);
        if ($aggregateType === 'order' && isset($payload['order']) && is_array($payload['order'])) {
            unset($payload['order']['sync_revision']);
        } elseif ($aggregateType === 'table' && isset($payload['table']) && is_array($payload['table'])) {
            unset($payload['table']['sync_revision']);
        } elseif ($aggregateType === 'menu_item' && isset($payload['menu_item']) && is_array($payload['menu_item'])) {
            unset($payload['menu_item']['menu_version']);
        }

        return hash('sha256', $this->encodeJson($payload));
    }

    private function existingOrderResult(array $existing, string $branchUuid, string $orderUuid): array
    {
        return array_merge(
            $this->existingEntityResult($existing, $branchUuid, 'order_uuid', $orderUuid),
            ['order_uuid' => $orderUuid]
        );
    }

    private function existingEntityResult(
        array $existing,
        string $branchUuid,
        string $entityUuidKey,
        string $entityUuid
    ): array {
        return [
            'outbox_id' => (int) $existing['id'],
            'event_uuid' => (string) $existing['event_uuid'],
            'branch_uuid' => $branchUuid,
            $entityUuidKey => $entityUuid,
            'idempotency_key' => (string) $existing['idempotency_key'],
            'payload_hash' => (string) $existing['payload_hash'],
            'event_version' => (int) $existing['event_version'],
            'source_transaction_id' => (string) ($existing['source_transaction_id'] ?? ''),
            'status' => (string) $existing['status'],
            'cloud_branch_events' => [],
            'replayed' => true,
        ];
    }

    private function nextAggregateEventVersion(
        mysqli $conn,
        int $posTenant,
        int $posBranch,
        string $aggregateType,
        string $branchUuid,
        int $localId,
        int $baseRevision
    ): int {
        $stmt = $conn->prepare("
            SELECT COALESCE(MAX(event_version), 0) AS max_version
            FROM sync_outbox
            WHERE aggregate_type = ?
              AND aggregate_local_id = ?
              AND branch_uuid = ?
        ");
        $stmt->bind_param('sis', $aggregateType, $localId, $branchUuid);
        $stmt->execute();
        $existingRevision = (int) ($stmt->get_result()->fetch_assoc()['max_version'] ?? 0);
        $stmt->close();

        $counter = new DocumentCounterService();
        $counterKey = $aggregateType . ':' . $branchUuid . ':' . $localId;
        $counter->ensureCounterRow(
            $conn,
            $posTenant,
            $posBranch,
            $aggregateType . '_sync',
            $counterKey,
            max(0, $baseRevision - 1, $existingRevision)
        );

        return $counter->nextCounter(
            $conn,
            $posTenant,
            $posBranch,
            $aggregateType . '_sync',
            $counterKey
        );
    }

    private function buildMenuItemPayload(mysqli $conn, string $branchUuid, int $itemId, array $options): array
    {
        $stmt = $conn->prepare("
            SELECT i.*,
                   g.gname AS sync_category_name
            FROM myitems i
            LEFT JOIN item_group g ON g.id = i.group1
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$item) {
            throw new RuntimeException('POS menu item was not found for sync snapshot: ' . $itemId);
        }

        $itemUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'myitems:' . $itemId);
        $revision = $this->revisionFromItem($item);
        $categoryId = $this->intOrNull($item['group1'] ?? null);
        $price1 = $this->decimalString($item['price1'] ?? null);
        $price2 = $this->decimalString($item['price2'] ?? null);
        $price3 = $this->decimalString($item['price3'] ?? null);
        $cost = $this->decimalString($item['cost_price'] ?? null);
        $catalogActive = ((int) ($item['is_active'] ?? 1)) === 1;
        $catalogNotDeleted = ((int) ($item['isdeleted'] ?? 0)) === 0;

        $variantService = new ItemVariantService();
        $variants = $variantService->variantsForParent($conn, $itemId, true);
        $variantParent = $variantService->variantParentForChild($conn, $itemId);
        $hasVariants = count($variants) > 0;
        $isVariantChild = $variantParent !== null;

        $menuItem = [
            'item_uuid' => $itemUuid,
            'local_item_id' => $itemId,
            'item_id' => $itemId,
            'external_item_id' => null,
            'barcode' => $this->nullableString($item['barcode'] ?? null),
            'item_name' => $this->nullableString($item['iname'] ?? null),
            'name2' => $this->nullableString($item['name2'] ?? null),
            'category_id' => $categoryId,
            'category_name' => $this->nullableString($item['sync_category_name'] ?? null),
            'group2' => $this->intOrZero($item['group2'] ?? 0),
            'price' => $price1,
            'price1' => $price1,
            'price2' => $price2,
            'price3' => $price3,
            'cost' => $cost,
            'cost_price' => $cost,
            'available_online' => $catalogActive && $catalogNotDeleted,
            'is_orderable' => !$hasVariants && $catalogActive && $catalogNotDeleted,
            'has_variants' => $hasVariants,
            'is_variant_child' => $isVariantChild,
            'parent_item_id' => $isVariantChild ? (int) ($variantParent['parent_item_id'] ?? 0) : null,
            'variant_label' => $isVariantChild ? (string) ($variantParent['variant_label'] ?? '') : null,
            'isdeleted' => (int) ($item['isdeleted'] ?? 0),
            'is_active' => $catalogActive,
            'menu_version' => $revision,
            'legacy' => [
                'code' => $this->intOrNull($item['code'] ?? null),
                'info' => $this->nullableString($item['info'] ?? null),
                'market_price' => $this->decimalString($item['market_price'] ?? null),
                'user' => $this->intOrNull($item['user'] ?? null),
                'tenant' => $this->intOrZero($item['tenant'] ?? 0),
                'branch' => $this->intOrZero($item['branch'] ?? 0),
                'item_type' => $this->nullableString($item['item_type'] ?? null),
                'track_stock' => $this->intOrZero($item['track_stock'] ?? 1),
                'manual_price_edit' => $this->intOrZero($item['manual_price_edit'] ?? 0),
            ],
        ];
        $menuItem['variants'] = array_map(function (array $variant): array {
            return [
                'relation_id' => (int) ($variant['relation_id'] ?? 0),
                'item_id' => (int) ($variant['variant_item_id'] ?? $variant['item_id'] ?? 0),
                'variant_item_id' => (int) ($variant['variant_item_id'] ?? $variant['item_id'] ?? 0),
                'label' => (string) ($variant['variant_label'] ?? $variant['label'] ?? ''),
                'name' => (string) ($variant['iname'] ?? $variant['name'] ?? ''),
                'barcode' => (string) ($variant['barcode'] ?? ''),
                'price' => $this->decimalString($variant['price1'] ?? $variant['price'] ?? null),
                'price1' => $this->decimalString($variant['price1'] ?? $variant['price'] ?? null),
                'price2' => $this->decimalString($variant['price2'] ?? null),
                'price3' => $this->decimalString($variant['price3'] ?? null),
                'cost_price' => $this->decimalString($variant['cost_price'] ?? null),
                'sort_order' => (int) ($variant['sort_order'] ?? 0),
                'is_default' => (bool) ($variant['is_default'] ?? false),
                'is_active' => (bool) ($variant['is_active'] ?? true),
                'is_orderable' => true,
            ];
        }, $variants);

        $menuItem['modifier_groups'] = $this->modifierGroupsForMenuItem($conn, $itemId);

        $recipeAvailability = $this->recipeMenuAvailabilityPayload($conn, $branchUuid, $menuItem, $options);
        if ($recipeAvailability !== null) {
            $menuItem['recipe_availability'] = $recipeAvailability;
            foreach ([
                'recipe_enabled',
                'active_recipe_version',
                'computed_available_qty',
                'effective_available_qty',
                'effective_is_available',
                'unavailable_reason',
                'availability_revision',
            ] as $key) {
                if (array_key_exists($key, $recipeAvailability)) {
                    $menuItem[$key] = $recipeAvailability[$key];
                }
            }

            if (!empty($recipeAvailability['recipe_enabled']) && empty($recipeAvailability['effective_is_available'])) {
                $menuItem['available_online'] = false;
                $menuItem['is_orderable'] = false;
            }
        }

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'pos_menu_item',
            'source_system' => $this->sourceSystem($options['source_system'] ?? null),
            'branch_uuid' => $branchUuid,
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'item_uuid' => $itemUuid,
            'local_item_id' => $itemId,
            'menu_item' => $menuItem,
        ];
        $payload['payload_hash'] = hash('sha256', $this->encodeJson($payload));

        return $payload;
    }

    private function recipeMenuAvailabilityPayload(mysqli $conn, string $branchUuid, array $menuItem, array $options): ?array
    {
        $config = is_array($options['config'] ?? null) ? $options['config'] : [];
        $flags = new RecipeFeatureFlags($config);
        if (!$flags->isMoovaSyncEnabled()) {
            return null;
        }

        $scope = (new RecipeScopeResolver($config))->resolveForConn($conn, [
            'pos_tenant' => $options['pos_tenant'] ?? null,
            'pos_branch' => $options['pos_branch'] ?? null,
            'branch_uuid' => $branchUuid,
            'store_id' => $options['store_id'] ?? 0,
            'channel' => 'moova',
            'order_type' => 'delivery',
            'source' => $options['source_system'] ?? 'pos',
        ], 'read');

        return (new RecipeSyncPayloadService($flags))->menuItemSnapshotPayload(
            $conn,
            $scope,
            $menuItem,
            'delivery',
            'moova'
        );
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

    private function revisionFromItem(array $item): int
    {
        foreach (['mdtime', 'updated_at', 'crtime', 'created_at'] as $key) {
            if (empty($item[$key])) {
                continue;
            }

            $timestamp = strtotime((string) $item[$key]);
            if ($timestamp !== false) {
                return max(1, (int) $timestamp);
            }
        }

        return max(1, (int) ($item['id'] ?? 1));
    }

    private function modifierGroupsForMenuItem(mysqli $conn, int $itemId): array
    {
        if (
            $itemId <= 0
            || !$this->tableExists($conn, 'modifier_groups')
            || !$this->tableExists($conn, 'modifier_options')
            || !$this->tableExists($conn, 'item_modifier_groups')
        ) {
            return [];
        }

        $stmt = $conn->prepare('
            SELECT mg.*, COALESCE(img.sort_order, mg.sort_order, 0) AS item_sort_order
            FROM item_modifier_groups img
            JOIN modifier_groups mg ON mg.id = img.group_id
            WHERE img.item_id = ?
            ORDER BY img.sort_order, mg.sort_order, mg.id
        ');
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $groups = [];
        while ($row = $result->fetch_assoc()) {
            $groupId = (int) $row['id'];
            $groups[$groupId] = [
                'modifier_group_id' => $groupId,
                'group_id' => $groupId,
                'local_group_id' => $groupId,
                'name_ar' => $row['name_ar'] ?? null,
                'name_en' => $row['name_en'] ?? null,
                'selection_min' => $row['selection_min'] ?? 0,
                'selection_max' => $row['selection_max'] ?? 0,
                'is_required' => $row['is_required'] ?? 0,
                'is_active' => $row['is_active'] ?? 1,
                'sort_order' => $row['item_sort_order'] ?? 0,
                'options' => [],
            ];
        }
        $stmt->close();

        if ($groups === []) {
            return [];
        }

        $groupIds = array_keys($groups);
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $conn->prepare("
            SELECT *
            FROM modifier_options
            WHERE group_id IN ({$placeholders})
            ORDER BY group_id, sort_order, id
        ");
        $stmt->bind_param(str_repeat('i', count($groupIds)), ...$groupIds);
        $stmt->execute();
        $optionResult = $stmt->get_result();
        while ($option = $optionResult->fetch_assoc()) {
            $groupId = (int) ($option['group_id'] ?? 0);
            if (!isset($groups[$groupId])) {
                continue;
            }
            $optionId = (int) ($option['id'] ?? 0);
            $groups[$groupId]['options'][] = [
                'modifier_option_id' => $optionId,
                'option_id' => $optionId,
                'local_option_id' => $optionId,
                'name_ar' => $option['name_ar'] ?? null,
                'name_en' => $option['name_en'] ?? null,
                'price_delta' => $option['price_delta'] ?? 0,
                'is_active' => $option['is_active'] ?? 1,
                'sort_order' => $option['sort_order'] ?? 0,
            ];
        }
        $stmt->close();

        return array_values($groups);
    }

    private function decimalString($value): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
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

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result && $result->num_rows > 0;
    }
}
