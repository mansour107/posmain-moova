<?php

require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/../Router/ShopRouter.php';

class CloudBranchSyncPublisher
{
    public function publish(mysqli $conn, array $event, array $config = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        if (!$this->shouldPublish($config) || !$this->tableExists($conn, 'cloud_sync_branch_events')) {
            return [];
        }

        $payload = isset($event['payload']) && is_array($event['payload']) ? $event['payload'] : [];
        $payloadJson = $this->encodeJson($payload);
        $payloadHash = trim((string) ($event['payload_hash'] ?? ''));
        if ($payloadHash === '') {
            $payloadHash = hash('sha256', $payloadJson);
        }

        $eventType = $this->stringOrDefault($event['event_type'] ?? null, 'pos.updated', 80);
        $entityType = $this->stringOrDefault($event['entity_type'] ?? null, $event['aggregate_type'] ?? 'pos_entity', 50);
        $aggregateType = $this->stringOrDefault($event['aggregate_type'] ?? null, $entityType, 50);
        $eventVersion = max(1, (int) ($event['event_version'] ?? $this->revisionFromPayload($payload)));
        $sourceSystem = $this->stringOrDefault($event['source_system'] ?? null, 'cloud_pos', 40);
        $aggregateUuid = $this->uuidOrNull($event['aggregate_uuid'] ?? null);
        $entityUuid = $this->uuidOrNull($event['entity_uuid'] ?? null);
        $aggregateLocalId = $this->intOrNull($event['aggregate_local_id'] ?? null);
        $entityLocalId = $this->intOrNull($event['entity_local_id'] ?? null);
        $aggregateId = $this->stringOrDefault($event['aggregate_id'] ?? null, '', 191);
        $targets = $this->targetBranchUuids($conn, $config, $payload, (string) ($event['branch_uuid'] ?? ''));
        $published = [];

        foreach ($targets as $targetBranchUuid) {
            $eventUuid = SyncBranchIdentity::generateUuidV4();
            $idempotencyKey = $this->idempotencyKey($targetBranchUuid, $entityType, $entityLocalId, $eventType, $payloadHash);
            $stmt = $conn->prepare("
                INSERT INTO cloud_sync_branch_events (
                    event_uuid,
                    branch_uuid,
                    event_type,
                    event_version,
                    source_system,
                    aggregate_type,
                    aggregate_uuid,
                    aggregate_local_id,
                    aggregate_id,
                    entity_type,
                    entity_uuid,
                    entity_local_id,
                    idempotency_key,
                    payload_hash,
                    payload_json,
                    status,
                    attempts
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0)
                ON DUPLICATE KEY UPDATE
                    id = LAST_INSERT_ID(id),
                    event_uuid = VALUES(event_uuid),
                    event_version = VALUES(event_version),
                    source_system = VALUES(source_system),
                    aggregate_uuid = VALUES(aggregate_uuid),
                    aggregate_local_id = VALUES(aggregate_local_id),
                    aggregate_id = VALUES(aggregate_id),
                    entity_uuid = VALUES(entity_uuid),
                    entity_local_id = VALUES(entity_local_id),
                    payload_hash = VALUES(payload_hash),
                    payload_json = VALUES(payload_json),
                    status = IF(status IN ('ack_failed','dead'), 'pending', status),
                    last_error = NULL
            ");

            $params = [
                $eventUuid,
                $targetBranchUuid,
                $eventType,
                $eventVersion,
                $sourceSystem,
                $aggregateType,
                $aggregateUuid,
                $aggregateLocalId,
                $aggregateId,
                $entityType,
                $entityUuid,
                $entityLocalId,
                $idempotencyKey,
                $payloadHash,
                $payloadJson,
            ];
            $this->bindParams($stmt, str_repeat('s', count($params)), $params);
            $stmt->execute();
            $published[] = [
                'branch_uuid' => $targetBranchUuid,
                'cloud_branch_event_id' => (int) $conn->insert_id,
                'event_uuid' => $eventUuid,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
            ];
            $stmt->close();
        }

        return $published;
    }

    private function shouldPublish(array $config): bool
    {
        return in_array((string) ($config['role'] ?? 'branch'), ['cloud', 'fake_cloud'], true)
            && !empty($config['sync']['cloud_to_branch_publish_enabled']);
    }

    private function targetBranchUuids(mysqli $conn, array $config, array $payload, string $sourceBranchUuid): array
    {
        $routerTargets = $this->routerBranchUuidsForCurrentShop($conn, $config);
        if ($routerTargets !== null) {
            $payloadBranchUuid = strtolower(trim((string) ($payload['branch_uuid'] ?? $sourceBranchUuid)));
            if (SyncBranchIdentity::isUuid($payloadBranchUuid) && in_array($payloadBranchUuid, $routerTargets, true)) {
                return [$payloadBranchUuid];
            }

            $configured = strtolower(trim((string) ($config['branch']['uuid'] ?? '')));
            if (SyncBranchIdentity::isUuid($configured) && in_array($configured, $routerTargets, true)) {
                return [$configured];
            }

            return $routerTargets;
        }

        $configured = trim((string) ($config['branch']['uuid'] ?? ''));
        if (SyncBranchIdentity::isUuid($configured) && $this->cloudBranchExists($conn, $configured)) {
            return [$configured];
        }

        $payloadBranchUuid = trim((string) ($payload['branch_uuid'] ?? $sourceBranchUuid));
        if (SyncBranchIdentity::isUuid($payloadBranchUuid) && $this->cloudBranchExists($conn, $payloadBranchUuid)) {
            return [$payloadBranchUuid];
        }

        if (!$this->tableExists($conn, 'cloud_branches')) {
            return SyncBranchIdentity::isUuid($payloadBranchUuid) ? [$payloadBranchUuid] : [];
        }

        $rows = $conn->query("
            SELECT branch_uuid
            FROM cloud_branches
            WHERE status = 'active'
            ORDER BY id ASC
        ");

        $targets = [];
        while ($row = $rows->fetch_assoc()) {
            $branchUuid = (string) ($row['branch_uuid'] ?? '');
            if (SyncBranchIdentity::isUuid($branchUuid)) {
                $targets[] = $branchUuid;
            }
        }

        return array_values(array_unique($targets));
    }

    private function routerBranchUuidsForCurrentShop(mysqli $shopConn, array $config): ?array
    {
        if (!PosmainShopRouter::enabled($config)) {
            return null;
        }

        $shopDbName = $this->currentDatabaseName($shopConn);
        if ($shopDbName === '') {
            return [];
        }

        try {
            $routerConn = PosmainShopRouter::connectRouter($config);
        } catch (Throwable $e) {
            error_log('Router branch target lookup unavailable: ' . $e->getMessage());
            return [];
        }

        try {
            $router = new PosmainShopRouter();
            $shop = $router->findShopByDatabaseName($routerConn, $shopDbName);
            if (!$shop) {
                return [];
            }

            $shopId = (int) ($shop['id'] ?? 0);
            $stmt = $routerConn->prepare("
                SELECT r.branch_uuid
                FROM router_branch_routes r
                INNER JOIN router_shops s ON s.id = r.shop_id
                WHERE r.shop_id = ?
                  AND r.status = 'active'
                  AND s.status = 'active'
                ORDER BY r.id ASC
            ");
            $stmt->bind_param('i', $shopId);
            $stmt->execute();
            $result = $stmt->get_result();
            $targets = [];
            while ($row = $result->fetch_assoc()) {
                $branchUuid = strtolower(trim((string) ($row['branch_uuid'] ?? '')));
                if (SyncBranchIdentity::isUuid($branchUuid)) {
                    $targets[] = $branchUuid;
                }
            }
            $stmt->close();

            return array_values(array_unique($targets));
        } finally {
            $routerConn->close();
        }
    }

    private function currentDatabaseName(mysqli $conn): string
    {
        $result = $conn->query('SELECT DATABASE() AS db_name');
        $row = $result ? $result->fetch_assoc() : [];

        return trim((string) ($row['db_name'] ?? ''));
    }

    private function cloudBranchExists(mysqli $conn, string $branchUuid): bool
    {
        if (!$this->tableExists($conn, 'cloud_branches')) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS branch_count
            FROM cloud_branches
            WHERE branch_uuid = ?
              AND status = 'active'
        ");
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ((int) ($row['branch_count'] ?? 0)) > 0;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result && $result->num_rows > 0;
    }

    private function idempotencyKey(string $branchUuid, string $entityType, ?int $entityLocalId, string $eventType, string $payloadHash): string
    {
        $entityId = $entityLocalId !== null ? (string) $entityLocalId : 'unknown';

        return 'cloud:' . $entityType . ':' . $entityId . ':' . $eventType . ':' . substr(hash('sha256', $branchUuid . ':' . $payloadHash), 0, 32);
    }

    private function revisionFromPayload(array $payload): int
    {
        foreach ([
            $payload['order']['sync_revision'] ?? null,
            $payload['table']['sync_revision'] ?? null,
            $payload['menu_item']['menu_version'] ?? null,
            $payload['sync_revision'] ?? null,
            $payload['revision'] ?? null,
        ] as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return 1;
    }

    private function uuidOrNull($value): ?string
    {
        $value = trim((string) $value);
        return SyncBranchIdentity::isUuid($value) ? strtolower($value) : null;
    }

    private function stringOrDefault($value, string $default, int $maxLength): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            $value = $default;
        }

        return substr($value, 0, $maxLength);
    }

    private function intOrNull($value): ?int
    {
        if ($value === null || $value === false || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function encodeJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to encode cloud branch sync payload JSON.');
        }

        return $json;
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }

        $stmt->bind_param($types, ...$refs);
    }
}
