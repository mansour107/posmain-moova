<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class SyncRecoveryToolTest extends TestCase
{
    private const BRANCH_UUID = 'eeeeeeee-3434-4434-8434-eeeeeeeeeeee';
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

    public function testHelpDocsAndSourceShowDryRunRecoveryBoundaries(): void
    {
        exec('php ' . escapeshellarg($this->root() . '/tools/sync_recovery_tool.php') . ' --help', $lines, $code);

        $output = implode("\n", $lines);
        $source = $this->source('tools/sync_recovery_tool.php');
        $doc = $this->source('docs/sync_recovery_tool.md');

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Without --apply, recovery actions are dry-run only', $output);
        $this->assertStringContainsString('--release-expired-outbox-locks', $output);
        $this->assertStringContainsString('--requeue-failed-moova-ack', $output);
        $this->assertStringContainsString('UPDATE sync_outbox', $source);
        $this->assertStringContainsString('UPDATE moova_pos_inbound_events', $source);
        $this->assertStringNotContainsString('doadd_invoice', $source);
        $this->assertStringNotContainsString('cloud_orders', $source);
        $this->assertStringContainsString('dry-run by default', $doc);
        $this->assertStringContainsString('does not modify POS orders', $doc);
        $this->assertStringContainsString('--all --apply', $doc);
    }

    public function testDryRunThenApplyRecoversStuckAndFailedRowsAgainstLocalDatabase(): void
    {
        $ids = $this->insertRecoveryRows();

        $dryRun = $this->runTool('--json --branch-uuid=' . escapeshellarg(self::BRANCH_UUID) . ' --all');
        $this->assertSame(0, $dryRun['code'], $dryRun['output']);
        $dryPayload = $this->decodeJson($dryRun['output']);
        $this->assertTrue($dryPayload['ok']);
        $this->assertTrue($dryPayload['dry_run']);
        $this->assertSame(1, $dryPayload['actions']['release_expired_outbox_locks']['would_update']);
        $this->assertSame(1, $dryPayload['actions']['requeue_failed_outbox']['would_update']);
        $this->assertSame(1, $dryPayload['actions']['release_expired_moova_locks']['would_update']);
        $this->assertSame(1, $dryPayload['actions']['requeue_failed_moova_apply']['would_update']);
        $this->assertSame(1, $dryPayload['actions']['requeue_failed_moova_ack']['would_update']);
        $this->assertSame('syncing', $this->outboxRow($ids['outbox_syncing'])['status']);
        $this->assertSame('processing', $this->moovaRow($ids['moova_processing'])['status']);

        $apply = $this->runTool('--json --branch-uuid=' . escapeshellarg(self::BRANCH_UUID) . ' --all --apply');
        $this->assertSame(0, $apply['code'], $apply['output']);
        $applyPayload = $this->decodeJson($apply['output']);
        $this->assertFalse($applyPayload['dry_run']);
        $this->assertSame(1, $applyPayload['actions']['release_expired_outbox_locks']['updated']);
        $this->assertSame(1, $applyPayload['actions']['requeue_failed_outbox']['updated']);
        $this->assertSame(1, $applyPayload['actions']['release_expired_moova_locks']['updated']);
        $this->assertSame(1, $applyPayload['actions']['requeue_failed_moova_apply']['updated']);
        $this->assertSame(1, $applyPayload['actions']['requeue_failed_moova_ack']['updated']);

        $releasedOutbox = $this->outboxRow($ids['outbox_syncing']);
        $failedOutbox = $this->outboxRow($ids['outbox_failed']);
        $releasedMoova = $this->moovaRow($ids['moova_processing']);
        $failedMoova = $this->moovaRow($ids['moova_failed']);
        $ackMoova = $this->moovaRow($ids['moova_ack_failed']);

        $this->assertSame('pending', $releasedOutbox['status']);
        $this->assertNull($releasedOutbox['locked_by']);
        $this->assertNull($releasedOutbox['locked_until']);
        $this->assertNotNull($releasedOutbox['next_retry_at']);
        $this->assertSame('pending', $failedOutbox['status']);
        $this->assertNull($failedOutbox['last_error']);
        $this->assertSame('received', $releasedMoova['status']);
        $this->assertNull($releasedMoova['locked_by']);
        $this->assertNull($releasedMoova['locked_until']);
        $this->assertSame('received', $failedMoova['status']);
        $this->assertNull($failedMoova['error_message']);
        $this->assertSame('applied', $ackMoova['status']);
        $this->assertNull($ackMoova['cloud_ack_status']);
        $this->assertNull($ackMoova['cloud_ack_error']);
    }

    private function insertRecoveryRows(): array
    {
        return [
            'outbox_syncing' => $this->insertOutbox('aaaaaaaa-0000-4000-8000-000000000101', 'syncing', 'worker-1', 'expired lock'),
            'outbox_failed' => $this->insertOutbox('aaaaaaaa-0000-4000-8000-000000000102', 'failed', null, 'cloud down'),
            'moova_processing' => $this->insertMoova('bbbbbbbb-0000-4000-8000-000000000101', 'processing', 'worker-2', 'apply stuck', null),
            'moova_failed' => $this->insertMoova('bbbbbbbb-0000-4000-8000-000000000102', 'failed', null, 'apply failed', null),
            'moova_ack_failed' => $this->insertMoova('bbbbbbbb-0000-4000-8000-000000000103', 'applied', null, null, 'failed'),
        ];
    }

    private function insertOutbox(string $eventUuid, string $status, ?string $lockedBy, ?string $lastError): int
    {
        $payload = json_encode(['event_uuid' => $eventUuid], JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payload);
        $idempotency = 'recovery-' . substr($eventUuid, -12);
        $stmt = self::$conn->prepare("
            INSERT INTO sync_outbox (
                event_uuid,
                branch_uuid,
                aggregate_type,
                aggregate_id,
                entity_type,
                event_type,
                source_system,
                idempotency_key,
                payload_json,
                payload_hash,
                status,
                attempts,
                locked_by,
                locked_until,
                next_retry_at,
                last_error
            ) VALUES (?, ?, 'order', ?, 'order', 'phpunit_recovery', 'pos', ?, ?, ?, ?, 2, ?, DATE_SUB(NOW(6), INTERVAL 5 MINUTE), DATE_SUB(NOW(6), INTERVAL 1 MINUTE), ?)
        ");
        $branchUuid = self::BRANCH_UUID;
        $aggregateId = 'recovery-test';
        $stmt->bind_param('sssssssss', $eventUuid, $branchUuid, $aggregateId, $idempotency, $payload, $hash, $status, $lockedBy, $lastError);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    private function insertMoova(string $eventUuid, string $status, ?string $lockedBy, ?string $errorMessage, ?string $cloudAckStatus): int
    {
        $payload = json_encode(['event_uuid' => $eventUuid], JSON_UNESCAPED_SLASHES);
        $hash = hash('sha256', $payload);
        $idempotency = 'moova-recovery-' . substr($eventUuid, -12);
        $cloudAckError = $cloudAckStatus === 'failed' ? 'cloud still offline' : null;
        $stmt = self::$conn->prepare("
            INSERT INTO moova_pos_inbound_events (
                event_uuid,
                moova_order_id,
                pos_tenant,
                pos_branch,
                branch_uuid,
                idempotency_key,
                request_hash,
                event_type,
                delivery_path,
                payload_json,
                status,
                locked_by,
                locked_until,
                attempt_count,
                last_attempt_at,
                error_message,
                cloud_ack_status,
                cloud_ack_error,
                cloud_ack_attempt_count,
                cloud_ack_last_attempt_at
            ) VALUES (?, ?, 0, 0, ?, ?, ?, 'new_order', 'test', ?, ?, ?, DATE_SUB(NOW(6), INTERVAL 5 MINUTE), 2, DATE_SUB(NOW(6), INTERVAL 2 MINUTE), ?, ?, ?, 1, DATE_SUB(NOW(6), INTERVAL 1 MINUTE))
        ");
        $branchUuid = self::BRANCH_UUID;
        $moovaOrderId = 'moova-' . substr($eventUuid, -12);
        $stmt->bind_param(
            'sssssssssss',
            $eventUuid,
            $moovaOrderId,
            $branchUuid,
            $idempotency,
            $hash,
            $payload,
            $status,
            $lockedBy,
            $errorMessage,
            $cloudAckStatus,
            $cloudAckError
        );
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    private function outboxRow(int $id): array
    {
        return self::$conn->query('SELECT * FROM sync_outbox WHERE id = ' . $id)->fetch_assoc();
    }

    private function moovaRow(int $id): array
    {
        return self::$conn->query('SELECT * FROM moova_pos_inbound_events WHERE id = ' . $id)->fetch_assoc();
    }

    private function cleanup(): void
    {
        $branch = self::$conn->real_escape_string(self::BRANCH_UUID);
        self::$conn->query("DELETE FROM sync_outbox WHERE branch_uuid = '{$branch}'");
        self::$conn->query("DELETE FROM moova_pos_inbound_events WHERE branch_uuid = '{$branch}'");
    }

    private function runTool(string $args): array
    {
        $cmd = $this->dbEnvPrefix() . ' php ' . escapeshellarg($this->root() . '/tools/sync_recovery_tool.php') . ' ' . $args;
        exec($cmd, $lines, $code);

        return [
            'code' => $code,
            'output' => implode("\n", $lines),
        ];
    }

    private function decodeJson(string $output): array
    {
        $payload = json_decode($output, true);
        $this->assertIsArray($payload, $output);

        return $payload;
    }

    private function dbEnvPrefix(): string
    {
        $vars = [
            'POSMAIN_TEST_MYSQL_HOST' => getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'POSMAIN_TEST_MYSQL_PORT' => getenv('POSMAIN_TEST_MYSQL_PORT') ?: '3307',
            'POSMAIN_TEST_MYSQL_USER' => getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root',
            'POSMAIN_TEST_MYSQL_PASS' => getenv('POSMAIN_TEST_MYSQL_PASS') ?: '',
            'POSMAIN_TEST_MYSQL_DB' => getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2',
        ];

        $parts = [];
        foreach ($vars as $name => $value) {
            $parts[] = $name . '=' . escapeshellarg((string) $value);
        }

        return implode(' ', $parts);
    }

    private function source(string $path): string
    {
        $source = file_get_contents($this->root() . '/' . $path);
        $this->assertIsString($source, $path);

        return $source;
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../..');
        $this->assertNotFalse($root);

        return $root;
    }
}

class sync_recovery_tool_test extends SyncRecoveryToolTest
{
}
