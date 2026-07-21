<?php

use PHPUnit\Framework\TestCase;

class BranchWorkerStatusTest extends TestCase
{
    public function testHelpDocumentsReadOnlyStatusReport(): void
    {
        exec('php ' . escapeshellarg($this->root() . '/tools/branch_worker_status.php') . ' --help', $lines, $code);

        $output = implode("\n", $lines);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('--json', $output);
        $this->assertStringContainsString('--recent-minutes=60', $output);
        $this->assertStringContainsString('--fail-on-problems', $output);
        $this->assertStringContainsString('Read-only status report', $output);
    }

    public function testSourceIsReadOnlyAndCoversExpectedQueues(): void
    {
        $source = $this->source('tools/branch_worker_status.php');

        $this->assertStringContainsString('sync_outbox', $source);
        $this->assertStringContainsString('moova_pos_inbound_events', $source);
        $this->assertStringContainsString('moova_catalog_sync_outbox', $source);
        $this->assertStringContainsString('sync_worker_logs', $source);
        $this->assertStringContainsString('expired_syncing_locks', $source);
        $this->assertStringContainsString('pending_cloud_ack', $source);
        $this->assertStringContainsString('retry_errors', $source);
        $this->assertStringContainsString('recent_failed', $source);
        $this->assertStringContainsString('recent_minutes', $source);
        $this->assertStringContainsString('DATE_SUB(NOW(6), INTERVAL', $source);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE)\b/i', $source);
    }

    public function testDocsWireStatusIntoDaemonOperations(): void
    {
        $doc = $this->source('docs/branch_worker_status.md');
        $daemonDoc = $this->source('docs/branch_worker_daemon.md');

        $this->assertStringContainsString('php tools/branch_worker_status.php --json', $doc);
        $this->assertStringContainsString('/api/sync/status.php?limit=10&recent_minutes=60', $doc);
        $this->assertStringContainsString('POSMAIN_STATUS_TOKEN', $doc);
        $this->assertStringContainsString('X-POSMAIN-STATUS-TOKEN', $doc);
        $this->assertStringContainsString('fail_on_problems=1', $doc);
        $this->assertStringContainsString('status_token_not_configured', $doc);
        $this->assertStringContainsString('--recent-minutes=10', $doc);
        $this->assertStringContainsString('outbox_retryable_due', $doc);
        $this->assertStringContainsString('moova_pending_cloud_ack', $doc);
        $this->assertStringContainsString('never write sync rows', $doc);
        $this->assertStringContainsString('docs/branch_worker_status.md', $daemonDoc);
        $this->assertStringContainsString('php tools/branch_worker_status.php --json', $daemonDoc);
    }

    public function testHttpStatusEndpointIsTokenGatedReadOnlyWrapper(): void
    {
        $source = $this->source('api/sync/status.php');

        $this->assertStringContainsString('POSMAIN_BRANCH_WORKER_STATUS_LIBRARY', $source);
        $this->assertStringContainsString('POSMAIN_STATUS_TOKEN', $source);
        $this->assertStringContainsString('HTTP_X_POSMAIN_STATUS_TOKEN', $source);
        $this->assertStringContainsString('HTTP_AUTHORIZATION', $source);
        $this->assertStringContainsString('hash_equals', $source);
        $this->assertStringContainsString('status_token_not_configured', $source);
        $this->assertStringContainsString('method_not_allowed', $source);
        $this->assertStringContainsString('branchWorkerStatusReport', $source);
        $this->assertStringContainsString('branchWorkerStatusUnavailable', $source);
        $this->assertStringContainsString('fail_on_problems', $source);
        $this->assertStringContainsString('unset($report[\'database\'][\'user\']);', $source);
        $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE)\b/i', $source);
    }

    public function testJsonReportRunsAgainstLocalTestDatabaseWhenAvailable(): void
    {
        $cmd = 'POSMAIN_TEST_MYSQL_PORT=3307 php ' . escapeshellarg($this->root() . '/tools/branch_worker_status.php') . ' --json --limit=3 --recent-minutes=0';
        exec($cmd, $lines, $code);
        $output = implode("\n", $lines);
        $payload = json_decode($output, true);
        $this->assertIsArray($payload, $output);

        if ($code === 2 && ($payload['error'] ?? '') === 'db_connect_failed') {
            $this->markTestSkipped('Local test database is not available.');
        }

        $this->assertSame(0, $code, $output);
        $this->assertTrue($payload['ok']);
        $this->assertArrayHasKey('healthy', $payload);
        $this->assertSame(0, $payload['recent_minutes']);
        $this->assertArrayHasKey('sync_outbox', $payload['checks']);
        $this->assertArrayHasKey('moova_inbound', $payload['checks']);
        $this->assertArrayHasKey('moova_catalog', $payload['checks']);
        $this->assertArrayHasKey('worker_logs', $payload['checks']);
        $this->assertArrayHasKey('counts_by_status', $payload['checks']['sync_outbox']);
        $this->assertArrayHasKey('pending_cloud_ack', $payload['checks']['moova_inbound']);
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

class branch_worker_status_test extends BranchWorkerStatusTest
{
}
