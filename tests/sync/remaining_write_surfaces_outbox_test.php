<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudReceiveService.php';
require_once __DIR__ . '/../../classes/Sync/CloudOrderSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/CloudTableSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';
require_once __DIR__ . '/../../classes/Sync/SyncOutboxEventService.php';

class RemainingWriteSurfacesOutboxTest extends TestCase
{
    private const BRANCH_UUID = 'dddddddd-4444-4444-8444-dddddddddddd';
    private const SECRET = 'phpunit-remaining-surfaces-secret';

    private static $conn;
    private $tableId;
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

    public function testRemainingWriteSurfacesCallDurableOutboxProducer(): void
    {
        $expectations = [
            'ajax/save_order.php' => ['recordOrderSnapshot', 'recordTableSnapshot', 'pos_table'],
            'ajax/process_table_payment.php' => ['recordOrderSnapshot', 'recordTableSnapshot', 'order.payment_recorded'],
            'ajax/process_split_payment.php' => ['recordOrderSnapshot', 'recordTableSnapshot', 'order.split_paid'],
            'ajax/delete_order.php' => ['recordOrderSnapshot', 'recordTableSnapshot', 'order.cancelled'],
            'ajax/clear_table.php' => ['recordOrderSnapshot', 'recordTableSnapshot', 'order.cancelled'],
            'ajax/clear_table_normal.php' => ['recordOrderSnapshot', 'recordTableSnapshot', 'table.updated'],
            'ajax/update_table_status.php' => ['recordOrderSnapshot', 'recordTableSnapshot', 'order.table_status_updated'],
            'ajax/get_tables.php' => ['recordTableSnapshot', 'pos_table_refresh'],
            'ajax/cofe_create_order.php' => ['recordOrderSnapshot', 'recordTableSnapshot', 'cofe_widget'],
            'do/doadd_invoice.php' => ['recordOrderSnapshot', 'recordTableSnapshot', 'pos_cashier'],
            'classes/Moova/MoovaNewOrderApplyService.php' => ['SyncOutboxEventService', 'recordOrderSnapshot', 'recordTableSnapshot'],
            'classes/Moova/MoovaChangeOrderApplyService.php' => ['SyncOutboxEventService', 'recordOrderSnapshot', 'recordTableSnapshot'],
        ];

        foreach ($expectations as $path => $needles) {
            $source = $this->source($path);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $source, $path . ' should contain ' . $needle);
            }
        }
    }

    public function testTableSnapshotOutboxEventAppliesToCloudTables(): void
    {
        $this->seedTable();

        $result = (new SyncOutboxEventService())->recordTableSnapshot(self::$conn, $this->tableId, [
            'event_type' => 'table.updated',
            'source_system' => 'pos_table_status',
            'active_order_id' => null,
            'config' => $this->branchConfig(),
        ]);

        $this->assertIsArray($result);
        $outbox = $this->fetchOutbox((int) $result['outbox_id']);
        $payload = json_decode($outbox['payload_json'], true);

        $this->assertSame('table', $outbox['aggregate_type']);
        $this->assertSame('table.updated', $outbox['event_type']);
        $this->assertSame('pos_table_status', $outbox['source_system']);
        $this->assertSame($this->tableId, (int) $payload['table']['local_table_id']);
        $this->assertSame(1, (int) $payload['table']['table_case']);
        $this->assertNull($payload['table']['active_order_uuid']);

        $response = $this->postOutboxEventToCloud($outbox);
        $this->assertSame(200, $response['status_code']);
        $this->assertSame('processed', (string) ($response['body']['results'][0]['status'] ?? ''), json_encode($response['body'], JSON_UNESCAPED_SLASHES));

        $cloudTable = $this->fetchCloudTable((string) $result['table_uuid']);
        $this->assertNotNull($cloudTable);
        $this->assertSame($this->tableId, (int) $cloudTable['local_table_id']);
        $this->assertSame(1, (int) $cloudTable['table_case']);
    }

    private function seedTable(): void
    {
        $name = 'PHPUnit Sync Table ' . bin2hex(random_bytes(4));
        $stmt = self::$conn->prepare("INSERT INTO tables (tname, table_case, isdeleted) VALUES (?, 1, 0)");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $this->tableId = (int) self::$conn->insert_id;
        $stmt->close();
    }

    private function postOutboxEventToCloud(array $outbox): array
    {
        $payload = json_decode($outbox['payload_json'], true);
        $event = [
            'event_uuid' => $outbox['event_uuid'],
            'idempotency_key' => $outbox['idempotency_key'],
            'payload_hash' => $outbox['payload_hash'],
            'event_type' => $outbox['event_type'],
            'event_version' => (int) $outbox['event_version'],
            'source_system' => $outbox['source_system'],
            'aggregate_type' => $outbox['aggregate_type'],
            'aggregate_uuid' => $outbox['aggregate_uuid'],
            'aggregate_local_id' => (int) $outbox['aggregate_local_id'],
            'entity_type' => $outbox['entity_type'],
            'entity_uuid' => $outbox['entity_uuid'],
            'entity_local_id' => (int) $outbox['entity_local_id'],
            'payload' => $payload,
        ];
        $body = json_encode([
            'schema_version' => 1,
            'branch_uuid' => self::BRANCH_UUID,
            'events' => [$event],
        ], JSON_UNESCAPED_SLASHES);

        return (new CloudReceiveService())->handle(
            self::$conn,
            $this->signedHeaders($body),
            $body,
            $this->cloudConfig()
        );
    }

    private function branchConfig(): array
    {
        return posmain_app_config([
            'role' => 'branch',
            'branch' => [
                'uuid' => self::BRANCH_UUID,
                'name' => 'PHPUnit Remaining Surfaces Branch',
                'pos_tenant' => 0,
                'pos_branch' => 0,
                'cloud_base_url' => 'http://cloud-runtime.test',
            ],
            'sync' => [
                'branch_secret' => self::SECRET,
                'outbox_enabled' => true,
                'branch_sync_enabled' => true,
                'worker_enabled' => true,
            ],
        ]);
    }

    private function cloudConfig(): array
    {
        return posmain_app_config([
            'role' => 'cloud',
            'sync' => [
                'cloud_branch_secrets' => [self::BRANCH_UUID => self::SECRET],
                'cloud_apply_enabled' => true,
                'shadow_mode' => false,
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
        $name = 'PHPUnit Remaining Surfaces Branch';
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

    private function fetchOutbox(int $id): array
    {
        return self::$conn->query('SELECT * FROM sync_outbox WHERE id = ' . $id)->fetch_assoc();
    }

    private function fetchCloudTable(string $tableUuid): ?array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM cloud_tables
            WHERE branch_uuid = ?
              AND table_uuid = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $stmt->bind_param('ss', $branchUuid, $tableUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function cleanup(): void
    {
        $branchUuid = self::$conn->real_escape_string(self::BRANCH_UUID);
        self::$conn->query("DELETE FROM cloud_tables WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM sync_inbox WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM sync_outbox WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '{$branchUuid}'");
        self::$conn->query('DELETE FROM sync_branch_identity WHERE id = 1');

        if ($this->tableId) {
            self::$conn->query('DELETE FROM tables WHERE id = ' . (int) $this->tableId);
        }
    }

    private function source(string $path): string
    {
        $absolute = __DIR__ . '/../../' . $path;
        $source = file_get_contents($absolute);
        $this->assertIsString($source);

        return $source;
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }
}

class remaining_write_surfaces_outbox_test extends RemainingWriteSurfacesOutboxTest
{
}
