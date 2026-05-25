<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Moova/MoovaLocalIngestService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class MoovaLocalIngestServiceTest extends TestCase
{
    private const BRANCH_UUID = 'cccccccc-8888-4888-8888-cccccccccccc';
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
    }

    protected function tearDown(): void
    {
        if (self::$conn) {
            $this->cleanup();
        }
    }

    public function testNormalizesSameMoovaEventAcrossWidgetAndPollerShapes(): void
    {
        $service = new MoovaLocalIngestService();
        $widgetPayload = [
            'moovaOrderId' => 'phpunit-order-1',
            'moovaBranchId' => 'phpunit-branch-1',
            'revision' => 7,
            'items' => [
                ['itemId' => 'coffee-1', 'quantity' => 2, 'unitPrice' => '35.00'],
            ],
        ];
        $pollerPayload = [
            'revision' => 7,
            'moova_branch_id' => 'phpunit-branch-1',
            'moova_order_id' => 'phpunit-order-1',
            'items' => [
                ['item_id' => 'coffee-1', 'qty' => 2, 'unit_price' => '35.00'],
            ],
        ];

        $this->assertSame(
            $service->normalizeIdempotencyKey($widgetPayload, 'new_order'),
            $service->normalizeIdempotencyKey($pollerPayload, 'new_order')
        );
        $this->assertSame(
            $service->normalizePayloadHash($widgetPayload),
            $service->normalizePayloadHash($pollerPayload)
        );
    }

    public function testWidgetAndPollerDeliveryShareOneInboundIdempotencyRow(): void
    {
        $service = new MoovaLocalIngestService();
        $widgetPayload = [
            'moovaOrderId' => 'phpunit-order-2',
            'moovaBranchId' => 'phpunit-branch-1',
            'revision' => 3,
            'items' => [
                ['itemId' => 'tea-1', 'qty' => 1],
            ],
        ];
        $pollerPayload = [
            'moova_order_id' => 'phpunit-order-2',
            'moova_branch_id' => 'phpunit-branch-1',
            'revision' => 3,
            'items' => [
                ['item_id' => 'tea-1', 'quantity' => 1],
            ],
        ];

        $first = $service->ingestNewOrder(self::$conn, $widgetPayload, $this->ctx('widget'));
        $second = $service->ingestNewOrder(self::$conn, $pollerPayload, $this->ctx('poller'));

        $this->assertSame('received', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertSame($first['inbound_event_id'], $second['inbound_event_id']);
        $this->assertSame($first['idempotency_key'], $second['idempotency_key']);
        $this->assertSame($first['payload_hash'], $second['payload_hash']);

        $row = $this->fetchInbound($first['idempotency_key']);
        $this->assertSame('widget', $row['delivery_path']);
        $this->assertSame('new_order', $row['event_type']);
        $this->assertSame('received', $row['status']);
        $this->assertSame(1, $this->inboundCount($first['idempotency_key']));
    }

    public function testExplicitProviderIdempotencyKeyIsPreservedForCloudAck(): void
    {
        $service = new MoovaLocalIngestService();
        $payload = [
            'idempotency_key' => 'provider:event:with:colons',
            'moova_order_id' => 'phpunit-order-explicit',
            'moova_branch_id' => 'phpunit-branch-1',
            'items' => [
                ['item_id' => 'water-1', 'qty' => 1],
            ],
        ];

        $result = $service->ingestNewOrder(self::$conn, $payload, $this->ctx('poller'));

        $this->assertSame('provider:event:with:colons', $result['idempotency_key']);
        $this->assertSame('received', $result['status']);
        $this->assertSame('provider:event:with:colons', $this->fetchInbound($result['idempotency_key'])['idempotency_key']);
    }

    public function testNormalizesNewOrderPayloadForPosOrderService(): void
    {
        $service = new MoovaLocalIngestService();
        $normalized = $service->normalizeNewOrderForPos([
            'moova_order_id' => 'order-777',
            'moova_branch_id' => 'branch-777',
            'table_id' => 'table-provider-1',
            'notes' => 'leave at counter',
            'items' => [
                ['item_id' => 'coffee-1', 'quantity' => '2'],
                ['barcode' => 'BAR-2', 'qty' => 1],
                ['item_id' => 'bad-zero', 'qty' => 0],
            ],
        ]);

        $this->assertSame('order-777', $normalized['cofeOrderId']);
        $this->assertSame('branch-777', $normalized['branchId']);
        $this->assertSame('table-provider-1', $normalized['tableId']);
        $this->assertSame('leave at counter', $normalized['notes']);
        $this->assertSame([
            ['itemId' => 'coffee-1', 'qty' => 2.0],
            ['itemId' => 'BAR-2', 'qty' => 1.0],
        ], $normalized['items']);
    }

    public function testNormalizesNewOrderPayloadWithoutDroppingLineIdentityOrModifiers(): void
    {
        $service = new MoovaLocalIngestService();
        $normalized = $service->normalizeNewOrderForPos([
            'moova_order_id' => 'order-lines-777',
            'moova_branch_id' => 'branch-777',
            'table_id' => 'table-provider-1',
            'items' => [
                [
                    'item_id' => 'coffee-1',
                    'quantity' => '2',
                    'line_id' => 'provider-line-1',
                    'variant_id' => 'variant-3',
                    'modifiers' => [
                        ['option_id' => 10, 'qty' => 1],
                    ],
                ],
            ],
        ]);

        $this->assertSame('provider-line-1', $normalized['items'][0]['externalLineId']);
        $this->assertSame('variant-3', $normalized['items'][0]['variantId']);
        $this->assertSame([
            ['option_id' => 10, 'qty' => 1],
        ], $normalized['items'][0]['modifiers']);
    }

    public function testNewOrderPosNormalizationRejectsMissingTableBeforeApply(): void
    {
        $service = new MoovaLocalIngestService();

        $this->expectException(InvalidArgumentException::class);
        $service->normalizeNewOrderForPos([
            'moova_order_id' => 'order-no-table',
            'moova_branch_id' => 'branch-777',
            'items' => [
                ['item_id' => 'coffee-1', 'qty' => 1],
            ],
        ]);
    }

    public function testNormalizesEditChangePayloadForPosOrderService(): void
    {
        $service = new MoovaLocalIngestService();
        $normalized = $service->normalizeChangeForPos([
            'event_type' => 'edit_order',
            'moova_order_id' => 'order-edit-1',
            'moova_branch_id' => 'branch-edit-1',
            'provider_order_id' => '12345',
            'provider_event_id' => 'change-99',
            'idempotency_key' => 'provider-change-key',
            'expected_state_hash' => 'abc123',
            'items' => [
                ['item_id' => 'coffee-1', 'quantity' => '2'],
                ['barcode' => 'BAR-2', 'qty' => 1],
            ],
        ]);

        $this->assertSame('edit', $normalized['action']);
        $this->assertSame('order-edit-1', $normalized['moovaOrderId']);
        $this->assertSame('branch-edit-1', $normalized['branchId']);
        $this->assertSame('12345', $normalized['providerOrderId']);
        $this->assertSame('change-99', $normalized['requestEventId']);
        $this->assertSame('provider-change-key', $normalized['providerReferenceId']);
        $this->assertSame('abc123', $normalized['expectedStateHash']);
        $this->assertSame([
            ['itemId' => 'coffee-1', 'qty' => 2.0],
            ['itemId' => 'BAR-2', 'qty' => 1.0],
        ], $normalized['items']);
    }

    public function testNormalizesCancelChangePayloadWithoutItems(): void
    {
        $service = new MoovaLocalIngestService();
        $normalized = $service->normalizeChangeForPos([
            'event_type' => 'cancel_order',
            'moova_order_id' => 'order-cancel-1',
            'moova_branch_id' => 'branch-cancel-1',
            'provider_order_id' => '54321',
            'change_id' => 'cancel-99',
            'reason' => 'customer cancelled',
        ]);

        $this->assertSame('cancel', $normalized['action']);
        $this->assertSame('order-cancel-1', $normalized['moovaOrderId']);
        $this->assertSame('branch-cancel-1', $normalized['branchId']);
        $this->assertSame('54321', $normalized['providerOrderId']);
        $this->assertSame('cancel-99', $normalized['requestEventId']);
        $this->assertSame('customer cancelled', $normalized['reason']);
        $this->assertArrayNotHasKey('items', $normalized);
    }

    public function testChangeIngestSelectsCancelEventType(): void
    {
        $service = new MoovaLocalIngestService();
        $payload = [
            'action' => 'cancel',
            'moovaOrderId' => 'phpunit-order-3',
            'moovaBranchId' => 'phpunit-branch-1',
            'changeId' => 'cancel-1',
        ];

        $result = $service->ingestChange(self::$conn, $payload, $this->ctx('poller'));

        $this->assertSame('received', $result['status']);
        $this->assertSame('cancel_order', $result['event_type']);
        $this->assertStringContainsString(':cancel_order:', $result['idempotency_key']);
    }

    public function testMissingOrderIdIsRejectedBeforeWriting(): void
    {
        $service = new MoovaLocalIngestService();

        $this->expectException(InvalidArgumentException::class);
        $service->ingestNewOrder(
            self::$conn,
            [
                'moovaBranchId' => 'phpunit-branch-1',
                'revision' => 1,
            ],
            $this->ctx('widget')
        );
    }

    private function ctx(string $deliveryPath): array
    {
        return [
            'branch_uuid' => self::BRANCH_UUID,
            'pos_tenant' => 11,
            'pos_branch' => 12,
            'delivery_path' => $deliveryPath,
        ];
    }

    private function fetchInbound(string $idempotencyKey): array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM moova_pos_inbound_events
            WHERE pos_tenant = 11
              AND pos_branch = 12
              AND idempotency_key = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }

    private function inboundCount(string $idempotencyKey): int
    {
        $stmt = self::$conn->prepare("
            SELECT COUNT(*) AS c
            FROM moova_pos_inbound_events
            WHERE pos_tenant = 11
              AND pos_branch = 12
              AND idempotency_key = ?
        ");
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0);
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM moova_pos_inbound_events WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
    }
}

class moova_local_ingest_service_test extends MoovaLocalIngestServiceTest
{
}
