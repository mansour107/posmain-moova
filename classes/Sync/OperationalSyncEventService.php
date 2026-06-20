<?php

require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/OperationalSyncDomains.php';
require_once __DIR__ . '/PosOrderSnapshotBuilder.php';
require_once __DIR__ . '/../Recipe/Repository/RecipeRepository.php';
require_once __DIR__ . '/../Recipe/Repository/RecipeLineRepository.php';
require_once __DIR__ . '/../Recipe/Repository/RecipeVariantLineRepository.php';

class OperationalSyncEventService
{
    private SyncBranchIdentity $branchIdentity;

    public function __construct(?SyncBranchIdentity $branchIdentity = null)
    {
        $this->branchIdentity = $branchIdentity ?: new SyncBranchIdentity();
    }

    public function recordRowSnapshot(mysqli $conn, string $domain, int $rowId, array $options = []): ?array
    {
        if (!$this->enabled($options['config'] ?? null)) {
            return null;
        }

        $definition = OperationalSyncDomains::get($domain);
        if (!$definition || !empty($definition['composite'])) {
            throw new InvalidArgumentException('Unsupported operational sync domain: ' . $domain);
        }

        $table = (string) $definition['table'];
        if (!$this->tableExists($conn, $table)) {
            return null;
        }

        $row = $this->fetchRow($conn, $table, $rowId);
        if (!$row) {
            return null;
        }

        return $this->recordRowPayload($conn, $domain, $definition, $row, $options);
    }

