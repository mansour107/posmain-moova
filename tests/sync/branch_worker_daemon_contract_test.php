<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../classes/Sync/BranchWorkerDaemon.php';

class BranchWorkerDaemonContractTest extends TestCase
{
    public function testDefaultJobOrderCoversBranchSyncAndMoovaLifecycle(): void
    {
        $daemon = new BranchWorkerDaemon();

        $this->assertSame([
            'sync_outbox',
            'moova_poller',
            'moova_apply',
            'moova_ack',
        ], $daemon->jobNames());

        $descriptions = $daemon->describeJobs();
        $this->assertSame('BranchSyncWorker', $descriptions[0]['worker_class']);
        $this->assertSame('BranchMoovaPollWorker', $descriptions[1]['worker_class']);
        $this->assertSame('BranchMoovaApplyWorker', $descriptions[2]['worker_class']);
        $this->assertSame('BranchMoovaAckWorker', $descriptions[3]['worker_class']);
    }

    public function testCliListIsJsonAndDoesNotRequireDatabase(): void
    {
        $root = realpath(__DIR__ . '/../..');
        $this->assertNotFalse($root);

        exec('php ' . escapeshellarg($root . '/cli/branch_worker_daemon.php') . ' --list', $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));

        $payload = json_decode(implode("\n", $lines), true);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['ok']);
        $this->assertCount(4, $payload['jobs']);
        $this->assertSame('sync_outbox', $payload['jobs'][0]['name']);
        $this->assertSame('moova_ack', $payload['jobs'][3]['name']);
    }

    public function testDaemonSourceKeepsSupervisionAndPreflightBoundaries(): void
    {
        $source = $this->source('classes/Sync/BranchWorkerDaemon.php');
        $cli = $this->source('cli/branch_worker_daemon.php');

        $this->assertStringContainsString("'strict'", $cli);
        $this->assertStringContainsString('--preflight --strict', $cli);
        $this->assertStringContainsString('strict_blockers', $cli);
        $this->assertStringContainsString('public function preflight', $source);
        $this->assertStringContainsString('public function runCycle', $source);
        $this->assertStringContainsString('catch (Throwable $e)', $source);
        $this->assertStringContainsString('schema_pending', $source);
        $this->assertStringContainsString('cloud_base_url_missing', $source);
        $this->assertStringContainsString('db_connect_failed', $cli);
        $this->assertStringContainsString("'only' => \$options['only'] ?? null", $cli);
        $this->assertStringContainsString("empty(\$preflight['schema_pending']) && (!\$strict || empty(\$preflight['warnings']))", $cli);
    }

    public function testStrictPreflightFailsOnConfigWarningsWhenDatabaseIsAvailable(): void
    {
        $root = realpath(__DIR__ . '/../..');
        $this->assertNotFalse($root);

        $base = 'POSMAIN_TEST_MYSQL_PORT=3307 POSMAIN_BRANCH_UUID= POSMAIN_CLOUD_BASE_URL= POSMAIN_BRANCH_SYNC_SECRET= php '
            . escapeshellarg($root . '/cli/branch_worker_daemon.php');

        exec($base . ' --preflight', $normalLines, $normalCode);
        $normalPayload = json_decode(implode("\n", $normalLines), true);
        $this->assertIsArray($normalPayload, implode("\n", $normalLines));
        if (($normalPayload['error'] ?? '') === 'db_connect_failed') {
            $this->markTestSkipped('Local test database is not available.');
        }

        exec($base . ' --preflight --strict', $strictLines, $strictCode);
        $strictPayload = json_decode(implode("\n", $strictLines), true);
        $this->assertIsArray($strictPayload, implode("\n", $strictLines));

        $this->assertSame(0, $normalCode, implode("\n", $normalLines));
        $this->assertSame(2, $strictCode, implode("\n", $strictLines));
        $this->assertTrue($strictPayload['strict']);
        $this->assertContains('branch_uuid_missing', $strictPayload['warnings']);
        $this->assertContains('cloud_base_url_missing', $strictPayload['warnings']);
        $this->assertContains('branch_sync_secret_missing', $strictPayload['warnings']);
        $this->assertContains('branch_uuid_missing', $strictPayload['strict_blockers']);
    }

    private function source(string $path): string
    {
        $absolute = __DIR__ . '/../../' . $path;
        $source = file_get_contents($absolute);
        $this->assertIsString($source);

        return $source;
    }
}

class branch_worker_daemon_contract_test extends BranchWorkerDaemonContractTest
{
}
