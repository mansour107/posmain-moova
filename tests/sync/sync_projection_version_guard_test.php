<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/SyncApplyMode.php';
require_once __DIR__ . '/../../classes/Sync/SyncInboxService.php';
require_once __DIR__ . '/../../classes/Sync/SyncProjectionVersionGuard.php';

class SyncProjectionVersionGuardTest extends TestCase
{
    private const BRANCH_A = 'aaaa1111-1111-4111-8111-111111111111';
    private const BRANCH_B = 'bbbb2222-2222-4222-8222-222222222222';
    private const TABLE_UUID = 'cccc3333-3333-4333-8333-333333333333';

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
    }

    protected function tearDown(): void
    {
        if (self::$conn) {
            $this->cleanup();
        }
    }

    public function testGuardRejectsOlderAndEqualConflictingPayloadsButAllowsBranchesToAdvanceIndependently(): void
    {
        $guard = new SyncProjectionVersionGuard();
        $current = $this->tableEvent('current', 5, 'Current');

        self::$conn->begin_transaction();
        $first = $guard->evaluateAndLock(self::$conn, self::BRANCH_A, $current);
        $this->assertSame('apply', $first['decision']);
        $guard->markApplied(self::$conn, self::BRANCH_A, $current, $first);
        self::$conn->commit();

        self::$conn->begin_transaction();
        $older = $guard->evaluateAndLock(self::$conn, self::BRANCH_A, $this->tableEvent('older', 4, 'Old'));
        self::$conn->commit();
        $this->assertSame('stale', $older['decision']);

        self::$conn->begin_transaction();
        $duplicate = $guard->evaluateAndLock(self::$conn, self::BRANCH_A, $current);
        self::$conn->commit();
        $this->assertSame('duplicate', $duplicate['decision']);

        self::$conn->begin_transaction();
        $conflict = $guard->evaluateAndLock(self::$conn, self::BRANCH_A, $this->tableEvent('conflict', 5, 'Changed'));
        self::$conn->commit();
        $this->assertSame('conflict', $conflict['decision']);

        self::$conn->begin_transaction();
        $otherBranch = $guard->evaluateAndLock(self::$conn, self::BRANCH_B, $this->tableEvent('other-branch', 1, 'Branch B'));
        $guard->markApplied(self::$conn, self::BRANCH_B, $this->tableEvent('other-branch', 1, 'Branch B'), $otherBranch);
        self::$conn->commit();
        $this->assertSame('apply', $otherBranch['decision']);
        $this->assertSame(5, $this->storedRevision(self::BRANCH_A));
        $this->assertSame(1, $this->storedRevision(self::BRANCH_B));
    }

    public function testCursorAdvanceRollsBackWithFailedProjectionTransaction(): void
    {
        $guard = new SyncProjectionVersionGuard();
        $event = $this->tableEvent('rollback', 9, 'Rollback');

        self::$conn->begin_transaction();
        $decision = $guard->evaluateAndLock(self::$conn, self::BRANCH_A, $event);
        $guard->markApplied(self::$conn, self::BRANCH_A, $event, $decision);
        self::$conn->rollback();

        $this->assertSame(0, $this->storedRevision(self::BRANCH_A));
    }

    public function testInboxAppliesNewerTableSnapshotAndDoesNotLetOlderOrEqualConflictingDataOverwriteIt(): void
    {
        $service = new SyncInboxService();
        $newer = $this->tableEvent('newer', 8, 'New State');
        $older = $this->tableEvent('older-inbox', 7, 'Old State');
        $conflict = $this->tableEvent('equal-conflict-inbox', 8, 'Wrong Equal State');

        $applied = $service->receiveBranchEvent(self::$conn, self::BRANCH_A, $newer, SyncApplyMode::LIVE_APPLY);
        $stale = $service->receiveBranchEvent(self::$conn, self::BRANCH_A, $older, SyncApplyMode::LIVE_APPLY);
        $rejected = $service->receiveBranchEvent(self::$conn, self::BRANCH_A, $conflict, SyncApplyMode::LIVE_APPLY);

        $this->assertSame('processed', $applied['status']);
        $this->assertSame('stale', $stale['status']);
        $this->assertSame('conflict', $rejected['status']);
        $this->assertSame('New State', $this->storedTableName());
        $this->assertSame(1, $this->conflictCount('projection_revision_conflict'));
    }

    public function testInboxQuarantinesGenericOperationalHardDelete(): void
    {
        $payload = [
            'snapshot_type' => 'operational_delete',
            'table' => 'drawer_movements',
            'local_id' => 42,
        ];
        $event = [
            'event_uuid' => 'dddd4444-4444-4444-8444-444444444444',
            'idempotency_key' => 'phpunit:projection:unsafe-delete',
            'aggregate_type' => 'drawer_movement',
            'aggregate_uuid' => 'eeee5555-5555-4555-8555-555555555555',
            'event_type' => 'drawer_movement.deleted',
            'event_version' => 1,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'payload' => $payload,
        ];

        $result = (new SyncInboxService())->receiveBranchEvent(
            self::$conn,
            self::BRANCH_A,
            $event,
            SyncApplyMode::LIVE_APPLY
        );

        $this->assertSame('conflict', $result['status']);
        $this->assertStringContainsString('tombstone', $result['message']);
        $this->assertSame(1, $this->conflictCount('unsafe_operational_delete'));
    }

    public function testOperationalMirrorRejectsDomainTableMismatchBeforeMutation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OPERATIONAL_SYNC_DOMAIN_TABLE_MISMATCH');

        (new CloudOperationalMirrorService())->applyFromBranchEvent(self::$conn, self::BRANCH_A, [
            'payload' => [
                'snapshot_type' => 'operational_row',
                'domain' => 'order_event',
                'table' => 'users',
                'row' => ['id' => 987654, 'username' => 'must-not-write'],
            ],
        ]);
    }

    public function testInventoryBalanceKeepsNewerMovementRevisionWhenOlderSnapshotArrivesLater(): void
    {
        $service = new SyncInboxService();
        $newer = $this->inventoryBalanceEvent('inventory-newer', 12, '8.000000');
        $older = $this->inventoryBalanceEvent('inventory-older', 11, '3.000000');

        $applied = $service->receiveBranchEvent(self::$conn, self::BRANCH_A, $newer, SyncApplyMode::LIVE_APPLY);
        $stale = $service->receiveBranchEvent(self::$conn, self::BRANCH_A, $older, SyncApplyMode::LIVE_APPLY);

        $this->assertSame('processed', $applied['status']);
        $this->assertSame('stale', $stale['status']);
        $row = self::$conn->query('SELECT qty_on_hand, last_movement_id FROM inventory_item_balances WHERE id = 9988101')->fetch_assoc();
        $this->assertSame('8.000000', number_format((float) ($row['qty_on_hand'] ?? 0), 6, '.', ''));
        $this->assertSame(12, (int) ($row['last_movement_id'] ?? 0));
    }

    private function tableEvent(string $suffix, int $revision, string $name): array
    {
        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'pos_table',
            'table_uuid' => self::TABLE_UUID,
            'table' => [
                'table_uuid' => self::TABLE_UUID,
                'local_table_id' => 42,
                'tname' => $name,
                'table_case' => 0,
                'isdeleted' => 0,
                'sync_revision' => $revision,
            ],
        ];

        return [
            'event_uuid' => sprintf('ffff%04d-6666-4666-8666-%012d', $revision, abs(crc32($suffix)) % 1000000000000),
            'idempotency_key' => 'phpunit:projection:' . $suffix,
            'aggregate_type' => 'table',
            'aggregate_uuid' => self::TABLE_UUID,
            'event_type' => 'table.updated',
            'event_version' => 1,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'payload' => $payload,
        ];
    }

    private function inventoryBalanceEvent(string $suffix, int $revision, string $qty): array
    {
        $aggregateUuid = '89898989-8989-4989-8989-898989898989';
        $payload = [
            'schema_version' => 1,
            'snapshot_type' => 'operational_row',
            'domain' => 'inventory_balance',
            'table' => 'inventory_item_balances',
            'primary_key' => 'id',
            'branch_uuid' => self::BRANCH_A,
            'sync_revision' => $revision,
            'row' => [
                'id' => 9988101,
                'pos_tenant' => 89,
                'pos_branch' => 89,
                'branch_uuid' => self::BRANCH_A,
                'store_id' => 1,
                'item_id' => 9988101,
                'qty_on_hand' => $qty,
                'qty_reserved' => '0.000000',
                'qty_available' => $qty,
                'moving_average_cost' => '1.000000',
                'last_movement_id' => $revision,
            ],
        ];

        return [
            'event_uuid' => sprintf('8989%04d-8989-4989-8989-%012d', $revision, abs(crc32($suffix)) % 1000000000000),
            'idempotency_key' => 'phpunit:inventory-balance:' . $suffix,
            'aggregate_type' => 'inventory_balance',
            'aggregate_uuid' => $aggregateUuid,
            'event_type' => 'inventory.balance_saved',
            'event_version' => $revision,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)),
            'payload' => $payload,
        ];
    }

    private function storedRevision(string $branchUuid): int
    {
        $stmt = self::$conn->prepare("
            SELECT last_event_version
            FROM sync_projection_versions
            WHERE branch_uuid = ? AND aggregate_type = 'table' AND aggregate_uuid = ?
        ");
        $aggregateUuid = self::TABLE_UUID;
        $stmt->bind_param('ss', $branchUuid, $aggregateUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['last_event_version'] ?? 0);
    }

    private function storedTableName(): ?string
    {
        $stmt = self::$conn->prepare("SELECT tname FROM cloud_tables WHERE branch_uuid = ? AND table_uuid = ?");
        $branchUuid = self::BRANCH_A;
        $aggregateUuid = self::TABLE_UUID;
        $stmt->bind_param('ss', $branchUuid, $aggregateUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['tname'] ?? null;
    }

    private function conflictCount(string $type): int
    {
        $stmt = self::$conn->prepare("SELECT COUNT(*) AS c FROM sync_conflicts WHERE branch_uuid = ? AND conflict_type = ?");
        $branchUuid = self::BRANCH_A;
        $stmt->bind_param('ss', $branchUuid, $type);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int) ($row['c'] ?? 0);
    }

    private function cleanup(): void
    {
        self::$conn->query('DELETE FROM inventory_item_balances WHERE id = 9988101');
        foreach ([self::BRANCH_A, self::BRANCH_B] as $branchUuid) {
            $escaped = self::$conn->real_escape_string($branchUuid);
            self::$conn->query("DELETE FROM cloud_tables WHERE branch_uuid = '{$escaped}'");
            self::$conn->query("DELETE FROM sync_projection_versions WHERE branch_uuid = '{$escaped}'");
            self::$conn->query("DELETE FROM sync_inbox WHERE branch_uuid = '{$escaped}'");
            self::$conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '{$escaped}'");
        }
    }
}

class sync_projection_version_guard_test extends SyncProjectionVersionGuardTest
{
}
