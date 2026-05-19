<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/CloudBranchSyncPublisher.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class CloudBranchSyncPublisherTest extends TestCase
{
    private const ACTIVE_BRANCH_UUID = 'abababab-1111-4111-8111-abababababab';
    private const STALE_CONFIG_BRANCH_UUID = 'bcbcbcbc-2222-4222-8222-bcbcbcbcbcbc';
    private const LOCAL_ITEM_ID = 98765;

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
        $this->registerCloudBranch(self::ACTIVE_BRANCH_UUID);
    }

    protected function tearDown(): void
    {
        if (self::$conn) {
            $this->cleanup();
        }
    }

    public function testConfiguredCloudUuidIsIgnoredWhenItIsNotAnActiveBranch(): void
    {
        $payload = [
            'local_item_id' => self::LOCAL_ITEM_ID,
            'menu_item' => [
                'local_item_id' => self::LOCAL_ITEM_ID,
                'item_name' => 'PHPUnit Cloud Publisher Item',
                'price1' => 12.5,
                'mdtime' => '2026-05-19 17:20:00',
            ],
            'captured_at_utc' => '2026-05-19T17:20:00Z',
        ];

        $published = (new CloudBranchSyncPublisher())->publish(self::$conn, [
            'event_type' => 'menu.item_saved',
            'event_version' => 1,
            'source_system' => 'cloud_pos',
            'aggregate_type' => 'menu_item',
            'aggregate_local_id' => self::LOCAL_ITEM_ID,
            'aggregate_id' => 'myitems:' . self::LOCAL_ITEM_ID,
            'entity_type' => 'menu_item',
            'entity_local_id' => self::LOCAL_ITEM_ID,
            'payload' => $payload,
        ], $this->cloudConfig());

        $publishedBranches = array_column($published, 'branch_uuid');
        $this->assertContains(self::ACTIVE_BRANCH_UUID, $publishedBranches);
        $this->assertNotContains(self::STALE_CONFIG_BRANCH_UUID, $publishedBranches);

        $rows = self::$conn->query("
            SELECT branch_uuid, status
            FROM cloud_sync_branch_events
            WHERE entity_type = 'menu_item'
              AND entity_local_id = " . self::LOCAL_ITEM_ID . "
        ");
        $eventBranches = [];
        while ($row = $rows->fetch_assoc()) {
            $eventBranches[$row['branch_uuid']] = $row['status'];
        }

        $this->assertArrayHasKey(self::ACTIVE_BRANCH_UUID, $eventBranches);
        $this->assertArrayNotHasKey(self::STALE_CONFIG_BRANCH_UUID, $eventBranches);
        $this->assertSame('pending', $eventBranches[self::ACTIVE_BRANCH_UUID]);
    }

    private function cloudConfig(): array
    {
        return posmain_app_config([
            'role' => 'cloud',
            'branch' => [
                'uuid' => self::STALE_CONFIG_BRANCH_UUID,
            ],
            'sync' => [
                'cloud_to_branch_publish_enabled' => true,
            ],
        ]);
    }

    private function registerCloudBranch(string $branchUuid): void
    {
        $hash = hash('sha256', 'phpunit-cloud-sync-secret');
        $name = 'PHPUnit Publisher Branch';
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

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM cloud_sync_branch_events WHERE entity_type = 'menu_item' AND entity_local_id = " . self::LOCAL_ITEM_ID);
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid IN ('" . self::ACTIVE_BRANCH_UUID . "', '" . self::STALE_CONFIG_BRANCH_UUID . "')");
    }
}

class cloud_branch_sync_publisher_test extends CloudBranchSyncPublisherTest
{
}
