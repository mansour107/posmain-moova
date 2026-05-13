<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/MoovaInboundQueueService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class MoovaInboundQueueTest extends TestCase
{
    private const BRANCH_UUID = 'abababab-7777-4777-8777-abababababab';
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

    public function testRecordsPollerEventAsReceivedWithoutPosMutation(): void
    {
        $event = $this->event('new_order');
        $result = (new MoovaInboundQueueService())->recordPollerEvent(self::$conn, $event, $this->ctx());

        $this->assertSame('received', $result['status']);
        $this->assertTrue($result['recorded']);

        $row = $this->fetchInbound($event['idempotency_key']);
        $this->assertSame($event['event_uuid'], $row['event_uuid']);
        $this->assertSame($event['moova_order_id'], $row['moova_order_id']);
        $this->assertSame('new_order', $row['event_type']);
        $this->assertSame('poller', $row['delivery_path']);
        $this->assertSame('received', $row['status']);
        $this->assertSame(7, (int) $row['pos_tenant']);
        $this->assertSame(9, (int) $row['pos_branch']);
        $this->assertNull($row['pos_order_id']);
        $this->assertNull($row['pos_order_uuid']);
    }

    public function testDuplicateSameIdempotencyAndHashReturnsExistingRow(): void
    {
        $event = $this->event('edit_order');
        $service = new MoovaInboundQueueService();

        $first = $service->recordPollerEvent(self::$conn, $event, $this->ctx());
        $second = $service->recordPollerEvent(self::$conn, $event, $this->ctx());

        $this->assertSame('received', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        $this->assertFalse($second['recorded']);
        $this->assertSame($first['inbound_event_id'], $second['inbound_event_id']);
        $this->assertSame(1, $this->inboundCount($event['idempotency_key']));
    }

    public function testDuplicateIdempotencyDifferentHashCreatesConflictWithoutSecondInboundRow(): void
    {
        $event = $this->event('cancel_order');
        $changed = $event;
        $changed['payload'] = [
            'moova_order_id' => $event['moova_order_id'],
            'revision' => 2,
            'reason' => 'changed after first receipt',
        ];
        $changed['payload_hash'] = hash('sha256', json_encode($changed['payload'], JSON_UNESCAPED_SLASHES));

        $service = new MoovaInboundQueueService();
        $service->recordPollerEvent(self::$conn, $event, $this->ctx());
        $result = $service->recordPollerEvent(self::$conn, $changed, $this->ctx());

        $this->assertSame('conflict', $result['status']);
        $this->assertFalse($result['recorded']);
        $this->assertSame(1, $this->inboundCount($event['idempotency_key']));
        $this->assertSame(1, $this->conflictCount());
    }

    public function testRejectsInvalidEventTypeBeforeWriting(): void
    {
        $event = $this->event('new_order');
        $event['event_type'] = 'refund_order';

        $this->expectException(InvalidArgumentException::class);
        (new MoovaInboundQueueService())->recordPollerEvent(self::$conn, $event, $this->ctx());
    }

    public function testClaimsReceivedAndExpiredProcessingRowsWithLockMetadata(): void
    {
        $service = new MoovaInboundQueueService();
        $received = $this->event('new_order');
        $expired = $this->event('edit_order');
        $future = $this->event('cancel_order');

        $receivedResult = $service->recordPollerEvent(self::$conn, $received, $this->ctx());
        $expiredResult = $service->recordPollerEvent(self::$conn, $expired, $this->ctx());
        $futureResult = $service->recordPollerEvent(self::$conn, $future, $this->ctx());

        self::$conn->query("
            UPDATE moova_pos_inbound_events
               SET status = 'processing',
                   locked_by = 'stale-worker',
                   locked_until = DATE_SUB(NOW(6), INTERVAL 5 SECOND),
                   attempt_count = 2
             WHERE id = " . (int) $expiredResult['inbound_event_id']
        );
        self::$conn->query("
            UPDATE moova_pos_inbound_events
               SET status = 'processing',
                   locked_by = 'active-worker',
                   locked_until = DATE_ADD(NOW(6), INTERVAL 5 MINUTE),
                   attempt_count = 4
             WHERE id = " . (int) $futureResult['inbound_event_id']
        );

        $claimed = $service->claimPending(self::$conn, $this->ctx(), [
            'worker_name' => 'phpunit-worker',
            'limit' => 10,
            'lock_ttl_seconds' => 30,
        ]);

        $claimedIds = array_map(static function (array $row): int {
            return (int) $row['id'];
        }, $claimed);
        sort($claimedIds);

        $expectedIds = [
            (int) $receivedResult['inbound_event_id'],
            (int) $expiredResult['inbound_event_id'],
        ];
        sort($expectedIds);

        $this->assertSame($expectedIds, $claimedIds);
        foreach ($claimed as $row) {
            $this->assertSame('processing', $row['status']);
            $this->assertSame('phpunit-worker', $row['locked_by']);
            $this->assertNotNull($row['locked_until']);
            $this->assertNotNull($row['last_attempt_at']);
            $this->assertSame($row['moova_order_id'], $row['payload']['moova_order_id']);
        }

        $expiredRow = $this->fetchInbound($expired['idempotency_key']);
        $futureRow = $this->fetchInbound($future['idempotency_key']);

        $this->assertSame(3, (int) $expiredRow['attempt_count']);
        $this->assertSame('active-worker', $futureRow['locked_by']);
        $this->assertSame(4, (int) $futureRow['attempt_count']);
    }

    public function testClaimPendingCanFilterEventTypesAndAllowZeroPosScope(): void
    {
        $service = new MoovaInboundQueueService();
        $newOrder = $this->event('new_order');
        $editOrder = $this->event('edit_order');
        $zeroCtx = [
            'branch_uuid' => self::BRANCH_UUID,
            'pos_tenant' => 0,
            'pos_branch' => 0,
        ];

        $newResult = $service->recordPollerEvent(self::$conn, $newOrder, $zeroCtx);
        $service->recordPollerEvent(self::$conn, $editOrder, $zeroCtx);

        $claimed = $service->claimPending(self::$conn, $zeroCtx, [
            'worker_name' => 'phpunit-filter-worker',
            'event_types' => ['new_order'],
        ]);

        $this->assertCount(1, $claimed);
        $this->assertSame((int) $newResult['inbound_event_id'], (int) $claimed[0]['id']);
        $this->assertSame('new_order', $claimed[0]['event_type']);
        $this->assertSame('received', $this->fetchInboundForScope($editOrder['idempotency_key'], 0, 0)['status']);
    }

    public function testMarkProcessingResultCompletesRowAndClearsLock(): void
    {
        $service = new MoovaInboundQueueService();
        $event = $this->event('edit_order');
        $service->recordPollerEvent(self::$conn, $event, $this->ctx());
        $claimed = $service->claimPending(self::$conn, $this->ctx(), [
            'worker_name' => 'phpunit-result-worker',
        ]);

        $updated = $service->markProcessingResult(
            self::$conn,
            (int) $claimed[0]['id'],
            'declined',
            [
                'code' => 'POS_ORDER_LINK_NOT_FOUND',
                'message' => 'No matching POS order link.',
            ],
            [
                'error_message' => 'No matching POS order link.',
            ]
        );

        $this->assertSame('declined', $updated['status']);
        $this->assertNull($updated['locked_by']);
        $this->assertNull($updated['locked_until']);
        $this->assertNotNull($updated['applied_at']);
        $this->assertSame('POS_ORDER_LINK_NOT_FOUND', $updated['result']['code']);
        $this->assertSame('No matching POS order link.', $updated['error_message']);
    }

    public function testMarkProcessingResultRejectsUnclaimedRows(): void
    {
        $service = new MoovaInboundQueueService();
        $event = $this->event('cancel_order');
        $result = $service->recordPollerEvent(self::$conn, $event, $this->ctx());

        $this->expectException(RuntimeException::class);
        $service->markProcessingResult(
            self::$conn,
            (int) $result['inbound_event_id'],
            'declined',
            ['code' => 'NOT_CLAIMED']
        );
    }

    private function event(string $eventType): array
    {
        $key = 'phpunit:moova-inbound:' . bin2hex(random_bytes(8));
        $payload = [
            'moova_order_id' => 'moova-order-' . substr($key, -6),
            'moova_branch_id' => 'moova-branch-1',
            'revision' => 1,
        ];

        return [
            'event_uuid' => $this->uuid(),
            'moova_order_id' => $payload['moova_order_id'],
            'moova_branch_id' => $payload['moova_branch_id'],
            'event_type' => $eventType,
            'idempotency_key' => $key,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'payload' => $payload,
        ];
    }

    private function ctx(): array
    {
        return [
            'branch_uuid' => self::BRANCH_UUID,
            'pos_tenant' => 7,
            'pos_branch' => 9,
        ];
    }

    private function fetchInbound(string $idempotencyKey): array
    {
        return $this->fetchInboundForScope($idempotencyKey, 7, 9);
    }

    private function fetchInboundForScope(string $idempotencyKey, int $tenant, int $branch): array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM moova_pos_inbound_events
            WHERE pos_tenant = ?
              AND pos_branch = ?
              AND idempotency_key = ?
            LIMIT 1
        ");
        $stmt->bind_param('iis', $tenant, $branch, $idempotencyKey);
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
            WHERE pos_tenant = 7
              AND pos_branch = 9
              AND idempotency_key = ?
        ");
        $stmt->bind_param('s', $idempotencyKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0);
    }

    private function conflictCount(): int
    {
        $branchUuid = self::BRANCH_UUID;
        $stmt = self::$conn->prepare("
            SELECT COUNT(*) AS c
            FROM sync_conflicts
            WHERE branch_uuid = ?
              AND conflict_type = 'moova_inbound_idempotency_hash_mismatch'
        ");
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0);
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM moova_pos_inbound_events WHERE idempotency_key LIKE 'phpunit:moova-inbound:%'");
        self::$conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}

class moova_inbound_queue_test extends MoovaInboundQueueTest
{
}
