<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/MoovaInboundQueueService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Moova/MoovaLocalIngestService.php';
require_once __DIR__ . '/../../classes/MoovaPosIntegration.php';
require_once __DIR__ . '/../../classes/PosOrderService.php';
require_once __DIR__ . '/../../classes/Sync/BranchMoovaApplyWorker.php';

class BranchMoovaApplyWorkerTest extends TestCase
{
    private const BRANCH_UUID = 'eeeeeeee-1111-4111-8111-eeeeeeeeeeee';

    private static $conn;
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
        MoovaPosIntegration::ensureSchema(self::$conn);
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }

        $this->originalIdentity = (new SyncBranchIdentity())->find(self::$conn);
        $this->cleanup();
        self::$conn->query('DELETE FROM sync_branch_identity WHERE id = 1');
    }

    protected function tearDown(): void
    {
        if (!self::$conn) {
            return;
        }

        $this->cleanup();
        self::$conn->query('DELETE FROM sync_branch_identity WHERE id = 1');
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

    public function testApplyWorkerCreatesPosOrderFromQueuedNewOrder(): void
    {
        $table = $this->loadFreeTable();
        $item = $this->loadItem();
        if (!$table || !$item) {
            $this->markTestSkipped('POS table and item fixtures are not available.');
        }

        $ingest = new MoovaLocalIngestService();
        $payload = [
            'idempotency_key' => 'phpunit:moova-apply:new-order',
            'moova_order_id' => 'phpunit-apply-order-1',
            'moova_branch_id' => 'phpunit-apply-branch',
            'table_id' => (string) $table['id'],
            'items' => [
                ['item_id' => (string) $item['id'], 'qty' => 1],
            ],
        ];
        $ingest->ingestNewOrder(self::$conn, $payload, $this->ctx());

        $metrics = (new BranchMoovaApplyWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
        ]);

        $this->assertSame(1, $metrics['claimed']);
        $this->assertSame(1, $metrics['applied']);
        $this->assertSame(0, $metrics['declined']);
        $this->assertSame(0, $metrics['failed']);

        $row = $this->fetchInbound('phpunit:moova-apply:new-order');
        $this->assertSame('applied', $row['status']);
        $this->assertGreaterThan(0, (int) $row['pos_order_id']);
        $this->assertNotNull($row['applied_at']);
        $result = json_decode((string) $row['result_json'], true);
        $this->assertIsArray($result);
        $this->assertQueuedApplyMetadata($result, 'new_order', 'applied');

        $order = $this->fetchOrder((int) $row['pos_order_id']);
        $this->assertSame((int) $table['id'], (int) $order['table_id']);
        $this->assertSame(0, (int) $order['tenant']);
        $this->assertSame(0, (int) $order['branch']);
        $this->assertSame('unpaid', (string) $order['payment_status']);

        $link = $this->fetchOrderLink('phpunit:moova-apply:new-order');
        $this->assertSame((int) $row['pos_order_id'], (int) $link['pos_order_id']);
        $this->assertSame('created', $link['provider_status']);
        $this->assertNotEmpty($link['last_pos_state_hash']);
    }

    public function testApplyWorkerDeclinesInvalidNewOrderWithoutPosMutation(): void
    {
        $ingest = new MoovaLocalIngestService();
        $payload = [
            'idempotency_key' => 'phpunit:moova-apply:no-table',
            'moova_order_id' => 'phpunit-apply-order-no-table',
            'moova_branch_id' => 'phpunit-apply-branch',
            'items' => [
                ['item_id' => 'missing-table-item', 'qty' => 1],
            ],
        ];
        $ingest->ingestNewOrder(self::$conn, $payload, $this->ctx());

        $metrics = (new BranchMoovaApplyWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
        ]);

        $this->assertSame(1, $metrics['claimed']);
        $this->assertSame(0, $metrics['applied']);
        $this->assertSame(1, $metrics['declined']);
        $this->assertSame(0, $metrics['failed']);

        $row = $this->fetchInbound('phpunit:moova-apply:no-table');
        $this->assertSame('declined', $row['status']);
        $this->assertNull($row['pos_order_id']);
        $result = json_decode((string) $row['result_json'], true);
        $this->assertIsArray($result);
        $this->assertSame('TABLE_REQUIRED', $result['code']);
        $this->assertQueuedApplyMetadata($result, 'new_order', 'declined');
    }

    public function testApplyWorkerSkipsWhenFeatureFlagDisabled(): void
    {
        $ingest = new MoovaLocalIngestService();
        $payload = [
            'idempotency_key' => 'phpunit:moova-apply:disabled',
            'moova_order_id' => 'phpunit-apply-order-disabled',
            'moova_branch_id' => 'phpunit-apply-branch',
            'table_id' => '1',
            'items' => [
                ['item_id' => '1', 'qty' => 1],
            ],
        ];
        $ingest->ingestNewOrder(self::$conn, $payload, $this->ctx());

        $config = $this->branchConfig();
        $config['sync']['moova_apply_enabled'] = false;
        $metrics = (new BranchMoovaApplyWorker())->runOnce(self::$conn, $config, [
            'batch_size' => 10,
        ]);

        $this->assertSame('moova_apply_disabled', $metrics['skipped']);
        $this->assertSame('received', $this->fetchInbound('phpunit:moova-apply:disabled')['status']);
    }

    public function testApplyWorkerCancelsLinkedQueuedMoovaOrder(): void
    {
        $created = $this->createAppliedMoovaOrder(
            'phpunit:moova-apply:cancel-new-order',
            'phpunit-apply-order-cancel'
        );

        $ingest = new MoovaLocalIngestService();
        $payload = [
            'idempotency_key' => 'phpunit:moova-apply:cancel-order',
            'moova_order_id' => $created['moova_order_id'],
            'moova_branch_id' => 'phpunit-apply-branch',
            'action' => 'cancel',
            'provider_order_id' => (string) $created['pos_order_id'],
            'request_event_id' => 'phpunit-cancel-1',
        ];
        $ingest->ingestChange(self::$conn, $payload, $this->ctx());

        $metrics = (new BranchMoovaApplyWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
        ]);

        $this->assertSame(1, $metrics['claimed']);
        $this->assertSame(1, $metrics['applied']);
        $this->assertSame(0, $metrics['declined']);
        $this->assertSame(0, $metrics['failed']);

        $row = $this->fetchInbound('phpunit:moova-apply:cancel-order');
        $this->assertSame('applied', $row['status']);
        $this->assertSame((int) $created['pos_order_id'], (int) $row['pos_order_id']);
        $result = json_decode((string) $row['result_json'], true);
        $this->assertIsArray($result);
        $this->assertQueuedApplyMetadata($result, 'cancel_order', 'applied');

        $order = $this->fetchOrder((int) $created['pos_order_id']);
        $this->assertSame(1, (int) $order['isdeleted']);
        $this->assertSame('cancelled', (string) $order['invoice_status']);
        $this->assertSame('cancelled', (string) $order['order_status']);

        $link = $this->fetchOrderLink('phpunit:moova-apply:cancel-new-order');
        $this->assertSame('cancelled', $link['provider_status']);
        $this->assertNull($link['last_pos_state_hash']);

        $changeLink = $this->fetchChangeLink('phpunit:moova-apply:cancel-order');
        $this->assertSame('cancelled', $changeLink['provider_status']);
        $response = json_decode((string) $changeLink['response_payload'], true);
        $this->assertIsArray($response);
        $this->assertSame('cancel', $response['action']);
        $this->assertSame('cancelled', $response['providerStatus']);
        $this->assertQueuedApplyMetadata($response, 'cancel_order', 'applied');
    }

    public function testApplyWorkerDeclinesCancelWhenNoLinkedPosOrderExists(): void
    {
        $ingest = new MoovaLocalIngestService();
        $payload = [
            'idempotency_key' => 'phpunit:moova-apply:cancel-missing-link',
            'moova_order_id' => 'phpunit-apply-order-cancel-missing',
            'moova_branch_id' => 'phpunit-apply-branch',
            'action' => 'cancel',
            'request_event_id' => 'phpunit-cancel-missing-1',
        ];
        $ingest->ingestChange(self::$conn, $payload, $this->ctx());

        $metrics = (new BranchMoovaApplyWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
        ]);

        $this->assertSame(1, $metrics['claimed']);
        $this->assertSame(0, $metrics['applied']);
        $this->assertSame(1, $metrics['declined']);
        $this->assertSame(0, $metrics['failed']);

        $row = $this->fetchInbound('phpunit:moova-apply:cancel-missing-link');
        $this->assertSame('declined', $row['status']);
        $this->assertNull($row['pos_order_id']);
        $result = json_decode((string) $row['result_json'], true);
        $this->assertIsArray($result);
        $this->assertSame('POS_ORDER_LINK_NOT_FOUND', $result['code']);
        $this->assertQueuedApplyMetadata($result, 'cancel_order', 'declined');

        $changeLink = $this->fetchChangeLink('phpunit:moova-apply:cancel-missing-link');
        $this->assertSame('declined', $changeLink['provider_status']);
        $response = json_decode((string) $changeLink['response_payload'], true);
        $this->assertIsArray($response);
        $this->assertQueuedApplyMetadata($response, 'cancel_order', 'declined');
    }

    public function testApplyWorkerEditsLinkedQueuedMoovaOrder(): void
    {
        $created = $this->createAppliedMoovaOrder(
            'phpunit:moova-apply:edit-new-order',
            'phpunit-apply-order-edit'
        );
        $item = $this->loadItem();
        if (!$item) {
            $this->markTestSkipped('POS item fixtures are not available.');
        }

        $ingest = new MoovaLocalIngestService();
        $payload = [
            'idempotency_key' => 'phpunit:moova-apply:edit-order',
            'moova_order_id' => $created['moova_order_id'],
            'moova_branch_id' => 'phpunit-apply-branch',
            'action' => 'edit',
            'provider_order_id' => (string) $created['pos_order_id'],
            'request_event_id' => 'phpunit-edit-1',
            'items' => [
                ['item_id' => (string) $item['id'], 'qty' => 2],
            ],
        ];
        $ingest->ingestChange(self::$conn, $payload, $this->ctx());

        $metrics = (new BranchMoovaApplyWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
        ]);

        $this->assertSame(1, $metrics['claimed']);
        $this->assertSame(1, $metrics['applied']);
        $this->assertSame(0, $metrics['declined']);
        $this->assertSame(0, $metrics['failed']);

        $row = $this->fetchInbound('phpunit:moova-apply:edit-order');
        $this->assertSame('applied', $row['status']);
        $this->assertSame((int) $created['pos_order_id'], (int) $row['pos_order_id']);
        $result = json_decode((string) $row['result_json'], true);
        $this->assertIsArray($result);
        $this->assertQueuedApplyMetadata($result, 'edit_order', 'applied');

        $link = $this->fetchOrderLink('phpunit:moova-apply:edit-new-order');
        $this->assertSame('edited', $link['provider_status']);
        $this->assertNotEmpty($link['last_pos_state_hash']);

        $changeLink = $this->fetchChangeLink('phpunit:moova-apply:edit-order');
        $this->assertSame('edited', $changeLink['provider_status']);
        $response = json_decode((string) $changeLink['response_payload'], true);
        $this->assertIsArray($response);
        $this->assertSame('edit', $response['action']);
        $this->assertSame('edited', $response['providerStatus']);
        $this->assertQueuedApplyMetadata($response, 'edit_order', 'applied');
        $this->assertSame(2.0, $this->fetchActiveMappedQty($created['moova_order_id'], (int) $created['pos_order_id']));
    }

    public function testApplyWorkerDeclinesEditWhenLinkedLinesChanged(): void
    {
        $created = $this->createAppliedMoovaOrder(
            'phpunit:moova-apply:edit-stale-new-order',
            'phpunit-apply-order-edit-stale'
        );
        $item = $this->loadItem();
        if (!$item) {
            $this->markTestSkipped('POS item fixtures are not available.');
        }

        self::$conn->query("
            UPDATE moova_pos_order_links
            SET last_pos_state_hash = '0000000000000000000000000000000000000000000000000000000000000000'
            WHERE idempotency_key = 'phpunit:moova-apply:edit-stale-new-order'
        ");

        $ingest = new MoovaLocalIngestService();
        $payload = [
            'idempotency_key' => 'phpunit:moova-apply:edit-stale',
            'moova_order_id' => $created['moova_order_id'],
            'moova_branch_id' => 'phpunit-apply-branch',
            'action' => 'edit',
            'provider_order_id' => (string) $created['pos_order_id'],
            'request_event_id' => 'phpunit-edit-stale-1',
            'items' => [
                ['item_id' => (string) $item['id'], 'qty' => 2],
            ],
        ];
        $ingest->ingestChange(self::$conn, $payload, $this->ctx());

        $metrics = (new BranchMoovaApplyWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
        ]);

        $this->assertSame(1, $metrics['claimed']);
        $this->assertSame(0, $metrics['applied']);
        $this->assertSame(1, $metrics['declined']);
        $this->assertSame(0, $metrics['failed']);

        $row = $this->fetchInbound('phpunit:moova-apply:edit-stale');
        $this->assertSame('declined', $row['status']);
        $result = json_decode((string) $row['result_json'], true);
        $this->assertIsArray($result);
        $this->assertSame('POS_ORDER_LINES_CHANGED', $result['code']);
        $this->assertQueuedApplyMetadata($result, 'edit_order', 'declined');
        $this->assertSame(1.0, $this->fetchActiveMappedQty($created['moova_order_id'], (int) $created['pos_order_id']));
    }

    private function assertQueuedApplyMetadata(array $response, string $eventType, string $syncStatus): void
    {
        $this->assertSame('poller', $response['deliveryPath']);
        $this->assertSame('queued_worker', $response['applyPath']);
        $this->assertSame($eventType, $response['syncEventType']);
        $this->assertSame($syncStatus, $response['syncStatus']);
    }

    private function branchConfig(): array
    {
        return posmain_app_config([
            'role' => 'branch',
            'branch' => [
                'uuid' => self::BRANCH_UUID,
                'name' => 'PHPUnit Moova Apply Branch',
                'pos_tenant' => 0,
                'pos_branch' => 0,
                'cloud_base_url' => 'http://fake-cloud.local',
            ],
            'sync' => [
                'moova_apply_enabled' => true,
                'moova_apply_user_id' => 1,
            ],
        ]);
    }

    private function ctx(): array
    {
        return [
            'branch_uuid' => self::BRANCH_UUID,
            'pos_tenant' => 0,
            'pos_branch' => 0,
            'delivery_path' => 'poller',
        ];
    }

    private function createAppliedMoovaOrder(string $idempotencyKey, string $moovaOrderId): array
    {
        $table = $this->loadFreeTable();
        $item = $this->loadItem();
        if (!$table || !$item) {
            $this->markTestSkipped('POS table and item fixtures are not available.');
        }

        $ingest = new MoovaLocalIngestService();
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'moova_order_id' => $moovaOrderId,
            'moova_branch_id' => 'phpunit-apply-branch',
            'table_id' => (string) $table['id'],
            'items' => [
                ['item_id' => (string) $item['id'], 'qty' => 1],
            ],
        ];
        $ingest->ingestNewOrder(self::$conn, $payload, $this->ctx());

        $metrics = (new BranchMoovaApplyWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
        ]);
        $this->assertSame(1, $metrics['claimed']);
        $this->assertSame(1, $metrics['applied']);

        $row = $this->fetchInbound($idempotencyKey);
        $this->assertSame('applied', $row['status']);

        return [
            'idempotency_key' => $idempotencyKey,
            'moova_order_id' => $moovaOrderId,
            'pos_order_id' => (int) $row['pos_order_id'],
            'table_id' => (int) $table['id'],
        ];
    }

    private function loadFreeTable(): ?array
    {
        return self::$conn->query("
            SELECT t.id
            FROM tables t
            WHERE t.isdeleted = 0
              AND (t.branch IS NULL OR t.branch = '' OR t.branch = '0')
              AND NOT EXISTS (
                  SELECT 1
                  FROM ot_head h
                  WHERE h.tenant = 0
                    AND h.branch = 0
                    AND h.table_id = t.id
                    AND h.pro_tybe = 9
                    AND h.isdeleted = 0
                    AND COALESCE(h.order_status, 'active') = 'active'
                    AND COALESCE(h.payment_status, 'unpaid') IN ('unpaid', 'partial')
              )
            ORDER BY t.id ASC
            LIMIT 1
        ")->fetch_assoc() ?: null;
    }

    private function loadItem(): ?array
    {
        return self::$conn->query("
            SELECT id
            FROM myitems
            WHERE isdeleted = 0
              AND tenant = 0
              AND branch = 0
            ORDER BY id ASC
            LIMIT 1
        ")->fetch_assoc() ?: null;
    }

    private function fetchInbound(string $idempotencyKey): array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM moova_pos_inbound_events
            WHERE branch_uuid = ?
              AND idempotency_key = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('ss', $branchUuid, $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }

    private function fetchOrder(int $orderId): array
    {
        $stmt = self::$conn->prepare("SELECT * FROM ot_head WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }

    private function fetchOrderLink(string $idempotencyKey): array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM moova_pos_order_links
            WHERE idempotency_key = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }

    private function fetchChangeLink(string $idempotencyKey): array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM moova_pos_order_change_links
            WHERE idempotency_key = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }

    private function fetchActiveMappedQty(string $moovaOrderId, int $posOrderId): float
    {
        $stmt = self::$conn->prepare("
            SELECT COALESCE(SUM(fd.qty_out), 0) AS qty
            FROM moova_pos_order_lines l
            INNER JOIN fat_details fd
                    ON fd.id = l.fat_detail_id
                   AND fd.tenant = l.pos_tenant
                   AND fd.branch = l.pos_branch
            WHERE l.moova_order_id = ?
              AND l.pos_order_id = ?
              AND l.status = 'active'
              AND fd.isdeleted = 0
        ");
        $stmt->bind_param('si', $moovaOrderId, $posOrderId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (float) ($row['qty'] ?? 0);
    }

    private function cleanup(): void
    {
        $branchUuid = self::$conn->real_escape_string(self::BRANCH_UUID);
        $orderIds = [];
        $result = self::$conn->query("
            SELECT DISTINCT pos_order_id
            FROM moova_pos_inbound_events
            WHERE branch_uuid = '{$branchUuid}'
              AND pos_order_id IS NOT NULL
            UNION
            SELECT DISTINCT pos_order_id
            FROM moova_pos_order_links
            WHERE idempotency_key LIKE 'phpunit:moova-apply:%'
              AND pos_order_id IS NOT NULL
            UNION
            SELECT DISTINCT pos_order_id
            FROM moova_pos_order_change_links
            WHERE idempotency_key LIKE 'phpunit:moova-apply:%'
              AND pos_order_id IS NOT NULL
        ");
        while ($row = $result->fetch_assoc()) {
            $orderIds[] = (int) $row['pos_order_id'];
        }

        foreach (array_unique($orderIds) as $orderId) {
            if ($orderId < 1) {
                continue;
            }
            $table = self::$conn->query('SELECT table_id FROM ot_head WHERE id = ' . $orderId)->fetch_assoc();
            self::$conn->query('DELETE je FROM journal_entries je INNER JOIN journal_heads jh ON jh.id = je.journal_id WHERE jh.op_id = ' . $orderId);
            self::$conn->query('DELETE FROM journal_heads WHERE op_id = ' . $orderId);
            self::$conn->query('DELETE FROM moova_pos_order_lines WHERE pos_order_id = ' . $orderId);
            self::$conn->query('DELETE FROM fat_details WHERE fatid = ' . $orderId);
            self::$conn->query('DELETE FROM ot_head WHERE id = ' . $orderId);
            if ($table && (int) $table['table_id'] > 0) {
                self::$conn->query('UPDATE tables SET table_case = 0 WHERE id = ' . (int) $table['table_id']);
            }
        }

        self::$conn->query("DELETE FROM moova_pos_inbound_events WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM moova_pos_order_change_links WHERE idempotency_key LIKE 'phpunit:moova-apply:%'");
        self::$conn->query("DELETE FROM moova_pos_order_links WHERE idempotency_key LIKE 'phpunit:moova-apply:%'");
        self::$conn->query("DELETE FROM sync_worker_logs WHERE worker_name = 'moova_apply'");
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }
}

class branch_moova_apply_worker_test extends BranchMoovaApplyWorkerTest
{
}
