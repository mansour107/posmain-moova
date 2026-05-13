<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';

class SyncConflictToolTest extends TestCase
{
    private const BRANCH_UUID = 'cdcdcdcd-1212-4212-8212-cdcdcdcdcdcd';
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

    public function testHelpDocumentsReadOnlyListingAndExplicitResolution(): void
    {
        exec('php ' . escapeshellarg($this->root() . '/tools/sync_conflict_tool.php') . ' --help', $lines, $code);

        $output = implode("\n", $lines);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('--status=open|ignored|resolved|remote_rejected|local_rejected|all', $output);
        $this->assertStringContainsString('--include-payloads', $output);
        $this->assertStringContainsString('--resolve=ID', $output);
        $this->assertStringContainsString('only updates open sync_conflicts rows', $output);
    }

    public function testSourceAndDocsKeepConflictToolScopedToSyncConflicts(): void
    {
        $source = $this->source('tools/sync_conflict_tool.php');
        $doc = $this->source('docs/sync_conflict_tool.md');

        $this->assertStringContainsString('sync_conflicts', $source);
        $this->assertStringContainsString("UPDATE sync_conflicts", $source);
        $this->assertStringContainsString("AND resolution_status = 'open'", $source);
        $this->assertStringNotContainsString('sync_outbox', $source);
        $this->assertStringNotContainsString('sync_inbox', $source);
        $this->assertStringNotContainsString('moova_pos_inbound_events', $source);
        $this->assertStringContainsString('does not modify `sync_outbox`', $doc);
        $this->assertStringContainsString('--dry-run', $doc);
        $this->assertStringContainsString('--include-payloads', $doc);
    }

    public function testListDryRunResolveAndRejectSecondResolutionAgainstLocalDatabase(): void
    {
        $id = $this->insertConflict();

        $list = $this->runTool('--json --branch-uuid=' . escapeshellarg(self::BRANCH_UUID) . ' --include-payloads');
        $this->assertSame(0, $list['code'], $list['output']);
        $payload = $this->decodeJson($list['output']);
        $this->assertTrue($payload['ok']);
        $this->assertSame('list', $payload['action']);
        $this->assertSame('open', $payload['status_filter']);
        $this->assertCount(1, $payload['conflicts']);
        $this->assertSame($id, $payload['conflicts'][0]['id']);
        $this->assertStringContainsString('"source":"local"', $payload['conflicts'][0]['local_payload_json']);

        $dryRun = $this->runTool('--json --resolve=' . $id . ' --resolution-status=ignored --notes=' . escapeshellarg('known duplicate') . ' --dry-run');
        $this->assertSame(0, $dryRun['code'], $dryRun['output']);
        $dryPayload = $this->decodeJson($dryRun['output']);
        $this->assertSame('would_resolve', $dryPayload['action']);
        $this->assertSame('open', $this->conflictStatus($id));

        $resolved = $this->runTool('--json --resolve=' . $id . ' --resolution-status=ignored --notes=' . escapeshellarg('known duplicate'));
        $this->assertSame(0, $resolved['code'], $resolved['output']);
        $resolvedPayload = $this->decodeJson($resolved['output']);
        $this->assertSame('resolved', $resolvedPayload['action']);
        $this->assertSame('ignored', $resolvedPayload['resolution_status']);
        $this->assertSame('ignored', $this->conflictStatus($id));
        $this->assertNotNull($this->resolvedAt($id));

        $second = $this->runTool('--json --resolve=' . $id . ' --resolution-status=resolved');
        $this->assertSame(2, $second['code'], $second['output']);
        $secondPayload = $this->decodeJson($second['output']);
        $this->assertSame('conflict_not_open', $secondPayload['error']);
    }

    private function insertConflict(): int
    {
        $branchUuid = self::BRANCH_UUID;
        $local = json_encode(['source' => 'local'], JSON_UNESCAPED_SLASHES);
        $remote = json_encode(['source' => 'remote'], JSON_UNESCAPED_SLASHES);
        $stmt = self::$conn->prepare("
            INSERT INTO sync_conflicts (
                branch_uuid,
                conflict_type,
                aggregate_type,
                remote_entity_id,
                local_payload_json,
                remote_payload_json,
                resolution_status
            ) VALUES (?, 'phpunit_conflict', 'order', 'remote-1', ?, ?, 'open')
        ");
        $stmt->bind_param('sss', $branchUuid, $local, $remote);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        return (int) $id;
    }

    private function conflictStatus(int $id): string
    {
        $row = self::$conn->query('SELECT resolution_status FROM sync_conflicts WHERE id = ' . $id)->fetch_assoc();

        return (string) $row['resolution_status'];
    }

    private function resolvedAt(int $id): ?string
    {
        $row = self::$conn->query('SELECT resolved_at FROM sync_conflicts WHERE id = ' . $id)->fetch_assoc();

        return $row['resolved_at'] === null ? null : (string) $row['resolved_at'];
    }

    private function cleanup(): void
    {
        self::$conn->query("DELETE FROM sync_conflicts WHERE branch_uuid = '" . self::$conn->real_escape_string(self::BRANCH_UUID) . "'");
    }

    private function runTool(string $args): array
    {
        $cmd = $this->dbEnvPrefix() . ' php ' . escapeshellarg($this->root() . '/tools/sync_conflict_tool.php') . ' ' . $args;
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

class sync_conflict_tool_test extends SyncConflictToolTest
{
}
