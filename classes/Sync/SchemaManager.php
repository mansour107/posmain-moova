<?php

class SyncSchemaManager
{
    public function plannedStatements()
    {
        return [
            'app_sessions' => $this->appSessionsSql(),
            'sync_branch_identity' => $this->syncBranchIdentitySql(),
            'document_counters' => $this->documentCountersSql(),
            'pos_request_keys' => $this->posRequestKeysSql(),
            'order_events' => $this->orderEventsSql(),
            'order_fulfillment' => $this->orderFulfillmentSql(),
            'delivery_zones' => $this->deliveryZonesSql(),
            'security_audit_log' => $this->securityAuditLogSql(),
            'failed_login_attempts' => $this->failedLoginAttemptsSql(),
            'item_availability' => $this->itemAvailabilitySql(),
            'item_variants' => $this->itemVariantsSql(),
            'modifier_groups' => $this->modifierGroupsSql(),
            'modifier_options' => $this->modifierOptionsSql(),
            'item_modifier_groups' => $this->itemModifierGroupsSql(),
            'order_line_modifiers' => $this->orderLineModifiersSql(),
            'order_line_notes' => $this->orderLineNotesSql(),
            'table_areas' => $this->tableAreasSql(),
            'payment_methods' => $this->paymentMethodsSql(),
            'manager_approvals' => $this->managerApprovalsSql(),
            'drawer_sessions' => $this->drawerSessionsSql(),
            'drawer_movements' => $this->drawerMovementsSql(),
            'printers' => $this->printersSql(),
            'print_jobs' => $this->printJobsSql(),
            'item_nutrition_profiles' => $this->itemNutritionProfilesSql(),
            'recipe_headers' => $this->recipeHeadersSql(),
            'recipe_lines' => $this->recipeLinesSql(),
            'recipe_variant_lines' => $this->recipeVariantLinesSql(),
            'recipe_cost_snapshots' => $this->recipeCostSnapshotsSql(),
            'recipe_order_line_usage' => $this->recipeOrderLineUsageSql(),
            'inventory_movements' => $this->inventoryMovementsSql(),
            'inventory_item_balances' => $this->inventoryItemBalancesSql(),
            'inventory_item_stock_levels' => $this->inventoryItemStockLevelsSql(),
            'inventory_reason_codes' => $this->inventoryReasonCodesSql(),
            'inventory_counts' => $this->inventoryCountsSql(),
            'inventory_count_lines' => $this->inventoryCountLinesSql(),
            'inventory_transfers' => $this->inventoryTransfersSql(),
            'inventory_transfer_lines' => $this->inventoryTransferLinesSql(),
            'inventory_purchase_orders' => $this->inventoryPurchaseOrdersSql(),
            'inventory_purchase_order_lines' => $this->inventoryPurchaseOrderLinesSql(),
            'inventory_purchase_receipts' => $this->inventoryPurchaseReceiptsSql(),
            'inventory_purchase_receipt_lines' => $this->inventoryPurchaseReceiptLinesSql(),
            'stock_reservations' => $this->stockReservationsSql(),
            'production_batches' => $this->productionBatchesSql(),
            'production_batch_lines' => $this->productionBatchLinesSql(),
            'recipe_audit_log' => $this->recipeAuditLogSql(),
            'recipe_availability_cache' => $this->recipeAvailabilityCacheSql(),
            'external_order_line_map' => $this->externalOrderLineMapSql(),
            'sync_outbox' => $this->syncOutboxSql(),
            'sync_inbox' => $this->syncInboxSql(),
            'sync_checkpoints' => $this->syncCheckpointsSql(),
            'sync_conflicts' => $this->syncConflictsSql(),
            'sync_worker_logs' => $this->syncWorkerLogsSql(),
            'sync_bulk_push_jobs' => $this->syncBulkPushJobsSql(),
            'sync_image_queue' => $this->syncImageQueueSql(),
            'sync_runtime_settings' => $this->syncRuntimeSettingsSql(),
            'moova_pos_inbound_events' => $this->moovaPosInboundEventsSql(),
            'cloud_branches' => $this->cloudBranchesSql(),
            'cloud_orders' => $this->cloudOrdersSql(),
            'cloud_order_lines' => $this->cloudOrderLinesSql(),
            'cloud_order_payments' => $this->cloudOrderPaymentsSql(),
            'cloud_payment_receipts' => $this->cloudPaymentReceiptsSql(),
            'cloud_tables' => $this->cloudTablesSql(),
            'cloud_shifts' => $this->cloudShiftsSql(),
            'cloud_menu_items' => $this->cloudMenuItemsSql(),
            'cloud_sync_branch_events' => $this->cloudSyncBranchEventsSql(),
            'cloud_moova_branch_events' => $this->cloudMoovaBranchEventsSql(),
        ];
    }

    public function phase4LegacyTargets()
    {
        return [
            'tables' => [
                'columns' => [
                    'area_id' => "ALTER TABLE `tables` ADD COLUMN area_id BIGINT UNSIGNED NULL AFTER table_case",
                    'capacity' => "ALTER TABLE `tables` ADD COLUMN capacity INT NULL AFTER area_id",
                    'pos_x' => "ALTER TABLE `tables` ADD COLUMN pos_x INT NULL AFTER capacity",
                    'pos_y' => "ALTER TABLE `tables` ADD COLUMN pos_y INT NULL AFTER pos_x",
                    'shape' => "ALTER TABLE `tables` ADD COLUMN shape VARCHAR(40) NULL AFTER pos_y",
                    'display_order' => "ALTER TABLE `tables` ADD COLUMN display_order INT NOT NULL DEFAULT 0 AFTER shape",
                ],
                'indexes' => [
                    'idx_tables_area_order' => [
                        'columns' => ['area_id', 'display_order'],
                        'sql' => "ALTER TABLE `tables` ADD KEY idx_tables_area_order (area_id, display_order)",
                    ],
                ],
            ],
            'ot_head' => [
                'columns' => [
                    'cofe_idempotency_key' => "ALTER TABLE ot_head ADD COLUMN cofe_idempotency_key VARCHAR(191) NULL AFTER uuid",
                    'guest_count' => "ALTER TABLE ot_head ADD COLUMN guest_count INT NULL AFTER table_id",
                    'waiter_id' => "ALTER TABLE ot_head ADD COLUMN waiter_id BIGINT NULL AFTER guest_count",
                ],
                'indexes' => [
                    'uq_ot_head_cofe_idempotency' => [
                        'columns' => ['cofe_idempotency_key'],
                        'sql' => "ALTER TABLE ot_head ADD UNIQUE KEY uq_ot_head_cofe_idempotency (cofe_idempotency_key)",
                    ],
                    'idx_ot_head_waiter' => [
                        'columns' => ['waiter_id'],
                        'sql' => "ALTER TABLE ot_head ADD KEY idx_ot_head_waiter (waiter_id)",
                    ],
                ],
            ],
            'myitems' => [
                'columns' => [
                    'item_type' => "ALTER TABLE myitems ADD COLUMN item_type ENUM('sellable','ingredient','packaging','service') NOT NULL DEFAULT 'sellable'",
                    'track_stock' => "ALTER TABLE myitems ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 1",
                    'preferred_unit_id' => "ALTER TABLE myitems ADD COLUMN preferred_unit_id BIGINT UNSIGNED NULL",
                    'is_active' => "ALTER TABLE myitems ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
                ],
                'indexes' => [
                    'idx_myitems_type_stock' => [
                        'columns' => ['item_type', 'track_stock'],
                        'sql' => "ALTER TABLE myitems ADD KEY idx_myitems_type_stock (item_type, track_stock)",
                    ],
                    'idx_myitems_barcode_deleted' => [
                        'columns' => ['barcode', 'isdeleted'],
                        'sql' => "ALTER TABLE myitems ADD KEY idx_myitems_barcode_deleted (barcode, isdeleted)",
                    ],
                    'idx_myitems_group_deleted' => [
                        'columns' => ['group1', 'isdeleted'],
                        'sql' => "ALTER TABLE myitems ADD KEY idx_myitems_group_deleted (group1, isdeleted)",
                    ],
                    'idx_myitems_active_deleted' => [
                        'columns' => ['is_active', 'isdeleted'],
                        'sql' => "ALTER TABLE myitems ADD KEY idx_myitems_active_deleted (is_active, isdeleted)",
                    ],
                ],
            ],
            'acc_head' => [
                'columns' => [
                    'is_operational_store' => 'ALTER TABLE acc_head ADD COLUMN is_operational_store TINYINT(1) NOT NULL DEFAULT 0',
                ],
                'indexes' => [
                    'idx_acc_head_operational_store' => [
                        'columns' => ['is_operational_store', 'is_stock', 'isdeleted'],
                        'sql' => 'ALTER TABLE acc_head ADD KEY idx_acc_head_operational_store (is_operational_store, is_stock, isdeleted)',
                    ],
                ],
            ],
            'fat_details' => [
                'columns' => [],
                'indexes' => [
                    'idx_fat_details_stock_item' => [
                        'columns' => ['item_id', 'isdeleted', 'qty_in', 'qty_out'],
                        'sql' => "ALTER TABLE fat_details ADD KEY idx_fat_details_stock_item (item_id, isdeleted, qty_in, qty_out)",
                    ],
                    'idx_fat_details_fatid_deleted' => [
                        'columns' => ['fatid', 'isdeleted'],
                        'sql' => "ALTER TABLE fat_details ADD KEY idx_fat_details_fatid_deleted (fatid, isdeleted)",
                    ],
                ],
            ],
        ];
    }

    public function phase2UuidTargets()
    {
        return [
            'ot_head' => [
                'column' => 'uuid',
                'index' => 'uq_ot_head_uuid',
            ],
            'fat_details' => [
                'column' => 'uuid',
                'index' => 'uq_fat_details_uuid',
            ],
            'order_payments' => [
                'column' => 'uuid',
                'index' => 'uq_order_payments_uuid',
            ],
            'tables' => [
                'column' => 'uuid',
                'index' => 'uq_tables_uuid',
            ],
            'closed_orders' => [
                'column' => 'uuid',
                'index' => 'uq_closed_orders_uuid',
            ],
        ];
    }

    public function inspect(mysqli $conn)
    {
        $status = [];
        foreach (array_keys($this->plannedStatements()) as $table) {
            $status[$table] = [
                'exists' => $this->tableExists($conn, $table),
                'columns' => $this->existingColumns($conn, $table),
                'indexes' => $this->existingIndexes($conn, $table),
            ];
        }

        return $status;
    }

    public function pendingStatements(mysqli $conn)
    {
        $pending = [];
        foreach ($this->plannedStatements() as $table => $sql) {
            if (!$this->tableExists($conn, $table)) {
                $pending[$table] = $sql;
                continue;
            }

            foreach ($this->upgradeStatements($conn, $table) as $label => $statement) {
                $pending[$label] = $statement;
            }
        }

        foreach ($this->phase2UuidUpgradeStatements($conn) as $label => $statement) {
            $pending[$label] = $statement;
        }

        foreach ($this->phase4LegacyUpgradeStatements($conn) as $label => $statement) {
            $pending[$label] = $statement;
        }

        foreach ($this->journalPrecisionUpgradeStatements($conn) as $label => $statement) {
            $pending[$label] = $statement;
        }

        return $pending;
    }

