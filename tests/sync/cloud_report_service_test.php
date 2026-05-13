<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/CloudReportService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';

class CloudReportServiceTest extends TestCase
{
    private const BRANCH_UUID = 'abababab-bbbb-4bbb-8bbb-abababababab';
    private const ORDER_UUID = 'cdcdcdcd-bbbb-4bbb-8bbb-cdcdcdcdcdcd';
    private const CANCELLED_ORDER_UUID = 'dededede-bbbb-4bbb-8bbb-dededededede';
    private const OUTSIDE_ORDER_UUID = 'efefefef-bbbb-4bbb-8bbb-efefefefefef';
    private const LINE_UUID = '11112222-bbbb-4bbb-8bbb-111122221111';
    private const PAYMENT_UUID = '33334444-bbbb-4bbb-8bbb-333344443333';
    private const ITEM_UUID = '55556666-bbbb-4bbb-8bbb-555566665555';
    private const TABLE_UUID = '77778888-bbbb-4bbb-8bbb-777788887777';
    private const CLOSE_UUID = '99990000-bbbb-4bbb-8bbb-999900009999';

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
        (new SyncSchemaManager())->apply(self::$conn);
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }

        $this->cleanup();
        $this->seedSnapshots();
    }

    protected function tearDown(): void
    {
        if (self::$conn) {
            $this->cleanup();
        }
    }

    public function testBranchSummaryAggregatesCloudSnapshotsForTrustedLiveReports(): void
    {
        $report = (new CloudReportService())->branchSummary(self::$conn, self::BRANCH_UUID, [
            'from' => '2026-05-10 00:00:00',
            'to' => '2026-05-11 00:00:00',
        ], SyncApplyMode::LIVE_APPLY);

        $this->assertTrue($report['report_trusted']);
        $this->assertSame('trusted', $report['trust']['label']);
        $this->assertSame('2026-05-10 00:00:00', $report['range']['from']);
        $this->assertSame('2026-05-11 00:00:00', $report['range']['to']);

        $this->assertSame(2, $report['sales']['total_orders']);
        $this->assertSame(1, $report['sales']['cancelled_orders']);
        $this->assertSame(1, $report['sales']['net_orders']);
        $this->assertSame(1, $report['sales']['paid_orders']);
        $this->assertSame('55.2500', $report['sales']['total_sales']);
        $this->assertSame('50.2500', $report['sales']['net_sales']);
        $this->assertSame('5.0000', $report['sales']['discounts']);
        $this->assertSame('55.2500', $report['sales']['paid_amount']);
        $this->assertSame('18.0000', $report['sales']['cancelled_sales']);

        $this->assertSame(5, (int) $report['by_cashier'][0]['key']);
        $this->assertSame('55.2500', $report['by_cashier'][0]['total_sales']);
        $this->assertSame(7, (int) $report['by_waiter'][0]['key']);
        $this->assertSame('table', $report['by_order_type'][0]['key']);
        $this->assertSame('moova', $report['by_source'][0]['source']);

        $this->assertSame('cash', $report['payments'][0]['payment_method']);
        $this->assertSame('55.2500', $report['payments'][0]['amount']);
        $this->assertSame(self::ITEM_UUID, $report['items'][0]['item_uuid']);
        $this->assertSame(7, $report['items'][0]['category_id']);
        $this->assertSame('3.0000', $report['items'][0]['qty_out']);
        $this->assertSame('54.0000', $report['items'][0]['line_total']);

        $this->assertSame(1, $report['shifts']['shift_count']);
        $this->assertSame('55.2500', $report['shifts']['total_sales']);
        $this->assertSame('55.2500', $report['shifts']['expected_cash']);
        $this->assertSame('55.0000', $report['shifts']['actual_cash']);
        $this->assertSame('-0.2500', $report['shifts']['cash_deficit']);

        $this->assertSame(1, $report['tables']['table_count']);
        $this->assertSame(1, $report['tables']['active_tables']);
        $this->assertSame(1, $report['tables']['occupied_tables']);
        $this->assertSame(3, $report['snapshot_counts']['orders']);
        $this->assertSame(1, $report['snapshot_counts']['menu_items']);
    }

    public function testBranchSummaryMarksShadowAndReceiveOnlyReportsUntrusted(): void
    {
        $shadow = (new CloudReportService())->branchSummary(self::$conn, self::BRANCH_UUID, [], SyncApplyMode::SHADOW_APPLY);
        $receiveOnly = (new CloudReportService())->branchSummary(self::$conn, self::BRANCH_UUID, [], SyncApplyMode::RECEIVE_ONLY);

        $this->assertFalse($shadow['report_trusted']);
        $this->assertSame('shadow_untrusted', $shadow['trust']['label']);
        $this->assertStringContainsString('Shadow mode', $shadow['trust']['warning']);
        $this->assertFalse($receiveOnly['report_trusted']);
        $this->assertSame('receive_only_untrusted', $receiveOnly['trust']['label']);
        $this->assertStringContainsString('Cloud apply is disabled', $receiveOnly['trust']['warning']);
    }

    private function seedSnapshots(): void
    {
        $this->insertRow('cloud_orders', [
            'branch_uuid' => self::BRANCH_UUID,
            'order_uuid' => self::ORDER_UUID,
            'local_order_id' => 101,
            'pro_id' => 'POS-101',
            'pro_tybe' => 9,
            'order_type' => 'table',
            'source_system' => 'moova',
            'source_external_id' => 'moova-101',
            'cashier_user_id' => 5,
            'waiter_id' => 7,
            'pro_date' => '2026-05-10 13:00:00',
            'branch_timezone' => 'Africa/Cairo',
            'pro_value' => '55.2500',
            'fat_total' => '55.2500',
            'fat_net' => '50.2500',
            'fat_disc' => '5.0000',
            'paid_amount' => '55.2500',
            'remaining_amount' => '0.0000',
            'payment_status' => 'paid',
            'invoice_status' => 'closed',
            'order_status' => 'closed',
            'isdeleted' => 0,
            'closed' => 1,
            'sync_revision' => 1,
            'payload_hash' => hash('sha256', 'order'),
            'payload_json' => '{}',
        ]);
        $this->insertRow('cloud_orders', [
            'branch_uuid' => self::BRANCH_UUID,
            'order_uuid' => self::CANCELLED_ORDER_UUID,
            'local_order_id' => 102,
            'pro_id' => 'POS-102',
            'pro_tybe' => 9,
            'order_type' => 'table',
            'source_system' => 'pos',
            'cashier_user_id' => 5,
            'waiter_id' => 7,
            'pro_date' => '2026-05-10 14:00:00',
            'branch_timezone' => 'Africa/Cairo',
            'pro_value' => '18.0000',
            'fat_total' => '18.0000',
            'fat_net' => '18.0000',
            'fat_disc' => '0.0000',
            'paid_amount' => '0.0000',
            'remaining_amount' => '0.0000',
            'payment_status' => 'voided',
            'invoice_status' => 'cancelled',
            'order_status' => 'cancelled',
            'isdeleted' => 1,
            'closed' => 1,
            'sync_revision' => 1,
            'payload_hash' => hash('sha256', 'cancelled'),
            'payload_json' => '{}',
        ]);
        $this->insertRow('cloud_orders', [
            'branch_uuid' => self::BRANCH_UUID,
            'order_uuid' => self::OUTSIDE_ORDER_UUID,
            'local_order_id' => 103,
            'pro_id' => 'POS-103',
            'pro_tybe' => 9,
            'order_type' => 'delivery',
            'source_system' => 'pos',
            'cashier_user_id' => 9,
            'pro_date' => '2026-05-09 11:00:00',
            'branch_timezone' => 'Africa/Cairo',
            'pro_value' => '100.0000',
            'fat_total' => '100.0000',
            'fat_net' => '100.0000',
            'fat_disc' => '0.0000',
            'paid_amount' => '100.0000',
            'remaining_amount' => '0.0000',
            'payment_status' => 'paid',
            'invoice_status' => 'closed',
            'order_status' => 'closed',
            'isdeleted' => 0,
            'closed' => 1,
            'sync_revision' => 1,
            'payload_hash' => hash('sha256', 'outside'),
            'payload_json' => '{}',
        ]);
        $this->insertRow('cloud_order_lines', [
            'branch_uuid' => self::BRANCH_UUID,
            'order_uuid' => self::ORDER_UUID,
            'line_uuid' => self::LINE_UUID,
            'item_id' => 201,
            'item_uuid' => self::ITEM_UUID,
            'item_name' => 'Temp Tea',
            'qty_out' => '3.0000',
            'price' => '18.0000',
            'det_value' => '54.0000',
            'isdeleted' => 0,
            'payload_hash' => hash('sha256', 'line'),
            'payload_json' => '{}',
        ]);
        $this->insertRow('cloud_order_payments', [
            'branch_uuid' => self::BRANCH_UUID,
            'order_uuid' => self::ORDER_UUID,
            'payment_uuid' => self::PAYMENT_UUID,
            'amount' => '55.2500',
            'payment_method' => 'cash',
            'voided' => 0,
            'payload_hash' => hash('sha256', 'payment'),
            'payload_json' => '{}',
        ]);
        $this->insertRow('cloud_menu_items', [
            'branch_uuid' => self::BRANCH_UUID,
            'item_uuid' => self::ITEM_UUID,
            'local_item_id' => 201,
            'external_item_id' => 'moova-tea',
            'item_name' => 'Temp Tea',
            'category_id' => 7,
            'price' => '18.0000',
            'cost' => '8.0000',
            'available_online' => 1,
            'isdeleted' => 0,
            'menu_version' => 3,
            'payload_hash' => hash('sha256', 'menu'),
            'payload_json' => '{}',
        ]);
        $this->insertRow('cloud_tables', [
            'branch_uuid' => self::BRANCH_UUID,
            'table_uuid' => self::TABLE_UUID,
            'local_table_id' => 3,
            'tname' => 'T3',
            'table_case' => 1,
            'isdeleted' => 0,
            'active_order_uuid' => self::ORDER_UUID,
            'sync_revision' => 1,
            'payload_hash' => hash('sha256', 'table'),
            'payload_json' => '{}',
        ]);
        $this->insertRow('cloud_shifts', [
            'branch_uuid' => self::BRANCH_UUID,
            'close_uuid' => self::CLOSE_UUID,
            'local_closed_order_id' => 301,
            'cashier_user_id' => 5,
            'shift_number' => '20260510_5',
            'opened_at' => '2026-05-10 09:00:00',
            'closed_at' => '2026-05-10 22:00:00',
            'branch_timezone' => 'Africa/Cairo',
            'total_sales' => '55.2500',
            'total_cash' => '55.2500',
            'total_card' => '0.0000',
            'actual_cash' => '55.0000',
            'actual_card' => '0.0000',
            'cash_deficit' => '-0.2500',
            'card_deficit' => '0.0000',
            'payload_hash' => hash('sha256', 'shift'),
            'payload_json' => '{}',
        ]);
    }

    private function insertRow(string $table, array $row): void
    {
        $columns = array_keys($row);
        $columnSql = implode(', ', array_map(fn (string $column): string => "`{$column}`", $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $values = array_values($row);
        $types = str_repeat('s', count($values));

        $stmt = self::$conn->prepare("INSERT INTO {$table} ({$columnSql}) VALUES ({$placeholders})");
        $this->bindParams($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
    }

    private function bindParams(mysqli_stmt $stmt, string $types, array &$params): void
    {
        $refs = [];
        foreach ($params as $key => &$value) {
            $refs[$key] = &$value;
        }

        $stmt->bind_param($types, ...$refs);
    }

    private function cleanup(): void
    {
        foreach ([
            'cloud_order_payments',
            'cloud_order_lines',
            'cloud_payment_receipts',
            'cloud_orders',
            'cloud_menu_items',
            'cloud_tables',
            'cloud_shifts',
        ] as $table) {
            self::$conn->query("DELETE FROM {$table} WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        }
    }
}

class cloud_report_service_test extends CloudReportServiceTest
{
}
