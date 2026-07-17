<?php

require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/OperationalSyncDomains.php';
require_once __DIR__ . '/PosOrderSnapshotBuilder.php';
require_once __DIR__ . '/ShopSettingsSyncPayloadService.php';
require_once __DIR__ . '/ModifierGroupSyncPayloadService.php';
require_once __DIR__ . '/ShiftCloseSyncPayloadService.php';
require_once __DIR__ . '/PosCustomerSyncPayloadService.php';
require_once __DIR__ . '/InventoryAccountingSyncPayloadService.php';
require_once __DIR__ . '/InventoryCountSyncPayloadService.php';
require_once __DIR__ . '/ProductionBatchSyncPayloadService.php';
require_once __DIR__ . '/PurchaseReceiptSyncPayloadService.php';
require_once __DIR__ . '/PurchaseOrderSyncPayloadService.php';
require_once __DIR__ . '/DocumentCounterService.php';
require_once __DIR__ . '/CloudBranchSyncPublisher.php';
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
        if ($domain === 'drawer_movement') {
            return $this->recordDrawerMovementSnapshot($conn, $rowId, $options);
        }
        if ($domain === 'drawer_session') {
            throw new InvalidArgumentException('Drawer sessions require the typed movement or shift-close sync contract.');
        }
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

    public function recordCustomerSnapshot(mysqli $conn, int $customerId, array $options = []): ?array
    {
        if ($customerId < 1) {
            throw new InvalidArgumentException('CUSTOMER_SYNC_ID_REQUIRED');
        }
        if (!$this->enabled($options['config'] ?? null)) {
            return null;
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        // Customer edits made on the hosted management surface are not an
        // automatic reverse-sync feed. Cloud-to-branch remains the guarded,
        // explicit disaster-recovery workflow.
        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            return null;
        }

        $this->assertOutboxTableExists($conn);
        if (!$this->tableExists($conn, 'document_counters')) {
            throw new RuntimeException('document_counters table is missing. Run tools/run_migrations.php --apply before enabling branch sync.');
        }

        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $posTenant = $this->intOrZero($branch['pos_tenant'] ?? ($config['branch']['pos_tenant'] ?? 0));
        $posBranch = $this->intOrZero($branch['pos_branch'] ?? ($config['branch']['pos_branch'] ?? 0));
        $customerUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'pos_customers:' . $customerId);
        $eventType = (string) ($options['event_type'] ?? 'customer.snapshot');
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? null);

        $stmt = $conn->prepare("
            SELECT COALESCE(MAX(event_version), 0) AS max_version
            FROM sync_outbox
            WHERE aggregate_type = 'pos_customer'
              AND aggregate_uuid = ?
        ");
        $stmt->bind_param('s', $customerUuid);
        $stmt->execute();
        $highestRevision = (int) ($stmt->get_result()->fetch_assoc()['max_version'] ?? 0);
        $stmt->close();

        $counter = new DocumentCounterService();
        $counterKey = 'customer:' . $branchUuid . ':' . $customerId;
        $counter->ensureCounterRow(
            $conn,
            $posTenant,
            $posBranch,
            'customer_sync',
            $counterKey,
            $highestRevision
        );
        $revision = $counter->nextCounter(
            $conn,
            $posTenant,
            $posBranch,
            'customer_sync',
            $counterKey
        );

        $payload = (new PosCustomerSyncPayloadService())->build(
            $conn,
            $branchUuid,
            $customerId,
            $revision,
            ['source_system' => $sourceSystem]
        );

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'pos_customer',
            'entity_type' => 'pos_customer',
            'aggregate_local_id' => $customerId,
            'entity_local_id' => $customerId,
            'aggregate_uuid' => $customerUuid,
            'entity_uuid' => $customerUuid,
            'aggregate_id' => 'pos_customers:' . $customerId,
            'payload' => $payload,
            'event_version' => $revision,
            'idempotency_suffix' => $eventType . ':v' . $revision,
        ]);
    }

    public function recordInventoryAccountingSnapshot(
        mysqli $conn,
        int $journalHeadId,
        array $movementIds,
        array $options = []
    ): ?array {
        if ($journalHeadId < 1) {
            throw new InvalidArgumentException('INVENTORY_JOURNAL_SYNC_IDENTITY_INVALID');
        }
        if (!$this->enabled($options['config'] ?? null)) {
            return null;
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            return null;
        }

        $this->assertOutboxTableExists($conn);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $payload = (new InventoryAccountingSyncPayloadService())->build(
            $conn,
            $branchUuid,
            $journalHeadId,
            $movementIds
        );
        $eventType = (string) ($options['event_type'] ?? 'inventory.accounting_journal_saved');
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? 'inventory_accounting');
        $aggregateUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'journal_heads:' . $journalHeadId);

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'inventory_journal',
            'entity_type' => 'inventory_journal',
            'aggregate_local_id' => $journalHeadId,
            'entity_local_id' => $journalHeadId,
            'aggregate_uuid' => $aggregateUuid,
            'entity_uuid' => $aggregateUuid,
            'aggregate_id' => 'journal_heads:' . $journalHeadId,
            'payload' => $payload,
            'event_version' => 1,
            'idempotency_suffix' => $eventType . ':v1',
        ]);
    }

    public function recordInventoryCountSnapshot(mysqli $conn, int $countId, array $options = []): ?array
    {
        if ($countId < 1) {
            throw new InvalidArgumentException('INVENTORY_COUNT_SYNC_IDENTITY_INVALID');
        }
        if (!$this->enabled($options['config'] ?? null)) {
            return null;
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            return null;
        }

        $this->assertOutboxTableExists($conn);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $legacyIdentity = $conn->prepare("UPDATE inventory_counts
            SET branch_uuid = ?
            WHERE id = ?
              AND sync_revision <= 1
              AND (branch_uuid IS NULL OR branch_uuid = '')");
        $legacyIdentity->bind_param('si', $branchUuid, $countId);
        $legacyIdentity->execute();
        $legacyIdentity->close();
        $payload = (new InventoryCountSyncPayloadService())->build($conn, $branchUuid, $countId);
        $count = $payload['inventory_count'];
        $countUuid = strtolower(trim((string) $count['count_uuid']));
        $revision = (int) $payload['sync_revision'];
        $eventType = (string) ($options['event_type'] ?? 'inventory.count_snapshot');
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? 'inventory_count');

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'inventory_count',
            'entity_type' => 'inventory_count',
            'aggregate_local_id' => $countId,
            'entity_local_id' => $countId,
            'aggregate_uuid' => $countUuid,
            'entity_uuid' => $countUuid,
            'aggregate_id' => 'inventory_counts:' . $countId,
            'payload' => $payload,
            'event_version' => $revision,
            'idempotency_suffix' => $eventType . ':v' . $revision,
        ]);
    }

    public function recordProductionBatchSnapshot(mysqli $conn, int $batchId, array $options = []): ?array
    {
        if ($batchId < 1) {
            throw new InvalidArgumentException('PRODUCTION_BATCH_SYNC_IDENTITY_INVALID');
        }
        if (!$this->enabled($options['config'] ?? null)) {
            return null;
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            return null;
        }

        $this->assertOutboxTableExists($conn);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $legacyIdentity = $conn->prepare("UPDATE production_batches
            SET branch_uuid = ?
            WHERE id = ?
              AND sync_revision <= 1
              AND (branch_uuid IS NULL OR branch_uuid = '')");
        $legacyIdentity->bind_param('si', $branchUuid, $batchId);
        $legacyIdentity->execute();
        $legacyIdentity->close();
        $payload = (new ProductionBatchSyncPayloadService())->build($conn, $branchUuid, $batchId);
        $batch = $payload['production_batch'];
        $batchUuid = strtolower(trim((string) $batch['batch_uuid']));
        $revision = (int) $payload['sync_revision'];
        $eventType = (string) ($options['event_type'] ?? 'production.batch_snapshot');
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? 'production_batch');

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'production_batch',
            'entity_type' => 'production_batch',
            'aggregate_local_id' => $batchId,
            'entity_local_id' => $batchId,
            'aggregate_uuid' => $batchUuid,
            'entity_uuid' => $batchUuid,
            'aggregate_id' => 'production_batches:' . $batchId,
            'payload' => $payload,
            'event_version' => $revision,
            'idempotency_suffix' => $eventType . ':v' . $revision,
        ]);
    }

    public function recordPurchaseReceiptSnapshot(mysqli $conn, int $receiptId, array $options = []): ?array
    {
        if ($receiptId < 1) {
            throw new InvalidArgumentException('PURCHASE_RECEIPT_SYNC_IDENTITY_INVALID');
        }
        if (!$this->enabled($options['config'] ?? null)) {
            return null;
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        if ((string) ($config['role'] ?? 'branch') !== 'branch') {
            return null;
        }

        $this->assertOutboxTableExists($conn);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $legacyIdentity = $conn->prepare("UPDATE inventory_purchase_receipts
            SET branch_uuid = ?
            WHERE id = ?
              AND (branch_uuid IS NULL OR branch_uuid = '')");
        $legacyIdentity->bind_param('si', $branchUuid, $receiptId);
        $legacyIdentity->execute();
        $legacyIdentity->close();
        $payload = (new PurchaseReceiptSyncPayloadService())->build($conn, $branchUuid, $receiptId);
        $receipt = $payload['purchase_receipt'];
        $receiptUuid = strtolower(trim((string) $receipt['purchase_receipt_uuid']));
        $eventType = (string) ($options['event_type'] ?? 'inventory.purchase_receipt_snapshot');
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? 'inventory_purchase_receiving');

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'purchase_receipt',
            'entity_type' => 'purchase_receipt',
            'aggregate_local_id' => $receiptId,
            'entity_local_id' => $receiptId,
            'aggregate_uuid' => $receiptUuid,
            'entity_uuid' => $receiptUuid,
            'aggregate_id' => 'inventory_purchase_receipts:' . $receiptId,
            'payload' => $payload,
            'event_version' => 1,
            'idempotency_suffix' => $eventType . ':v1',
        ]);
    }

    public function recordPurchaseOrderSnapshot(mysqli $conn, int $orderId, array $options = []): ?array
    {
        if ($orderId < 1) throw new InvalidArgumentException('PURCHASE_ORDER_SYNC_IDENTITY_INVALID');
        if (!$this->enabled($options['config'] ?? null)) return null;
        $config=$options['config']??(function_exists('posmain_app_config')?posmain_app_config():[]);
        if ((string)($config['role']??'branch')!=='branch') return null;
        $this->assertOutboxTableExists($conn); $branch=$this->branchIdentity->ensure($conn,$config); $branchUuid=(string)$branch['branch_uuid'];
        $stmt=$conn->prepare("UPDATE inventory_purchase_orders SET branch_uuid = ?, sync_revision = GREATEST(sync_revision, 1) WHERE id = ? AND (branch_uuid IS NULL OR branch_uuid = '')");
        $stmt->bind_param('si',$branchUuid,$orderId);$stmt->execute();$stmt->close();
        $payload=(new PurchaseOrderSyncPayloadService())->build($conn,$branchUuid,$orderId);$order=$payload['purchase_order'];$revision=(int)$payload['sync_revision'];
        $eventType=(string)($options['event_type']??'inventory.purchase_order_snapshot');
        return $this->insertOutbox($conn,$config,$branch,['event_type'=>$eventType,'source_system'=>$this->sourceSystem($options['source_system']??'inventory_purchase_order'),'aggregate_type'=>'purchase_order','entity_type'=>'purchase_order','aggregate_local_id'=>$orderId,'entity_local_id'=>$orderId,'aggregate_uuid'=>strtolower((string)$order['purchase_order_uuid']),'entity_uuid'=>strtolower((string)$order['purchase_order_uuid']),'aggregate_id'=>'inventory_purchase_orders:'.$orderId,'payload'=>$payload,'event_version'=>$revision,'idempotency_suffix'=>$eventType.':v'.$revision]);
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

    public function recordShopSettingsSnapshot(mysqli $conn, array $options = []): ?array
    {
        if (!$this->enabled($options['config'] ?? null)) {
            return null;
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $payload = (new ShopSettingsSyncPayloadService())->build($conn, $branchUuid, $options);
        if (!$payload) {
            return null;
        }

        $entityUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'settings:1');

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => (string) ($options['event_type'] ?? 'shop_settings.saved'),
            'source_system' => $this->sourceSystem($options['source_system'] ?? null),
            'aggregate_type' => 'shop_settings',
            'entity_type' => 'shop_settings',
            'aggregate_local_id' => 1,
            'entity_local_id' => 1,
            'aggregate_uuid' => $entityUuid,
            'entity_uuid' => $entityUuid,
            'aggregate_id' => 'settings:1',
            'payload' => $payload,
            'event_version' => 1,
            'idempotency_suffix' => 'shop_settings.saved',
        ]);
    }

    public function recordModifierGroupSnapshot(mysqli $conn, int $groupId, array $options = []): ?array
    {
        if (!$this->enabled($options['config'] ?? null) || $groupId <= 0) {
            return null;
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $payload = (new ModifierGroupSyncPayloadService())->build($conn, $groupId, $branchUuid, $options);
        if (!$payload) {
            return null;
        }

        $entityUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'modifier_groups:' . $groupId);

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => (string) ($options['event_type'] ?? 'modifier_group.saved'),
            'source_system' => $this->sourceSystem($options['source_system'] ?? null),
            'aggregate_type' => 'modifier_group',
            'entity_type' => 'modifier_group',
            'aggregate_local_id' => $groupId,
            'entity_local_id' => $groupId,
            'aggregate_uuid' => $entityUuid,
            'entity_uuid' => $entityUuid,
            'aggregate_id' => 'modifier_groups:' . $groupId,
            'payload' => $payload,
            'event_version' => 1,
            'idempotency_suffix' => 'modifier_group.saved',
        ]);
    }

    public function recordMoovaShopLinkSnapshot(mysqli $conn, int $linkId, array $options = []): ?array
    {
        if (!$this->enabled($options['config'] ?? null) || $linkId <= 0 || !$this->tableExists($conn, 'moova_pos_shop_links')) {
            return null;
        }

        $row = $this->fetchRow($conn, 'moova_pos_shop_links', $linkId);
        if (!$row) {
            return null;
        }
        unset($row['moova_device_token_hash']);

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $entityUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'moova_pos_shop_links:' . $linkId);
        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'moova_shop_link',
            'branch_uuid' => $branchUuid,
            'source_system' => $this->sourceSystem($options['source_system'] ?? null),
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'link' => $row,
        ];

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => (string) ($options['event_type'] ?? 'moova.shop_link_saved'),
            'source_system' => $this->sourceSystem($options['source_system'] ?? null),
            'aggregate_type' => 'moova_shop_link',
            'entity_type' => 'moova_shop_link',
            'aggregate_local_id' => $linkId,
            'entity_local_id' => $linkId,
            'aggregate_uuid' => $entityUuid,
            'entity_uuid' => $entityUuid,
            'aggregate_id' => 'moova_pos_shop_links:' . $linkId,
            'payload' => $payload,
            'event_version' => $this->revisionFromRow($row),
            'idempotency_suffix' => 'moova.shop_link_saved',
        ]);
    }

    public function recordShiftCloseSnapshot(mysqli $conn, int $closeSummaryId, array $options = []): ?array
    {
        if (!$this->enabled($options['config'] ?? null) || $closeSummaryId <= 0) {
            return null;
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $payload = (new ShiftCloseSyncPayloadService())->build($conn, $closeSummaryId, $branchUuid, $options);
        if (!$payload) {
            return null;
        }

        $drawerSessionId = (int) ($payload['shift']['local_drawer_session_id'] ?? 0);
        $entityUuid = trim((string) ($payload['close_uuid'] ?? ''));
        if ($entityUuid === '') {
            $entityUuid = PosOrderSnapshotBuilder::deterministicUuid(
                $branchUuid,
                'drawer_session_close_summaries:' . $closeSummaryId
            );
            $payload['close_uuid'] = $entityUuid;
            if (isset($payload['shift']) && is_array($payload['shift'])) {
                $payload['shift']['close_uuid'] = $entityUuid;
            }
        }

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => (string) ($options['event_type'] ?? 'shift_close.saved'),
            'source_system' => $this->sourceSystem($options['source_system'] ?? null),
            'aggregate_type' => 'shift_close',
            'entity_type' => 'shift_close',
            'aggregate_local_id' => $drawerSessionId,
            'entity_local_id' => $closeSummaryId,
            'aggregate_uuid' => $entityUuid,
            'entity_uuid' => $entityUuid,
            'aggregate_id' => 'drawer_sessions:' . $drawerSessionId . ':close',
            'payload' => $payload,
            'event_version' => 2,
            'idempotency_suffix' => 'shift_close.saved',
        ]);
    }

    public function recordDrawerMovementSnapshot(mysqli $conn, int $movementId, array $options = []): ?array
    {
        if (!$this->enabled($options['config'] ?? null) || $movementId <= 0) {
            return null;
        }

        $movement = $this->fetchRow($conn, 'drawer_movements', $movementId);
        if (!$movement) {
            return null;
        }

        $drawerSession = null;
        $drawerSessionUuid = null;
        $drawerSessionId = (int) ($movement['drawer_session_id'] ?? 0);
        if ($drawerSessionId > 0) {
            $drawerSession = $this->fetchRow($conn, 'drawer_sessions', $drawerSessionId);
            if (!$drawerSession || (int) ($drawerSession['id'] ?? 0) !== $drawerSessionId) {
                throw new RuntimeException('DRAWER_MOVEMENT_SESSION_NOT_FOUND');
            }
            $drawerSessionUuid = trim((string) ($drawerSession['uuid'] ?? ''));
            if (!SyncBranchIdentity::isUuid($drawerSessionUuid)) {
                throw new RuntimeException('DRAWER_MOVEMENT_SESSION_UUID_INVALID');
            }
            $drawerSession = array_intersect_key($drawerSession, array_flip([
                'id',
                'uuid',
                'user_id',
                'tenant',
                'branch',
                'fund_account_id',
                'opened_at',
                'opened_by',
                'opening_cash',
                'business_day',
            ]));
        }

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $entityUuid = PosOrderSnapshotBuilder::deterministicUuid($branchUuid, 'drawer_movements:' . $movementId);
        $eventType = (string) ($options['event_type'] ?? 'drawer_movement.saved');
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? null);
        $revision = (int) ($movement['ref_ot_head_id'] ?? 0) > 0 ? 2 : 1;

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'drawer_movement_bundle',
            'domain' => 'drawer_movement',
            'branch_uuid' => $branchUuid,
            'source_system' => $sourceSystem,
            'sync_revision' => $revision,
            'movement' => $movement,
            'drawer_session_uuid' => $drawerSessionUuid,
            'drawer_session' => $drawerSession,
        ];
        $payload['payload_hash'] = hash('sha256', $this->encodeJson($payload));

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'drawer_movement',
            'entity_type' => 'drawer_movement',
            'aggregate_local_id' => $movementId,
            'entity_local_id' => $movementId,
            'aggregate_uuid' => $entityUuid,
            'entity_uuid' => $entityUuid,
            'aggregate_id' => 'drawer_movements:' . $movementId,
            'payload' => $payload,
            'event_version' => $revision,
            'idempotency_suffix' => $eventType . ':v' . $revision,
        ]);
    }

    public function recordDrawerSessionSnapshot(mysqli $conn, int $sessionId, array $options = []): ?array
    {
        if (!$this->enabled($options['config'] ?? null) || $sessionId <= 0) {
            return null;
        }

        $session = $this->fetchRow($conn, 'drawer_sessions', $sessionId);
        if (!$session) {
            return null;
        }
        if (!array_key_exists('sync_revision', $session)) {
            throw new RuntimeException('DRAWER_SESSION_SYNC_REVISION_SCHEMA_REQUIRED');
        }
        $revision = (int) $session['sync_revision'];
        if ($revision < 1) {
            throw new RuntimeException('DRAWER_SESSION_SYNC_REVISION_INVALID');
        }
        $sessionUuid = trim((string) ($session['uuid'] ?? ''));
        if (!SyncBranchIdentity::isUuid($sessionUuid)) {
            throw new RuntimeException('DRAWER_SESSION_UUID_INVALID');
        }
        $session = $this->sanitizeRow($session, [
            'close_token_hash',
            'open_branch_lock',
            'open_register_lock',
            'open_user_lock',
            'preceding_session_id',
        ]);

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $eventType = (string) ($options['event_type'] ?? 'drawer_session.saved');
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? null);
        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'drawer_session_snapshot',
            'domain' => 'drawer_session',
            'branch_uuid' => $branchUuid,
            'source_system' => $sourceSystem,
            'sync_revision' => $revision,
            'drawer_session' => $session,
            'count_attempts' => $this->fetchRowsByForeignKey(
                $conn,
                'drawer_count_attempts',
                'drawer_session_id',
                $sessionId
            ),
            'resolutions' => $this->fetchRowsByForeignKey(
                $conn,
                'drawer_session_resolutions',
                'drawer_session_id',
                $sessionId
            ),
        ];
        $payload['payload_hash'] = hash('sha256', $this->encodeJson($payload));

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'drawer_session',
            'entity_type' => 'drawer_session',
            'aggregate_local_id' => $sessionId,
            'entity_local_id' => $sessionId,
            'aggregate_uuid' => $sessionUuid,
            'entity_uuid' => $sessionUuid,
            'aggregate_id' => 'drawer_sessions:' . $sessionId,
            'payload' => $payload,
            'event_version' => $revision,
            'idempotency_suffix' => $eventType . ':v' . $revision,
        ]);
    }

    public function recordFinancialRefundSnapshot(mysqli $conn, int $creditNoteId, array $options = []): ?array
    {
        if (!$this->enabled($options['config'] ?? null) || $creditNoteId <= 0) {
            return null;
        }

        $creditNote = $this->fetchRow($conn, 'credit_notes', $creditNoteId);
        if (!$creditNote) {
            return null;
        }

        $creditNoteUuid = trim((string) ($creditNote['uuid'] ?? ''));
        if (!SyncBranchIdentity::isUuid($creditNoteUuid)) {
            throw new RuntimeException('FINANCIAL_REFUND_UUID_INVALID');
        }

        $lines = $this->fetchRowsByForeignKey($conn, 'credit_note_lines', 'credit_note_id', $creditNoteId);
        $refunds = $this->fetchRowsByForeignKey($conn, 'payment_refunds', 'credit_note_id', $creditNoteId);
        $journalIds = [];
        foreach (array_merge([$creditNote], $refunds) as $row) {
            $journalId = (int) ($row['journal_head_id'] ?? 0);
            if ($journalId > 0) {
                $journalIds[$journalId] = $journalId;
            }
        }
        ksort($journalIds, SORT_NUMERIC);

        $journalHeads = $this->fetchRowsByIds($conn, 'journal_heads', array_values($journalIds));
        $journalEntries = $this->fetchRowsByForeignKeyIds(
            $conn,
            'journal_entries',
            'journal_id',
            array_values($journalIds)
        );

        $config = $options['config'] ?? (function_exists('posmain_app_config') ? posmain_app_config() : []);
        $branch = $this->branchIdentity->ensure($conn, $config);
        $branchUuid = (string) $branch['branch_uuid'];
        $eventType = (string) ($options['event_type'] ?? 'financial.refund_snapshot');
        $sourceSystem = $this->sourceSystem($options['source_system'] ?? null);
        $revision = $this->financialRefundRevision($creditNote, $refunds);

        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'financial_refund_bundle',
            'domain' => 'financial_refund',
            'branch_uuid' => $branchUuid,
            'source_system' => $sourceSystem,
            'captured_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'sync_revision' => $revision,
            'credit_note' => $creditNote,
            'credit_note_lines' => $lines,
            'payment_refunds' => $refunds,
            'journal_heads' => $journalHeads,
            'journal_entries' => $journalEntries,
        ];
        $payload['payload_hash'] = hash('sha256', $this->encodeJson($payload));

        return $this->insertOutbox($conn, $config, $branch, [
            'event_type' => $eventType,
            'source_system' => $sourceSystem,
            'aggregate_type' => 'financial_refund',
            'entity_type' => 'financial_refund',
            'aggregate_local_id' => $creditNoteId,
            'entity_local_id' => $creditNoteId,
            'aggregate_uuid' => $creditNoteUuid,
            'entity_uuid' => $creditNoteUuid,
            'aggregate_id' => 'credit_notes:' . $creditNoteId,
            'payload' => $payload,
            'event_version' => $revision,
            'idempotency_suffix' => $eventType . ':v' . $revision,
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
        if (isset($options['event_version']) && is_numeric($options['event_version'])) {
            $payload['sync_revision'] = max(1, (int) $options['event_version']);
        }
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
            'event_version' => isset($options['event_version']) && is_numeric($options['event_version'])
                ? max(1, (int) $options['event_version'])
                : $this->revisionFromRow($sanitizedRow),
            'idempotency_suffix' => $eventType,
        ]);
    }

    private function insertOutbox(mysqli $conn, array $config, array $branch, array $event): ?array
    {
        $branchUuid = (string) $branch['branch_uuid'];
        $payload = $event['payload'];
        $payloadJson = $this->encodeJson($payload);
        $payloadHash = hash('sha256', $payloadJson);
        $eventUuid = SyncBranchIdentity::generateUuidV4();
        $aggregateLocalId = (int) $event['aggregate_local_id'];
        $idempotencyKey = 'pos:' . $event['aggregate_type'] . ':' . $aggregateLocalId . ':' . $event['idempotency_suffix'] . ':' . substr(hash('sha256', $branchUuid . ':' . $payloadHash), 0, 32);
        $role = (string) ($config['role'] ?? 'branch');

        if (in_array($role, ['cloud', 'fake_cloud'], true) && !empty($config['sync']['cloud_to_branch_publish_enabled'])) {
            return [
                'outbox_id' => null,
                'event_uuid' => $eventUuid,
                'branch_uuid' => $branchUuid,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'cloud_branch_events' => (new CloudBranchSyncPublisher())->publish($conn, [
                    'branch_uuid' => $branchUuid,
                    'event_type' => (string) $event['event_type'],
                    'event_version' => (int) $event['event_version'],
                    'source_system' => (string) $event['source_system'],
                    'aggregate_type' => (string) $event['aggregate_type'],
                    'aggregate_uuid' => (string) $event['aggregate_uuid'],
                    'aggregate_local_id' => $aggregateLocalId,
                    'aggregate_id' => (string) $event['aggregate_id'],
                    'entity_type' => (string) $event['entity_type'],
                    'entity_uuid' => (string) $event['entity_uuid'],
                    'entity_local_id' => $aggregateLocalId,
                    'payload_hash' => $payloadHash,
                    'payload' => $payload,
                ], $config),
            ];
        }

        if (empty($config['sync']['outbox_enabled'])) {
            return null;
        }

        $this->assertOutboxTableExists($conn);

        $posTenant = $this->intOrZero($branch['pos_tenant'] ?? ($config['branch']['pos_tenant'] ?? 0));
        $posBranch = $this->intOrZero($branch['pos_branch'] ?? ($config['branch']['pos_branch'] ?? 0));

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
            'cloud_branch_events' => [],
        ];
    }

    private function enabled(?array $config): bool
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        if (empty($config['sync']['operational_sync_enabled'])) {
            return false;
        }

        $role = (string) ($config['role'] ?? 'branch');
        if ($role === 'branch') {
            return !empty($config['sync']['outbox_enabled'])
                && !empty($config['sync']['branch_sync_enabled']);
        }

        if (in_array($role, ['cloud', 'fake_cloud'], true)) {
            return !empty($config['sync']['cloud_to_branch_publish_enabled']);
        }

        return false;
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

    private function fetchRowsByForeignKey(mysqli $conn, string $table, string $column, int $value): array
    {
        if (!$this->tableExists($conn, $table)) {
            return [];
        }

        $stmt = $conn->prepare("SELECT * FROM `{$table}` WHERE `{$column}` = ? ORDER BY id ASC");
        $stmt->bind_param('i', $value);
        $stmt->execute();
        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    private function fetchRowsByIds(mysqli $conn, string $table, array $ids): array
    {
        return $this->fetchRowsByForeignKeyIds($conn, $table, 'id', $ids);
    }

    private function fetchRowsByForeignKeyIds(mysqli $conn, string $table, string $column, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === [] || !$this->tableExists($conn, $table)) {
            return [];
        }

        $sqlIds = implode(',', $ids);
        $result = $conn->query("SELECT * FROM `{$table}` WHERE `{$column}` IN ({$sqlIds}) ORDER BY id ASC");
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function financialRefundRevision(array $creditNote, array $refunds): int
    {
        $revision = 1;
        foreach ($refunds as $refund) {
            $revision += [
                'pending_external' => 0,
                'posted' => 1,
                'settled' => 2,
            ][(string) ($refund['status'] ?? '')] ?? 0;
        }
        if ((string) ($creditNote['status'] ?? '') === 'void') {
            $revision += 1000;
        }

        return $revision;
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
