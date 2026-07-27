<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/OutboxWorker.php';

class OutboxWorkerReclaimTest extends TestCase
{
    private const BRANCH_UUID = '00000000-0000-0000-0000-000000000001';
    private const OTHER_BRANCH_UUID = '00000000-0000-0000-0000-000000000099';

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

        self::$conn->query("DELETE FROM sync_outbox WHERE idempotency_key LIKE 'phpunit:outbox:%'");
    }

    protected function tearDown(): void
    {
        if (self::$conn) {
            self::$conn->query("DELETE FROM sync_outbox WHERE idempotency_key LIKE 'phpunit:outbox:%'");
        }
    }

    public function testClaimBatchClaimsRetryableAndExpiredSyncingRowsOnly(): void
    {
        $pendingId = $this->insertOutboxRow('pending');
        $heldId = $this->insertOutboxRow('held');
        $failedDueId = $this->insertOutboxRow('failed', 'DATE_SUB(NOW(6), INTERVAL 5 SECOND)');
        $failedFutureId = $this->insertOutboxRow('failed', 'DATE_ADD(NOW(6), INTERVAL 60 SECOND)');
        $syncingExpiredId = $this->insertOutboxRow('syncing', 'NULL', 'old-worker', 'DATE_SUB(NOW(6), INTERVAL 5 SECOND)');
        $syncingLockedId = $this->insertOutboxRow('syncing', 'NULL', 'active-worker', 'DATE_ADD(NOW(6), INTERVAL 60 SECOND)');
        $otherBranchId = $this->insertOutboxRow('pending', 'NULL', null, 'NULL', self::OTHER_BRANCH_UUID);

        $worker = new OutboxWorker();
        $claimed = $worker->claimBatch(self::$conn, 'phpunit-worker', 10, 120, self::BRANCH_UUID);

        $claimedIds = array_map('intval', array_column($claimed, 'id'));
        $this->assertSame([$pendingId, $failedDueId, $syncingExpiredId], $claimedIds);

        $rows = $this->loadRows([$pendingId, $heldId, $failedDueId, $failedFutureId, $syncingExpiredId, $syncingLockedId, $otherBranchId]);

        foreach ([$pendingId, $failedDueId, $syncingExpiredId] as $id) {
            $this->assertSame('syncing', $rows[$id]['status']);
            $this->assertSame('phpunit-worker', $rows[$id]['locked_by']);
            $this->assertSame(1, (int) $rows[$id]['attempts']);
        }

        $this->assertSame('held', $rows[$heldId]['status']);
        $this->assertNull($rows[$heldId]['locked_by']);
        $this->assertSame(0, (int) $rows[$heldId]['attempts']);

        $this->assertSame('failed', $rows[$failedFutureId]['status']);
        $this->assertNull($rows[$failedFutureId]['locked_by']);
        $this->assertSame(0, (int) $rows[$failedFutureId]['attempts']);

        $this->assertSame('syncing', $rows[$syncingLockedId]['status']);
        $this->assertSame('active-worker', $rows[$syncingLockedId]['locked_by']);
        $this->assertSame(0, (int) $rows[$syncingLockedId]['attempts']);

        $this->assertSame('pending', $rows[$otherBranchId]['status']);
        $this->assertNull($rows[$otherBranchId]['locked_by']);
        $this->assertSame(0, (int) $rows[$otherBranchId]['attempts']);
    }

    private function insertOutboxRow(
        string $status,
        string $nextRetrySql = 'NULL',
        ?string $lockedBy = null,
        string $lockedUntilSql = 'NULL',
        string $branchUuid = self::BRANCH_UUID
    ): int {
        $uuid = $this->uuid();
        $key = 'phpunit:outbox:' . bin2hex(random_bytes(8));
        $lockedBySql = $lockedBy === null ? 'NULL' : "'" . self::$conn->real_escape_string($lockedBy) . "'";

        self::$conn->query("
            INSERT INTO sync_outbox (
                event_uuid,
                branch_uuid,
                aggregate_type,
                aggregate_id,
                entity_type,
                event_type,
                idempotency_key,
                payload_json,
                payload_hash,
                status,
                locked_by,
                locked_until,
                next_retry_at
            ) VALUES (
                '{$uuid}',
                '{$branchUuid}',
                'phpunit',
                '{$key}',
                'phpunit',
                'phpunit.outbox',
                '{$key}',
                '{}',
                '" . hash('sha256', $key) . "',
                '{$status}',
                {$lockedBySql},
                {$lockedUntilSql},
                {$nextRetrySql}
            )
        ");

        return (int) self::$conn->insert_id;
    }

    private function loadRows(array $ids): array
    {
        $idList = implode(',', array_map('intval', $ids));
        $result = self::$conn->query("
            SELECT id, status, locked_by, attempts
            FROM sync_outbox
            WHERE id IN ({$idList})
        ");

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
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

class outbox_worker_reclaim_test extends OutboxWorkerReclaimTest
{
}