    public function recordRowDelete(mysqli $conn, string $domain, int $rowId, array $options = []): ?array
    {
        if (!$this->enabled($options['config'] ?? null)) {
            return null;
        }

        $definition = OperationalSyncDomains::get($domain);
        if (!$definition || !empty($definition['composite'])) {
            throw new InvalidArgumentException('Unsupported operational sync domain: ' . $domain);
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $eventType = (string) ($options['event_type'] ?? str_replace('.saved', '.deleted', (string) $definition['event_type']));
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? null);
        $entityUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, $definition['table'] . ':' . $rowId);

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'operational_delete',
            'domain' => $domain,
            'table' => (string) $definition['table'],
            'primary_key' => 'id',
            'row_id' => $rowId,
            'branch_uuid' => $branchUuid,
            'source_system' => $sourceSystem,
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'aggregate_type' => (string) $definition['aggregate_type'],
            'entity_type' => (string) $definition['entity_type'],
            'aggregate_local_id' => $rowId,
            'entity_local_id' => $rowId,
            'aggregate_uuid' => $entityUuid,
            'entity_uuid' => $entityUuid,
            'aggregate_id' => $definition['table'] . ':' . $rowId,
            'payload' => $payload,
            'event_version' => 1,
            'idempotency_suffix' => $eventType . ':delete',
        ]);
    }

    public function recordRecipeSnapshot(mysqli $conn, int $recipeId, array $options = []): ?array
    {
        if (!$this->enabled($options['config'] ?? null)) {
            return null;
        }

        if ($recipeId <= 0 || !$this->tableExists($conn, 'recipe_headers')) {
            return null;
        }

        $repo = new RecipeRepository();
        $header = $repo->findHeaderById($conn, $recipeId);
        if (!$header) {
            return null;
        }

        $lines = (new RecipeLineRepository())->findLinesByRecipeId($conn, $recipeId);
        $variantLines = [];
        if ($this->tableExists($conn, 'recipe_variant_lines')) {
            $variantRepo = new RecipeVariantLineRepository();
            $grouped = $variantRepo->findLinesGroupedByRecipe($conn, $recipeId);
            foreach ($grouped as $rows) {
                foreach ($rows as $row) {
                    $variantLines[] = $row;
                }
            }
        }
        $costSnapshots = [];
        if ($this->tableExists($conn, 'recipe_cost_snapshots')) {
            $result = $conn->query("
                SELECT *
                FROM recipe_cost_snapshots
                WHERE recipe_id = {$recipeId}
                ORDER BY id ASC
            ");
            while ($row = $result->fetch_assoc()) {
                $costSnapshots[] = $row;
            }
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $eventType = (string) ($options['event_type'] ?? 'recipe.saved');
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? null);
        $recipeUuid = (string) ($header['recipe_uuid'] ?? PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'recipe_headers:' . $recipeId));

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'recipe_bundle',
            'domain' => 'recipe',
            'branch_uuid' => $branchUuid,
            'source_system' => $sourceSystem,
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'recipe_id' => $recipeId,
            'recipe_uuid' => $recipeUuid,
            'header' => $header,
            'lines' => $lines,
            'variant_lines' => $variantLines,
            'cost_snapshots' => $costSnapshots,
        ];
        $payload['payload_hash'] = hash('sha256', $this->encodeJson($payload));

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'recipe',
            'entity_type' => 'recipe',
            'aggregate_local_id' => $recipeId,
            'entity_local_id' => $recipeId,
            'aggregate_uuid' => $recipeUuid,
            'entity_uuid' => $recipeUuid,
            'aggregate_id' => 'recipe_headers:' . $recipeId,
            'payload' => $payload,
            'event_version' => max(1, (int) ($header['version_number'] ?? 1)),
            'idempotency_suffix' => $eventType,
        ]);
    }

    private function recordRowPayload(mysqli $conn, string $domain, array $definition, array $row, array $options): ?array
    {
        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $rowId = (int) ($row['id'] ?? 0);
        if ($rowId <= 0) {
            return null;
        }

        $eventType = (string) ($options['event_type'] ?? $definition['event_type']);
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? null);
        $entityUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, $definition['table'] . ':' . $rowId);
        $sanitizedRow = $this->sanitizeRow($row, $definition['exclude_columns'] ?? []);

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'operational_row',
            'domain' => $domain,
            'table' => (string) $definition['table'],
            'primary_key' => 'id',
            'row' => $sanitizedRow,
            'branch_uuid' => $branchUuid,
            'source_system' => $sourceSystem,
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $payload['payload_hash'] = hash('sha256', $this->encodeJson($payload));

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'aggregate_type' => (string) $definition['aggregate_type'],
            'entity_type' => (string) $definition['entity_type'],
            'aggregate_local_id' => $rowId,
            'entity_local_id' => $rowId,
            'aggregate_uuid' => $entityUuid,
            'entity_uuid' => $entityUuid,
            'aggregate_id' => $definition['table'] . ':' . $rowId,
            'payload' => $payload,
            'event_version' => $this->revisionFromRow($sanitizedRow),
            'idempotency_suffix' => $eventType,
        ]);
    }

    private function insertOutbox(mysqli $conn, array $config, array $branch, array $event): ?array
    {
        if (empty($config['sync']['outbox_enabled'])) {
            return null;
        }

        $this->assertOutboxTableExists($conn);

        $branchUuid = (string) $branch['branch_uuid'];
        $posTenant = $this->intOrZero($branch['pos_tenant'] ?? ($config['branch']['pos_tenant'] ?? 0));
        $posBranch = $this->intOrZero($branch['pos_branch'] ?? ($config['branch']['pos_branch'] ?? 0));
        $payload = $event['payload'];
        $payloadJson = $this->encodeJson($payload);
        $payloadHash = hash('sha256', $payloadJson);
        $eventUuid = SyncBranchIdentity::generateUuidV4();
        $aggregateLocalId = (int) $event['aggregate_local_id'];
        $idempotencyKey = 'pos:' . $event['aggregate_type'] . ':' . $aggregateLocalId . ':' . $event['idempotency_suffix'] . ':' . substr(hash('sha256', $branchUuid . ':' . $payloadHash), 0, 32);

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
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 'pending', 0)
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
            (string) $event['aggregate_type'],
            (string) $event['aggregate_uuid'],
            $aggregateLocalId,
            (string) $event['aggregate_id'],
            (string) $event['entity_type'],
            (string) $event['entity_uuid'],
            $aggregateLocalId,
            (string) $event['event_type'],
            (int) $event['event_version'],
            (string) $event['source_system'],
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
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
        ];
    }

    private function enabled(?array $config): bool
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        return (string) ($config['role'] ?? 'branch') === 'branch'
            && !empty($config['sync']['outbox_enabled'])
            && !empty($config['sync']['branch_sync_enabled'])
            && !empty($config['sync']['operational_sync_enabled']);
    }

    private function fetchRow(mysqli $conn, string $table, int $rowId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $rowId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function sanitizeRow(array $row, array $excludeColumns): array
    {
        foreach ($excludeColumns as $column) {
            unset($row[$column]);
        }

        return $row;
    }

    private function revisionFromRow(array $row): int
    {
        foreach (['updated_at', 'mdtime', 'recorded_at', 'created_at', 'crtime'] as $key) {
            if (empty($row[$key])) {
                continue;
            }

            $timestamp = strtotime((string) $row[$key]);
            if ($timestamp !== false) {
                return max(1, (int) $timestamp);
            }
        }

        return max(1, (int) ($row['id'] ?? 1));
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
            throw new RuntimeException('Unable to encode operational sync payload JSON.');
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

    private function tableExists(mysqli $conn, string $table): bool
    {
        $escaped = $conn->real_escape_string($table);
        $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
        return $result && $result->num_rows > 0;
    }

    private function assertOutboxTableExists(mysqli $conn): void
    {
        $result = $conn->query("SHOW TABLES LIKE 'sync_outbox'");
        if (!$result || $result->num_rows < 1) {
            throw new RuntimeException('sync_outbox table is missing. Run tools/run_migrations.php --apply before enabling branch sync.');
        }
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
