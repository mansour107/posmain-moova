<?php

class SyncSchemaManager
{
    public function plannedStatements()
    {
        return [
            'sync_branch_identity' => $this->syncBranchIdentitySql(),
            'document_counters' => $this->documentCountersSql(),
            'pos_request_keys' => $this->posRequestKeysSql(),
            'order_events' => $this->orderEventsSql(),
            'sync_outbox' => $this->syncOutboxSql(),
            'sync_inbox' => $this->syncInboxSql(),
            'sync_checkpoints' => $this->syncCheckpointsSql(),
            'sync_conflicts' => $this->syncConflictsSql(),
            'sync_worker_logs' => $this->syncWorkerLogsSql(),
            'moova_pos_inbound_events' => $this->moovaPosInboundEventsSql(),
            'cloud_branches' => $this->cloudBranchesSql(),
            'cloud_orders' => $this->cloudOrdersSql(),
            'cloud_order_lines' => $this->cloudOrderLinesSql(),
            'cloud_order_payments' => $this->cloudOrderPaymentsSql(),
            'cloud_payment_receipts' => $this->cloudPaymentReceiptsSql(),
            'cloud_tables' => $this->cloudTablesSql(),
            'cloud_shifts' => $this->cloudShiftsSql(),
            'cloud_menu_items' => $this->cloudMenuItemsSql(),
            'cloud_moova_branch_events' => $this->cloudMoovaBranchEventsSql(),
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

        if ($table === 'cloud_moova_branch_events') {
            return $this->cloudMoovaBranchEventUpgradeStatements($conn);
        }

        if ($table === 'moova_pos_inbound_events') {
            return $this->moovaPosInboundEventUpgradeStatements($conn);
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

    private function columnExists(mysqli $conn, $table, $column)
    {
        return in_array($column, $this->existingColumns($conn, $table), true);
    }

    private function indexExists(mysqli $conn, $table, $index)
    {
        return in_array($index, $this->existingIndexes($conn, $table), true);
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
}
