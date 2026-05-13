<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudMoovaEventService.php';
require_once __DIR__ . '/../../classes/Sync/MoovaInboundQueueService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/BranchMoovaAckWorker.php';

class BranchMoovaAckWorkerTest extends TestCase
{
    private const BRANCH_UUID = 'dadadada-eeee-4eee-8eee-dadadadadada';
    private const SECRET = 'phpunit-moova-ack-secret';

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
    }

    protected function setUp(): void
    {
        if (!self::$conn) {
            $this->markTestSkipped('MySQL test database is not available.');
        }

        $this->originalIdentity = (new SyncBranchIdentity())->find(self::$conn);
        $this->cleanup();
        $this->registerCloudBranch();
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

    public function testAckWorkerPostsAppliedResultAndMarksLocalRowAcked(): void
    {
        $event = $this->insertCloudAndLocalTerminalEvent('applied', 'new_order', 'ack-applied-1');

        $metrics = (new BranchMoovaAckWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'http_post' => $this->cloudHttpPost(),
        ]);

        $this->assertSame(1, $metrics['candidates']);
        $this->assertSame(1, $metrics['posted']);
        $this->assertSame(1, $metrics['acked']);
        $this->assertSame(0, $metrics['failed']);
        $this->assertSame('ack_applied', $this->fetchCloudEvent((int) $event['cloud_id'])['status']);

        $local = $this->fetchLocalInbound((int) $event['local_id']);
        $this->assertSame('ack_applied', $local['cloud_ack_status']);
        $this->assertSame(1, (int) $local['cloud_ack_attempt_count']);
        $this->assertNotNull($local['cloud_acknowledged_at']);

        $second = (new BranchMoovaAckWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'http_post' => $this->cloudHttpPost(),
        ]);
        $this->assertSame(0, $second['candidates']);
    }

    public function testAckWorkerKeepsTerminalRowRetryableWhenCloudIsUnreachable(): void
    {
        $event = $this->insertCloudAndLocalTerminalEvent('declined', 'cancel_order', 'ack-declined-1');

        $metrics = (new BranchMoovaAckWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'http_post' => fn (): array => [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'json' => null,
                'error' => 'moova ack cloud unreachable',
            ],
        ]);

        $this->assertSame(1, $metrics['candidates']);
        $this->assertSame(1, $metrics['failed']);
        $this->assertSame('pending', $this->fetchCloudEvent((int) $event['cloud_id'])['status']);

        $local = $this->fetchLocalInbound((int) $event['local_id']);
        $this->assertSame('failed', $local['cloud_ack_status']);
        $this->assertSame(1, (int) $local['cloud_ack_attempt_count']);
        $this->assertSame('moova ack cloud unreachable', $local['cloud_ack_error']);

        $retry = (new BranchMoovaAckWorker())->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'http_post' => $this->cloudHttpPost(),
        ]);
        $this->assertSame(1, $retry['acked']);
        $this->assertSame('ack_declined', $this->fetchCloudEvent((int) $event['cloud_id'])['status']);
    }

    private function insertCloudAndLocalTerminalEvent(string $localStatus, string $eventType, string $suffix): array
    {
        $eventUuid = SyncBranchIdentity::generateUuidV4();
        $idempotencyKey = 'phpunit:moova-ack:' . $suffix;
        $payload = [
            'moova_order_id' => 'MOOVA-ACK-' . $suffix,
            'moova_branch_id' => 'ack-branch',
            'items' => [['name' => 'Temp Tea', 'qty' => 1]],
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', (string) $payloadJson);
        $branchUuid = self::BRANCH_UUID;
        $moovaOrderId = $payload['moova_order_id'];
        $moovaBranchId = $payload['moova_branch_id'];

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
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param(
            'ssssssss',
            $eventUuid,
            $branchUuid,
            $moovaOrderId,
            $moovaBranchId,
            $eventType,
            $idempotencyKey,
            $payloadHash,
            $payloadJson
        );
        $stmt->execute();
        $cloudId = (int) self::$conn->insert_id;
        $stmt->close();

        $resultJson = json_encode(['providerStatus' => $localStatus], JSON_UNESCAPED_SLASHES);
        $error = $localStatus === 'failed' ? 'local apply failed' : null;
        $stmt = self::$conn->prepare("
            INSERT INTO moova_pos_inbound_events (
                event_uuid,
                moova_order_id,
                moova_branch_id,
                pos_tenant,
                pos_branch,
                branch_uuid,
                idempotency_key,
                request_hash,
                event_type,
                delivery_path,
                payload_json,
                status,
                result_json,
                error_message,
                applied_at
            ) VALUES (?, ?, ?, 31, 32, ?, ?, ?, ?, 'poller', ?, ?, ?, ?, NOW(6))
        ");
        $stmt->bind_param(
            'sssssssssss',
            $eventUuid,
            $moovaOrderId,
            $moovaBranchId,
            $branchUuid,
            $idempotencyKey,
            $payloadHash,
            $eventType,
            $payloadJson,
            $localStatus,
            $resultJson,
            $error
        );
        $stmt->execute();
        $localId = (int) self::$conn->insert_id;
        $stmt->close();

        return ['cloud_id' => $cloudId, 'local_id' => $localId];
    }

    private function cloudHttpPost(): callable
    {
        return function (string $url, string $body, array $headers): array {
            $this->assertStringContainsString('/api/moova/ack_branch_events.php', $url);
            $result = (new CloudMoovaEventService())->handleAck(
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
        };
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

    private function branchConfig(): array
    {
        return posmain_app_config([
            'role' => 'branch',
            'branch' => [
                'uuid' => self::BRANCH_UUID,
                'name' => 'PHPUnit Moova Ack Branch',
                'pos_tenant' => 31,
                'pos_branch' => 32,
                'cloud_base_url' => 'http://fake-cloud.local',
            ],
            'sync' => [
                'branch_secret' => self::SECRET,
                'moova_poller_enabled' => true,
            ],
        ]);
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

    private function registerCloudBranch(): void
    {
        $hash = hash('sha256', self::SECRET);
        $name = 'PHPUnit Moova Ack Cloud Branch';
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

    private function fetchCloudEvent(int $id): ?array
    {
        $stmt = self::$conn->prepare("SELECT * FROM cloud_moova_branch_events WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function fetchLocalInbound(int $id): ?array
    {
        $stmt = self::$conn->prepare("SELECT * FROM moova_pos_inbound_events WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM moova_pos_inbound_events WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM cloud_moova_branch_events WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query('DELETE FROM sync_branch_identity WHERE id = 1');
    }
}

class branch_moova_ack_worker_test extends BranchMoovaAckWorkerTest
{
}
