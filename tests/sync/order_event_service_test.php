<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/OrderEventService.php';

class OrderEventServiceTest extends TestCase
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
        (new SyncSchemaManager())->apply(self::$conn);
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }
    }

    public function testRecordStoresStructuredOrderEventJsonInsideCallerTransaction(): void
    {
        $service = new OrderEventService();
        $orderId = random_int(100000, 999999);
        $eventId = 0;

        self::$conn->begin_transaction();
        try {
            self::$conn->query('DELETE FROM sync_branch_identity WHERE id = 1');
            $event = $service->record(self::$conn, $orderId, 'payment_added', 'unit_test', [
                'actor_user_id' => 77,
                'tenant' => 12,
                'branch' => 34,
                'before_state' => ['payment_status' => 'unpaid'],
                'after_state' => ['payment_status' => 'partial'],
                'metadata' => ['amount' => '10.50'],
                'sync_config' => $this->syncConfig(),
            ]);
            $eventId = (int) $event['id'];

            $this->assertGreaterThan(0, $event['id']);

            $stmt = self::$conn->prepare("SELECT * FROM order_events WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $event['id']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $this->assertSame($orderId, (int) $row['order_id']);
            $this->assertSame('payment_added', $row['event_type']);
            $this->assertSame('unit_test', $row['event_source']);
            $this->assertSame(77, (int) $row['actor_user_id']);
            $this->assertSame(12, (int) $row['tenant']);
            $this->assertSame(34, (int) $row['branch']);
            $this->assertSame('unpaid', json_decode($row['before_state_json'], true)['payment_status']);
            $this->assertSame('partial', json_decode($row['after_state_json'], true)['payment_status']);
            $this->assertSame('10.50', json_decode($row['metadata_json'], true)['amount']);

            $outbox = self::$conn->query(
                "SELECT * FROM sync_outbox WHERE aggregate_type = 'order_event' AND aggregate_local_id = {$eventId} LIMIT 1"
            )->fetch_assoc();
            $this->assertIsArray($outbox);
            $this->assertSame('pending', $outbox['status']);
            $payload = json_decode($outbox['payload_json'], true);
            $this->assertSame('order_event', $payload['domain']);
            $this->assertSame($eventId, (int) $payload['row']['id']);
        } finally {
            self::$conn->rollback();
        }

        $this->assertSame(
            0,
            (int) self::$conn->query("SELECT COUNT(*) AS c FROM order_events WHERE id = {$eventId}")->fetch_assoc()['c']
        );
        $this->assertSame(
            0,
            (int) self::$conn->query(
                "SELECT COUNT(*) AS c FROM sync_outbox WHERE aggregate_type = 'order_event' AND aggregate_local_id = {$eventId}"
            )->fetch_assoc()['c']
        );
    }

    public function testRecordRequiresOrderIdAndEventNames(): void
    {
        $service = new OrderEventService();

        $this->expectException(InvalidArgumentException::class);
        $service->record(self::$conn, 0, 'payment_added', 'unit_test');
    }

    private function syncConfig(): array
    {
        return [
            'role' => 'branch',
            'branch' => [
                'uuid' => '12345678-1234-4234-8234-123456789012',
                'name' => 'Order Event PHPUnit Branch',
                'pos_tenant' => 12,
                'pos_branch' => 34,
                'cloud_base_url' => 'http://cloud-runtime.test',
            ],
            'sync' => [
                'outbox_enabled' => true,
                'branch_sync_enabled' => true,
                'operational_sync_enabled' => true,
            ],
        ];
    }
}

class order_event_service_test extends OrderEventServiceTest
{
}