    public function apply(mysqli $conn)
    {
        $applied = [];
        foreach ($this->pendingStatements($conn) as $table => $sql) {
            $conn->query($sql);
            $applied[] = $table;
        }

        return $applied;
    }

    private function tableExists(mysqli $conn, $table)
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS table_count
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (int) $row['table_count'] > 0;
    }

    private function upgradeStatements(mysqli $conn, $table)
    {
        if ($table === 'document_counters') {
            return $this->documentCounterUpgradeStatements($conn);
        }

        if ($table === 'sync_branch_identity') {
            return $this->syncBranchIdentityUpgradeStatements($conn);
        }

        if ($table === 'sync_outbox') {
            return $this->syncOutboxUpgradeStatements($conn);
        }

        if ($table === 'inventory_movements') {
            return $this->inventoryMovementsUpgradeStatements($conn);
        }

        if ($table === 'inventory_count_lines') {
            return $this->inventoryCountLinesUpgradeStatements($conn);
        }

        if ($table === 'inventory_transfers') {
            return $this->inventoryTransfersUpgradeStatements($conn);
        }

        if ($table === 'inventory_item_stock_levels') {
            return $this->inventoryItemStockLevelsUpgradeStatements($conn);
        }

        if ($table === 'stock_reservations') {
            return $this->stockReservationsUpgradeStatements($conn);
        }

        if ($table === 'cloud_moova_branch_events') {
            return $this->cloudMoovaBranchEventUpgradeStatements($conn);
        }

        if ($table === 'cloud_branches') {
            return $this->cloudBranchesUpgradeStatements($conn);
        }

        if ($table === 'moova_pos_inbound_events') {
            return $this->moovaPosInboundEventUpgradeStatements($conn);
        }

        if ($table === 'order_fulfillment') {
            return $this->orderFulfillmentUpgradeStatements($conn);
        }

        return [];
    }

    private function syncBranchIdentityUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        $columns = [
            'branch_uuid' => "ALTER TABLE sync_branch_identity ADD COLUMN branch_uuid CHAR(36) NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000' AFTER id",
            'branch_name' => "ALTER TABLE sync_branch_identity ADD COLUMN branch_name VARCHAR(255) NULL AFTER branch_uuid",
            'pos_tenant' => "ALTER TABLE sync_branch_identity ADD COLUMN pos_tenant INT NULL AFTER branch_name",
            'pos_branch' => "ALTER TABLE sync_branch_identity ADD COLUMN pos_branch INT NULL AFTER pos_tenant",
            'cloud_base_url' => "ALTER TABLE sync_branch_identity ADD COLUMN cloud_base_url VARCHAR(500) NULL AFTER pos_branch",
            'current_menu_version' => "ALTER TABLE sync_branch_identity ADD COLUMN current_menu_version BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER cloud_base_url",
            'created_at' => "ALTER TABLE sync_branch_identity ADD COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER current_menu_version",
            'updated_at' => "ALTER TABLE sync_branch_identity ADD COLUMN updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6) AFTER created_at",
        ];

        foreach ($columns as $column => $sql) {
            if (!$this->columnExists($conn, 'sync_branch_identity', $column)) {
                $statements['sync_branch_identity.add_' . $column] = $sql;
            }
        }

        if (!$this->indexExists($conn, 'sync_branch_identity', 'uq_sync_branch_identity_uuid')) {
            $statements['sync_branch_identity.add_uq_sync_branch_identity_uuid'] = "
ALTER TABLE sync_branch_identity
  ADD UNIQUE KEY uq_sync_branch_identity_uuid (branch_uuid)";
        }

        return $statements;
    }

    private function documentCounterUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        $columns = [
            'pos_tenant' => "ALTER TABLE document_counters ADD COLUMN pos_tenant INT NOT NULL DEFAULT 0 AFTER id",
            'pos_branch' => "ALTER TABLE document_counters ADD COLUMN pos_branch INT NOT NULL DEFAULT 0 AFTER pos_tenant",
            'counter_type' => "ALTER TABLE document_counters ADD COLUMN counter_type VARCHAR(50) NOT NULL DEFAULT 'generic' AFTER pos_branch",
            'counter_key' => "ALTER TABLE document_counters ADD COLUMN counter_key VARCHAR(100) NOT NULL DEFAULT '' AFTER counter_type",
        ];

        foreach ($columns as $column => $sql) {
            if (!$this->columnExists($conn, 'document_counters', $column)) {
                $statements['document_counters.add_' . $column] = $sql;
            }
        }

        if ($this->columnRequiresExplicitValue($conn, 'document_counters', 'document_type')) {
            $statements['document_counters.relax_legacy_document_type'] = "
ALTER TABLE document_counters
  MODIFY COLUMN document_type VARCHAR(64) NULL DEFAULT NULL";
        }

        if (
            $this->legacyDocumentCounterScopeNeedsCopy($conn)
            && (
                $this->columnExists($conn, 'document_counters', 'tenant_id')
                || $this->columnExists($conn, 'document_counters', 'branch_id')
                || $this->columnExists($conn, 'document_counters', 'document_type')
            )
        ) {
            $posTenant = $this->columnExists($conn, 'document_counters', 'tenant_id') ? 'tenant_id' : '0';
            $posBranch = $this->columnExists($conn, 'document_counters', 'branch_id') ? 'branch_id' : '0';
            $counterKey = $this->columnExists($conn, 'document_counters', 'document_type') ? 'document_type' : "''";
            $statements['document_counters.copy_legacy_scope'] = "
UPDATE document_counters
   SET pos_tenant = {$posTenant},
       pos_branch = {$posBranch},
       counter_type = 'generic',
       counter_key = {$counterKey}
 WHERE counter_key = ''";
        }

        if (!$this->indexExists($conn, 'document_counters', 'uq_document_counter_scope')) {
            $statements['document_counters.add_uq_document_counter_scope'] = "
ALTER TABLE document_counters
  ADD UNIQUE KEY uq_document_counter_scope (pos_tenant, pos_branch, counter_type, counter_key)";
        }

        return $statements;
    }

    private function syncOutboxUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        $columns = [
            'branch_uuid' => "ALTER TABLE sync_outbox ADD COLUMN branch_uuid CHAR(36) NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000' AFTER event_uuid",
            'pos_tenant' => "ALTER TABLE sync_outbox ADD COLUMN pos_tenant INT NOT NULL DEFAULT 0 AFTER branch_uuid",
            'pos_branch' => "ALTER TABLE sync_outbox ADD COLUMN pos_branch INT NOT NULL DEFAULT 0 AFTER pos_tenant",
            'aggregate_uuid' => "ALTER TABLE sync_outbox ADD COLUMN aggregate_uuid CHAR(36) NULL AFTER aggregate_type",
            'aggregate_local_id' => "ALTER TABLE sync_outbox ADD COLUMN aggregate_local_id BIGINT UNSIGNED NULL AFTER aggregate_uuid",
            'aggregate_id' => "ALTER TABLE sync_outbox ADD COLUMN aggregate_id VARCHAR(191) NOT NULL DEFAULT '' AFTER aggregate_local_id",
            'entity_type' => "ALTER TABLE sync_outbox ADD COLUMN entity_type VARCHAR(50) NOT NULL DEFAULT 'unknown' AFTER aggregate_id",
            'entity_uuid' => "ALTER TABLE sync_outbox ADD COLUMN entity_uuid CHAR(36) NULL AFTER entity_type",
            'entity_local_id' => "ALTER TABLE sync_outbox ADD COLUMN entity_local_id BIGINT UNSIGNED NULL AFTER entity_uuid",
            'event_version' => "ALTER TABLE sync_outbox ADD COLUMN event_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER event_type",
            'source_system' => "ALTER TABLE sync_outbox ADD COLUMN source_system VARCHAR(40) NOT NULL DEFAULT 'pos' AFTER event_version",
            'source_event_uuid' => "ALTER TABLE sync_outbox ADD COLUMN source_event_uuid CHAR(36) NULL AFTER source_system",
            'payload_hash' => "ALTER TABLE sync_outbox ADD COLUMN payload_hash CHAR(64) NOT NULL DEFAULT '' AFTER payload_json",
            'locked_by' => "ALTER TABLE sync_outbox ADD COLUMN locked_by VARCHAR(100) NULL AFTER last_error",
            'locked_until' => "ALTER TABLE sync_outbox ADD COLUMN locked_until DATETIME(6) NULL AFTER locked_by",
            'next_retry_at' => "ALTER TABLE sync_outbox ADD COLUMN next_retry_at DATETIME(6) NULL AFTER locked_until",
        ];

        foreach ($columns as $column => $sql) {
            if (!$this->columnExists($conn, 'sync_outbox', $column)) {
                $statements['sync_outbox.add_' . $column] = $sql;
            }
        }

        $statusInfo = $this->columnInfo($conn, 'sync_outbox', 'status');
        if ($statusInfo && stripos((string) $statusInfo['COLUMN_TYPE'], 'enum(') !== 0) {
            $statements['sync_outbox.modify_status_enum'] = "
ALTER TABLE sync_outbox
  MODIFY COLUMN status ENUM('pending','syncing','synced','failed','dead') NOT NULL DEFAULT 'pending'";
        }

        if (!$this->indexExists($conn, 'sync_outbox', 'uq_sync_outbox_idempotency')) {
            $statements['sync_outbox.add_uq_sync_outbox_idempotency'] = "
ALTER TABLE sync_outbox
  ADD UNIQUE KEY uq_sync_outbox_idempotency (branch_uuid, idempotency_key)";
        }

        foreach ([
            'idx_sync_outbox_pending' => 'ADD KEY idx_sync_outbox_pending (status, next_retry_at, id)',
            'idx_sync_outbox_aggregate' => 'ADD KEY idx_sync_outbox_aggregate (aggregate_type, aggregate_uuid)',
            'idx_sync_outbox_entity' => 'ADD KEY idx_sync_outbox_entity (entity_type, entity_uuid)',
            'idx_sync_outbox_created' => 'ADD KEY idx_sync_outbox_created (created_at)',
        ] as $index => $clause) {
            if (!$this->indexExists($conn, 'sync_outbox', $index)) {
                $statements['sync_outbox.add_' . $index] = "ALTER TABLE sync_outbox {$clause}";
            }
        }

        return $statements;
    }

    private function inventoryMovementsUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        $columns = [
            'payload_hash' => "ALTER TABLE inventory_movements ADD COLUMN payload_hash CHAR(64) NOT NULL DEFAULT '' AFTER idempotency_key",
            'metadata_json' => "ALTER TABLE inventory_movements ADD COLUMN metadata_json JSON NULL AFTER payload_hash",
        ];

        foreach ($columns as $column => $sql) {
            if (!$this->columnExists($conn, 'inventory_movements', $column)) {
                $statements['inventory_movements.add_' . $column] = $sql;
            }
        }

        $sourceType = $this->columnInfo($conn, 'inventory_movements', 'source_type');
        if ($sourceType && strpos((string) $sourceType['COLUMN_TYPE'], "'purchase_receipt'") === false) {
            $statements['inventory_movements.modify_source_type_workflow_enum'] = "
ALTER TABLE inventory_movements
  MODIFY COLUMN source_type ENUM('order','order_line','invoice','fat_details','recipe','recipe_order_line_usage','production_batch','purchase_invoice','purchase_order','purchase_receipt','inventory_count','inventory_transfer','adjustment','reservation','sync_event','manual') NOT NULL";
        }

        $movementType = $this->columnInfo($conn, 'inventory_movements', 'movement_type');
        if ($movementType && strpos((string) $movementType['COLUMN_TYPE'], "'purchase_return'") === false) {
            $statements['inventory_movements.modify_movement_type_purchase_return_enum'] = "
ALTER TABLE inventory_movements
  MODIFY COLUMN movement_type ENUM('purchase','purchase_return','sale_direct','recipe_consumption','production_input','production_output','waste','adjustment','transfer_in','transfer_out','reservation','reservation_release','refund_reversal','sync_replay','opening_balance') NOT NULL";
        }

        if (!$this->indexExists($conn, 'inventory_movements', 'idx_inventory_movement_type_time')) {
            $statements['inventory_movements.add_idx_inventory_movement_type_time'] = "
ALTER TABLE inventory_movements
  ADD KEY idx_inventory_movement_type_time (movement_type, created_at)";
        }

        return $statements;
    }

    private function inventoryCountLinesUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        if (!$this->columnExists($conn, 'inventory_count_lines', 'unit_conversion_to_base')) {
            $statements['inventory_count_lines.add_unit_conversion_to_base'] = "
ALTER TABLE inventory_count_lines
  ADD COLUMN unit_conversion_to_base DECIMAL(18,8) NOT NULL DEFAULT 1.00000000 AFTER unit_id";
            if ($this->tableExists($conn, 'item_units')) {
                $statements['inventory_count_lines.backfill_unit_conversion_to_base'] = "
UPDATE inventory_count_lines l
INNER JOIN item_units iu
        ON iu.item_id = l.item_id
       AND iu.unit_id = l.unit_id
   SET l.unit_conversion_to_base = iu.u_val
 WHERE l.unit_id IS NOT NULL
   AND iu.u_val > 0";
            }
        }

        return $statements;
    }

    private function inventoryTransfersUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        if (!$this->columnExists($conn, 'inventory_transfers', 'destination_pos_branch')) {
            $statements['inventory_transfers.add_destination_pos_branch'] = "
ALTER TABLE inventory_transfers
  ADD COLUMN destination_pos_branch INT NULL AFTER destination_store_id";
        }
        if (!$this->columnExists($conn, 'inventory_transfers', 'destination_branch_uuid')) {
            $statements['inventory_transfers.add_destination_branch_uuid'] = "
ALTER TABLE inventory_transfers
  ADD COLUMN destination_branch_uuid CHAR(36) NULL AFTER destination_pos_branch";
        }
        if (!$this->indexExists($conn, 'inventory_transfers', 'idx_inventory_transfer_destination_branch')) {
            $statements['inventory_transfers.add_idx_inventory_transfer_destination_branch'] = "
ALTER TABLE inventory_transfers
  ADD KEY idx_inventory_transfer_destination_branch (pos_tenant, destination_pos_branch, destination_store_id, status)";
        }

        return $statements;
    }

    private function inventoryItemStockLevelsUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        if (!$this->columnExists($conn, 'inventory_item_stock_levels', 'preferred_count_unit_id')) {
            $statements['inventory_item_stock_levels.add_preferred_count_unit_id'] = "
ALTER TABLE inventory_item_stock_levels
  ADD COLUMN preferred_count_unit_id BIGINT UNSIGNED NULL AFTER safety_stock_qty";
        }
        if (!$this->columnExists($conn, 'inventory_item_stock_levels', 'preferred_purchase_unit_id')) {
            $statements['inventory_item_stock_levels.add_preferred_purchase_unit_id'] = "
ALTER TABLE inventory_item_stock_levels
  ADD COLUMN preferred_purchase_unit_id BIGINT UNSIGNED NULL AFTER preferred_count_unit_id";
        }
        if (!$this->columnExists($conn, 'inventory_item_stock_levels', 'default_supplier_account_id')) {
            $statements['inventory_item_stock_levels.add_default_supplier_account_id'] = "
ALTER TABLE inventory_item_stock_levels
  ADD COLUMN default_supplier_account_id BIGINT UNSIGNED NULL AFTER preferred_purchase_unit_id";
        }

        return $statements;
    }

    private function stockReservationsUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        if (!$this->indexExists($conn, 'stock_reservations', 'idx_stock_reservation_order_line')) {
            $statements['stock_reservations.add_idx_stock_reservation_order_line'] = "
ALTER TABLE stock_reservations
  ADD KEY idx_stock_reservation_order_line (order_id, order_line_uuid)";
        }

        return $statements;
    }

    private function cloudMoovaBranchEventUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        if ($this->columnRequiresExplicitValue($conn, 'cloud_moova_branch_events', 'cursor_value')) {
            $statements['cloud_moova_branch_events.relax_cursor_value'] = "
ALTER TABLE cloud_moova_branch_events
  MODIFY COLUMN cursor_value BIGINT UNSIGNED NULL DEFAULT NULL";
        }

        if (!$this->indexExists($conn, 'cloud_moova_branch_events', 'idx_cloud_moova_branch_pending')) {
            $statements['cloud_moova_branch_events.add_idx_cloud_moova_branch_pending'] = "
ALTER TABLE cloud_moova_branch_events
  ADD KEY idx_cloud_moova_branch_pending (branch_uuid, status, id)";
        }

        return $statements;
    }

    private function cloudBranchesUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        if (!$this->columnExists($conn, 'cloud_branches', 'sync_secret_encrypted')) {
            $statements['cloud_branches.add_sync_secret_encrypted'] = "
ALTER TABLE cloud_branches
  ADD COLUMN sync_secret_encrypted TEXT NULL AFTER sync_secret_hash";
        }

        return $statements;
    }

    private function moovaPosInboundEventUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        $columns = [
            'locked_by' => "ALTER TABLE moova_pos_inbound_events ADD COLUMN locked_by VARCHAR(100) NULL AFTER error_message",
            'locked_until' => "ALTER TABLE moova_pos_inbound_events ADD COLUMN locked_until DATETIME(6) NULL AFTER locked_by",
            'attempt_count' => "ALTER TABLE moova_pos_inbound_events ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER locked_until",
            'last_attempt_at' => "ALTER TABLE moova_pos_inbound_events ADD COLUMN last_attempt_at DATETIME(6) NULL AFTER attempt_count",
            'cloud_ack_status' => "ALTER TABLE moova_pos_inbound_events ADD COLUMN cloud_ack_status VARCHAR(30) NULL AFTER last_attempt_at",
            'cloud_ack_error' => "ALTER TABLE moova_pos_inbound_events ADD COLUMN cloud_ack_error TEXT NULL AFTER cloud_ack_status",
            'cloud_ack_attempt_count' => "ALTER TABLE moova_pos_inbound_events ADD COLUMN cloud_ack_attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER cloud_ack_error",
            'cloud_ack_last_attempt_at' => "ALTER TABLE moova_pos_inbound_events ADD COLUMN cloud_ack_last_attempt_at DATETIME(6) NULL AFTER cloud_ack_attempt_count",
            'cloud_acknowledged_at' => "ALTER TABLE moova_pos_inbound_events ADD COLUMN cloud_acknowledged_at DATETIME(6) NULL AFTER cloud_ack_last_attempt_at",
        ];

        foreach ($columns as $column => $sql) {
            if (!$this->columnExists($conn, 'moova_pos_inbound_events', $column)) {
                $statements['moova_pos_inbound_events.add_' . $column] = $sql;
            }
        }

        $statusInfo = $this->columnInfo($conn, 'moova_pos_inbound_events', 'status');
        if ($statusInfo && strpos((string) $statusInfo['COLUMN_TYPE'], "'processing'") === false) {
            $statements['moova_pos_inbound_events.modify_status_enum'] = "
ALTER TABLE moova_pos_inbound_events
  MODIFY COLUMN status ENUM('received','processing','notified','cashier_confirmed','applied','declined','failed','duplicate','conflict') NOT NULL DEFAULT 'received'";
        }

        if (!$this->indexExists($conn, 'moova_pos_inbound_events', 'idx_moova_inbound_claim')) {
            $statements['moova_pos_inbound_events.add_idx_moova_inbound_claim'] = "
ALTER TABLE moova_pos_inbound_events
  ADD KEY idx_moova_inbound_claim (status, locked_until, received_at, id)";
        }

        if (!$this->indexExists($conn, 'moova_pos_inbound_events', 'idx_moova_inbound_cloud_ack')) {
            $statements['moova_pos_inbound_events.add_idx_moova_inbound_cloud_ack'] = "
ALTER TABLE moova_pos_inbound_events
  ADD KEY idx_moova_inbound_cloud_ack (cloud_ack_status, status, applied_at, id)";
        }

        return $statements;
    }

    private function phase2UuidUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        foreach ($this->phase2UuidTargets() as $table => $target) {
            if (!$this->tableExists($conn, $table)) {
                continue;
            }

            $column = $target['column'];
            $index = $target['index'];
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $quotedIndex = '`' . str_replace('`', '``', $index) . '`';

            if (!$this->columnExists($conn, $table, $column)) {
                $statements[$table . '.add_uuid'] = "
ALTER TABLE {$quotedTable}
  ADD COLUMN uuid CHAR(36) NULL AFTER id";
            }

            if (!$this->indexExists($conn, $table, $index)) {
                $statements[$table . '.add_' . $index] = "
ALTER TABLE {$quotedTable}
  ADD UNIQUE KEY {$quotedIndex} (uuid)";
            }
        }

        return $statements;
    }

    private function phase4LegacyUpgradeStatements(mysqli $conn)
    {
        $statements = [];

        foreach ($this->phase4LegacyTargets() as $table => $target) {
            if (!$this->tableExists($conn, $table)) {
                continue;
            }

            $availableColumns = array_fill_keys($this->existingColumns($conn, $table), true);
            $plannedColumns = $target['columns'] ?? [];
            foreach ($plannedColumns as $column => $sql) {
                if (!$this->columnExists($conn, $table, $column)) {
                    $statements[$table . '.add_' . $column] = $this->sqlWithAvailableAfterAnchor($sql, $availableColumns);
                    $availableColumns[$column] = true;
                } else {
                    $availableColumns[$column] = true;
                }
            }

            foreach (($target['indexes'] ?? []) as $index => $definition) {
                $columns = $definition['columns'] ?? [];
                if (
                    $this->indexExists($conn, $table, $index)
                    || $this->indexWithColumnsExists($conn, $table, $columns)
                ) {
                    continue;
                }

                if (!$this->phase4IndexColumnsAvailable($conn, $table, $columns, $plannedColumns)) {
                    continue;
                }

                $statements[$table . '.add_' . $index] = $definition['sql'];
            }
        }

        return $statements;
    }

    private function journalPrecisionUpgradeStatements(mysqli $conn)
    {
        $statements = [];
        if (!$this->tableExists($conn, 'journal_entries')) {
            return $statements;
        }

        foreach (['debit', 'credit'] as $column) {
            if (!$this->columnNeedsRecipeDecimalPrecision($conn, 'journal_entries', $column)) {
                continue;
            }

            $statements['journal_entries.modify_' . $column . '_decimal'] = "
ALTER TABLE journal_entries
  MODIFY COLUMN {$column} DECIMAL(18,6) NOT NULL DEFAULT 0.000000";
        }

        return $statements;
    }

    private function sqlWithAvailableAfterAnchor($sql, array $availableColumns)
    {
        if (!preg_match('/\s+AFTER\s+`?([a-zA-Z0-9_]+)`?\s*$/i', $sql, $matches)) {
            return $sql;
        }

        $anchor = $matches[1];
        if (isset($availableColumns[$anchor])) {
            return $sql;
        }

        return preg_replace('/\s+AFTER\s+`?[a-zA-Z0-9_]+`?\s*$/i', '', $sql);
    }

    private function phase4IndexColumnsAvailable(mysqli $conn, $table, array $columns, array $plannedColumns)
    {
        foreach ($columns as $column) {
            if ($this->columnExists($conn, $table, $column)) {
                continue;
            }

            if (array_key_exists($column, $plannedColumns)) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function columnExists(mysqli $conn, $table, $column)
    {
        return in_array($column, $this->existingColumns($conn, $table), true);
    }

    private function indexExists(mysqli $conn, $table, $index)
    {
        return in_array($index, $this->existingIndexes($conn, $table), true);
    }

    private function indexWithColumnsExists(mysqli $conn, $table, array $columns)
    {
        if (!$columns || !$this->tableExists($conn, $table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (!$this->columnExists($conn, $table, $column)) {
                return false;
            }
        }

        $stmt = $conn->prepare("
            SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS index_columns
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            GROUP BY INDEX_NAME
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result = $stmt->get_result();
        $needle = implode(',', $columns);
        while ($row = $result->fetch_assoc()) {
            if ((string) ($row['index_columns'] ?? '') === $needle) {
                $stmt->close();
                return true;
            }
        }
        $stmt->close();

        return false;
    }

    private function legacyDocumentCounterScopeNeedsCopy(mysqli $conn)
    {
        if (!$this->columnExists($conn, 'document_counters', 'counter_key')) {
            return true;
        }

        $result = $conn->query("
            SELECT COUNT(*) AS row_count
            FROM document_counters
            WHERE counter_key = ''
        ");
        $row = $result->fetch_assoc();

        return $row && (int) $row['row_count'] > 0;
    }

    private function columnRequiresExplicitValue(mysqli $conn, $table, $column)
    {
        $info = $this->columnInfo($conn, $table, $column);
        if (!$info) {
            return false;
        }

        return $info['IS_NULLABLE'] === 'NO'
            && $info['COLUMN_DEFAULT'] === null
            && stripos((string) $info['EXTRA'], 'auto_increment') === false;
    }

    private function columnInfo(mysqli $conn, $table, $column)
    {
        if (!$this->tableExists($conn, $table)) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function columnNeedsRecipeDecimalPrecision(mysqli $conn, $table, $column)
    {
        $info = $this->columnInfo($conn, $table, $column);
        if (!$info) {
            return false;
        }

        $type = strtolower((string) ($info['COLUMN_TYPE'] ?? ''));
        if (preg_match('/^(decimal|numeric)\((\d+),(\d+)\)/', $type, $matches)) {
            return (int) $matches[2] < 18 || (int) $matches[3] < 6;
        }

        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint|float|double)\b/', $type)) {
            return true;
        }

        return false;
    }

    private function existingColumns(mysqli $conn, $table)
    {
        if (!$this->tableExists($conn, $table)) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result = $stmt->get_result();
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['COLUMN_NAME'];
        }
        $stmt->close();

        return $columns;
    }

    private function existingIndexes(mysqli $conn, $table)
    {
        if (!$this->tableExists($conn, $table)) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT DISTINCT INDEX_NAME
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            ORDER BY INDEX_NAME
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $result = $stmt->get_result();
        $indexes = [];
        while ($row = $result->fetch_assoc()) {
            $indexes[] = $row['INDEX_NAME'];
        }
        $stmt->close();

        return $indexes;
    }

    private function syncBranchIdentitySql()
    {
        return "
CREATE TABLE IF NOT EXISTS sync_branch_identity (
  id TINYINT UNSIGNED NOT NULL,
  branch_uuid CHAR(36) NOT NULL,
  branch_name VARCHAR(255) NULL,
  pos_tenant INT NULL,
  pos_branch INT NULL,
  cloud_base_url VARCHAR(500) NULL,
  current_menu_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_branch_identity_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function appSessionsSql()
    {
        require_once __DIR__ . '/../Infrastructure/DatabaseSessionHandler.php';

        return DatabaseSessionHandler::schemaSql('app_sessions');
    }

    private function documentCountersSql()
    {
        return "
CREATE TABLE IF NOT EXISTS document_counters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  counter_type VARCHAR(50) NOT NULL,
  counter_key VARCHAR(100) NOT NULL,
  current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_document_counter_scope (pos_tenant, pos_branch, counter_type, counter_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function posRequestKeysSql()
    {
        return "
CREATE TABLE IF NOT EXISTS pos_request_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope VARCHAR(80) NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  user_id BIGINT NULL,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  status ENUM('processing','completed','failed','voided') NOT NULL DEFAULT 'processing',
  response_json JSON NULL,
  error_code VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_scope_key (scope, idempotency_key),
  KEY idx_status_created (status, created_at),
  KEY idx_tenant_branch (tenant, branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function orderEventsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS order_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  event_source VARCHAR(80) NOT NULL,
  actor_user_id BIGINT NULL,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  before_state_json JSON NULL,
  after_state_json JSON NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_created (order_id, created_at),
  KEY idx_type_created (event_type, created_at),
  KEY idx_tenant_branch_created (tenant, branch, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function orderFulfillmentSql()
    {
        return "
CREATE TABLE IF NOT EXISTS order_fulfillment (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  order_channel VARCHAR(40) NOT NULL DEFAULT 'cashier',
  fulfillment_type VARCHAR(40) NOT NULL DEFAULT 'takeaway',
  external_provider VARCHAR(40) NULL,
  external_order_id VARCHAR(120) NULL,
  customer_name VARCHAR(160) NULL,
  customer_phone VARCHAR(60) NULL,
  customer_address VARCHAR(500) NULL,
  delivery_client_id INT NULL,
  delivery_zone VARCHAR(120) NULL,
  delivery_fee DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  delivery_status VARCHAR(40) NOT NULL DEFAULT 'none',
  promised_at DATETIME NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_fulfillment_order (order_id),
  KEY idx_order_fulfillment_channel (order_channel, fulfillment_type, delivery_status),
  KEY idx_order_fulfillment_provider (external_provider, external_order_id),
  KEY idx_fulfillment_client (delivery_client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function deliveryZonesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS delivery_zones (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  fee DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_delivery_zones_active (is_active, sort_order),
  KEY idx_delivery_zones_tenant_branch (tenant, branch, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function orderFulfillmentUpgradeStatements(mysqli $conn)
    {
        $statements = [];
        if (!$this->columnExists($conn, 'order_fulfillment', 'delivery_client_id')) {
            $statements['order_fulfillment.add_delivery_client_id'] = "
ALTER TABLE order_fulfillment
  ADD COLUMN delivery_client_id INT NULL AFTER customer_address";
        }
        if (!$this->indexExists($conn, 'order_fulfillment', 'idx_fulfillment_client')) {
            $statements['order_fulfillment.add_idx_fulfillment_client'] = "
ALTER TABLE order_fulfillment
  ADD KEY idx_fulfillment_client (delivery_client_id)";
        }

        return $statements;
    }

    private function securityAuditLogSql()
    {
        return "
CREATE TABLE IF NOT EXISTS security_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(80) NOT NULL,
  user_id BIGINT NULL,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  target_type VARCHAR(80) NULL,
  target_id BIGINT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_security_audit_event_created (event_type, created_at),
  KEY idx_security_audit_user_created (user_id, created_at),
  KEY idx_security_audit_target (target_type, target_id),
  KEY idx_security_audit_tenant_branch (tenant, branch, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function failedLoginAttemptsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS failed_login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username_hash CHAR(64) NOT NULL,
  username VARCHAR(191) NULL,
  ip VARCHAR(64) NOT NULL,
  user_agent VARCHAR(255) NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_until DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_failed_login_identity (username_hash, ip),
  KEY idx_failed_login_locked (locked_until),
  KEY idx_failed_login_last_failed (last_failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function itemAvailabilitySql()
    {
        return "
CREATE TABLE IF NOT EXISTS item_availability (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id BIGINT UNSIGNED NOT NULL,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  channel VARCHAR(40) NOT NULL DEFAULT 'all',
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  unavailable_reason VARCHAR(255) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_branch_channel (item_id, tenant, branch, channel),
  KEY idx_item_availability_branch (tenant, branch, is_available),
  KEY idx_item_availability_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function itemVariantsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS item_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_item_id BIGINT UNSIGNED NOT NULL,
  variant_item_id BIGINT UNSIGNED NOT NULL,
  variant_label VARCHAR(120) NOT NULL,
  variant_name_en VARCHAR(120) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_variant_child (variant_item_id),
  UNIQUE KEY uq_item_variant_parent_child (parent_item_id, variant_item_id),
  KEY idx_item_variants_parent (parent_item_id, is_active, sort_order),
  KEY idx_item_variants_variant (variant_item_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function modifierGroupsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS modifier_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name_ar VARCHAR(120) NOT NULL,
  name_en VARCHAR(120) NULL,
  selection_min INT NOT NULL DEFAULT 0,
  selection_max INT NOT NULL DEFAULT 1,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_modifier_groups_branch (tenant, branch, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function modifierOptionsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS modifier_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id BIGINT UNSIGNED NOT NULL,
  name_ar VARCHAR(120) NOT NULL,
  name_en VARCHAR(120) NULL,
  price_delta DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_modifier_options_group (group_id, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function itemModifierGroupsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS item_modifier_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_group (item_id, group_id),
  KEY idx_item_modifier_groups_group (group_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function orderLineModifiersSql()
    {
        return "
CREATE TABLE IF NOT EXISTS order_line_modifiers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  detail_id BIGINT UNSIGNED NOT NULL,
  modifier_group_id BIGINT UNSIGNED NOT NULL,
  modifier_option_id BIGINT UNSIGNED NOT NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 1.000,
  price_delta DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_line_modifiers_order (order_id, detail_id),
  KEY idx_order_line_modifiers_option (modifier_option_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function orderLineNotesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS order_line_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  detail_id BIGINT UNSIGNED NOT NULL,
  note_type ENUM('kitchen','cashier','customer') NOT NULL DEFAULT 'kitchen',
  note_text VARCHAR(500) NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order_line_notes_order (order_id, detail_id),
  KEY idx_order_line_notes_type (note_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function tableAreasSql()
    {
        return "
CREATE TABLE IF NOT EXISTS table_areas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name_ar VARCHAR(120) NOT NULL,
  name_en VARCHAR(120) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_table_areas_branch (tenant, branch, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function paymentMethodsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS payment_methods (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  name_ar VARCHAR(120) NOT NULL,
  name_en VARCHAR(120) NULL,
  account_id BIGINT UNSIGNED NULL,
  type ENUM('cash','card','wallet','bank','gift_card','other') NOT NULL,
  requires_reference TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_methods_code (code),
  KEY idx_payment_methods_active (is_active, sort_order),
  KEY idx_payment_methods_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function managerApprovalsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS manager_approvals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  action_type VARCHAR(80) NOT NULL,
  target_type VARCHAR(80) NOT NULL,
  target_id BIGINT UNSIGNED NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  status ENUM('requested','approved','declined','expired') NOT NULL DEFAULT 'requested',
  reason VARCHAR(500) NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_manager_approvals_status (status, created_at),
  KEY idx_manager_approvals_target (target_type, target_id),
  KEY idx_manager_approvals_action (action_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function drawerSessionsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS drawer_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  fund_account_id BIGINT UNSIGNED NULL,
  opened_at DATETIME NOT NULL,
  opened_by BIGINT UNSIGNED NOT NULL,
  opening_cash DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  closed_at DATETIME NULL,
  closed_by BIGINT UNSIGNED NULL,
  expected_cash DECIMAL(12,3) NULL,
  counted_cash DECIMAL(12,3) NULL,
  difference DECIMAL(12,3) NULL,
  status ENUM('open','closed','forced_closed') NOT NULL DEFAULT 'open',
  notes VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_drawer_sessions_uuid (uuid),
  KEY idx_drawer_sessions_user_status (user_id, status, opened_at),
  KEY idx_drawer_sessions_branch_status (tenant, branch, status, opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function drawerMovementsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS drawer_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  drawer_session_id BIGINT UNSIGNED NOT NULL,
  movement_type ENUM('sale_cash','refund_cash','paid_in','paid_out','safe_drop','opening','closing_adjustment') NOT NULL,
  amount DECIMAL(12,3) NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  payment_id BIGINT UNSIGNED NULL,
  reason VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_drawer_movements_session (drawer_session_id, created_at),
  KEY idx_drawer_movements_order (order_id, payment_id),
  KEY idx_drawer_movements_type (movement_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function printersSql()
    {
        return "
CREATE TABLE IF NOT EXISTS printers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  printer_type ENUM('receipt','kitchen','label','other') NOT NULL,
  connection_type ENUM('browser','network','usb','file','cloud') NOT NULL DEFAULT 'browser',
  config_json JSON NULL,
  tenant INT NOT NULL DEFAULT 0,
  branch INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_printers_branch_type (tenant, branch, printer_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function printJobsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS print_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type ENUM('receipt','kot','kitchen','z_report','x_report') NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  drawer_session_id BIGINT UNSIGNED NULL,
  printer_id BIGINT UNSIGNED NULL,
  status ENUM('queued','printed','failed','cancelled') NOT NULL DEFAULT 'queued',
  payload_json JSON NULL,
  attempts INT NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  printed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_print_jobs_status (status, created_at),
  KEY idx_print_jobs_order (order_id, job_type),
  KEY idx_print_jobs_printer (printer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function itemNutritionProfilesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS item_nutrition_profiles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id BIGINT UNSIGNED NOT NULL,
  serving_qty DECIMAL(12,3) NOT NULL DEFAULT 1.000,
  serving_unit_id BIGINT UNSIGNED NULL,
  calories_kcal DECIMAL(12,3) NULL,
  protein_g DECIMAL(12,3) NULL,
  carbs_g DECIMAL(12,3) NULL,
  fat_g DECIMAL(12,3) NULL,
  sugar_g DECIMAL(12,3) NULL,
  fiber_g DECIMAL(12,3) NULL,
  sodium_mg DECIMAL(12,3) NULL,
  allergens_json JSON NULL,
  dietary_flags_json JSON NULL,
  data_source VARCHAR(120) NULL,
  verified_by BIGINT UNSIGNED NULL,
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_nutrition (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function recipeHeadersSql()
    {
        return "
CREATE TABLE IF NOT EXISTS recipe_headers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_uuid CHAR(36) NOT NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  sellable_item_id BIGINT UNSIGNED NOT NULL,
  recipe_name VARCHAR(255) NOT NULL,
  recipe_type ENUM('make_to_order','batch_prepared','hybrid','packaging_bundle','modifier_only','sub_recipe') NOT NULL DEFAULT 'make_to_order',
  status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
  version_number INT UNSIGNED NOT NULL DEFAULT 1,
  yield_qty DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
  yield_unit_id BIGINT UNSIGNED NULL,
  default_wastage_percent DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
  effective_from DATETIME NULL,
  effective_to DATETIME NULL,
  costing_method ENUM('item_cost_price','moving_average','last_purchase','manual_snapshot') NOT NULL DEFAULT 'item_cost_price',
  requires_recipe_for_sale TINYINT(1) NOT NULL DEFAULT 0,
  allow_sale_without_stock TINYINT(1) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_uuid (recipe_uuid),
  UNIQUE KEY uq_recipe_item_version (pos_tenant, pos_branch, sellable_item_id, version_number),
  KEY idx_recipe_active_lookup (pos_tenant, pos_branch, sellable_item_id, status, effective_from, effective_to),
  KEY idx_recipe_status (pos_tenant, pos_branch, status),
  KEY idx_recipe_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function recipeLinesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS recipe_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id BIGINT UNSIGNED NOT NULL,
  line_uuid CHAR(36) NOT NULL,
  ingredient_item_id BIGINT UNSIGNED NULL,
  sub_recipe_id BIGINT UNSIGNED NULL,
  line_type ENUM('ingredient','packaging','sub_recipe','modifier_ingredient','labor_placeholder') NOT NULL DEFAULT 'ingredient',
  ingredient_item_type_snapshot VARCHAR(64) NULL,
  qty_per_yield DECIMAL(18,6) NOT NULL,
  unit_id BIGINT UNSIGNED NULL,
  unit_conversion_to_base DECIMAL(18,8) NOT NULL DEFAULT 1.00000000,
  wastage_percent DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  modifier_group_id BIGINT UNSIGNED NULL,
  modifier_option_id BIGINT UNSIGNED NULL,
  modifier_behavior ENUM('additive','substitution_remove','substitution_add') NOT NULL DEFAULT 'additive',
  substitution_group VARCHAR(64) NULL,
  order_type ENUM('any','dine_in','takeaway','delivery') NOT NULL DEFAULT 'any',
  channel ENUM('any','pos','table','moova','cofe','api') NOT NULL DEFAULT 'any',
  sort_order INT NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_line_uuid (line_uuid),
  KEY idx_recipe_lines_recipe (recipe_id, sort_order),
  KEY idx_recipe_lines_ingredient (ingredient_item_id),
  KEY idx_recipe_lines_sub_recipe (sub_recipe_id),
  KEY idx_recipe_lines_modifier (modifier_group_id, modifier_option_id),
  KEY idx_recipe_lines_order_channel (order_type, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function recipeCostSnapshotsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS recipe_cost_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  snapshot_uuid CHAR(36) NOT NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  recipe_id BIGINT UNSIGNED NOT NULL,
  sellable_item_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  cost_per_yield DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  cost_per_sell_unit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  ingredient_cost_json JSON NULL,
  calculated_at DATETIME NOT NULL,
  based_on_stock_cost_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_cost_snapshot_uuid (snapshot_uuid),
  KEY idx_recipe_cost_latest (pos_tenant, pos_branch, sellable_item_id, recipe_id, calculated_at),
  KEY idx_recipe_cost_version (recipe_id, version_number),
  KEY idx_recipe_cost_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function recipeVariantLinesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS recipe_variant_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipe_id BIGINT UNSIGNED NOT NULL,
  variant_item_id BIGINT UNSIGNED NOT NULL,
  base_line_id BIGINT UNSIGNED NULL,
  line_uuid CHAR(36) NOT NULL,
  ingredient_item_id BIGINT UNSIGNED NULL,
  sub_recipe_id BIGINT UNSIGNED NULL,
  line_type ENUM('ingredient','packaging','sub_recipe','labor_placeholder') NOT NULL DEFAULT 'ingredient',
  ingredient_item_type_snapshot VARCHAR(64) NULL,
  qty_per_yield DECIMAL(18,6) NOT NULL,
  unit_id BIGINT UNSIGNED NULL,
  unit_conversion_to_base DECIMAL(18,8) NOT NULL DEFAULT 1.00000000,
  wastage_percent DECIMAL(9,4) NOT NULL DEFAULT 0.0000,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  order_type ENUM('any','dine_in','takeaway','delivery') NOT NULL DEFAULT 'any',
  channel ENUM('any','pos','table','moova','cofe','api') NOT NULL DEFAULT 'any',
  sort_order INT NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_variant_line_uuid (line_uuid),
  KEY idx_recipe_variant_lines_variant (recipe_id, variant_item_id, sort_order),
  KEY idx_recipe_variant_lines_base (base_line_id),
  KEY idx_recipe_variant_lines_ingredient (ingredient_item_id),
  KEY idx_recipe_variant_lines_sub_recipe (sub_recipe_id),
  KEY idx_recipe_variant_lines_order_channel (order_type, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function recipeOrderLineUsageSql()
    {
        return "
CREATE TABLE IF NOT EXISTS recipe_order_line_usage (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  usage_uuid CHAR(36) NOT NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  order_id BIGINT UNSIGNED NOT NULL,
  fat_detail_id BIGINT UNSIGNED NULL,
  order_line_uuid CHAR(36) NULL,
  source_channel ENUM('pos','table','moova','cofe','api','sync') NOT NULL DEFAULT 'pos',
  source_order_uuid VARCHAR(128) NULL,
  source_line_uuid VARCHAR(128) NULL,
  source_event_uuid VARCHAR(128) NULL,
  sellable_item_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NULL,
  modifiers_hash CHAR(64) NULL,
  modifiers_json JSON NULL,
  order_qty DECIMAL(18,6) NOT NULL,
  order_unit_id BIGINT UNSIGNED NULL,
  recipe_id BIGINT UNSIGNED NULL,
  recipe_version_number INT UNSIGNED NULL,
  recipe_cost_snapshot_id BIGINT UNSIGNED NULL,
  explosion_json JSON NULL,
  cost_total DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  status ENUM('previewed','reserved','consumed','released','voided','refunded','wasted') NOT NULL DEFAULT 'previewed',
  idempotency_key VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  consumed_at DATETIME NULL,
  released_at DATETIME NULL,
  voided_at DATETIME NULL,
  refunded_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_usage_uuid (usage_uuid),
  UNIQUE KEY uq_recipe_usage_idem (pos_tenant, pos_branch, store_id, idempotency_key),
  KEY idx_recipe_usage_order (pos_tenant, pos_branch, order_id, fat_detail_id),
  KEY idx_recipe_usage_line_uuid (order_line_uuid),
  KEY idx_recipe_usage_source_line (source_channel, source_order_uuid, source_line_uuid),
  KEY idx_recipe_usage_recipe (recipe_id, recipe_version_number),
  KEY idx_recipe_usage_snapshot (recipe_cost_snapshot_id),
  KEY idx_recipe_usage_status (pos_tenant, pos_branch, status),
  KEY idx_recipe_usage_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryMovementsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  movement_uuid CHAR(36) NOT NULL,
  movement_group_uuid CHAR(36) NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  item_id BIGINT UNSIGNED NOT NULL,
  movement_type ENUM('purchase','purchase_return','sale_direct','recipe_consumption','production_input','production_output','waste','adjustment','transfer_in','transfer_out','reservation','reservation_release','refund_reversal','sync_replay','opening_balance') NOT NULL,
  source_type ENUM('order','order_line','invoice','fat_details','recipe','recipe_order_line_usage','production_batch','purchase_invoice','purchase_order','purchase_receipt','inventory_count','inventory_transfer','adjustment','reservation','sync_event','manual') NOT NULL,
  source_id BIGINT UNSIGNED NULL,
  source_uuid VARCHAR(128) NULL,
  order_id BIGINT UNSIGNED NULL,
  fat_detail_id BIGINT UNSIGNED NULL,
  order_line_uuid CHAR(36) NULL,
  recipe_order_line_usage_id BIGINT UNSIGNED NULL,
  recipe_id BIGINT UNSIGNED NULL,
  recipe_cost_snapshot_id BIGINT UNSIGNED NULL,
  production_batch_id BIGINT UNSIGNED NULL,
  qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  unit_id BIGINT UNSIGNED NULL,
  unit_conversion_to_base DECIMAL(18,8) NOT NULL DEFAULT 1.00000000,
  unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  total_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  accounting_journal_id BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  payload_hash CHAR(64) NOT NULL DEFAULT '',
  metadata_json JSON NULL,
  reversed_movement_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_movement_uuid (movement_uuid),
  UNIQUE KEY uq_inventory_idempotency (pos_tenant, pos_branch, store_id, idempotency_key),
  KEY idx_inventory_item_time (pos_tenant, pos_branch, store_id, item_id, created_at),
  KEY idx_inventory_source (source_type, source_id),
  KEY idx_inventory_order_line (order_id, fat_detail_id, order_line_uuid),
  KEY idx_inventory_recipe_usage (recipe_order_line_usage_id),
  KEY idx_inventory_recipe (recipe_id),
  KEY idx_inventory_journal (accounting_journal_id),
  KEY idx_inventory_group (movement_group_uuid),
  KEY idx_inventory_movement_type_time (movement_type, created_at),
  KEY idx_inventory_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryItemBalancesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_item_balances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  item_id BIGINT UNSIGNED NOT NULL,
  qty_on_hand DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  qty_reserved DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  qty_available DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  moving_average_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  last_movement_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_balance_item (pos_tenant, pos_branch, store_id, item_id),
  KEY idx_inventory_balance_branch (pos_tenant, pos_branch),
  KEY idx_inventory_balance_available (pos_tenant, pos_branch, store_id, item_id, qty_available),
  KEY idx_inventory_balance_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryItemStockLevelsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_item_stock_levels (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  item_id BIGINT UNSIGNED NOT NULL,
  minimum_level DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  reorder_level DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  par_level DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  maximum_level DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  safety_stock_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  preferred_count_unit_id BIGINT UNSIGNED NULL,
  preferred_purchase_unit_id BIGINT UNSIGNED NULL,
  default_supplier_account_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_stock_level_item (pos_tenant, pos_branch, store_id, item_id),
  KEY idx_inventory_stock_level_branch (pos_tenant, pos_branch, store_id, is_active),
  KEY idx_inventory_stock_level_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryReasonCodesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_reason_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  reason_code VARCHAR(64) NOT NULL,
  reason_name VARCHAR(255) NOT NULL,
  reason_group ENUM('count','waste','adjustment','transfer_variance','purchase_return','production_variance','manual') NOT NULL DEFAULT 'manual',
  direction ENUM('in','out','both','none') NOT NULL DEFAULT 'both',
  requires_approval TINYINT(1) NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_reason_scope_code (pos_tenant, pos_branch, reason_code),
  KEY idx_inventory_reason_group_active (pos_tenant, pos_branch, reason_group, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryCountsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_counts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  count_uuid CHAR(36) NOT NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('draft','submitted','approved','rejected','closed','cancelled') NOT NULL DEFAULT 'draft',
  count_type ENUM('full','category','selected','spot') NOT NULL DEFAULT 'selected',
  hide_expected_qty TINYINT(1) NOT NULL DEFAULT 0,
  include_zero_stock TINYINT(1) NOT NULL DEFAULT 0,
  assigned_user_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  submitted_by BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  closed_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME NULL,
  approved_at DATETIME NULL,
  closed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  notes TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_count_uuid (count_uuid),
  KEY idx_inventory_count_scope_status (pos_tenant, pos_branch, store_id, status, created_at),
  KEY idx_inventory_count_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryCountLinesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_count_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  count_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NULL,
  unit_conversion_to_base DECIMAL(18,8) NOT NULL DEFAULT 1.00000000,
  snapshot_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  counted_qty DECIMAL(18,6) NULL,
  variance_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  variance_percent DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  variance_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  snapshot_last_movement_id BIGINT UNSIGNED NULL,
  stale_count_conflict TINYINT(1) NOT NULL DEFAULT 0,
  reason_code_id BIGINT UNSIGNED NULL,
  counted_by BIGINT UNSIGNED NULL,
  counted_at DATETIME NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_count_line_item (count_id, item_id),
  KEY idx_inventory_count_line_item (item_id),
  KEY idx_inventory_count_line_snapshot_movement (snapshot_last_movement_id),
  KEY idx_inventory_count_line_reason (reason_code_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryTransfersSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_transfers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_uuid CHAR(36) NOT NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  source_store_id BIGINT UNSIGNED NOT NULL,
  destination_store_id BIGINT UNSIGNED NOT NULL,
  destination_pos_branch INT NULL,
  destination_branch_uuid CHAR(36) NULL,
  status ENUM('draft','submitted','sent','partially_received','received','closed','cancelled','returned','variance_closed') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NULL,
  submitted_by BIGINT UNSIGNED NULL,
  sent_by BIGINT UNSIGNED NULL,
  received_by BIGINT UNSIGNED NULL,
  cancelled_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME NULL,
  sent_at DATETIME NULL,
  received_at DATETIME NULL,
  closed_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  notes TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_transfer_uuid (transfer_uuid),
  KEY idx_inventory_transfer_scope_status (pos_tenant, pos_branch, status, created_at),
  KEY idx_inventory_transfer_stores (pos_tenant, pos_branch, source_store_id, destination_store_id, status),
  KEY idx_inventory_transfer_destination_branch (pos_tenant, destination_pos_branch, destination_store_id, status),
  KEY idx_inventory_transfer_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryTransferLinesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_transfer_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NULL,
  requested_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  sent_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  received_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  variance_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  transfer_out_movement_id BIGINT UNSIGNED NULL,
  transfer_in_movement_id BIGINT UNSIGNED NULL,
  reason_code_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_transfer_line_item (transfer_id, item_id),
  KEY idx_inventory_transfer_line_item (item_id),
  KEY idx_inventory_transfer_line_out_movement (transfer_out_movement_id),
  KEY idx_inventory_transfer_line_in_movement (transfer_in_movement_id),
  KEY idx_inventory_transfer_line_reason (reason_code_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryPurchaseOrdersSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_purchase_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_order_uuid CHAR(36) NOT NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  supplier_account_id BIGINT UNSIGNED NULL,
  destination_store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('draft','submitted','approved','rejected','partially_received','received','closed','cancelled') NOT NULL DEFAULT 'draft',
  expected_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  submitted_by BIGINT UNSIGNED NULL,
  approved_by BIGINT UNSIGNED NULL,
  closed_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_at DATETIME NULL,
  approved_at DATETIME NULL,
  closed_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  notes TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_purchase_order_uuid (purchase_order_uuid),
  KEY idx_inventory_purchase_order_scope_status (pos_tenant, pos_branch, status, created_at),
  KEY idx_inventory_purchase_order_supplier (supplier_account_id, status),
  KEY idx_inventory_purchase_order_store (pos_tenant, pos_branch, destination_store_id, status),
  KEY idx_inventory_purchase_order_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryPurchaseOrderLinesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_purchase_order_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_order_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NULL,
  ordered_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  received_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  total_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_purchase_order_line_item (purchase_order_id, item_id),
  KEY idx_inventory_purchase_order_line_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryPurchaseReceiptsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_purchase_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_receipt_uuid CHAR(36) NOT NULL,
  purchase_order_id BIGINT UNSIGNED NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  supplier_account_id BIGINT UNSIGNED NULL,
  destination_store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  legacy_ot_head_id BIGINT UNSIGNED NULL,
  supplier_invoice_no VARCHAR(128) NULL,
  status ENUM('draft','received','posted','cancelled','returned') NOT NULL DEFAULT 'draft',
  received_at DATETIME NULL,
  posted_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  posted_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  notes TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_purchase_receipt_uuid (purchase_receipt_uuid),
  KEY idx_inventory_purchase_receipt_scope_status (pos_tenant, pos_branch, status, created_at),
  KEY idx_inventory_purchase_receipt_order (purchase_order_id),
  KEY idx_inventory_purchase_receipt_supplier (supplier_account_id, status),
  KEY idx_inventory_purchase_receipt_store (pos_tenant, pos_branch, destination_store_id, status),
  KEY idx_inventory_purchase_receipt_legacy (legacy_ot_head_id),
  KEY idx_inventory_purchase_receipt_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function inventoryPurchaseReceiptLinesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS inventory_purchase_receipt_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_receipt_id BIGINT UNSIGNED NOT NULL,
  purchase_order_line_id BIGINT UNSIGNED NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NULL,
  received_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  returned_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  total_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  inventory_movement_id BIGINT UNSIGNED NULL,
  reason_code_id BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inventory_purchase_receipt_line_receipt (purchase_receipt_id),
  KEY idx_inventory_purchase_receipt_line_po_line (purchase_order_line_id),
  KEY idx_inventory_purchase_receipt_line_item (item_id),
  KEY idx_inventory_purchase_receipt_line_movement (inventory_movement_id),
  KEY idx_inventory_purchase_receipt_line_reason (reason_code_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function stockReservationsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS stock_reservations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_uuid CHAR(36) NOT NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  order_id BIGINT UNSIGNED NOT NULL,
  fat_detail_id BIGINT UNSIGNED NULL,
  order_line_uuid CHAR(36) NULL,
  recipe_order_line_usage_id BIGINT UNSIGNED NULL,
  sellable_item_id BIGINT UNSIGNED NOT NULL,
  recipe_id BIGINT UNSIGNED NULL,
  ingredient_item_id BIGINT UNSIGNED NOT NULL,
  qty_reserved DECIMAL(18,6) NOT NULL,
  status ENUM('reserved','consumed','released','expired') NOT NULL DEFAULT 'reserved',
  expires_at DATETIME NULL,
  consumed_at DATETIME NULL,
  released_at DATETIME NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_reservation_uuid (reservation_uuid),
  UNIQUE KEY uq_stock_reservation_idem (pos_tenant, pos_branch, store_id, idempotency_key),
  KEY idx_stock_reservation_order (order_id, fat_detail_id, order_line_uuid),
  KEY idx_stock_reservation_order_line (order_id, order_line_uuid),
  KEY idx_stock_reservation_usage (recipe_order_line_usage_id),
  KEY idx_stock_reservation_item_status (pos_tenant, pos_branch, store_id, ingredient_item_id, status),
  KEY idx_stock_reservation_expiry (status, expires_at),
  KEY idx_stock_reservation_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function productionBatchesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS production_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_uuid CHAR(36) NOT NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  recipe_id BIGINT UNSIGNED NOT NULL,
  output_item_id BIGINT UNSIGNED NOT NULL,
  planned_output_qty DECIMAL(18,6) NOT NULL,
  actual_output_qty DECIMAL(18,6) NULL,
  status ENUM('draft','committed','cancelled') NOT NULL DEFAULT 'draft',
  started_at DATETIME NULL,
  committed_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  committed_by BIGINT UNSIGNED NULL,
  variance_reason VARCHAR(255) NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_production_batch_uuid (batch_uuid),
  KEY idx_production_recipe (pos_tenant, pos_branch, store_id, recipe_id, status),
  KEY idx_production_output (pos_tenant, pos_branch, store_id, output_item_id, committed_at),
  KEY idx_production_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function productionBatchLinesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS production_batch_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id BIGINT UNSIGNED NOT NULL,
  line_type ENUM('input','output','variance') NOT NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  planned_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  actual_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  unit_id BIGINT UNSIGNED NULL,
  unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  total_cost DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  inventory_movement_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_production_batch_lines_batch (batch_id),
  KEY idx_production_batch_lines_item (item_id),
  KEY idx_production_batch_lines_movement (inventory_movement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function recipeAuditLogSql()
    {
        return "
CREATE TABLE IF NOT EXISTS recipe_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  recipe_id BIGINT UNSIGNED NULL,
  entity_type VARCHAR(64) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  actor_user_id BIGINT UNSIGNED NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_recipe_audit_recipe (pos_tenant, pos_branch, recipe_id, created_at),
  KEY idx_recipe_audit_entity (entity_type, entity_id),
  KEY idx_recipe_audit_actor (actor_user_id, created_at),
  KEY idx_recipe_audit_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function recipeAvailabilityCacheSql()
    {
        return "
CREATE TABLE IF NOT EXISTS recipe_availability_cache (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sellable_item_id BIGINT UNSIGNED NOT NULL,
  recipe_id BIGINT UNSIGNED NULL,
  order_type ENUM('any','dine_in','takeaway','delivery') NOT NULL DEFAULT 'any',
  channel ENUM('any','pos','table','moova','cofe','api') NOT NULL DEFAULT 'any',
  computed_available_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  effective_available_qty DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
  effective_is_available TINYINT(1) NOT NULL DEFAULT 1,
  blocking_item_id BIGINT UNSIGNED NULL,
  unavailable_reason VARCHAR(255) NULL,
  availability_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  calculated_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_availability_item (pos_tenant, pos_branch, store_id, sellable_item_id, order_type, channel),
  KEY idx_recipe_availability_available (pos_tenant, pos_branch, store_id, effective_is_available),
  KEY idx_recipe_availability_revision (pos_tenant, pos_branch, availability_revision),
  KEY idx_recipe_availability_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function externalOrderLineMapSql()
    {
        return "
CREATE TABLE IF NOT EXISTS external_order_line_map (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  source_channel ENUM('moova','cofe','api','sync') NOT NULL,
  external_order_id VARCHAR(128) NOT NULL,
  external_line_id VARCHAR(128) NOT NULL,
  external_event_uuid VARCHAR(128) NULL,
  order_id BIGINT UNSIGNED NULL,
  fat_detail_id BIGINT UNSIGNED NULL,
  order_line_uuid CHAR(36) NULL,
  item_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NULL,
  modifiers_hash CHAR(64) NULL,
  modifiers_json JSON NULL,
  line_status ENUM('active','cancelled','changed','merged','split') NOT NULL DEFAULT 'active',
  idempotency_key VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_external_line (pos_tenant, pos_branch, source_channel, external_order_id, external_line_id),
  UNIQUE KEY uq_external_line_idem (pos_tenant, pos_branch, idempotency_key),
  KEY idx_external_line_order (order_id, fat_detail_id, order_line_uuid),
  KEY idx_external_line_branch_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function syncOutboxSql()
    {
        return "
CREATE TABLE IF NOT EXISTS sync_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_uuid CHAR(36) NOT NULL,
  branch_uuid CHAR(36) NOT NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  aggregate_type VARCHAR(50) NOT NULL,
  aggregate_uuid CHAR(36) NULL,
  aggregate_local_id BIGINT UNSIGNED NULL,
  aggregate_id VARCHAR(191) NOT NULL DEFAULT '',
  entity_type VARCHAR(50) NOT NULL,
  entity_uuid CHAR(36) NULL,
  entity_local_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(80) NOT NULL,
  event_version INT UNSIGNED NOT NULL DEFAULT 1,
  source_system VARCHAR(40) NOT NULL DEFAULT 'pos',
  source_event_uuid CHAR(36) NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  status ENUM('pending','syncing','synced','failed','dead') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  locked_by VARCHAR(100) NULL,
  locked_until DATETIME(6) NULL,
  next_retry_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  synced_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_outbox_event_uuid (event_uuid),
  UNIQUE KEY uq_sync_outbox_idempotency (branch_uuid, idempotency_key),
  KEY idx_sync_outbox_pending (status, next_retry_at, id),
  KEY idx_sync_outbox_aggregate (aggregate_type, aggregate_uuid),
  KEY idx_sync_outbox_entity (entity_type, entity_uuid),
  KEY idx_sync_outbox_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function syncInboxSql()
    {
        return "
CREATE TABLE IF NOT EXISTS sync_inbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_uuid CHAR(36) NULL,
  branch_uuid CHAR(36) NOT NULL,
  direction ENUM('branch_to_cloud','cloud_to_branch','moova_to_branch') NOT NULL,
  source_system VARCHAR(40) NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  status ENUM('received','processing','processed','failed','duplicate','conflict','dead') NOT NULL DEFAULT 'received',
  result_json LONGTEXT NULL,
  error_message TEXT NULL,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  processed_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_inbox_idempotency (branch_uuid, direction, idempotency_key),
  KEY idx_sync_inbox_status (status, received_at),
  KEY idx_sync_inbox_event_uuid (event_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function syncConflictsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS sync_conflicts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_uuid CHAR(36) NOT NULL,
  conflict_type VARCHAR(80) NOT NULL,
  aggregate_type VARCHAR(50) NULL,
  aggregate_uuid CHAR(36) NULL,
  local_entity_id BIGINT UNSIGNED NULL,
  remote_entity_id VARCHAR(191) NULL,
  local_revision BIGINT UNSIGNED NULL,
  remote_revision BIGINT UNSIGNED NULL,
  local_payload_json LONGTEXT NULL,
  remote_payload_json LONGTEXT NULL,
  resolution_status ENUM('open','ignored','resolved','remote_rejected','local_rejected') NOT NULL DEFAULT 'open',
  resolution_notes TEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  resolved_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  KEY idx_sync_conflicts_open (resolution_status, created_at),
  KEY idx_sync_conflicts_aggregate (aggregate_type, aggregate_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function syncCheckpointsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS sync_checkpoints (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_uuid CHAR(36) NOT NULL,
  stream_name VARCHAR(100) NOT NULL,
  last_cursor VARCHAR(255) NULL,
  last_event_time DATETIME(6) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_checkpoint (branch_uuid, stream_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function syncWorkerLogsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS sync_worker_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  worker_name VARCHAR(100) NOT NULL,
  run_uuid CHAR(36) NOT NULL,
  status ENUM('started','success','failed','heartbeat') NOT NULL,
  message TEXT NULL,
  metrics_json LONGTEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_sync_worker_logs_name_time (worker_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function syncBulkPushJobsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS sync_bulk_push_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_uuid CHAR(36) NOT NULL,
  shop_id INT UNSIGNED NULL,
  shop_db_name VARCHAR(120) NULL,
  status ENUM('queued','running','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
  phase VARCHAR(40) NOT NULL DEFAULT 'queued',
  message VARCHAR(500) NOT NULL DEFAULT '',
  progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  queue_json LONGTEXT NULL,
  dispatch_json LONGTEXT NULL,
  result_json LONGTEXT NULL,
  error_message TEXT NULL,
  started_at DATETIME(6) NULL,
  finished_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_bulk_push_job_uuid (job_uuid),
  KEY idx_sync_bulk_push_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function syncImageQueueSql()
    {
        return "
CREATE TABLE IF NOT EXISTS sync_image_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_uuid CHAR(36) NOT NULL,
  imgs_id INT NOT NULL,
  item_id INT NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  file_sha256 CHAR(64) NULL,
  direction ENUM('branch_to_cloud','cloud_to_branch') NOT NULL DEFAULT 'branch_to_cloud',
  status ENUM('pending','uploading','synced','failed','missing_file','skipped') NOT NULL DEFAULT 'pending',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  locked_until DATETIME(6) NULL,
  locked_by VARCHAR(120) NULL,
  synced_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_image_branch_imgs_dir (branch_uuid, imgs_id, direction),
  KEY idx_sync_image_pending (status, direction, locked_until),
  KEY idx_sync_image_branch_status (branch_uuid, status, direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function syncRuntimeSettingsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS sync_runtime_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,
  source VARCHAR(40) NOT NULL DEFAULT 'ui',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_runtime_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function moovaPosInboundEventsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS moova_pos_inbound_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_uuid CHAR(36) NOT NULL,
  moova_order_id VARCHAR(191) NOT NULL,
  moova_branch_id VARCHAR(191) NULL,
  pos_tenant INT NOT NULL DEFAULT 0,
  pos_branch INT NOT NULL DEFAULT 0,
  branch_uuid CHAR(36) NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  event_type ENUM('new_order','edit_order','cancel_order') NOT NULL,
  delivery_path ENUM('widget','poller','manual','test') NOT NULL DEFAULT 'widget',
  payload_json LONGTEXT NOT NULL,
  status ENUM('received','processing','notified','cashier_confirmed','applied','declined','failed','duplicate','conflict') NOT NULL DEFAULT 'received',
  pos_order_id BIGINT UNSIGNED NULL,
  pos_order_uuid CHAR(36) NULL,
  result_json LONGTEXT NULL,
  error_message TEXT NULL,
  locked_by VARCHAR(100) NULL,
  locked_until DATETIME(6) NULL,
  attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt_at DATETIME(6) NULL,
  cloud_ack_status VARCHAR(30) NULL,
  cloud_ack_error TEXT NULL,
  cloud_ack_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  cloud_ack_last_attempt_at DATETIME(6) NULL,
  cloud_acknowledged_at DATETIME(6) NULL,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  notified_at DATETIME(6) NULL,
  applied_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_moova_inbound_event_uuid (event_uuid),
  UNIQUE KEY uq_moova_inbound_idempotency (pos_tenant, pos_branch, idempotency_key),
  KEY idx_moova_inbound_status (status, received_at),
  KEY idx_moova_inbound_claim (status, locked_until, received_at, id),
  KEY idx_moova_inbound_cloud_ack (cloud_ack_status, status, applied_at, id),
  KEY idx_moova_inbound_order (moova_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function cloudBranchesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS cloud_branches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_uuid CHAR(36) NOT NULL,
  branch_name VARCHAR(255) NULL,
  pos_tenant INT NULL,
  pos_branch INT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  sync_secret_hash CHAR(64) NULL,
  sync_secret_encrypted TEXT NULL,
  last_seen_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_cloud_branches_uuid (branch_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function cloudOrdersSql()
    {
        return "
CREATE TABLE IF NOT EXISTS cloud_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_uuid CHAR(36) NOT NULL,
  order_uuid CHAR(36) NOT NULL,
  local_order_id BIGINT UNSIGNED NULL,
  pro_id VARCHAR(100) NULL,
  pro_tybe INT NULL,
  order_type VARCHAR(50) NULL,
  source_system VARCHAR(40) NULL,
  source_external_id VARCHAR(191) NULL,
  cashier_user_id INT NULL,
  waiter_id INT NULL,
  table_uuid CHAR(36) NULL,
  table_id BIGINT UNSIGNED NULL,
  table_name VARCHAR(255) NULL,
  pro_date DATETIME NULL,
  completed_at DATETIME NULL,
  payment_date DATETIME NULL,
  branch_timezone VARCHAR(100) NOT NULL DEFAULT 'Africa/Cairo',
  pro_value DECIMAL(15,4) NOT NULL DEFAULT 0,
  fat_total DECIMAL(15,4) NOT NULL DEFAULT 0,
  fat_net DECIMAL(15,4) NOT NULL DEFAULT 0,
  fat_disc DECIMAL(15,4) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
  remaining_amount DECIMAL(15,4) NOT NULL DEFAULT 0,
  payment_status VARCHAR(50) NULL,
  invoice_status VARCHAR(50) NULL,
  order_status VARCHAR(50) NULL,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  closed TINYINT(1) NOT NULL DEFAULT 0,
  sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
  payload_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  last_event_uuid CHAR(36) NULL,
  last_received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_cloud_order_branch_uuid (branch_uuid, order_uuid),
  KEY idx_cloud_orders_date (branch_uuid, pro_date),
  KEY idx_cloud_orders_status (branch_uuid, order_status, payment_status),
  KEY idx_cloud_orders_source (source_system, source_external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function cloudOrderLinesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS cloud_order_lines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_uuid CHAR(36) NOT NULL,
  order_uuid CHAR(36) NOT NULL,
  line_uuid CHAR(36) NOT NULL,
  local_line_id BIGINT UNSIGNED NULL,
  item_id BIGINT UNSIGNED NULL,
  item_uuid CHAR(36) NULL,
  item_name VARCHAR(255) NULL,
  barcode VARCHAR(191) NULL,
  qty_in DECIMAL(15,4) NOT NULL DEFAULT 0,
  qty_out DECIMAL(15,4) NOT NULL DEFAULT 0,
  price DECIMAL(15,4) NOT NULL DEFAULT 0,
  cost_price DECIMAL(15,4) NOT NULL DEFAULT 0,
  discount DECIMAL(15,4) NOT NULL DEFAULT 0,
  det_value DECIMAL(15,4) NOT NULL DEFAULT 0,
  profit DECIMAL(15,4) NOT NULL DEFAULT 0,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  payload_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cloud_line_branch_uuid (branch_uuid, line_uuid),
  KEY idx_cloud_lines_order (branch_uuid, order_uuid),
  KEY idx_cloud_lines_item (branch_uuid, item_uuid, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function cloudOrderPaymentsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS cloud_order_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_uuid CHAR(36) NOT NULL,
  order_uuid CHAR(36) NOT NULL,
  payment_uuid CHAR(36) NOT NULL,
  local_payment_id BIGINT UNSIGNED NULL,
  amount DECIMAL(15,4) NOT NULL DEFAULT 0,
  payment_method VARCHAR(50) NULL,
  reference_no VARCHAR(191) NULL,
  paid_by_customer_id BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NULL,
  voided TINYINT(1) NOT NULL DEFAULT 0,
  payload_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cloud_payment_branch_uuid (branch_uuid, payment_uuid),
  KEY idx_cloud_payments_order (branch_uuid, order_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function cloudPaymentReceiptsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS cloud_payment_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_uuid CHAR(36) NOT NULL,
  receipt_uuid CHAR(36) NOT NULL,
  order_uuid CHAR(36) NULL,
  local_receipt_id BIGINT UNSIGNED NULL,
  local_order_id BIGINT UNSIGNED NULL,
  pro_id VARCHAR(100) NULL,
  pro_tybe INT NULL,
  amount DECIMAL(15,4) NOT NULL DEFAULT 0,
  acc_fund BIGINT UNSIGNED NULL,
  payment_method VARCHAR(50) NULL,
  payment_date DATETIME NULL,
  payload_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cloud_receipt_branch_uuid (branch_uuid, receipt_uuid),
  KEY idx_cloud_receipts_order (branch_uuid, order_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function cloudTablesSql()
    {
        return "
CREATE TABLE IF NOT EXISTS cloud_tables (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_uuid CHAR(36) NOT NULL,
  table_uuid CHAR(36) NOT NULL,
  local_table_id BIGINT UNSIGNED NULL,
  tname VARCHAR(255) NULL,
  table_case INT NULL,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  active_order_uuid CHAR(36) NULL,
  sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
  payload_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  last_received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_cloud_table_branch_uuid (branch_uuid, table_uuid),
  KEY idx_cloud_tables_active_order (branch_uuid, active_order_uuid),
  KEY idx_cloud_tables_case (branch_uuid, table_case, isdeleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function cloudShiftsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS cloud_shifts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_uuid CHAR(36) NOT NULL,
  close_uuid CHAR(36) NOT NULL,
  local_closed_order_id BIGINT UNSIGNED NULL,
  cashier_user_id INT NULL,
  shift_number VARCHAR(100) NULL,
  opened_at DATETIME NULL,
  closed_at DATETIME NULL,
  branch_timezone VARCHAR(100) NOT NULL DEFAULT 'Africa/Cairo',
  total_sales DECIMAL(15,4) NOT NULL DEFAULT 0,
  total_cash DECIMAL(15,4) NOT NULL DEFAULT 0,
  total_card DECIMAL(15,4) NOT NULL DEFAULT 0,
  actual_cash DECIMAL(15,4) NULL,
  actual_card DECIMAL(15,4) NULL,
  cash_deficit DECIMAL(15,4) NULL,
  card_deficit DECIMAL(15,4) NULL,
  payload_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  last_received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_cloud_shift_branch_uuid (branch_uuid, close_uuid),
  KEY idx_cloud_shifts_closed (branch_uuid, closed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function cloudMenuItemsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS cloud_menu_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_uuid CHAR(36) NOT NULL,
  item_uuid CHAR(36) NOT NULL,
  local_item_id BIGINT UNSIGNED NULL,
  external_item_id VARCHAR(191) NULL,
  barcode VARCHAR(191) NULL,
  item_name VARCHAR(255) NULL,
  category_id BIGINT UNSIGNED NULL,
  price DECIMAL(15,4) NULL,
  cost DECIMAL(15,4) NULL,
  available_online TINYINT(1) NOT NULL DEFAULT 1,
  isdeleted TINYINT(1) NOT NULL DEFAULT 0,
  menu_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  payload_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  last_received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_cloud_menu_item_branch_uuid (branch_uuid, item_uuid),
  KEY idx_cloud_menu_external (external_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function cloudMoovaBranchEventsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS cloud_moova_branch_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_uuid CHAR(36) NOT NULL,
  branch_uuid CHAR(36) NOT NULL,
  moova_order_id VARCHAR(191) NOT NULL,
  moova_branch_id VARCHAR(191) NULL,
  event_type ENUM('new_order','edit_order','cancel_order') NOT NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  status ENUM('pending','delivered','ack_applied','ack_declined','ack_failed','dead') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  delivered_at DATETIME(6) NULL,
  acknowledged_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cloud_moova_event_uuid (event_uuid),
  UNIQUE KEY uq_cloud_moova_branch_idempotency (branch_uuid, idempotency_key),
  KEY idx_cloud_moova_branch_pending (branch_uuid, status, id),
  KEY idx_cloud_moova_order (moova_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }

    private function cloudSyncBranchEventsSql()
    {
        return "
CREATE TABLE IF NOT EXISTS cloud_sync_branch_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_uuid CHAR(36) NOT NULL,
  branch_uuid CHAR(36) NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  event_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  source_system VARCHAR(40) NOT NULL DEFAULT 'cloud_pos',
  aggregate_type VARCHAR(50) NOT NULL,
  aggregate_uuid CHAR(36) NULL,
  aggregate_local_id BIGINT UNSIGNED NULL,
  aggregate_id VARCHAR(191) NOT NULL DEFAULT '',
  entity_type VARCHAR(50) NOT NULL,
  entity_uuid CHAR(36) NULL,
  entity_local_id BIGINT UNSIGNED NULL,
  idempotency_key VARCHAR(191) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  status ENUM('pending','delivered','ack_applied','ack_declined','ack_failed','dead') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  delivered_at DATETIME(6) NULL,
  acknowledged_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cloud_sync_branch_event_uuid (event_uuid),
  UNIQUE KEY uq_cloud_sync_branch_idempotency (branch_uuid, idempotency_key),
  KEY idx_cloud_sync_branch_pending (branch_uuid, status, id),
  KEY idx_cloud_sync_branch_entity (branch_uuid, entity_type, entity_local_id),
  KEY idx_cloud_sync_branch_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    }
}
