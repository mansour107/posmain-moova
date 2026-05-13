<?php

use PHPUnit\Framework\TestCase;

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

class BranchCloudRuntimeTest extends TestCase
{
    private const BRANCH_UUID = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
    private const SECRET = 'phpunit-runtime-secret';

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

    public function testCloudReceiveValidatesActiveBranchAndStoresInbox(): void
    {
        $event = $this->event('receive-only');
        $body = json_encode([
            'schema_version' => 1,
            'branch_uuid' => self::BRANCH_UUID,
            'events' => [$event],
        ], JSON_UNESCAPED_SLASHES);

        $result = (new CloudReceiveService())->handle(
            self::$conn,
            $this->signedHeaders($body),
            $body,
            $this->cloudConfig(false, false)
        );

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('receive_only', $result['body']['mode']);
        $this->assertSame('accepted_shadow', $result['body']['results'][0]['status']);
        $this->assertFalse($result['body']['results'][0]['applied']);

        $row = $this->fetchInbox($event['idempotency_key']);
        $this->assertSame('received', $row['status']);
        $this->assertSame($event['payload_hash'], $row['payload_hash']);
    }

    public function testCloudReceiveDetectsDuplicateHashConflict(): void
    {
        $event = $this->event('conflict');
        $body = json_encode(['events' => [$event]], JSON_UNESCAPED_SLASHES);
        (new CloudReceiveService())->handle(self::$conn, $this->signedHeaders($body), $body, $this->cloudConfig(true, true));

        $changed = $event;
        $changed['payload'] = ['scenario' => 'conflict', 'amount' => 99];
        $changed['payload_hash'] = hash('sha256', json_encode($changed['payload'], JSON_UNESCAPED_SLASHES));
        $changedBody = json_encode(['events' => [$changed]], JSON_UNESCAPED_SLASHES);

        $result = (new CloudReceiveService())->handle(
            self::$conn,
            $this->signedHeaders($changedBody),
            $changedBody,
            $this->cloudConfig(true, true)
        );

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('conflict', $result['body']['results'][0]['status']);

        $conflicts = self::$conn->query("
            SELECT COUNT(*) AS c
            FROM sync_conflicts
            WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'
              AND conflict_type = 'idempotency_hash_mismatch'
        ")->fetch_assoc();
        $this->assertSame(1, (int) $conflicts['c']);
    }

    public function testBranchWorkerPostsClaimedOutboxRowsToCloudReceiveService(): void
    {
        $event = $this->insertOutbox('worker-success');
        $worker = new BranchSyncWorker();

        $metrics = $worker->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'worker_id' => 'phpunit-runtime-worker',
            'http_post' => function (string $url, string $body, array $headers): array {
                $this->assertStringContainsString('/api/sync/receive_branch_events.php', $url);
                $result = (new CloudReceiveService())->handle(
                    self::$conn,
                    $this->headerLinesToMap($headers),
                    $body,
                    $this->cloudConfig(true, true)
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
        $this->assertSame(0, $metrics['failed']);

        $outbox = $this->fetchOutbox($event['id']);
        $this->assertSame('synced', $outbox['status']);
        $this->assertNull($outbox['locked_by']);
        $this->assertNotNull($this->fetchInbox($event['idempotency_key']));
    }

    public function testBranchWorkerKeepsNetworkFailuresRetryable(): void
    {
        $event = $this->insertOutbox('cloud-down');
        $worker = new BranchSyncWorker();

        $metrics = $worker->runOnce(self::$conn, $this->branchConfig(), [
            'batch_size' => 10,
            'worker_id' => 'phpunit-runtime-worker-down',
            'http_post' => fn (): array => [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'json' => null,
                'error' => 'cloud unreachable',
            ],
        ]);

        $this->assertSame(1, $metrics['claimed']);
        $this->assertSame(1, $metrics['failed']);

        $outbox = $this->fetchOutbox($event['id']);
        $this->assertSame('failed', $outbox['status']);
        $this->assertSame('cloud unreachable', $outbox['last_error']);
        $this->assertNotNull($outbox['next_retry_at']);
        $retryWindow = self::$conn->query("
            SELECT TIMESTAMPDIFF(SECOND, NOW(6), next_retry_at) AS retry_seconds
            FROM sync_outbox
            WHERE id = " . (int) $event['id'] . "
        ")->fetch_assoc();
        $this->assertGreaterThanOrEqual(0, (int) $retryWindow['retry_seconds']);
        $this->assertLessThanOrEqual(15, (int) $retryWindow['retry_seconds']);
    }

    private function event(string $suffix): array
    {
        $payload = ['scenario' => $suffix, 'amount' => 12.34];

        return [
            'event_uuid' => $this->uuid(),
            'idempotency_key' => 'phpunit:runtime:' . $suffix,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'event_type' => 'order.saved',
            'event_version' => 1,
            'source_system' => 'pos',
            'aggregate_type' => 'order',
            'aggregate_uuid' => $this->uuid(),
            'entity_type' => 'order',
            'entity_uuid' => $this->uuid(),
            'payload' => $payload,
        ];
    }

    private function insertOutbox(string $suffix): array
    {
        $event = $this->event($suffix);
        $payloadJson = json_encode($event['payload'], JSON_UNESCAPED_SLASHES);

        $stmt = self::$conn->prepare("
            INSERT INTO sync_outbox (
                event_uuid,
                branch_uuid,
                pos_tenant,
                pos_branch,
                aggregate_type,
                aggregate_uuid,
                aggregate_local_id,
                aggregate_id,
                entity_type,
                entity_uuid,
                entity_local_id,
                event_type,
                event_version,
                source_system,
                source_event_uuid,
                idempotency_key,
                payload_json,
                payload_hash,
                status,
                attempts
            ) VALUES (?, ?, 0, 0, ?, ?, 1, ?, ?, ?, 1, ?, 1, 'pos', NULL, ?, ?, ?, 'pending', 0)
        ");
        $eventUuid = $event['event_uuid'];
        $branchUuid = self::BRANCH_UUID;
        $aggregateType = $event['aggregate_type'];
        $aggregateUuid = $event['aggregate_uuid'];
        $aggregateId = 'order:' . $event['idempotency_key'];
        $entityType = $event['entity_type'];
        $entityUuid = $event['entity_uuid'];
        $eventType = $event['event_type'];
        $idempotencyKey = $event['idempotency_key'];
        $payloadHash = $event['payload_hash'];
        $stmt->bind_param(
            'sssssssssss',
            $eventUuid,
            $branchUuid,
            $aggregateType,
            $aggregateUuid,
            $aggregateId,
            $entityType,
            $entityUuid,
            $eventType,
            $idempotencyKey,
            $payloadJson,
            $payloadHash
        );
        $stmt->execute();
        $event['id'] = (int) self::$conn->insert_id;
        $stmt->close();

        return $event;
    }

    private function branchConfig(): array
    {
        return posmain_app_config([
            'role' => 'branch',
            'branch' => [
                'uuid' => self::BRANCH_UUID,
                'name' => 'PHPUnit Branch',
                'cloud_base_url' => 'http://cloud-runtime.test',
            ],
            'sync' => [
                'branch_secret' => self::SECRET,
                'branch_sync_enabled' => true,
                'worker_enabled' => true,
            ],
        ]);
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

    private function registerCloudBranch(): void
    {
        $hash = hash('sha256', self::SECRET);
        $name = 'PHPUnit Branch';
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

    private function fetchOutbox(int $id): array
    {
        return self::$conn->query('SELECT * FROM sync_outbox WHERE id = ' . $id)->fetch_assoc();
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM cloud_orders WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM sync_inbox WHERE idempotency_key LIKE 'phpunit:runtime:%'");
        self::$conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM sync_outbox WHERE idempotency_key LIKE 'phpunit:runtime:%'");
        self::$conn->query("DELETE FROM sync_worker_logs WHERE worker_name = 'sync_worker' AND metrics_json LIKE '%phpunit-runtime%'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query('DELETE FROM sync_branch_identity WHERE id = 1');
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function uuid(): string
    {
        return SyncBranchIdentity::generateUuidV4();
    }
}

class branch_cloud_runtime_test extends BranchCloudRuntimeTest
{
}
