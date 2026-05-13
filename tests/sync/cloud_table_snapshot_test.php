<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudReceiveService.php';
require_once __DIR__ . '/../../classes/Sync/CloudOrderSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/CloudTableSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';

class CloudTableSnapshotTest extends TestCase
{
    private const BRANCH_UUID = '12121212-8888-4888-8888-121212121212';
    private const TABLE_UUID = '34343434-8888-4888-8888-343434343434';
    private const ACTIVE_ORDER_UUID = '56565656-8888-4888-8888-565656565656';
    private const SECRET = 'phpunit-cloud-table-secret';

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

    public function testReceiveOnlyStoresInboxWithoutCloudTableSnapshot(): void
    {
        $event = $this->event('receive-only', [
            'table_case' => 1,
            'active_order_uuid' => self::ACTIVE_ORDER_UUID,
        ]);

        $result = $this->postEvent($event, false, false);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('receive_only', $result['body']['mode']);
        $this->assertSame('accepted_shadow', $result['body']['results'][0]['status']);
        $this->assertFalse($result['body']['results'][0]['applied']);
        $this->assertFalse($result['body']['results'][0]['report_trusted']);
        $this->assertNull($this->fetchCloudTable());
        $this->assertSame('received', $this->fetchInbox($event['idempotency_key'])['status']);
    }

    public function testShadowModeAppliesTableSnapshotButKeepsReportsUntrusted(): void
    {
        $event = $this->event('shadow', [
            'table_case' => 1,
            'active_order_uuid' => self::ACTIVE_ORDER_UUID,
            'sync_revision' => 4,
        ]);

        $result = $this->postEvent($event, true, true);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('shadow_apply', $result['body']['mode']);
        $this->assertSame('accepted_shadow', $result['body']['results'][0]['status']);
        $this->assertTrue($result['body']['results'][0]['applied']);
        $this->assertFalse($result['body']['results'][0]['report_trusted']);
        $this->assertStringStartsWith('cloud_table:', (string) $result['body']['results'][0]['cloud_entity_id']);

        $table = $this->fetchCloudTable();
        $this->assertSame(self::TABLE_UUID, $table['table_uuid']);
        $this->assertSame(44, (int) $table['local_table_id']);
        $this->assertSame('Patio 4', $table['tname']);
        $this->assertSame(1, (int) $table['table_case']);
        $this->assertSame(0, (int) $table['isdeleted']);
        $this->assertSame(self::ACTIVE_ORDER_UUID, $table['active_order_uuid']);
        $this->assertSame(4, (int) $table['sync_revision']);
        $this->assertSame('processed', $this->fetchInbox($event['idempotency_key'])['status']);
    }

    public function testLiveApplyUpdatesExistingTableSnapshotByBranchAndTableUuid(): void
    {
        $first = $this->event('live-first', [
            'table_case' => 1,
            'active_order_uuid' => self::ACTIVE_ORDER_UUID,
            'sync_revision' => 1,
        ]);
        $second = $this->event('live-second', [
            'tname' => 'Patio 4 Closed',
            'table_case' => 0,
            'active_order_uuid' => null,
            'isdeleted' => true,
            'sync_revision' => 2,
        ]);

        $this->postEvent($first, true, false);
        $result = $this->postEvent($second, true, false);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('live_apply', $result['body']['mode']);
        $this->assertSame('processed', $result['body']['results'][0]['status']);
        $this->assertTrue($result['body']['results'][0]['applied']);
        $this->assertTrue($result['body']['results'][0]['report_trusted']);
        $this->assertSame(1, $this->cloudTableCount());

        $table = $this->fetchCloudTable();
        $this->assertSame('Patio 4 Closed', $table['tname']);
        $this->assertSame(0, (int) $table['table_case']);
        $this->assertSame(1, (int) $table['isdeleted']);
        $this->assertNull($table['active_order_uuid']);
        $this->assertSame(2, (int) $table['sync_revision']);
    }

    private function event(string $suffix, array $overrides): array
    {
        $table = array_merge([
            'table_uuid' => self::TABLE_UUID,
            'local_table_id' => 44,
            'tname' => 'Patio 4',
            'table_case' => 0,
            'isdeleted' => false,
            'active_order_uuid' => null,
            'sync_revision' => 1,
        ], $overrides);
        $payload = ['table' => $table];

        return [
            'event_uuid' => SyncBranchIdentity::generateUuidV4(),
            'idempotency_key' => 'phpunit:cloud-table:' . $suffix,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'event_type' => 'table.saved',
            'event_version' => (int) $table['sync_revision'],
            'source_system' => 'pos',
            'aggregate_type' => 'table',
            'aggregate_uuid' => self::TABLE_UUID,
            'entity_type' => 'table',
            'entity_uuid' => self::TABLE_UUID,
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
        $name = 'PHPUnit Cloud Table Branch';
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

    private function fetchCloudTable(): ?array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM cloud_tables
            WHERE branch_uuid = ?
              AND table_uuid = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $tableUuid = self::TABLE_UUID;
        $stmt->bind_param('ss', $branchUuid, $tableUuid);
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

    private function cloudTableCount(): int
    {
        $stmt = self::$conn->prepare("
            SELECT COUNT(*) AS c
            FROM cloud_tables
            WHERE branch_uuid = ?
              AND table_uuid = ?
        ");
        $branchUuid = self::BRANCH_UUID;
        $tableUuid = self::TABLE_UUID;
        $stmt->bind_param('ss', $branchUuid, $tableUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0);
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM cloud_tables WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM sync_inbox WHERE idempotency_key LIKE 'phpunit:cloud-table:%'");
        self::$conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
    }
}

class cloud_table_snapshot_test extends CloudTableSnapshotTest
{
}
