<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudReceiveService.php';
require_once __DIR__ . '/../../classes/Sync/CloudOrderSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/CloudShiftSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/CloudTableSnapshotService.php';
require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';

class CloudShiftSnapshotTest extends TestCase
{
    private const BRANCH_UUID = '67676767-9999-4999-8999-676767676767';
    private const CLOSE_UUID = '78787878-9999-4999-8999-787878787878';
    private const SECRET = 'phpunit-cloud-shift-secret';

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

    public function testReceiveOnlyStoresInboxWithoutCloudShiftSnapshot(): void
    {
        $event = $this->event('receive-only', [
            'total_sales' => 320.75,
            'actual_cash' => 180.25,
        ]);

        $result = $this->postEvent($event, false, false);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('receive_only', $result['body']['mode']);
        $this->assertSame('accepted_shadow', $result['body']['results'][0]['status']);
        $this->assertFalse($result['body']['results'][0]['applied']);
        $this->assertFalse($result['body']['results'][0]['report_trusted']);
        $this->assertNull($this->fetchCloudShift());
        $this->assertSame('received', $this->fetchInbox($event['idempotency_key'])['status']);
    }

    public function testShadowModeAppliesShiftSnapshotButKeepsReportsUntrusted(): void
    {
        $event = $this->event('shadow', [
            'total_sales' => 320.75,
            'total_cash' => 180.25,
            'total_card' => 140.5,
            'actual_cash' => 178.25,
            'cash_deficit' => -2,
        ]);

        $result = $this->postEvent($event, true, true);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('shadow_apply', $result['body']['mode']);
        $this->assertSame('accepted_shadow', $result['body']['results'][0]['status']);
        $this->assertTrue($result['body']['results'][0]['applied']);
        $this->assertFalse($result['body']['results'][0]['report_trusted']);
        $this->assertStringStartsWith('cloud_shift:', (string) $result['body']['results'][0]['cloud_entity_id']);

        $shift = $this->fetchCloudShift();
        $this->assertSame(self::CLOSE_UUID, $shift['close_uuid']);
        $this->assertSame(91, (int) $shift['local_closed_order_id']);
        $this->assertSame(12, (int) $shift['cashier_user_id']);
        $this->assertSame('20260510_12', $shift['shift_number']);
        $this->assertSame('2026-05-10 09:00:00', $shift['opened_at']);
        $this->assertSame('2026-05-10 22:30:00', $shift['closed_at']);
        $this->assertSame('320.7500', $shift['total_sales']);
        $this->assertSame('180.2500', $shift['total_cash']);
        $this->assertSame('140.5000', $shift['total_card']);
        $this->assertSame('178.2500', $shift['actual_cash']);
        $this->assertSame('-2.0000', $shift['cash_deficit']);
        $this->assertSame('processed', $this->fetchInbox($event['idempotency_key'])['status']);
    }

    public function testLiveApplyUpdatesExistingShiftSnapshotByBranchAndCloseUuid(): void
    {
        $first = $this->event('live-first', [
            'total_sales' => 320.75,
            'actual_cash' => 178.25,
        ]);
        $second = $this->event('live-second', [
            'total_sales' => 450,
            'total_cash' => 300,
            'total_card' => 150,
            'actual_cash' => 300,
            'actual_card' => 150,
            'cash_deficit' => 0,
            'card_deficit' => 0,
        ]);

        $this->postEvent($first, true, false);
        $result = $this->postEvent($second, true, false);

        $this->assertSame(200, $result['status_code']);
        $this->assertSame('live_apply', $result['body']['mode']);
        $this->assertSame('processed', $result['body']['results'][0]['status']);
        $this->assertTrue($result['body']['results'][0]['applied']);
        $this->assertTrue($result['body']['results'][0]['report_trusted']);
        $this->assertSame(1, $this->cloudShiftCount());

        $shift = $this->fetchCloudShift();
        $this->assertSame('450.0000', $shift['total_sales']);
        $this->assertSame('300.0000', $shift['total_cash']);
        $this->assertSame('150.0000', $shift['total_card']);
        $this->assertSame('300.0000', $shift['actual_cash']);
        $this->assertSame('150.0000', $shift['actual_card']);
        $this->assertSame('0.0000', $shift['cash_deficit']);
        $this->assertSame('0.0000', $shift['card_deficit']);
    }

    private function event(string $suffix, array $overrides): array
    {
        $shift = array_merge([
            'close_uuid' => self::CLOSE_UUID,
            'local_closed_order_id' => 91,
            'cashier_user_id' => 12,
            'shift_number' => '20260510_12',
            'opened_at' => '2026-05-10 09:00:00',
            'closed_at' => '2026-05-10 22:30:00',
            'branch_timezone' => 'Africa/Cairo',
            'total_sales' => 0,
            'total_cash' => 0,
            'total_card' => 0,
            'actual_cash' => null,
            'actual_card' => null,
            'cash_deficit' => null,
            'card_deficit' => null,
        ], $overrides);
        $payload = ['shift' => $shift];

        return [
            'event_uuid' => SyncBranchIdentity::generateUuidV4(),
            'idempotency_key' => 'phpunit:cloud-shift:' . $suffix,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'event_type' => 'shift.closed',
            'event_version' => 1,
            'source_system' => 'pos',
            'aggregate_type' => 'shift_close',
            'aggregate_uuid' => self::CLOSE_UUID,
            'entity_type' => 'shift_close',
            'entity_uuid' => self::CLOSE_UUID,
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
        $name = 'PHPUnit Cloud Shift Branch';
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

    private function fetchCloudShift(): ?array
    {
        $stmt = self::$conn->prepare("
            SELECT *
            FROM cloud_shifts
            WHERE branch_uuid = ?
              AND close_uuid = ?
            LIMIT 1
        ");
        $branchUuid = self::BRANCH_UUID;
        $closeUuid = self::CLOSE_UUID;
        $stmt->bind_param('ss', $branchUuid, $closeUuid);
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

    private function cloudShiftCount(): int
    {
        $stmt = self::$conn->prepare("
            SELECT COUNT(*) AS c
            FROM cloud_shifts
            WHERE branch_uuid = ?
              AND close_uuid = ?
        ");
        $branchUuid = self::BRANCH_UUID;
        $closeUuid = self::CLOSE_UUID;
        $stmt->bind_param('ss', $branchUuid, $closeUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['c'] ?? 0);
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM cloud_shifts WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM sync_inbox WHERE idempotency_key LIKE 'phpunit:cloud-shift:%'");
        self::$conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
        self::$conn->query("DELETE FROM cloud_branches WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
    }
}

class cloud_shift_snapshot_test extends CloudShiftSnapshotTest
{
}
