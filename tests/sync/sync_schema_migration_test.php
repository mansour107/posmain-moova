<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class SyncSchemaMigrationTest extends TestCase
{
    private static $conn;
    private static $dbName;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);

        $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
        $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
        $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';

        self::$conn = @new mysqli($host, $user, $pass, '', $port);
        if (self::$conn->connect_error) {
            self::$conn = null;
            return;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$dbName = 'posmain_sync_schema_' . getmypid();
        self::$conn->query("DROP DATABASE IF EXISTS `" . self::$dbName . "`");
        self::$conn->query("CREATE DATABASE `" . self::$dbName . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        self::$conn->select_db(self::$dbName);
        self::$conn->set_charset('utf8mb4');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$conn && self::$dbName) {
            self::$conn->query("DROP DATABASE IF EXISTS `" . self::$dbName . "`");
            self::$conn->close();
        }
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }
    }

    public function testPlannedSchemaContainsFoundationAndMoovaCursorTables(): void
    {
        $manager = new SyncSchemaManager();
        $sql = implode("\n", $manager->plannedStatements());

        $this->assertArrayHasKey('app_sessions', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_branch_identity', $manager->plannedStatements());
        $this->assertArrayHasKey('document_counters', $manager->plannedStatements());
        $this->assertArrayHasKey('pos_request_keys', $manager->plannedStatements());
        $this->assertArrayHasKey('order_events', $manager->plannedStatements());
        $this->assertArrayHasKey('item_variants', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_outbox', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_inbox', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_projection_versions', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_checkpoints', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_conflicts', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_worker_logs', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_runtime_settings', $manager->plannedStatements());
        $this->assertArrayHasKey('moova_pos_inbound_events', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_branches', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_orders', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_order_lines', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_order_payments', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_payment_receipts', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_tables', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_shifts', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_menu_items', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_sync_branch_events', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_moova_branch_events', $manager->plannedStatements());
        $this->assertStringContainsString('limit_value DECIMAL(12,3) NULL', $manager->plannedStatements()['user_permission_grants']);
        $this->assertStringContainsString('is_unlimited TINYINT(1) NOT NULL DEFAULT 1', $manager->plannedStatements()['user_permission_grants']);
        $this->assertStringContainsString('expected_cash DECIMAL(19,3) NULL', $manager->plannedStatements()['drawer_sessions']);
        $this->assertStringContainsString('counted_cash DECIMAL(19,3) NULL', $manager->plannedStatements()['drawer_sessions']);
        $this->assertStringContainsString('sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 0', $manager->plannedStatements()['drawer_sessions']);
        $this->assertStringContainsString('sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 0', $manager->plannedStatements()['inventory_counts']);
        $this->assertStringContainsString('sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 0', $manager->plannedStatements()['production_batches']);
        $this->assertStringContainsString('sync_revision BIGINT UNSIGNED NOT NULL DEFAULT 0', $manager->plannedStatements()['inventory_purchase_orders']);
        $this->assertStringContainsString('moving_average_cost DECIMAL(19,6)', $manager->plannedStatements()['inventory_item_balances']);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `app_sessions`', $sql);
        $this->assertStringContainsString('payload MEDIUMBLOB NOT NULL', $sql);
        $this->assertStringContainsString('KEY idx_app_sessions_expires_at (expires_at)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sync_branch_identity', $sql);
        $this->assertStringContainsString('id TINYINT UNSIGNED NOT NULL', $sql);
        $this->assertStringContainsString('branch_uuid CHAR(36) NOT NULL', $sql);
        $this->assertStringContainsString('branch_name VARCHAR(255) NULL', $sql);
        $this->assertStringContainsString('current_menu_version BIGINT UNSIGNED NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_sync_branch_identity_uuid (branch_uuid)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS document_counters', $sql);
        $this->assertStringContainsString('pos_tenant INT NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('counter_type VARCHAR(50) NOT NULL', $sql);
        $this->assertStringContainsString('counter_key VARCHAR(100) NOT NULL', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_document_counter_scope', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS pos_request_keys', $sql);
        $this->assertStringContainsString('scope VARCHAR(80) NOT NULL', $sql);
        $this->assertStringContainsString('idempotency_key VARCHAR(128) NOT NULL', $sql);
        $this->assertStringContainsString("status ENUM('processing','completed','failed','voided') NOT NULL DEFAULT 'processing'", $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_scope_key (scope, idempotency_key)', $sql);
        $this->assertStringContainsString('KEY idx_status_created (status, created_at)', $sql);
        $this->assertStringContainsString('KEY idx_tenant_branch (tenant, branch)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS order_events', $sql);
        $this->assertStringContainsString('event_type VARCHAR(80) NOT NULL', $sql);
        $this->assertStringContainsString('event_source VARCHAR(80) NOT NULL', $sql);
        $this->assertStringContainsString('before_state_json JSON NULL', $sql);
        $this->assertStringContainsString('KEY idx_order_created (order_id, created_at)', $sql);
        $this->assertStringContainsString('KEY idx_type_created (event_type, created_at)', $sql);
        $this->assertStringContainsString('KEY idx_tenant_branch_created (tenant, branch, created_at)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sync_outbox', $sql);
        $this->assertStringContainsString('branch_uuid CHAR(36) NOT NULL', $sql);
        $this->assertStringContainsString("aggregate_id VARCHAR(191) NOT NULL DEFAULT ''", $sql);
        $this->assertStringContainsString("status ENUM('pending','syncing','synced','failed','dead') NOT NULL DEFAULT 'pending'", $sql);
        $this->assertStringContainsString('locked_by VARCHAR(100) NULL', $sql);
        $this->assertStringContainsString('locked_until DATETIME(6) NULL', $sql);
        $this->assertStringContainsString('next_retry_at DATETIME(6) NULL', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_sync_outbox_event_uuid', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_sync_outbox_idempotency (branch_uuid, idempotency_key)', $sql);
        $this->assertStringContainsString('KEY idx_sync_outbox_pending (status, next_retry_at, id)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sync_inbox', $sql);
        $this->assertStringContainsString("direction ENUM('branch_to_cloud','cloud_to_branch','moova_to_branch') NOT NULL", $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_sync_inbox_idempotency (branch_uuid, direction, idempotency_key)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sync_projection_versions', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_sync_projection_versions_aggregate (branch_uuid, aggregate_type, aggregate_uuid)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sync_checkpoints', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_sync_checkpoint (branch_uuid, stream_name)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sync_conflicts', $sql);
        $this->assertStringContainsString('KEY idx_sync_conflicts_open (resolution_status, created_at)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sync_worker_logs', $sql);
        $this->assertStringContainsString('KEY idx_sync_worker_logs_name_time (worker_name, created_at)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sync_runtime_settings', $sql);
        $this->assertStringContainsString('setting_key VARCHAR(120) NOT NULL', $sql);
        $this->assertStringContainsString('is_secret TINYINT(1) NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_sync_runtime_settings_key (setting_key)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS moova_pos_inbound_events', $sql);
        $this->assertStringContainsString("delivery_path ENUM('widget','poller','manual','test') NOT NULL DEFAULT 'widget'", $sql);
        $this->assertStringContainsString("status ENUM('received','processing','notified','cashier_confirmed','applied','declined','failed','duplicate','conflict') NOT NULL DEFAULT 'received'", $sql);
        $this->assertStringContainsString('locked_by VARCHAR(100) NULL', $sql);
        $this->assertStringContainsString('locked_until DATETIME(6) NULL', $sql);
        $this->assertStringContainsString('attempt_count INT UNSIGNED NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('cloud_ack_status VARCHAR(30) NULL', $sql);
        $this->assertStringContainsString('cloud_ack_attempt_count INT UNSIGNED NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_moova_inbound_idempotency (pos_tenant, pos_branch, idempotency_key)', $sql);
        $this->assertStringContainsString('KEY idx_moova_inbound_claim (status, locked_until, received_at, id)', $sql);
        $this->assertStringContainsString('KEY idx_moova_inbound_cloud_ack (cloud_ack_status, status, applied_at, id)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_branches', $sql);
        $this->assertStringContainsString('sync_secret_hash CHAR(64) NULL', $sql);
        $this->assertStringContainsString('sync_secret_encrypted TEXT NULL', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_orders', $sql);
        $this->assertStringContainsString('order_uuid CHAR(36) NOT NULL', $sql);
        $this->assertStringContainsString('pro_value DECIMAL(19,4) NULL DEFAULT NULL', $sql);
        $this->assertStringContainsString('fat_tax DECIMAL(19,4) NULL DEFAULT NULL', $sql);
        $this->assertStringContainsString('profit DECIMAL(19,6) NULL DEFAULT NULL', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_cloud_order_branch_uuid (branch_uuid, order_uuid)', $sql);
        $this->assertStringContainsString('KEY idx_cloud_orders_status (branch_uuid, order_status, payment_status)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_order_lines', $sql);
        $this->assertStringContainsString('qty_out DECIMAL(19,6) NULL DEFAULT NULL', $sql);
        $this->assertStringContainsString('profit DECIMAL(19,6) NULL DEFAULT NULL', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_cloud_line_branch_uuid (branch_uuid, line_uuid)', $sql);
        $this->assertStringContainsString('KEY idx_cloud_lines_order (branch_uuid, order_uuid)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_order_payments', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_cloud_payment_branch_uuid (branch_uuid, payment_uuid)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_payment_receipts', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_cloud_receipt_branch_uuid (branch_uuid, receipt_uuid)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_tables', $sql);
        $this->assertStringContainsString('table_uuid CHAR(36) NOT NULL', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_cloud_table_branch_uuid (branch_uuid, table_uuid)', $sql);
        $this->assertStringContainsString('KEY idx_cloud_tables_active_order (branch_uuid, active_order_uuid)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_shifts', $sql);
        $this->assertStringContainsString('close_uuid CHAR(36) NOT NULL', $sql);
        $this->assertStringContainsString('total_sales DECIMAL(15,4) NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_cloud_shift_branch_uuid (branch_uuid, close_uuid)', $sql);
        $this->assertStringContainsString('KEY idx_cloud_shifts_closed (branch_uuid, closed_at)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_menu_items', $sql);
        $this->assertStringContainsString('item_uuid CHAR(36) NOT NULL', $sql);
        $this->assertStringContainsString('available_online TINYINT(1) NOT NULL DEFAULT 1', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_cloud_menu_item_branch_uuid (branch_uuid, item_uuid)', $sql);
        $this->assertStringContainsString('KEY idx_cloud_menu_external (external_item_id)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_sync_branch_events', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_cloud_sync_branch_idempotency (branch_uuid, idempotency_key)', $sql);
        $this->assertStringContainsString('KEY idx_cloud_sync_branch_pending (branch_uuid, status, id)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_moova_branch_events', $sql);
        $this->assertStringContainsString('id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('KEY idx_cloud_moova_branch_pending (branch_uuid, status, id)', $sql);
        $this->assertStringNotContainsString('cursor_value BIGINT UNSIGNED NOT NULL', $sql);
    }

    public function testPhase2UuidTargetsAreDeclaredForLegacyTables(): void
    {
        $manager = new SyncSchemaManager();
        $targets = $manager->phase2UuidTargets();

        foreach (['ot_head', 'fat_details', 'order_payments', 'tables'] as $table) {
            $this->assertArrayHasKey($table, $targets);
            $this->assertSame('uuid', $targets[$table]['column']);
            $this->assertStringStartsWith('uq_', $targets[$table]['index']);
        }
    }

    public function testApplyIsIdempotentAndInspectsTables(): void
    {
        $manager = new SyncSchemaManager();

        $first = $manager->apply(self::$conn);
        $second = $manager->apply(self::$conn);
        $inspect = $manager->inspect(self::$conn);

        $this->assertIsArray($first);
        $this->assertSame([], $second);
        $this->assertTrue($inspect['app_sessions']['exists']);
        $this->assertContains('payload', $inspect['app_sessions']['columns']);
        $this->assertContains('idx_app_sessions_expires_at', $inspect['app_sessions']['indexes']);
        $this->assertTrue($inspect['sync_branch_identity']['exists']);
        $this->assertTrue($inspect['document_counters']['exists']);
        $this->assertTrue($inspect['pos_request_keys']['exists']);
        $this->assertTrue($inspect['order_events']['exists']);
        $this->assertContains('uq_scope_key', $inspect['pos_request_keys']['indexes']);
        $this->assertContains('idx_order_created', $inspect['order_events']['indexes']);
        $this->assertContains('idx_type_created', $inspect['order_events']['indexes']);
        $this->assertContains('idx_tenant_branch_created', $inspect['order_events']['indexes']);
        $this->assertTrue($inspect['sync_outbox']['exists']);
        $this->assertTrue($inspect['sync_inbox']['exists']);
        $this->assertTrue($inspect['sync_projection_versions']['exists']);
        $this->assertContains('last_event_version', $inspect['sync_projection_versions']['columns']);
        $this->assertContains('uq_sync_projection_versions_aggregate', $inspect['sync_projection_versions']['indexes']);
        $this->assertTrue($inspect['sync_checkpoints']['exists']);
        $this->assertTrue($inspect['sync_conflicts']['exists']);
        $this->assertTrue($inspect['sync_worker_logs']['exists']);
        $this->assertTrue($inspect['sync_runtime_settings']['exists']);
        $this->assertContains('setting_key', $inspect['sync_runtime_settings']['columns']);
        $this->assertContains('uq_sync_runtime_settings_key', $inspect['sync_runtime_settings']['indexes']);
        $this->assertTrue($inspect['moova_pos_inbound_events']['exists']);
        $this->assertContains('locked_by', $inspect['moova_pos_inbound_events']['columns']);
        $this->assertContains('locked_until', $inspect['moova_pos_inbound_events']['columns']);
        $this->assertContains('attempt_count', $inspect['moova_pos_inbound_events']['columns']);
        $this->assertContains('last_attempt_at', $inspect['moova_pos_inbound_events']['columns']);
        $this->assertContains('cloud_ack_status', $inspect['moova_pos_inbound_events']['columns']);
        $this->assertContains('cloud_ack_attempt_count', $inspect['moova_pos_inbound_events']['columns']);
        $this->assertContains('cloud_acknowledged_at', $inspect['moova_pos_inbound_events']['columns']);
        $this->assertContains('idx_moova_inbound_claim', $inspect['moova_pos_inbound_events']['indexes']);
        $this->assertContains('idx_moova_inbound_cloud_ack', $inspect['moova_pos_inbound_events']['indexes']);
        $this->assertTrue($inspect['cloud_branches']['exists']);
        $this->assertContains('sync_secret_encrypted', $inspect['cloud_branches']['columns']);
        $this->assertTrue($inspect['cloud_orders']['exists']);
        $this->assertTrue($inspect['cloud_order_lines']['exists']);
        $this->assertTrue($inspect['cloud_order_payments']['exists']);
        $this->assertTrue($inspect['cloud_payment_receipts']['exists']);
        $this->assertTrue($inspect['cloud_tables']['exists']);
        $this->assertTrue($inspect['cloud_shifts']['exists']);
        $this->assertTrue($inspect['cloud_menu_items']['exists']);
        $this->assertTrue($inspect['cloud_sync_branch_events']['exists']);
        $this->assertTrue($inspect['cloud_moova_branch_events']['exists']);
        $this->assertContains('limit_value', $inspect['user_permission_grants']['columns']);
        $this->assertContains('is_unlimited', $inspect['user_permission_grants']['columns']);
        $this->assertSame('decimal(19,3)', strtolower($this->columnType('drawer_sessions', 'expected_cash')));
        $this->assertSame('decimal(19,3)', strtolower($this->columnType('drawer_sessions', 'counted_cash')));
        $this->assertContains('sync_revision', $inspect['drawer_sessions']['columns']);
        $this->assertContains('sync_revision', $inspect['inventory_counts']['columns']);
        $this->assertContains('sync_revision', $inspect['production_batches']['columns']);
        $this->assertContains('sync_revision', $inspect['inventory_purchase_orders']['columns']);
        $this->assertSame('decimal(19,6)', strtolower($this->columnType('inventory_item_balances', 'moving_average_cost')));
        $this->assertSame([], $manager->pendingStatements(self::$conn));

        foreach (array_keys($manager->phase2UuidTargets()) as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $columns = $this->columnsFor($table);
            $indexes = $this->indexesFor($table);
            $this->assertContains('uuid', $columns);
            $this->assertContains($manager->phase2UuidTargets()[$table]['index'], $indexes);
        }
    }

    public function testLegacyIntegerJournalEntriesArePlannedForDecimalPrecisionUpgrade(): void
    {
        $this->dropJournalEntries();
        self::$conn->query("
            CREATE TABLE journal_entries (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                account_id INT NOT NULL,
                debit INT NOT NULL DEFAULT 0,
                credit INT NOT NULL DEFAULT 0,
                tybe INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $manager = new SyncSchemaManager();
        $pending = $this->journalPrecisionPending($manager);

        $this->assertArrayHasKey('journal_entries.modify_debit_decimal', $pending);
        $this->assertArrayHasKey('journal_entries.modify_credit_decimal', $pending);
        $this->assertStringContainsString('MODIFY COLUMN debit DECIMAL(18,6)', $pending['journal_entries.modify_debit_decimal']);
        $this->assertStringContainsString('MODIFY COLUMN credit DECIMAL(18,6)', $pending['journal_entries.modify_credit_decimal']);

        foreach ($pending as $sql) {
            self::$conn->query($sql);
        }

        $this->assertSame('decimal(18,6)', strtolower($this->columnType('journal_entries', 'debit')));
        $this->assertSame('decimal(18,6)', strtolower($this->columnType('journal_entries', 'credit')));
        $this->assertSame([], $this->journalPrecisionPending($manager));
    }

    public function testExistingInventoryCountsGainRevisionWithoutLosingRows(): void
    {
        self::$conn->query("DELETE FROM inventory_count_lines WHERE count_id = 98761");
        self::$conn->query("DELETE FROM inventory_counts WHERE id = 98761");
        self::$conn->query("INSERT INTO inventory_counts (
            id, count_uuid, pos_tenant, pos_branch, branch_uuid, store_id, status, count_type, notes
        ) VALUES (
            98761, '98761987-6198-4198-8198-761987619876', 1, 1,
            '98760987-6098-4098-8098-760987609876', 1, 'draft', 'selected', 'legacy count'
        )");
        self::$conn->query('ALTER TABLE inventory_counts DROP COLUMN sync_revision');

        $manager = new SyncSchemaManager();
        $pending = $manager->pendingStatements(self::$conn);
        $this->assertArrayHasKey('inventory_counts.add_sync_revision', $pending);
        self::$conn->query($pending['inventory_counts.add_sync_revision']);

        $row = self::$conn->query('SELECT notes, sync_revision FROM inventory_counts WHERE id = 98761')->fetch_assoc();
        $this->assertSame('legacy count', (string) ($row['notes'] ?? ''));
        $this->assertSame(0, (int) ($row['sync_revision'] ?? -1));
        $this->assertArrayNotHasKey('inventory_counts.add_sync_revision', $manager->pendingStatements(self::$conn));

        self::$conn->query('DELETE FROM inventory_counts WHERE id = 98761');
    }

    public function testExistingProductionBatchesGainRevisionWithoutLosingRows(): void
    {
        self::$conn->query('DELETE FROM production_batch_lines WHERE batch_id = 98762');
        self::$conn->query('DELETE FROM production_batches WHERE id = 98762');
        self::$conn->query("INSERT INTO production_batches (
            id, batch_uuid, pos_tenant, pos_branch, branch_uuid, store_id, recipe_id,
            output_item_id, planned_output_qty, status, notes
        ) VALUES (
            98762, '98762987-6298-4298-8298-762987629876', 1, 1,
            '98760987-6098-4098-8098-760987609876', 1, 12, 22,
            '4.000000', 'draft', 'legacy production batch'
        )");
        self::$conn->query('ALTER TABLE production_batches DROP COLUMN sync_revision');

        $manager = new SyncSchemaManager();
        $pending = $manager->pendingStatements(self::$conn);
        $this->assertArrayHasKey('production_batches.add_sync_revision', $pending);
        self::$conn->query($pending['production_batches.add_sync_revision']);

        $row = self::$conn->query('SELECT notes, sync_revision FROM production_batches WHERE id = 98762')->fetch_assoc();
        $this->assertSame('legacy production batch', (string) ($row['notes'] ?? ''));
        $this->assertSame(0, (int) ($row['sync_revision'] ?? -1));
        $this->assertArrayNotHasKey('production_batches.add_sync_revision', $manager->pendingStatements(self::$conn));

        self::$conn->query('DELETE FROM production_batches WHERE id = 98762');
    }

    public function testExistingPurchaseOrdersGainRevisionWithoutLosingRows(): void
    {
        self::$conn->query('DELETE FROM inventory_purchase_order_lines WHERE purchase_order_id = 98763');
        self::$conn->query('DELETE FROM inventory_purchase_orders WHERE id = 98763');
        self::$conn->query("INSERT INTO inventory_purchase_orders (id,purchase_order_uuid,destination_store_id,status,notes) VALUES (98763,'98763987-6398-4398-8398-763987639876',1,'draft','legacy purchase order')");
        self::$conn->query('ALTER TABLE inventory_purchase_orders DROP COLUMN sync_revision');
        $manager=new SyncSchemaManager();$pending=$manager->pendingStatements(self::$conn);
        $this->assertArrayHasKey('inventory_purchase_orders.add_sync_revision',$pending);
        self::$conn->query($pending['inventory_purchase_orders.add_sync_revision']);
        $row=self::$conn->query('SELECT notes,sync_revision FROM inventory_purchase_orders WHERE id=98763')->fetch_assoc();
        $this->assertSame('legacy purchase order',(string)$row['notes']);$this->assertSame(0,(int)$row['sync_revision']);
        $this->assertArrayNotHasKey('inventory_purchase_orders.add_sync_revision',$manager->pendingStatements(self::$conn));
        self::$conn->query('DELETE FROM inventory_purchase_orders WHERE id=98763');
    }

    public function testNullableLegacyFinancialValuesAreNormalizedBeforeNotNullPrecisionUpgrade(): void
    {
        self::$conn->query('DROP TABLE IF EXISTS fat_details');
        self::$conn->query("
            CREATE TABLE fat_details (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                cost_price DOUBLE(12,2) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$conn->query('INSERT INTO fat_details (cost_price) VALUES (NULL), (23.10)');

        $manager = new SyncSchemaManager();
        $pending = $manager->pendingStatements(self::$conn);
        $normalizeKey = 'fat_details.normalize_cost_price_nulls';
        $modifyKey = 'fat_details.modify_cost_price_decimal19_6';

        $this->assertArrayHasKey($normalizeKey, $pending);
        $this->assertArrayHasKey($modifyKey, $pending);
        $keys = array_keys($pending);
        $this->assertLessThan(array_search($modifyKey, $keys, true), array_search($normalizeKey, $keys, true));

        self::$conn->query($pending[$normalizeKey]);
        self::$conn->query($pending[$modifyKey]);

        $this->assertSame('decimal(19,6)', strtolower($this->columnType('fat_details', 'cost_price')));
        $values = self::$conn->query('SELECT cost_price FROM fat_details ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $this->assertSame('0.000000', (string) ($values[0]['cost_price'] ?? ''));
        $this->assertSame('23.100000', (string) ($values[1]['cost_price'] ?? ''));

        self::$conn->query('DROP TABLE fat_details');
    }

    public function testPolymorphicHeaderUnknownsRemainNullableDuringFinancialPrecisionUpgrade(): void
    {
        self::$conn->query('DROP TABLE IF EXISTS ot_head');
        self::$conn->query("CREATE TABLE ot_head (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fat_total DOUBLE NULL,
            fat_tax DOUBLE NULL,
            profit FLOAT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::$conn->query('INSERT INTO ot_head (fat_total, fat_tax, profit) VALUES (NULL, NULL, NULL), (12.3456, 1.2345, 11.111111)');

        $manager = new SyncSchemaManager();
        $pending = $manager->pendingStatements(self::$conn);
        foreach (['fat_total', 'fat_tax', 'profit'] as $column) {
            $this->assertArrayNotHasKey('ot_head.normalize_' . $column . '_nulls', $pending);
        }
        $this->assertArrayHasKey('ot_head.modify_fat_total_decimal19_4_nullable', $pending);
        $this->assertArrayHasKey('ot_head.modify_fat_tax_decimal19_4_nullable', $pending);
        $this->assertArrayHasKey('ot_head.modify_profit_decimal19_6_nullable', $pending);

        self::$conn->query($pending['ot_head.modify_fat_total_decimal19_4_nullable']);
        self::$conn->query($pending['ot_head.modify_fat_tax_decimal19_4_nullable']);
        self::$conn->query($pending['ot_head.modify_profit_decimal19_6_nullable']);

        $unknown = self::$conn->query('SELECT fat_total, fat_tax, profit FROM ot_head WHERE id = 1')->fetch_assoc();
        $this->assertNull($unknown['fat_total']);
        $this->assertNull($unknown['fat_tax']);
        $this->assertNull($unknown['profit']);
        $this->assertSame('decimal(19,4)', strtolower($this->columnType('ot_head', 'fat_total')));
        $this->assertSame('decimal(19,4)', strtolower($this->columnType('ot_head', 'fat_tax')));
        $this->assertSame('decimal(19,6)', strtolower($this->columnType('ot_head', 'profit')));

        self::$conn->query('DROP TABLE ot_head');
    }

    public function testExistingCloudOrderProjectionGetsAdditiveFinancialFidelityUpgrade(): void
    {
        self::$conn->query('ALTER TABLE cloud_orders DROP COLUMN fat_tax, DROP COLUMN profit');
        self::$conn->query('ALTER TABLE cloud_orders MODIFY COLUMN fat_total DECIMAL(15,4) NOT NULL DEFAULT 0');
        self::$conn->query('ALTER TABLE cloud_order_lines MODIFY COLUMN qty_out DECIMAL(15,4) NOT NULL DEFAULT 0, MODIFY COLUMN profit DECIMAL(15,4) NOT NULL DEFAULT 0');

        $manager = new SyncSchemaManager();
        $pending = $manager->pendingStatements(self::$conn);
        $this->assertArrayHasKey('cloud_orders.add_fat_tax', $pending);
        $this->assertArrayHasKey('cloud_orders.add_profit', $pending);
        $this->assertArrayHasKey('cloud_orders.modify_fat_total_decimal19_4_nullable', $pending);
        $this->assertArrayHasKey('cloud_order_lines.modify_qty_out_decimal19_6_nullable', $pending);
        $this->assertArrayHasKey('cloud_order_lines.modify_profit_decimal19_6_nullable', $pending);

        foreach ($pending as $label => $sql) {
            if (strpos($label, 'cloud_orders.') === 0 || strpos($label, 'cloud_order_lines.') === 0) {
                self::$conn->query($sql);
            }
        }
        $this->assertSame('decimal(19,4)', strtolower($this->columnType('cloud_orders', 'fat_tax')));
        $this->assertSame('decimal(19,6)', strtolower($this->columnType('cloud_orders', 'profit')));
        $this->assertSame('decimal(19,6)', strtolower($this->columnType('cloud_order_lines', 'qty_out')));
        $this->assertSame('decimal(19,6)', strtolower($this->columnType('cloud_order_lines', 'profit')));
    }

    public function testLegacyThreeDecimalDrawerCashWidensWithoutLosingFractionalValues(): void
    {
        self::$conn->query("
            ALTER TABLE drawer_sessions
              MODIFY COLUMN opening_cash DECIMAL(12,3) NOT NULL DEFAULT 0.000,
              MODIFY COLUMN expected_opening_cash DECIMAL(12,3) NULL,
              MODIFY COLUMN opening_variance DECIMAL(12,3) NULL,
              MODIFY COLUMN expected_cash DECIMAL(12,3) NULL,
              MODIFY COLUMN counted_cash DECIMAL(12,3) NULL,
              MODIFY COLUMN difference DECIMAL(12,3) NULL,
              MODIFY COLUMN close_expected_snapshot DECIMAL(12,3) NULL
        ");
        self::$conn->query("
            INSERT INTO drawer_sessions (
                uuid, user_id, tenant, branch, opened_at, opened_by,
                opening_cash, expected_opening_cash, opening_variance,
                expected_cash, counted_cash, difference, close_expected_snapshot
            ) VALUES (
                '99999999-9999-4999-a999-999999999999', 1, 1, 1, NOW(), 1,
                123.456, 123.456, 0.001, 200.123, 199.122, -1.001, 200.123
            )
        ");

        $manager = new SyncSchemaManager();
        $this->assertArrayHasKey('pos_registers.seed_default_from_drawers', $manager->pendingStatements(self::$conn));
        $pending = $this->drawerPrecisionPending($manager);
        $this->assertCount(7, $pending);
        $this->assertStringContainsString(
            'MODIFY COLUMN opening_cash DECIMAL(19,3) NOT NULL DEFAULT 0.000',
            $pending['drawer_sessions.modify_opening_cash_decimal19_3']
        );
        foreach ($pending as $sql) {
            self::$conn->query($sql);
        }

        $this->assertSame('decimal(19,3)', strtolower($this->columnType('drawer_sessions', 'opening_cash')));
        $this->assertSame('decimal(19,3)', strtolower($this->columnType('drawer_sessions', 'difference')));
        $row = self::$conn->query("
            SELECT opening_cash, expected_cash, counted_cash, difference
            FROM drawer_sessions
            WHERE uuid = '99999999-9999-4999-a999-999999999999'
        ")->fetch_assoc();
        $this->assertSame('123.456', (string) ($row['opening_cash'] ?? ''));
        $this->assertSame('200.123', (string) ($row['expected_cash'] ?? ''));
        $this->assertSame('199.122', (string) ($row['counted_cash'] ?? ''));
        $this->assertSame('-1.001', (string) ($row['difference'] ?? ''));
        $this->assertSame([], $this->drawerPrecisionPending($manager));

        self::$conn->query("DELETE FROM drawer_sessions WHERE uuid = '99999999-9999-4999-a999-999999999999'");
        $this->assertArrayNotHasKey('pos_registers.seed_default_from_drawers', $manager->pendingStatements(self::$conn));
    }

    public function testDecimalSafeJournalEntriesDoNotNeedPrecisionUpgrade(): void
    {
        $this->dropJournalEntries();
        self::$conn->query("
            CREATE TABLE journal_entries (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                account_id INT NOT NULL,
                debit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                credit DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                tybe INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->assertSame([], $this->journalPrecisionPending(new SyncSchemaManager()));
    }

    public function testTwoDecimalJournalUpgradePreservesExistingWholeNumberCapacity(): void
    {
        $this->dropJournalEntries();
        self::$conn->query("
            CREATE TABLE journal_entries (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                journal_id INT NOT NULL,
                account_id INT NOT NULL,
                debit DECIMAL(19,2) NOT NULL DEFAULT 0.00,
                credit DECIMAL(19,2) NOT NULL DEFAULT 0.00,
                tybe INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$conn->query("
            INSERT INTO journal_entries (journal_id, account_id, debit, credit)
            VALUES (1, 1, 99999999999999999.12, 0)
        ");

        $manager = new SyncSchemaManager();
        $pending = $this->journalPrecisionPending($manager);

        $this->assertStringContainsString('MODIFY COLUMN debit DECIMAL(23,6)', $pending['journal_entries.modify_debit_decimal']);
        $this->assertStringContainsString('MODIFY COLUMN credit DECIMAL(23,6)', $pending['journal_entries.modify_credit_decimal']);
        foreach ($pending as $sql) {
            self::$conn->query($sql);
        }

        $this->assertSame('decimal(23,6)', strtolower($this->columnType('journal_entries', 'debit')));
        $this->assertSame('decimal(23,6)', strtolower($this->columnType('journal_entries', 'credit')));
        $row = self::$conn->query('SELECT debit FROM journal_entries WHERE id = 1')->fetch_assoc();
        $this->assertSame('99999999999999999.120000', (string) ($row['debit'] ?? ''));
        $this->assertSame([], $this->journalPrecisionPending($manager));
    }

    private function tableExists(string $table): bool
    {
        $stmt = self::$conn->prepare("
            SELECT COUNT(*) AS table_count
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ((int) ($row['table_count'] ?? 0)) > 0;
    }

    private function columnsFor(string $table): array
    {
        $stmt = self::$conn->prepare("
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

    private function indexesFor(string $table): array
    {
        $stmt = self::$conn->prepare("
            SELECT DISTINCT INDEX_NAME
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
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

    private function journalPrecisionPending(SyncSchemaManager $manager): array
    {
        $pending = [];
        foreach ($manager->pendingStatements(self::$conn) as $label => $sql) {
            if (strpos($label, 'journal_entries.') === 0) {
                $pending[$label] = $sql;
            }
        }

        return $pending;
    }

    private function drawerPrecisionPending(SyncSchemaManager $manager): array
    {
        $pending = [];
        foreach ($manager->pendingStatements(self::$conn) as $label => $sql) {
            if (strpos($label, 'drawer_sessions.modify_') === 0
                && substr($label, -12) === '_decimal19_3'
            ) {
                $pending[$label] = $sql;
            }
        }

        return $pending;
    }

    private function columnType(string $table, string $column): string
    {
        $stmt = self::$conn->prepare("
            SELECT COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (string) ($row['COLUMN_TYPE'] ?? '');
    }

    private function dropJournalEntries(): void
    {
        self::$conn->query('DROP TABLE IF EXISTS journal_entries');
    }
}

class sync_schema_migration_test extends SyncSchemaMigrationTest
{
}
