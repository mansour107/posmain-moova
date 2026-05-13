<?php

use PHPUnit\Framework\TestCase;

class BranchWorkerDeploymentTemplatesTest extends TestCase
{
    public function testServiceTemplatesPointAtPortableDaemonWithoutRealSecrets(): void
    {
        foreach ($this->templatePaths() as $path) {
            $source = $this->source($path);
            if ($path === 'deploy/branch-worker/windows/posmain-branch-worker-task.xml.example') {
                $this->assertStringContainsString('posmain-branch-worker-wrapper.ps1', $source, $path);
            } else {
                $this->assertStringContainsString('branch_worker_daemon.php', $source, $path);
                $this->assertStringContainsString('--loop', $source, $path);
                $this->assertStringContainsString('--sleep=5', $source, $path);
                $this->assertStringContainsString('--max-runtime=300', $source, $path);
            }
            $this->assertStringNotContainsString('POSMAIN_BRANCH_SYNC_SECRET=', $source, $path);
            $this->assertStringNotContainsString('POSMAIN_CLOUD_BRANCH_SECRETS=', $source, $path);
            $this->assertStringNotContainsString('b35c8eef-02db-4baf-9450-c99cb10ff105', $source, $path);
        }
    }

    public function testPlatformTemplatesExposeExpectedSupervisorControls(): void
    {
        $systemd = $this->source('deploy/branch-worker/systemd/posmain-branch-worker.service.example');
        $launchd = $this->source('deploy/branch-worker/launchd/com.posmain.branch-worker.plist.example');
        $windows = $this->source('deploy/branch-worker/windows/posmain-branch-worker-task.xml.example');
        $windowsWrapper = $this->source('deploy/branch-worker/windows/posmain-branch-worker-wrapper.ps1.example');
        $compose = $this->source('deploy/branch-worker/docker-compose.branch-worker.yml.example');

        $this->assertStringContainsString('Restart=always', $systemd);
        $this->assertStringContainsString('EnvironmentFile=/etc/posmain/branch-worker.env', $systemd);
        $this->assertStringContainsString('ExecStartPre=/usr/bin/php /opt/posmain/cli/branch_worker_daemon.php --preflight --strict', $systemd);
        $this->assertStringContainsString('<key>KeepAlive</key>', $launchd);
        $this->assertStringContainsString('<key>RunAtLoad</key>', $launchd);
        $this->assertStringContainsString('<key>POSMAIN_ROLE</key>', $launchd);
        $this->assertStringNotContainsString('POSMAIN_APP_ROLE', $launchd);
        $this->assertStringContainsString('<RestartOnFailure>', $windows);
        $this->assertStringContainsString('powershell.exe', $windows);
        $this->assertStringContainsString('posmain-branch-worker-wrapper.ps1', $windows);
        $this->assertStringContainsString('<WorkingDirectory>C:\posmain</WorkingDirectory>', $windows);
        $this->assertStringContainsString('--preflight --strict', $windowsWrapper);
        $this->assertStringContainsString('--loop --sleep=5 --max-runtime=300', $windowsWrapper);
        $this->assertStringContainsString('restart: unless-stopped', $compose);
        $this->assertStringContainsString('healthcheck:', $compose);
        $this->assertStringContainsString('- --preflight', $compose);
        $this->assertStringContainsString('- --strict', $compose);
        $this->assertStringContainsString('.env.branch-worker', $compose);
    }

    public function testDaemonDocsLinkDeploymentTemplatesAndSmokeCommands(): void
    {
        $doc = $this->source('docs/branch_worker_daemon.md');

        $this->assertStringContainsString('deploy/branch-worker/systemd/posmain-branch-worker.service.example', $doc);
        $this->assertStringContainsString('deploy/branch-worker/launchd/com.posmain.branch-worker.plist.example', $doc);
        $this->assertStringContainsString('deploy/branch-worker/windows/posmain-branch-worker-task.xml.example', $doc);
        $this->assertStringContainsString('deploy/branch-worker/windows/posmain-branch-worker-wrapper.ps1.example', $doc);
        $this->assertStringContainsString('deploy/branch-worker/docker-compose.branch-worker.yml.example', $doc);
        $this->assertStringContainsString('php cli/branch_worker_daemon.php --preflight', $doc);
        $this->assertStringContainsString('strict preflight healthcheck', $doc);
    }

    private function templatePaths(): array
    {
        return [
            'deploy/branch-worker/systemd/posmain-branch-worker.service.example',
            'deploy/branch-worker/launchd/com.posmain.branch-worker.plist.example',
            'deploy/branch-worker/windows/posmain-branch-worker-task.xml.example',
            'deploy/branch-worker/windows/posmain-branch-worker-wrapper.ps1.example',
            'deploy/branch-worker/docker-compose.branch-worker.yml.example',
        ];
    }

    private function source(string $path): string
    {
        $absolute = __DIR__ . '/../../' . $path;
        $source = file_get_contents($absolute);
        $this->assertIsString($source, $path);

        return $source;
    }
}

class branch_worker_deployment_templates_test extends BranchWorkerDeploymentTemplatesTest
{
}
