<?php

use PHPUnit\Framework\TestCase;

class E2eMockOnlineOfflineSyncContractTest extends TestCase
{
    public function testHarnessHelpIsSelfDescribingAndDoesNotNeedDatabase(): void
    {
        exec('php ' . escapeshellarg($this->root() . '/tools/e2e_mock_online_offline_sync.php') . ' --help', $lines, $code);

        $output = implode("\n", $lines);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('two-mock-server online/offline sync proof', $output);
        $this->assertStringContainsString('online_cloud_down_first_attempt', $output);
        $this->assertStringContainsString('offline_branch_back_cloud_event_delivered_and_acked', $output);
        $this->assertStringContainsString('report_path', $output);
    }

    public function testHarnessCoversRequiredOutageScenariosAndScopedCleanup(): void
    {
        $source = $this->source('tools/e2e_mock_online_offline_sync.php');

        foreach ($this->requiredScenarioNames() as $scenario) {
            $this->assertStringContainsString($scenario, $source);
        }

        $this->assertStringContainsString("cleanupRows(\$conn, 'e2e:')", $source);
        $this->assertStringContainsString('function cleanupRows', $source);
        $this->assertStringContainsString('sync_outbox WHERE idempotency_key LIKE', $source);
        $this->assertStringContainsString('cloud_moova_branch_events WHERE idempotency_key LIKE', $source);
        $this->assertStringContainsString('pcntl_fork', $source);
        $this->assertStringContainsString('stream_socket_server', $source);
    }

    public function testDocsWireHarnessIntoGoLiveReadiness(): void
    {
        $doc = $this->source('docs/online_offline_mock_e2e.md');
        $readiness = $this->source('docs/branch_go_live_readiness.md');

        $this->assertStringContainsString('POSMAIN_TEST_MYSQL_PORT=3307 php tools/e2e_mock_online_offline_sync.php', $doc);
        $this->assertStringContainsString('mock cloud server', $doc);
        $this->assertStringContainsString('mock branch server', $doc);
        $this->assertStringContainsString('Production Boundary', $doc);
        foreach ($this->requiredScenarioNames() as $scenario) {
            $this->assertStringContainsString($scenario, $doc);
        }
        $this->assertStringContainsString('docs/online_offline_mock_e2e.md', $readiness);
        $this->assertStringContainsString('php tools/e2e_mock_online_offline_sync.php', $readiness);
    }

    private function requiredScenarioNames(): array
    {
        return [
            'cloud_receive_only',
            'cloud_shadow_apply',
            'cloud_live_apply',
            'online_cloud_down_first_attempt',
            'online_cloud_back_retries_failed_event',
            'branch_worker_crash_lock_expires_and_reclaims',
            'offline_branch_down_first_attempt',
            'offline_branch_back_cloud_event_delivered_and_acked',
        ];
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

class e2e_mock_online_offline_sync_contract_test extends E2eMockOnlineOfflineSyncContractTest
{
}
