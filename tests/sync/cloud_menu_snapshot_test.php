<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudReceiveService.php';
require_once __DIR__ . '/../../classes/Sync/CloudMenuSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/CloudOrderSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/CloudShiftSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/CloudTableSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';

class CloudMenuSnapshotTest extends TestCase
{
    private const BRANCH_UUID = '89898989-aaaa-4aaa-8aaa-898989898989';
    private const ITEM_UUID = '90909090-aaaa-4aaa-8aaa-909090909090';
    private const SECRET = 'phpunit-cloud-menu-secret';

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

    public function testReceiveOnlyStoresInboxWithoutCloudMenuSnapshot(): void
    {
        $event = $this->event('receive-only', [
            'price' => 18,
            'menu_version' => 3,
        ]);

        $result = $this->postEvent($event, false, false);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('receive_only', $result['body']['mode']);
        $this->assertSame('accepted_shadow', $result['body']['results'][0]['status']);
        $this->assertFalse($result['body']['results'][0]['applied']);
        $this->assertFalse($result['body']['results'][0]['report_trusted']);
        $this->assertNull($this->fetchCloudMenuItem());
        $this->assertSame('received', $this->fetchInbox($event['idempotency_key'])['status']);
    }

    public function testShadowModeAppliesMenuSnapshotButKeepsReportsUntrusted(): void
    {
        $event = $this->event('shadow', [
            'price' => 18,
            'cost' => 8,
            'available_online' => true,
            'menu_version' => 4,
        ]);

        $result = $this->postEvent($event, true, true);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('shadow_apply', $result['body']['mode']);
        $this->assertSame('accepted_shadow', $result['body']['results'][0]['status']);
        $this->assertTrue($result['body']['results'][0]['applied']);
        $this->assertFalse($result['body']['results'][0]['report_trusted']);
        $this->assertStringStartsWith('cloud_menu_item:', (string) $result['body']['results'][0]['cloud_entity_id']);

        $item = $this->fetchCloudMenuItem();
        $this->assertSame(self::ITEM_UUID, $item['item_uuid']);
        $this->assertSame(101, (int) $item['local_item_id']);
        $this->assertSame('moova-tea-101', $item['external_item_id']);
        $this->assertSame('TEMPTEA', $item['barcode']);
        $this->assertSame('Temp Tea', $item['item_name']);
        $this->assertSame(7, (int) $item['category_id']);
        $this->assertSame('18.0000', $item['price']);
        $this->assertSame('8.0000', $item['cost']);
        $this->assertSame(1, (int) $item['available_online']);
        $this->assertSame(0, (int) $item['isdeleted']);
        $this->assertSame(4, (int) $item['menu_version']);
        $this->assertSame('processed', $this->fetchInbox($event['idempotency_key'])['status']);
    }

    public function testLiveApplyUpdatesExistingMenuSnapshotByBranchAndItemUuid(): void
    {
        $first = $this->event('live-first', [
            'price' => 18,
            'menu_version' => 1,
        ]);
        $second = $this->event('live-second', [
            'item_name' => 'Temp Tea Hidden',
            'price' => 20,
            'cost' => 9,
            'available_online' => false,
            'isdeleted' => true,
            'menu_version' => 2,
        ]);

        $this->postEvent($first, true, false);
        $result = $this->postEvent($second, true, false);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('live_apply', $result['body']['mode']);
        $this->assertSame('processed', $result['body']['results'][0]['status']);
        $this->assertTrue($result['body']['results'][0]['applied']);
        $this->assertTrue($result['body']['results'][0]['report_trusted']);
        $this->assertSame(1, $this->cloudMenuItemCount());

        $item = $this->fetchCloudMenuItem();
        $this->assertSame('Temp Tea Hidden', $item['item_name']);
        $this->assertSame('20.0000', $item['price']);
        $this->assertSame('9.0000', $item['cost']);
        $this->assertSame(0, (int) $item['available_online']);
        $this->assertSame(1, (int) $item['isdeleted']);
        $this->assertSame(2, (int) $item['menu_version']);
    }

    private function event(string $suffix, array $overrides): array
    {
        $item = array_merge([
            'item_uuid' => self::ITEM_UUID,
            'local_item_id' => 101,
            'external_item_id' => 'moova-tea-101',
            'barcode' => 'TEMPTEA',
            'item_name' => 'Temp Tea',
            'category_id' => 7,
            'price' => null,
            'cost' => null,
            'available_online' => true,
            'isdeleted' => false,
            'menu_version' => 1,
        ], $overrides);
        $payload = ['menu_item' => $item];

        return [
            'event_uuid' => SyncBranchIdentity::generateUuidV4(),
            'idempotency_key' => 'phpunit:cloud-menu:' . $suffix,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'event_type' => 'menu.item_saved',
            'event_version' => (int) $item['menu_version'],
            'source_system' => 'pos',
            'aggregate_type' => 'menu_item',
            'aggregate_uuid' => self::ITEM_UUID,
            'entity_type' => 'menu_item',
            'entity_uuid' => self::ITEM_UUID,
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
        $name = 'PHPUnit Cloud Menu Branch';
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

    private function fetchCloudMenuItem(): ?array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM cloud_menu_items
            WHERE branch_uuid = ?
              AND item_uuid = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $itemUuid = self::ITEM_UUID;
        $stmt->bind_param('ss', $branchUuid, $itemUuid);
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

    private function cloudMenuItemCount(): int
    {
        $stmt = self::$conn->prepare("
            SELECT COUNT(*) AS c
            FROM cloud_menu_items
            WHERE branch_uuid = ?
              AND item_uuid = ?
        ");
        $branchUuid = self::BRANCH_UUID;
        $itemUuid = self::ITEM_UUID;
        $stmt->bind_param('ss', $branchUuid, $itemUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0);
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM cloud_menu_items WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM sync_projection_versions WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM sync_inbox WHERE idempotency_key LIKE 'phpunit:cloud-menu:%'");
        self::$conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
    }
}

class cloud_menu_snapshot_test extends CloudMenuSnapshotTest
{
}
