<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Sync/MoovaBranchEventCursor.php';

class MoovaBranchEventCursorTest extends TestCase
{
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

        self::$conn->query("DELETE FROM cloud_moova_branch_events WHERE idempotency_key LIKE 'phpunit:moova:%'");
    }

    protected function tearDown(): void
    {
        if (self::$conn) {
            self::$conn->query("DELETE FROM cloud_moova_branch_events WHERE idempotency_key LIKE 'phpunit:moova:%'");
        }
    }

    public function testSchemaUsesIdAsCursorWithoutRequiredCursorValue(): void
    {
        $sql = implode("\n", (new SyncSchemaManager())->plannedStatements());

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_moova_branch_events', $sql);
        $this->assertStringContainsString('id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT', $sql);
        $this->assertStringNotContainsString('cursor_value BIGINT UNSIGNED NOT NULL', $sql);
        $this->assertStringContainsString('KEY idx_cloud_moova_branch_pending (branch_uuid, status, id)', $sql);
    }

    public function testFetchPendingAfterUsesExclusiveAutoIdCursorAndAckDoesNotUseCursorValue(): void
    {
        $branch = '44444444-4444-4444-4444-444444444444';
        $first = $this->insertEvent($branch, 'new_order');
        $second = $this->insertEvent($branch, 'edit_order');
        $this->insertEvent($branch, 'cancel_order', 'delivered');

        $cursor = new MoovaBranchEventCursor();
        $rows = $cursor->fetchPendingAfter(self::$conn, $branch, $first, 10);

        $this->assertSame([$second], array_map('intval', array_column($rows, 'id')));
        $this->assertSame($second, $cursor->cursorFromRow($rows[0]));
        $this->assertSame($second, (int) $rows[0]['cursor']);

        $affected = $cursor->ackByEvent(self::$conn, $rows[0]['event_uuid'], $rows[0]['idempotency_key'], 'ack_applied');
        $this->assertSame(1, $affected);

        $updated = self::$conn->query('SELECT status FROM cloud_moova_branch_events WHERE id = ' . $second)->fetch_assoc();
        $this->assertSame('ack_applied', $updated['status']);
    }

    public function testBranchScopedAckDoesNotAcknowledgeOtherBranchEvents(): void
    {
        $branch = '44444444-4444-4444-4444-444444444444';
        $wrongBranch = '55555555-5555-4555-8555-555555555555';
        $id = $this->insertEvent($branch, 'new_order');
        $row = $this->fetchEvent($id);

        $cursor = new MoovaBranchEventCursor();
        $this->assertSame(
            0,
            $cursor->ackByEventForBranch(self::$conn, $wrongBranch, $row['event_uuid'], $row['idempotency_key'], 'ack_applied')
        );
        $this->assertSame('pending', $this->fetchEvent($id)['status']);

        $this->assertSame(
            1,
            $cursor->ackByEventForBranch(self::$conn, $branch, $row['event_uuid'], $row['idempotency_key'], 'ack_declined', 'declined by test')
        );
        $updated = $this->fetchEvent($id);
        $this->assertSame('ack_declined', $updated['status']);
        $this->assertSame('declined by test', $updated['last_error']);
    }

    private function insertEvent(string $branchUuid, string $eventType, string $status = 'pending'): int
    {
        $eventUuid = $this->uuid();
        $key = 'phpunit:moova:' . bin2hex(random_bytes(8));
        $payload = json_encode(['event' => $eventType, 'key' => $key], JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payload);

        $stmt = self::$conn->prepare("
            INSERT INTO cloud_moova_branch_events (
                event_uuid,
                branch_uuid,
                moova_order_id,
                event_type,
                idempotency_key,
                payload_hash,
                payload_json,
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssssssss', $eventUuid, $branchUuid, $key, $eventType, $key, $hash, $payload, $status);
        $stmt->execute();
        $stmt->close();

        return (int) self::$conn->insert_id;
    }

    private function fetchEvent(int $id): array
    {
        $row = self::$conn->query('SELECT * FROM cloud_moova_branch_events WHERE id = ' . $id)->fetch_assoc();
        return $row ?: [];
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

class moova_branch_event_cursor_test extends MoovaBranchEventCursorTest
{
}
