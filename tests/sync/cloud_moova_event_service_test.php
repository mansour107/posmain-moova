<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudMoovaEventService.php';
require_once __DIR__ . '/../../classes/Sync/MoovaBranchEventCursor.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class CloudMoovaEventServiceTest extends TestCase
{
    private const BRANCH_UUID = 'eeeeeeee-5555-4555-8555-eeeeeeeeeeee';
    private const OTHER_BRANCH_UUID = 'ffffffff-6666-4666-8666-ffffffffffff';
    private const SECRET = 'phpunit-cloud-moova-secret';

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

    public function testBranchEventsReturnsSignedPendingEventsAfterExclusiveCursor(): void
    {
        $first = $this->insertEvent(self::BRANCH_UUID, 'new_order');
        $second = $this->insertEvent(self::BRANCH_UUID, 'edit_order');
        $this->insertEvent(self::BRANCH_UUID, 'cancel_order', 'ack_applied');
        $this->insertEvent(self::OTHER_BRANCH_UUID, 'new_order');

        $query = [
            'branch_uuid' => self::BRANCH_UUID,
            'after_cursor' => (string) $first['id'],
            'limit' => '25',
        ];
        $signatureBody = CloudMoovaEventService::branchEventsSignatureBody(self::BRANCH_UUID, (int) $first['id'], 25);

        $result = (new CloudMoovaEventService())->handleBranchEvents(
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
        $this->assertSame('edit_order', $result['body']['events'][0]['event_type']);
        $this->assertSame('order-' . $second['id'], $result['body']['events'][0]['payload']['moova_order_id']);
        $this->assertSame('pending', $this->fetchEvent((int) $second['id'])['status']);
    }

    public function testBranchEventsRejectsBadSignature(): void
    {
        $query = [
            'branch_uuid' => self::BRANCH_UUID,
            'after_cursor' => '0',
            'limit' => '25',
        ];

        $result = (new CloudMoovaEventService())->handleBranchEvents(
            self::$conn,
            $this->signedHeaders('wrong-signature-body'),
            $query,
            $this->cloudConfig()
        );

        $this->assertSame(401, $result['status_code']);
        $this->assertSame('signature_mismatch', $result['body']['reason']);
    }

    public function testAckIsAuthenticatedAndScopedToBranch(): void
    {
        $event = $this->insertEvent(self::BRANCH_UUID, 'new_order');
        $other = $this->insertEvent(self::OTHER_BRANCH_UUID, 'new_order');

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

        $result = (new CloudMoovaEventService())->handleAck(
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

    public function testAckRejectsInvalidStatusWithoutUpdatingEvent(): void
    {
        $event = $this->insertEvent(self::BRANCH_UUID, 'new_order');
        $body = json_encode([
            'branch_uuid' => self::BRANCH_UUID,
            'acks' => [[
                'event_uuid' => $event['event_uuid'],
                'idempotency_key' => $event['idempotency_key'],
                'ack_status' => 'not_a_real_ack',
            ]],
        ], JSON_UNESCAPED_SLASHES);

        $result = (new CloudMoovaEventService())->handleAck(
            self::$conn,
            $this->signedHeaders($body),
            $body,
            $this->cloudConfig()
        );

        $this->assertSame(200, $result['status_code']);
        $this->assertFalse($result['body']['acks'][0]['acknowledged']);
        $this->assertSame('invalid', $result['body']['acks'][0]['status']);
        $this->assertSame('pending', $this->fetchEvent((int) $event['id'])['status']);
    }

    private function insertEvent(string $branchUuid, string $eventType, string $status = 'pending'): array
    {
        $eventUuid = $this->uuid();
        $key = 'phpunit:cloud-moova:' . bin2hex(random_bytes(8));
        $payload = json_encode([
            'moova_order_id' => 'pending',
            'event_type' => $eventType,
            'idempotency_key' => $key,
        ], JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payload);

        $stmt = self::$conn->prepare("
            INSERT INTO cloud_moova_branch_events (
                event_uuid,
                branch_uuid,
                moova_order_id,
                moova_branch_id,
                event_type,
                idempotency_key,
                payload_hash,
                payload_json,
                status
            ) VALUES (?, ?, 'pending', 'moova-branch-1', ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('sssssss', $eventUuid, $branchUuid, $eventType, $key, $hash, $payload, $status);
        $stmt->execute();
        $id = (int) self::$conn->insert_id;
        $stmt->close();

        $moovaOrderId = 'order-' . $id;
        $payload = json_encode([
            'moova_order_id' => $moovaOrderId,
            'event_type' => $eventType,
            'idempotency_key' => $key,
        ], JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payload);

        $stmt = self::$conn->prepare("
            UPDATE cloud_moova_branch_events
            SET moova_order_id = ?,
                payload_hash = ?,
                payload_json = ?
            WHERE id = ?
        ");
        $stmt->bind_param('sssi', $moovaOrderId, $hash, $payload, $id);
        $stmt->execute();
        $stmt->close();

        return [
            'id' => $id,
            'event_uuid' => $eventUuid,
            'idempotency_key' => $key,
            'moova_order_id' => $moovaOrderId,
        ];
    }

    private function fetchEvent(int $id): array
    {
        $row = self::$conn->query('SELECT * FROM cloud_moova_branch_events WHERE id = ' . $id)->fetch_assoc();
        return $row ?: [];
    }

    private function registerCloudBranch(string $branchUuid): void
    {
        $hash = hash('sha256', self::SECRET);
        $name = 'PHPUnit Cloud Moova Branch';
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
                'cloud_branch_secrets' => [
                    self::BRANCH_UUID => self::SECRET,
                    self::OTHER_BRANCH_UUID => self::SECRET,
                ],
            ],
        ]);
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM cloud_moova_branch_events WHERE idempotency_key LIKE 'phpunit:cloud-moova:%'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid IN ('" . self::BRANCH_UUID . "', '" . self::OTHER_BRANCH_UUID . "')");
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

class cloud_moova_event_service_test extends CloudMoovaEventServiceTest
{
}
