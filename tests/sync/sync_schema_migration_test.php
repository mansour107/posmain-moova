<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class SyncSchemaMigrationTest extends TestCase
{
    private static $conn;

    public static function setUpBeforeClass(): void
    {
        mysqli_report(MYSQLI_REPORT_OFF);

        $host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
        $user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
        $pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
        $db = getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2';

        self::$conn = @new mysqli($host, $user, $pass, $db, $port);
        if (self::$conn->connect_error) {
            self::$conn = null;
            return;
        }

        self::$conn->set_charset('utf8mb4');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
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

        $this->assertArrayHasKey('sync_branch_identity', $manager->plannedStatements());
        $this->assertArrayHasKey('document_counters', $manager->plannedStatements());
        $this->assertArrayHasKey('pos_request_keys', $manager->plannedStatements());
        $this->assertArrayHasKey('order_events', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_outbox', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_inbox', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_checkpoints', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_conflicts', $manager->plannedStatements());
        $this->assertArrayHasKey('sync_worker_logs', $manager->plannedStatements());
        $this->assertArrayHasKey('moova_pos_inbound_events', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_branches', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_orders', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_order_lines', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_order_payments', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_payment_receipts', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_tables', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_shifts', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_menu_items', $manager->plannedStatements());
        $this->assertArrayHasKey('cloud_moova_branch_events', $manager->plannedStatements());
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
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sync_checkpoints', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_sync_checkpoint (branch_uuid, stream_name)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sync_conflicts', $sql);
        $this->assertStringContainsString('KEY idx_sync_conflicts_open (resolution_status, created_at)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS sync_worker_logs', $sql);
        $this->assertStringContainsString('KEY idx_sync_worker_logs_name_time (worker_name, created_at)', $sql);
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
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_orders', $sql);
        $this->assertStringContainsString('order_uuid CHAR(36) NOT NULL', $sql);
        $this->assertStringContainsString('pro_value DECIMAL(15,4) NOT NULL DEFAULT 0', $sql);
        $this->assertStringContainsString('UNIQUE KEY uq_cloud_order_branch_uuid (branch_uuid, order_uuid)', $sql);
        $this->assertStringContainsString('KEY idx_cloud_orders_status (branch_uuid, order_status, payment_status)', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_order_lines', $sql);
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
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_moova_branch_events', $sql);
        $this->assertStringContainsString('id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('KEY idx_cloud_moova_branch_pending (branch_uuid, status, id)', $sql);
        $this->assertStringNotContainsString('cursor_value BIGINT UNSIGNED NOT NULL', $sql);
    }

    public function testPhase2UuidTargetsAreDeclaredForLegacyTables(): void
    {
        $manager = new SyncSchemaManager();
        $targets = $manager->phase2UuidTargets();

        foreach (['ot_head', 'fat_details', 'order_payments', 'tables', 'closed_orders'] as $table) {
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
        $this->assertTrue($inspect['sync_checkpoints']['exists']);
        $this->assertTrue($inspect['sync_conflicts']['exists']);
        $this->assertTrue($inspect['sync_worker_logs']['exists']);
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
        $this->assertTrue($inspect['cloud_orders']['exists']);
        $this->assertTrue($inspect['cloud_order_lines']['exists']);
        $this->assertTrue($inspect['cloud_order_payments']['exists']);
        $this->assertTrue($inspect['cloud_payment_receipts']['exists']);
        $this->assertTrue($inspect['cloud_tables']['exists']);
        $this->assertTrue($inspect['cloud_shifts']['exists']);
        $this->assertTrue($inspect['cloud_menu_items']['exists']);
        $this->assertTrue($inspect['cloud_moova_branch_events']['exists']);
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
}

class sync_schema_migration_test extends SyncSchemaMigrationTest
{
}
