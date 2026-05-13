<?php

use PHPUnit\Framework\TestCase;

class LocalSyncWorkerSupervisorTest extends TestCase
{
    public function testSupervisorHelpRunsWithoutDatabase(): void
    {
        $lines = [];
        $code = 1;
        exec('php ' . escapeshellarg($this->root() . '/tools/local_sync_worker_supervisor.php') . ' --help', $lines, $code);

        $this->assertSame(0, $code);
        $help = implode("\n", $lines);
        $this->assertStringContainsString('local_sync_worker_supervisor.php', $help);
        $this->assertStringContainsString('--check', $help);
        $this->assertStringContainsString('--loop', $help);
        $this->assertStringContainsString('--status-file', $help);
    }

    public function testSupervisorWrapsBranchDaemonWithPreflightStatusAndPidFiles(): void
    {
        $source = $this->source('tools/local_sync_worker_supervisor.php');

        $this->assertStringContainsString('new BranchWorkerDaemon()', $source);
        $this->assertStringContainsString('preflight($conn, $config)', $source);
        $this->assertStringContainsString('runCycle($conn, $config, $runOptions)', $source);
        $this->assertStringContainsString('writePidFile($pidFile)', $source);
        $this->assertStringContainsString('writeSupervisorStatus($statusFile', $source);
        $this->assertStringContainsString('strict_blockers', $source);
        $this->assertStringContainsString('loadSupervisorEnvFile', $source);
    }

    public function testServiceTemplatesRunSupervisorUnderPlatformSupervision(): void
    {
        $systemd = $this->source('deploy/branch-worker/posmain-branch-worker.service.example');
        $plist = $this->source('deploy/branch-worker/posmain-branch-worker.plist.example');

        $this->assertStringContainsString('ExecStart=/usr/bin/php /opt/posmain/tools/local_sync_worker_supervisor.php --loop --strict', $systemd);
        $this->assertStringContainsString('Restart=always', $systemd);
        $this->assertStringContainsString('EnvironmentFile=/etc/posmain/branch-worker.env', $systemd);
        $this->assertStringContainsString('RuntimeDirectory=posmain', $systemd);

        $this->assertStringContainsString('<string>/opt/posmain/tools/local_sync_worker_supervisor.php</string>', $plist);
        $this->assertStringContainsString('<key>KeepAlive</key>', $plist);
        $this->assertStringContainsString('<key>RunAtLoad</key>', $plist);
        $this->assertStringContainsString('--env-file=/etc/posmain/branch-worker.env', $plist);
    }

    private function source(string $path): string
    {
        $absolute = $this->root() . '/' . $path;
        $source = file_get_contents($absolute);
        $this->assertIsString($source);

        return $source;
    }

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

class local_sync_worker_supervisor_test extends LocalSyncWorkerSupervisorTest
{
}
