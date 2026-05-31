<?php

use PHPUnit\Framework\TestCase;

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && !class_exists(TestCase::class)) {
    echo "pos-order-outbox-event-service-skipped-phpunit-unavailable\n";
    exit(0);
}

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudReceiveService.php';
require_once __DIR__ . '/../../classes/Sync/OutboxWorker.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncDeliveryResultHandler.php';
require_once __DIR__ . '/../../classes/Sync/SyncHttpClient.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';
require_once __DIR__ . '/../../classes/Sync/BranchSyncWorker.php';
require_once __DIR__ . '/../../classes/Sync/SyncOutboxEventService.php';

class PosOrderOutboxEventServiceTest extends TestCase
{
    private const BRANCH_UUID = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';
    private const SECRET = 'phpunit-pos-order-outbox-secret';

    private static $conn;
    private $orderId;
    private $itemId;
    private $originalIdentity;

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

        $this->originalIdentity = (new SyncBranchIdentity())->find(self::$conn);
        $this->cleanup();
        $this->registerCloudBranch();
        $this->seedOrder();
    }

    protected function tearDown(): void
    {
        if (!self::$conn) {
            return;
        }

        $this->cleanup();

        if ($this->originalIdentity) {
            $stmt = self::$conn->prepare("
                INSERT INTO sync_branch_identity (
                    id, branch_uuid, branch_name, pos_tenant, pos_branch, cloud_base_url, current_menu_version
                ) VALUES (1, ?, ?, ?, ?, ?, ?)
            ");
            $branchUuid = (string) $this->originalIdentity['branch_uuid'];
            $branchName = $this->originalIdentity['branch_name'];
            $posTenant = $this->nullableInt($this->originalIdentity['pos_tenant']);
            $posBranch = $this->nullableInt($this->originalIdentity['pos_branch']);
            $cloudBaseUrl = $this->originalIdentity['cloud_base_url'];
            $menuVersion = (int) $this->originalIdentity['current_menu_version'];
            $stmt->bind_param('ssiisi', $branchUuid, $branchName, $posTenant, $posBranch, $cloudBaseUrl, $menuVersion);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function testRecordOrderSnapshotCreatesDurableOutboxEventWithCloudApplyShape(): void
    {
        $result = (new SyncOutboxEventService())->recordOrderSnapshot(self::$conn, $this->orderId, [
            'event_type' => 'order.saved',
            'source_system' => 'pos_cashier',
            'config' => $this->branchConfig(),
        ]);

        $this->assertIsArray($result);
        $outbox = $this->fetchOutbox((int) $result['outbox_id']);
        $payload = json_decode($outbox['payload_json'], true);

        $this->assertSame('pending', $outbox['status']);
        $this->assertSame(self::BRANCH_UUID, $outbox['branch_uuid']);
        $this->assertSame('order.saved', $outbox['event_type']);
        $this->assertSame('pos_cashier', $outbox['source_system']);
        $this->assertSame($payload['order_uuid'], $outbox['aggregate_uuid']);
        $this->assertSame($this->orderId, (int) $payload['order']['local_order_id']);
        $this->assertSame('paid', $payload['order']['payment_status']);
        $this->assertCount(1, $payload['lines']);
        $this->assertCount(1, $payload['payments']);
        $this->assertCount(1, $payload['receipts']);
        $this->assertNotEmpty($payload['lines'][0]['line_uuid']);
    }

    public function testGeneratedOutboxEventSyncsThroughWorkerIntoCloudOrderSnapshot(): void
    {
        $event = (new SyncOutboxEventService())->recordOrderSnapshot(self::$conn, $this->orderId, [
            'event_type' => 'order.saved',
            'source_system' => 'pos_cashier',
            'config' => $this->branchConfig(),
        ]);

        $metrics = (new BranchSyncWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'worker_id' => 'phpunit-pos-order-outbox-worker',
            'http_post' => function (string $url, string $body, array $headers): array {
                $this->assertStringContainsString('/api/sync/receive_branch_events.php', $url);
                $result = (new CloudReceiveService())->handle(
                    self::$conn,
                    $this->headerLinesToMap($headers),
                    $body,
                    $this->cloudConfig()
                );

                return [
                    'ok' => $result['status_code'] >= 200 && $result['status_code'] < 300,
                    'status' => $result['status_code'],
                    'body' => json_encode($result['body'], JSON_UNESCAPED_SLASHES),
                    'json' => $result['body'],
                    'error' => '',
                ];
            },
        ]);

        $this->assertSame(1, $metrics['claimed']);
        $this->assertSame(1, $metrics['synced']);
        $this->assertSame('synced', $this->fetchOutbox((int) $event['outbox_id'])['status']);

        $cloudOrder = $this->fetchCloudOrder((string) $event['order_uuid']);
        $this->assertNotNull($cloudOrder);
        $this->assertSame((string) $this->orderId, (string) $cloudOrder['local_order_id']);
        $this->assertSame('paid', $cloudOrder['payment_status']);
        $this->assertSame(1, $this->cloudChildCount('cloud_order_lines', (string) $event['order_uuid']));
        $this->assertSame(1, $this->cloudChildCount('cloud_order_payments', (string) $event['order_uuid']));
        $this->assertSame(1, $this->cloudChildCount('cloud_payment_receipts', (string) $event['order_uuid']));
    }

    private function seedOrder(): void
    {
        $name = 'PHPUnit Sync Item ' . bin2hex(random_bytes(4));
        $barcode = 'SYNC' . random_int(100000, 999999);
        $stmt = self::$conn->prepare("
            INSERT INTO myitems (iname, barcode, itmqty, cost_price, price1, price2, price3, group1, group2, group3)
            VALUES (?, ?, 100, 5, 12, 12, 12, 0, 0, 0)
        ");
        $stmt->bind_param('ss', $name, $barcode);
        $stmt->execute();
        $this->itemId = (int) self::$conn->insert_id;
        $stmt->close();

        $proId = random_int(900000, 999999);
        $stmt = self::$conn->prepare("
            INSERT INTO ot_head (
                pro_id, pro_tybe, is_stock, is_journal, journal_tybe, info, pro_date,
                accural_date, pro_pattren, pro_serial, price_list, store_id, emp_id,
                emp2_id, acc1, acc2, pro_value, fat_total, fat_disc, fat_plus,
                fat_net, paid_amount, remaining_amount, payment_status, invoice_status,
                order_status, payment_date, user
            ) VALUES (?, 9, 1, 1, 9, 'phpunit sync order', CURDATE(),
                CURDATE(), 1, '', 1, 1, 1,
                1, 1, 1, 12.00, 12.00, 0, 0,
                12.00, 12.00, 0.00, 'paid', 'completed',
                'completed', NOW(), 1)
        ");
        $stmt->bind_param('i', $proId);
        $stmt->execute();
        $this->orderId = (int) self::$conn->insert_id;
        $stmt->close();

        $stmt = self::$conn->prepare("
            INSERT INTO fat_details (
                pro_tybe, pro_id, item_id, u_val, qty_in, qty_out, price,
                discount, det_value, fatid, fat_tybe, det_store, cost_price, profit
            ) VALUES (9, ?, ?, 1, 0, 1, 12, 0, 12, ?, 9, 1, 5, 7)
        ");
        $stmt->bind_param('iii', $this->orderId, $this->itemId, $this->orderId);
        $stmt->execute();
        $stmt->close();

        $paymentProId = $proId + 1;
        $stmt = self::$conn->prepare("
            INSERT INTO ot_head (
                pro_id, pro_tybe, is_journal, journal_tybe, info, pro_date,
                emp_id, acc1, acc2, pro_value, cost_center, profit, user, op2
            ) VALUES (?, 1, 1, 1, 'phpunit sync order - دفع كاش', CURDATE(),
                1, 1, 1, 12.00, 1, 0, 1, ?)
        ");
        $stmt->bind_param('ii', $paymentProId, $this->orderId);
        $stmt->execute();
        $stmt->close();
    }

    private function branchConfig(): array
    {
        return posmain_app_config([
            'role' => 'branch',
            'branch' => [
                'uuid' => self::BRANCH_UUID,
                'name' => 'PHPUnit POS Branch',
                'pos_tenant' => 0,
                'pos_branch' => 0,
                'cloud_base_url' => 'http://cloud-runtime.test',
            ],
            'sync' => [
                'branch_secret' => self::SECRET,
                'outbox_enabled' => true,
                'branch_sync_enabled' => true,
                'worker_enabled' => true,
            ],
        ]);
    }

    private function cloudConfig(): array
    {
        return posmain_app_config([
            'role' => 'fake_cloud',
            'sync' => [
                'cloud_branch_secrets' => [self::BRANCH_UUID => self::SECRET],
                'cloud_apply_enabled' => true,
                'shadow_mode' => false,
            ],
        ]);
    }

    private function registerCloudBranch(): void
    {
        $hash = hash('sha256', self::SECRET);
        $name = 'PHPUnit POS Branch';
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

    private function headerLinesToMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $header) {
            $parts = explode(':', (string) $header, 2);
            if (count($parts) === 2) {
                $map[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $map;
    }

    private function fetchOutbox(int $id): array
    {
        return self::$conn->query('SELECT * FROM sync_outbox WHERE id = ' . $id)->fetch_assoc();
    }

    private function fetchCloudOrder(string $orderUuid): ?array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM cloud_orders
            WHERE branch_uuid = ?
              AND order_uuid = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('ss', $branchUuid, $orderUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function cloudChildCount(string $table, string $orderUuid): int
    {
        $allowed = ['cloud_order_lines', 'cloud_order_payments', 'cloud_payment_receipts'];
        $this->assertContains($table, $allowed);

        $branchUuid = self::$conn->real_escape_string(self::BRANCH_UUID);
        $orderUuid = self::$conn->real_escape_string($orderUuid);
        $row = self::$conn->query("
            SELECT COUNT(*) AS c
            FROM {$table}
            WHERE branch_uuid = '{$branchUuid}'
              AND order_uuid = '{$orderUuid}'
        ")->fetch_assoc();

        return (int) $row['c'];
    }

    private function cleanup(): void
    {
        $branchUuid = self::$conn->real_escape_string(self::BRANCH_UUID);
        self::$conn->query("DELETE FROM cloud_order_lines WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM cloud_order_payments WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM cloud_payment_receipts WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM cloud_orders WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM sync_inbox WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM sync_outbox WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM sync_worker_logs WHERE worker_name = 'sync_worker' AND metrics_json LIKE '%phpunit-pos-order-outbox%'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query('DELETE FROM sync_branch_identity WHERE id = 1');

        if ($this->orderId) {
            $orderId = (int) $this->orderId;
            self::$conn->query("DELETE FROM fat_details WHERE fatid = {$orderId}");
            self::$conn->query("DELETE FROM ot_head WHERE op2 = {$orderId}");
            self::$conn->query("DELETE FROM ot_head WHERE id = {$orderId}");
        }

        if ($this->itemId) {
            self::$conn->query('DELETE FROM myitems WHERE id = ' . (int) $this->itemId);
        }
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }
}

class pos_order_outbox_event_service_test extends PosOrderOutboxEventServiceTest
{
}
