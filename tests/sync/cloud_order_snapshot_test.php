<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudReceiveService.php';
require_once __DIR__ . '/../../classes/Sync/CloudOrderSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';

class CloudOrderSnapshotTest extends TestCase
{
    private const BRANCH_UUID = 'cccccccc-3333-4333-8333-cccccccccccc';
    private const ORDER_UUID = 'dddddddd-4444-4444-8444-dddddddddddd';
    private const LINE_UUID = '11111111-7777-4777-8777-111111111111';
    private const PAYMENT_UUID = '22222222-7777-4777-8777-222222222222';
    private const RECEIPT_UUID = '33333333-7777-4777-8777-333333333333';
    private const SECRET = 'phpunit-cloud-order-secret';

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
        $this->registerCloudBranch();
    }

    protected function tearDown(): void
    {
        if (self::$conn) {
            $this->cleanup();
        }
    }

    public function testReceiveOnlyStoresInboxWithoutCloudOrderSnapshot(): void
    {
        $event = $this->event('receive-only', [
            'paid_amount' => 0,
            'remaining_amount' => 55.25,
            'payment_status' => 'unpaid',
        ]);

        $result = $this->postEvent($event, false, false);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('receive_only', $result['body']['mode']);
        $this->assertSame('accepted_shadow', $result['body']['results'][0]['status']);
        $this->assertFalse($result['body']['results'][0]['applied']);
        $this->assertFalse($result['body']['results'][0]['report_trusted']);
        $this->assertNull($this->fetchCloudOrder());
        $this->assertSame(0, $this->cloudChildCount('cloud_order_lines'));
        $this->assertSame(0, $this->cloudChildCount('cloud_order_payments'));
        $this->assertSame(0, $this->cloudChildCount('cloud_payment_receipts'));
        $this->assertSame('received', $this->fetchInbox($event['idempotency_key'])['status']);
    }

    public function testShadowModeAppliesOrderSnapshotButKeepsReportsUntrusted(): void
    {
        $event = $this->event('shadow', [
            'paid_amount' => 10,
            'remaining_amount' => 45.25,
            'payment_status' => 'partial',
            'order_status' => 'active',
            'sync_revision' => 3,
        ]);

        $result = $this->postEvent($event, true, true);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('shadow_apply', $result['body']['mode']);
        $this->assertSame('accepted_shadow', $result['body']['results'][0]['status']);
        $this->assertTrue($result['body']['results'][0]['applied']);
        $this->assertFalse($result['body']['results'][0]['report_trusted']);
        $this->assertStringStartsWith('cloud_order:', (string) $result['body']['results'][0]['cloud_entity_id']);

        $order = $this->fetchCloudOrder();
        $this->assertSame(self::ORDER_UUID, $order['order_uuid']);
        $this->assertSame('POS-42', $order['pro_id']);
        $this->assertSame(9, (int) $order['pro_tybe']);
        $this->assertSame('table', $order['order_type']);
        $this->assertSame('moova-test-42', $order['source_external_id']);
        $this->assertSame(12, (int) $order['cashier_user_id']);
        $this->assertSame(7, (int) $order['table_id']);
        $this->assertSame('55.2500', $order['fat_total']);
        $this->assertSame('10.0000', $order['paid_amount']);
        $this->assertSame('partial', $order['payment_status']);
        $this->assertSame(3, (int) $order['sync_revision']);
        $this->assertSame($event['event_uuid'], $order['last_event_uuid']);
        $line = $this->fetchCloudLine();
        $this->assertSame(self::LINE_UUID, $line['line_uuid']);
        $this->assertSame('Temp Tea', $line['item_name']);
        $this->assertSame('2.0000', $line['qty_out']);
        $this->assertSame('18.0000', $line['price']);
        $payment = $this->fetchCloudPayment();
        $this->assertSame(self::PAYMENT_UUID, $payment['payment_uuid']);
        $this->assertSame('10.0000', $payment['amount']);
        $this->assertSame('cash', $payment['payment_method']);
        $receipt = $this->fetchCloudReceipt();
        $this->assertSame(self::RECEIPT_UUID, $receipt['receipt_uuid']);
        $this->assertSame('10.0000', $receipt['amount']);
        $this->assertSame('RCPT-42', $receipt['pro_id']);
        $this->assertSame('processed', $this->fetchInbox($event['idempotency_key'])['status']);
    }

    public function testLiveApplyUpdatesExistingOrderSnapshotByBranchAndOrderUuid(): void
    {
        $first = $this->event('live-first', [
            'paid_amount' => 10,
            'remaining_amount' => 45.25,
            'payment_status' => 'partial',
            'order_status' => 'active',
            'sync_revision' => 1,
        ]);
        $second = $this->event('live-second', [
            'paid_amount' => 55.25,
            'remaining_amount' => 0,
            'payment_status' => 'paid',
            'invoice_status' => 'closed',
            'order_status' => 'closed',
            'closed' => true,
            'sync_revision' => 2,
            'completed_at' => '2026-05-10 15:05:00',
            'payment_date' => '2026-05-10 15:05:15',
            'lines' => [[
                'line_uuid' => self::LINE_UUID,
                'local_line_id' => 501,
                'item_id' => 101,
                'item_uuid' => '44444444-7777-4777-8777-444444444444',
                'item_name' => 'Temp Tea Updated',
                'qty_out' => 3,
                'price' => 18,
                'det_value' => 54,
            ]],
            'payments' => [[
                'payment_uuid' => self::PAYMENT_UUID,
                'local_payment_id' => 601,
                'amount' => 55.25,
                'payment_method' => 'card',
            ]],
            'receipts' => [[
                'receipt_uuid' => self::RECEIPT_UUID,
                'local_receipt_id' => 701,
                'local_order_id' => 4201,
                'pro_id' => 'RCPT-43',
                'pro_tybe' => 1,
                'amount' => 55.25,
                'payment_method' => 'card',
                'payment_date' => '2026-05-10 15:05:15',
            ]],
        ]);

        $this->postEvent($first, true, false);
        $result = $this->postEvent($second, true, false);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('live_apply', $result['body']['mode']);
        $this->assertSame('processed', $result['body']['results'][0]['status']);
        $this->assertTrue($result['body']['results'][0]['applied']);
        $this->assertTrue($result['body']['results'][0]['report_trusted']);

        $this->assertSame(1, $this->cloudOrderCount());
        $order = $this->fetchCloudOrder();
        $this->assertSame('55.2500', $order['paid_amount']);
        $this->assertSame('0.0000', $order['remaining_amount']);
        $this->assertSame('paid', $order['payment_status']);
        $this->assertSame('closed', $order['order_status']);
        $this->assertSame(1, (int) $order['closed']);
        $this->assertSame(2, (int) $order['sync_revision']);
        $this->assertSame($second['event_uuid'], $order['last_event_uuid']);
        $this->assertSame(1, $this->cloudChildCount('cloud_order_lines'));
        $this->assertSame(1, $this->cloudChildCount('cloud_order_payments'));
        $this->assertSame(1, $this->cloudChildCount('cloud_payment_receipts'));
        $this->assertSame('Temp Tea Updated', $this->fetchCloudLine()['item_name']);
        $this->assertSame('55.2500', $this->fetchCloudPayment()['amount']);
        $this->assertSame('RCPT-43', $this->fetchCloudReceipt()['pro_id']);
    }

    private function event(string $suffix, array $overrides): array
    {
        $order = array_merge([
            'order_uuid' => self::ORDER_UUID,
            'local_order_id' => 4201,
            'pro_id' => 'POS-42',
            'pro_tybe' => 9,
            'order_type' => 'table',
            'source_system' => 'pos',
            'source_external_id' => 'moova-test-42',
            'cashier_user_id' => 12,
            'waiter_id' => 8,
            'table_uuid' => 'eeeeeeee-5555-4555-8555-eeeeeeeeeeee',
            'table_id' => 7,
            'table_name' => 'T7',
            'pro_date' => '2026-05-10 14:30:00',
            'branch_timezone' => 'Africa/Cairo',
            'pro_value' => 55.25,
            'fat_total' => 55.25,
            'fat_net' => 55.25,
            'fat_disc' => 0,
            'paid_amount' => 0,
            'remaining_amount' => 55.25,
            'payment_status' => 'unpaid',
            'invoice_status' => 'open',
            'order_status' => 'active',
            'isdeleted' => false,
            'closed' => false,
            'sync_revision' => 1,
            'lines' => [[
                'line_uuid' => self::LINE_UUID,
                'local_line_id' => 501,
                'item_id' => 101,
                'item_uuid' => '44444444-7777-4777-8777-444444444444',
                'item_name' => 'Temp Tea',
                'barcode' => 'TEMPTEA',
                'qty_out' => 2,
                'price' => 18,
                'cost_price' => 8,
                'discount' => 0,
                'det_value' => 36,
                'profit' => 20,
            ]],
            'payments' => [[
                'payment_uuid' => self::PAYMENT_UUID,
                'local_payment_id' => 601,
                'amount' => 10,
                'payment_method' => 'cash',
                'reference_no' => 'cash-1',
                'created_by' => 12,
            ]],
            'receipts' => [[
                'receipt_uuid' => self::RECEIPT_UUID,
                'local_receipt_id' => 701,
                'local_order_id' => 4201,
                'pro_id' => 'RCPT-42',
                'pro_tybe' => 1,
                'amount' => 10,
                'acc_fund' => 1,
                'payment_method' => 'cash',
                'payment_date' => '2026-05-10 14:40:00',
            ]],
        ], $overrides);
        $payload = ['order' => $order];

        return [
            'event_uuid' => SyncBranchIdentity::generateUuidV4(),
            'idempotency_key' => 'phpunit:cloud-order:' . $suffix,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'event_type' => 'order.saved',
            'event_version' => (int) $order['sync_revision'],
            'source_system' => 'pos',
            'aggregate_type' => 'order',
            'aggregate_uuid' => self::ORDER_UUID,
            'entity_type' => 'order',
            'entity_uuid' => self::ORDER_UUID,
            'payload' => $payload,
        ];
    }

    private function postEvent(array $event, bool $apply, bool $shadow): array
    {
        $body = json_encode([
            'schema_version' => 1,
            'branch_uuid' => self::BRANCH_UUID,
            'events' => [$event],
        ], JSON_UNESCAPED_SLASHES);

        return (new CloudReceiveService())->handle(
            self::$conn,
            $this->signedHeaders($body),
            $body,
            $this->cloudConfig($apply, $shadow)
        );
    }

    private function cloudConfig(bool $apply, bool $shadow): array
    {
        return posmain_app_config([
            'role' => 'cloud',
            'sync' => [
                'cloud_branch_secrets' => [self::BRANCH_UUID => self::SECRET],
                'cloud_apply_enabled' => $apply,
                'shadow_mode' => $shadow,
            ],
        ]);
    }

    private function signedHeaders(string $body): array
    {
        $timestamp = (string) time();
        $nonce = 'phpunit-' . bin2hex(random_bytes(4));

        return [
            'x-posmain-branch-uuid' => self::BRANCH_UUID,
            'x-posmain-timestamp' => $timestamp,
            'x-posmain-nonce' => $nonce,
            'x-posmain-signature' => CloudAuthService::sign(self::SECRET, $timestamp, $nonce, $body),
        ];
    }

    private function registerCloudBranch(): void
    {
        $hash = hash('sha256', self::SECRET);
        $name = 'PHPUnit Cloud Order Branch';
        $stmt = self::$conn->prepare("
            INSERT INTO cloud_branches (branch_uuid, branch_name, status, sync_secret_hash)
            VALUES (?, ?, 'active', ?)
            ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name),
                                    status = 'active',
                                    sync_secret_hash = VALUES(sync_secret_hash)
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('sss', $branchUuid, $name, $hash);
        $stmt->execute();
        $stmt->close();
    }

    private function fetchCloudOrder(): ?array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM cloud_orders
            WHERE branch_uuid = ?
              AND order_uuid = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $orderUuid = self::ORDER_UUID;
        $stmt->bind_param('ss', $branchUuid, $orderUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchCloudLine(): ?array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM cloud_order_lines
            WHERE branch_uuid = ?
              AND line_uuid = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $lineUuid = self::LINE_UUID;
        $stmt->bind_param('ss', $branchUuid, $lineUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchCloudPayment(): ?array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM cloud_order_payments
            WHERE branch_uuid = ?
              AND payment_uuid = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $paymentUuid = self::PAYMENT_UUID;
        $stmt->bind_param('ss', $branchUuid, $paymentUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchCloudReceipt(): ?array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM cloud_payment_receipts
            WHERE branch_uuid = ?
              AND receipt_uuid = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $receiptUuid = self::RECEIPT_UUID;
        $stmt->bind_param('ss', $branchUuid, $receiptUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchInbox(string $idempotencyKey): ?array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM sync_inbox
            WHERE branch_uuid = ?
              AND direction = 'branch_to_cloud'
              AND idempotency_key = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('ss', $branchUuid, $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function cloudOrderCount(): int
    {
        $stmt = self::$conn->prepare("
            SELECT COUNT(*) AS c
            FROM cloud_orders
            WHERE branch_uuid = ?
              AND order_uuid = ?
        ");
        $branchUuid = self::BRANCH_UUID;
        $orderUuid = self::ORDER_UUID;
        $stmt->bind_param('ss', $branchUuid, $orderUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0);
    }

    private function cloudChildCount(string $table): int
    {
        if (!in_array($table, ['cloud_order_lines', 'cloud_order_payments', 'cloud_payment_receipts'], true)) {
            throw new InvalidArgumentException('Unexpected cloud child table.');
        }

        $stmt = self::$conn->prepare("
            SELECT COUNT(*) AS c
            FROM {$table}
            WHERE branch_uuid = ?
              AND order_uuid = ?
        ");
        $branchUuid = self::BRANCH_UUID;
        $orderUuid = self::ORDER_UUID;
        $stmt->bind_param('ss', $branchUuid, $orderUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0);
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM cloud_payment_receipts WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM cloud_order_payments WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM cloud_order_lines WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM cloud_orders WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM sync_inbox WHERE idempotency_key LIKE 'phpunit:cloud-order:%'");
        self::$conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
    }
}

class cloud_order_snapshot_test extends CloudOrderSnapshotTest
{
}
