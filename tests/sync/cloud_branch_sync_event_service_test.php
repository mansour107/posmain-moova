<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudBranchSyncEventService.php';
require_once __DIR__ . '/../../classes/Sync/CloudBranchSyncEventCursor.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class CloudBranchSyncEventServiceTest extends TestCase
{
    private const BRANCH_UUID = 'abababab-1111-4111-8111-abababababab';
    private const OTHER_BRANCH_UUID = 'cdcdcdcd-2222-4222-8222-cdcdcdcdcdcd';
    private const SECRET = 'phpunit-cloud-sync-secret';

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
        $this->registerCloudBranch(self::BRANCH_UUID);
        $this->registerCloudBranch(self::OTHER_BRANCH_UUID);
    }

    protected function tearDown(): void
    {
        if (self::$conn) {
            $this->cleanup();
        }
    }

    public function testBranchEventsReturnsSignedPendingGenericSyncEvents(): void
    {
        $first = $this->insertEvent(self::BRANCH_UUID, 'menu_item', 'menu.item_saved');
        $second = $this->insertEvent(self::BRANCH_UUID, 'table', 'table.updated');
        $this->insertEvent(self::BRANCH_UUID, 'order', 'order.saved', 'ack_applied');
        $this->insertEvent(self::OTHER_BRANCH_UUID, 'menu_item', 'menu.item_saved');

        $query = [
            'branch_uuid' => self::BRANCH_UUID,
            'after_cursor' => (string) $first['id'],
            'limit' => '25',
        ];
        $signatureBody = CloudBranchSyncEventService::branchEventsSignatureBody(self::BRANCH_UUID, (int) $first['id'], 25);

        $result = (new CloudBranchSyncEventService())->handleBranchEvents(
            self::$conn,
            $this->signedHeaders($signatureBody),
            $query,
            $this->cloudConfig()
        );

        $this->assertSame(200, $result['status_code']);
        $this->assertTrue($result['body']['ok']);
        $this->assertSame(1, $result['body']['count']);
        $this->assertSame((int) $second['id'], $result['body']['next_cursor']);
        $this->assertSame($second['event_uuid'], $result['body']['events'][0]['event_uuid']);
        $this->assertSame('table.updated', $result['body']['events'][0]['event_type']);
        $this->assertSame('table', $result['body']['events'][0]['entity_type']);
        $this->assertSame(123, $result['body']['events'][0]['payload']['local_table_id']);
        $this->assertSame('pending', $this->fetchEvent((int) $second['id'])['status']);
    }

    public function testAckIsAuthenticatedAndScopedToBranch(): void
    {
        $event = $this->insertEvent(self::BRANCH_UUID, 'menu_item', 'menu.item_saved');
        $other = $this->insertEvent(self::OTHER_BRANCH_UUID, 'menu_item', 'menu.item_saved');

        $body = json_encode([
            'branch_uuid' => self::BRANCH_UUID,
            'acks' => [[
                'event_uuid' => $event['event_uuid'],
                'idempotency_key' => $event['idempotency_key'],
                'ack_status' => 'ack_applied',
            ], [
                'event_uuid' => $other['event_uuid'],
                'idempotency_key' => $other['idempotency_key'],
                'ack_status' => 'ack_applied',
            ]],
        ], JSON_UNESCAPED_SLASHES);

        $result = (new CloudBranchSyncEventService())->handleAck(
            self::$conn,
            $this->signedHeaders($body),
            $body,
            $this->cloudConfig()
        );

        $this->assertSame(200, $result['status_code']);
        $this->assertTrue($result['body']['acks'][0]['acknowledged']);
        $this->assertFalse($result['body']['acks'][1]['acknowledged']);
        $this->assertSame('ack_applied', $this->fetchEvent((int) $event['id'])['status']);
        $this->assertSame('pending', $this->fetchEvent((int) $other['id'])['status']);
    }

    public function testBranchEventsRejectsBadSignature(): void
    {
        $query = [
            'branch_uuid' => self::BRANCH_UUID,
            'after_cursor' => '0',
            'limit' => '25',
        ];

        $result = (new CloudBranchSyncEventService())->handleBranchEvents(
            self::$conn,
            $this->signedHeaders('wrong-signature-body'),
            $query,
            $this->cloudConfig()
        );

        $this->assertSame(401, $result['status_code']);
        $this->assertSame('signature_mismatch', $result['body']['reason']);
    }

    private function insertEvent(string $branchUuid, string $entityType, string $eventType, string $status = 'pending'): array
    {
        $eventUuid = SyncBranchIdentity::generateUuidV4();
        $key = 'phpunit:cloud-sync:' . bin2hex(random_bytes(8));
        $payload = $entityType === 'table'
            ? ['table_uuid' => '11111111-1111-4111-8111-111111111111', 'local_table_id' => 123, 'table' => ['local_table_id' => 123, 'tname' => 'Cloud Table']]
            : ['item_uuid' => '22222222-2222-4222-8222-222222222222', 'local_item_id' => 456, 'menu_item' => ['local_item_id' => 456, 'item_name' => 'Cloud Item']];
        $payload['captured_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payloadJson);
        $aggregateUuid = $payload['table_uuid'] ?? $payload['item_uuid'];
        $localId = $payload['local_table_id'] ?? $payload['local_item_id'];
        $aggregateId = $entityType === 'table' ? 'tables:' . $localId : 'myitems:' . $localId;
        $sourceSystem = 'cloud_pos';

        $stmt = self::$conn->prepare("
            INSERT INTO cloud_sync_branch_events (
                event_uuid,
                branch_uuid,
                event_type,
                event_version,
                source_system,
                aggregate_type,
                aggregate_uuid,
                aggregate_local_id,
                aggregate_id,
                entity_type,
                entity_uuid,
                entity_local_id,
                idempotency_key,
                payload_hash,
                payload_json,
                status
            ) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            'ssssssisssissss',
            $eventUuid,
            $branchUuid,
            $eventType,
            $sourceSystem,
            $entityType,
            $aggregateUuid,
            $localId,
            $aggregateId,
            $entityType,
            $aggregateUuid,
            $localId,
            $key,
            $hash,
            $payloadJson,
            $status
        );
        $stmt->execute();
        $id = (int) self::$conn->insert_id;
        $stmt->close();

        return [
            'id' => $id,
            'event_uuid' => $eventUuid,
            'idempotency_key' => $key,
        ];
    }

    private function fetchEvent(int $id): array
    {
        $row = self::$conn->query('SELECT * FROM cloud_sync_branch_events WHERE id = ' . $id)->fetch_assoc();
        return $row ?: [];
    }

    private function registerCloudBranch(string $branchUuid): void
    {
        $hash = hash('sha256', self::SECRET);
        $name = 'PHPUnit Cloud Sync Branch';
        $stmt = self::$conn->prepare("
            INSERT INTO cloud_branches (branch_uuid, branch_name, status, sync_secret_hash)
            VALUES (?, ?, 'active', ?)
            ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name),
                                    status = 'active',
                                    sync_secret_hash = VALUES(sync_secret_hash)
        ");
        $stmt->bind_param('sss', $branchUuid, $name, $hash);
        $stmt->execute();
        $stmt->close();
    }

    private function signedHeaders(string $signatureBody): array
    {
        $timestamp = (string) time();
        $nonce = 'phpunit-' . bin2hex(random_bytes(4));

        return [
            'x-posmain-branch-uuid' => self::BRANCH_UUID,
            'x-posmain-timestamp' => $timestamp,
            'x-posmain-nonce' => $nonce,
            'x-posmain-signature' => CloudAuthService::sign(self::SECRET, $timestamp, $nonce, $signatureBody),
        ];
    }

    private function cloudConfig(): array
    {
        return posmain_app_config([
            'role' => 'cloud',
            'sync' => [
                'cloud_branch_secrets' => [self::BRANCH_UUID => self::SECRET],
            ],
        ]);
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM cloud_sync_branch_events WHERE idempotency_key LIKE 'phpunit:cloud-sync:%'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid IN ('" . self::BRANCH_UUID . "', '" . self::OTHER_BRANCH_UUID . "')");
    }
}

class cloud_branch_sync_event_service_test extends CloudBranchSyncEventServiceTest
{
}
