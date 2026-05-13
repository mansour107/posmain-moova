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
require_once __DIR__ . '/../../classes/Sync/BranchMoovaPollWorker.php';

class BranchMoovaPollWorkerTest extends TestCase
{
    private const BRANCH_UUID = 'babababa-cccc-4ccc-8ccc-babababababa';
    private const EVENT_UUID = 'cacacaca-cccc-4ccc-8ccc-cacacacacaca';
    private const SECRET = 'phpunit-moova-poller-secret';

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

    public function testPollerFetchesCloudMoovaEventIntoLocalInboundQueueWithoutApplyingIt(): void
    {
        $cursor = $this->insertCloudMoovaEvent('new_order');
        $worker = new BranchMoovaPollWorker();

        $metrics = $worker->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'http_get' => $this->cloudHttpGet(),
        ]);

        $this->assertSame(1, $metrics['fetched']);
        $this->assertSame(1, $metrics['recorded']);
        $this->assertSame(0, $metrics['failed']);
        $this->assertSame(1, $metrics['ack_deferred']);
        $this->assertSame($cursor, $metrics['checkpoint']);

        $inbound = $this->fetchInbound();
        $this->assertSame('received', $inbound['status']);
        $this->assertSame('poller', $inbound['delivery_path']);
        $this->assertSame('phpunit:moova-poller:new_order', $inbound['idempotency_key']);
        $this->assertNotSame(hash('sha256', (string) $inbound['payload_json']), $inbound['request_hash']);
        $this->assertSame(11, (int) $inbound['pos_tenant']);
        $this->assertSame(22, (int) $inbound['pos_branch']);
        $this->assertNull($inbound['pos_order_id']);
        $this->assertSame((string) $cursor, $this->fetchCheckpoint());
        $this->assertSame('pending', $this->fetchCloudEventStatus());

        $second = $worker->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'http_get' => $this->cloudHttpGet(),
        ]);
        $this->assertSame(0, $second['fetched']);
        $this->assertSame(1, $this->inboundCount());
    }

    public function testPollerDoesNotAdvanceCheckpointWhenCloudIsUnreachable(): void
    {
        $this->insertCloudMoovaEvent('cloud_down');
        $worker = new BranchMoovaPollWorker();

        $metrics = $worker->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'http_get' => fn (): array => [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'json' => null,
                'error' => 'moova cloud unreachable',
            ],
        ]);

        $this->assertSame(1, $metrics['failed']);
        $this->assertSame(0, $this->inboundCount());
        $this->assertNull($this->fetchCheckpoint());
    }

    private function insertCloudMoovaEvent(string $suffix): int
    {
        $payload = [
            'moova_order_id' => 'MOOVA-' . $suffix,
            'moova_branch_id' => 'branch-a',
            'items' => [['name' => 'Temp Tea', 'qty' => 1]],
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $eventType = 'new_order';
        $eventUuid = $suffix === 'new_order' ? self::EVENT_UUID : SyncBranchIdentity::generateUuidV4();
        $idempotencyKey = 'phpunit:moova-poller:' . $suffix;
        $payloadHash = hash('sha256', $payloadJson);
        $moovaOrderId = $payload['moova_order_id'];
        $moovaBranchId = $payload['moova_branch_id'];
        $branchUuid = self::BRANCH_UUID;

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
        $cursor = (int) self::$conn->insert_id;
        $stmt->close();

        return $cursor;
    }

    private function cloudHttpGet(): callable
    {
        return function (string $url, array $headers): array {
            $this->assertStringContainsString('/api/moova/branch_events.php', $url);
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $result = (new CloudMoovaEventService())->handleBranchEvents(
                self::$conn,
                $this->headerLinesToMap($headers),
                $query,
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
                'name' => 'PHPUnit Moova Poller Branch',
                'pos_tenant' => 11,
                'pos_branch' => 22,
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
        $name = 'PHPUnit Moova Poller Cloud Branch';
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

    private function fetchInbound(): ?array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM moova_pos_inbound_events
            WHERE branch_uuid = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function inboundCount(): int
    {
        $stmt = self::$conn->prepare("
            SELECT COUNT(*) AS c
            FROM moova_pos_inbound_events
            WHERE branch_uuid = ?
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0);
    }

    private function fetchCheckpoint(): ?string
    {
        $stmt = self::$conn->prepare("
            SELECT last_cursor
            FROM sync_checkpoints
            WHERE branch_uuid = ?
              AND stream_name = 'moova_orders'
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (string) $row['last_cursor'] : null;
    }

    private function fetchCloudEventStatus(): ?string
    {
        $stmt = self::$conn->prepare("
            SELECT status
            FROM cloud_moova_branch_events
            WHERE branch_uuid = ?
              AND idempotency_key LIKE 'phpunit:moova-poller:%'
            ORDER BY id ASC
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (string) $row['status'] : null;
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
        self::$conn->query("DELETE FROM sync_checkpoints WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM moova_pos_inbound_events WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM cloud_moova_branch_events WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query('DELETE FROM sync_branch_identity WHERE id = 1');
    }
}

class branch_moova_poll_worker_test extends BranchMoovaPollWorkerTest
{
}
