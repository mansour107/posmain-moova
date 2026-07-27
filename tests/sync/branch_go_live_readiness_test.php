<?php

use PHPUnit\Framework\TestCase;

class BranchGoLiveReadinessTest extends TestCase
{
    public function testHelpDocumentsNonDestructiveBackupGate(): void
    {
        exec('php ' . escapeshellarg($this->root() . '/tools/branch_go_live_readiness.php') . ' --help', $lines, $code);

        $output = implode("\n", $lines);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('--backup-file=', $output);
        $this->assertStringContainsString('--env-file=', $output);
        $this->assertStringContainsString('--moova-acceptance-file=', $output);
        $this->assertStringContainsString('--max-backup-age-hours=24', $output);
        $this->assertStringContainsString('--max-moova-acceptance-age-hours=24', $output);
        $this->assertStringContainsString('--skip-db', $output);
        $this->assertStringContainsString('without installing services, running migrations, or restoring data', $output);
    }

    public function testJsonSkipDbBlocksGoLiveWithoutBackupEvidence(): void
    {
        $payload = $this->runReadiness('--json --skip-db', $code);

        $this->assertSame(2, $code);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['ready_for_go_live']);
        $this->assertContains('backup_file_not_provided', $payload['blockers']);
        $this->assertContains('database_check_skipped', $payload['blockers']);
        $this->assertNotContains('branch_uuid_missing', $payload['blockers']);
        $this->assertNotContains('cloud_base_url_missing', $payload['blockers']);
        $this->assertNotContains('branch_sync_secret_missing', $payload['blockers']);
        $this->assertSame('sync_outbox', $payload['checks']['daemon_jobs']['jobs'][0]['name']);
    }

    public function testBackupFileIsValidatedWithoutRunningDatabaseOrRestore(): void
    {
        $backup = tempnam(sys_get_temp_dir(), 'posmain-readiness-');
        $this->assertIsString($backup);
        file_put_contents($backup, "-- test backup\nCREATE TABLE readiness_probe (id int);\n");

        try {
            $payload = $this->runReadiness('--json --skip-db --backup-file=' . escapeshellarg($backup), $code);
        } finally {
            @unlink($backup);
        }

        $this->assertSame(2, $code);
        $this->assertTrue($payload['checks']['backup_file']['ok']);
        $this->assertGreaterThan(0, $payload['checks']['backup_file']['bytes']);
        $this->assertSame(24, $payload['checks']['backup_file']['max_age_hours']);
        $this->assertArrayHasKey('age_seconds', $payload['checks']['backup_file']);
        $this->assertNotContains('backup_file_not_provided', $payload['blockers']);
        $this->assertContains('database_check_skipped', $payload['blockers']);
        $this->assertStringContainsString('mysqldump', $payload['checks']['commands']['backup']['command']);
        $this->assertStringContainsString('mysql --host=', $payload['checks']['commands']['rollback_restore']['command']);
        $this->assertSame('php cli/branch_worker_daemon.php --preflight --strict', $payload['checks']['commands']['daemon_preflight']['command']);
    }

    public function testDocsAndSourceKeepBackupRollbackAsOperatorCommands(): void
    {
        $tool = $this->source('tools/branch_go_live_readiness.php');
        $doc = $this->source('docs/branch_go_live_readiness.md');
        $daemonDoc = $this->source('docs/branch_worker_daemon.md');
        $envExample = $this->source('deploy/branch-worker/branch-worker.env.example');
        $acceptanceExample = $this->source('deploy/branch-worker/moova-cashier-acceptance.md.example');

        $this->assertStringContainsString('branchGoLiveCommandTemplates', $tool);
        $this->assertStringContainsString('branchGoLiveLoadEnvFile', $tool);
        $this->assertStringContainsString('branchGoLiveMoovaAcceptanceCheck', $tool);
        $this->assertStringContainsString('branchGoLiveMoovaAcceptanceRequiredMarkers', $tool);
        $this->assertStringContainsString('mysqldump --single-transaction', $tool);
        $this->assertStringContainsString('mysql --host=', $tool);
        $this->assertStringNotContainsString('shell_exec', $tool);
        $this->assertStringNotContainsString('passthru', $tool);
        $this->assertStringNotContainsString('system(', $tool);
        $this->assertStringContainsString('intentionally non-destructive', $doc);
        $this->assertStringContainsString('Rollback', $doc);
        $this->assertStringContainsString('php cli/branch_worker_daemon.php --preflight --strict', $doc);
        $this->assertStringContainsString('--moova-acceptance-file=', $doc);
        $this->assertStringContainsString('--max-backup-age-hours=0', $doc);
        $this->assertStringContainsString('--max-moova-acceptance-age-hours=0', $doc);
        $this->assertStringContainsString('Moova Cashier Acceptance Evidence', $doc);
        $this->assertStringContainsString('deploy/branch-worker/moova-cashier-acceptance.md.example', $doc);
        foreach ($this->requiredAcceptanceMarkers() as $marker) {
            $this->assertStringContainsString($marker, $doc);
            $this->assertStringContainsString($marker, $acceptanceExample);
        }
        $this->assertStringContainsString('deploy/branch-worker/branch-worker.env.example', $doc);
        $this->assertStringContainsString('docs/branch_go_live_readiness.md', $daemonDoc);
        $this->assertStringContainsString('php cli/branch_worker_daemon.php --preflight --strict', $daemonDoc);
        $this->assertStringContainsString('deploy/branch-worker/branch-worker.env.example', $daemonDoc);
        $this->assertStringContainsString('POSMAIN_ROLE=branch', $daemonDoc);
        $this->assertStringContainsString('POSMAIN_ROLE=branch', $envExample);
        $this->assertStringContainsString('POSMAIN_BRANCH_SYNC_SECRET=replace-with-protected-sync-secret', $envExample);
        $this->assertStringNotContainsString('local-e2e-branch-secret', $envExample);
        $this->assertStringNotContainsString('b35c8eef-02db-4baf-9450-c99cb10ff105', $envExample);
    }

    public function testEnvFileValidationBlocksPlaceholders(): void
    {
        $backup = $this->tempFile("-- backup\n");
        $env = $this->tempFile(implode("\n", [
            'POSMAIN_ROLE=branch',
            'POSMAIN_DB_HOST=127.0.0.1',
            'POSMAIN_DB_PORT=3306',
            'POSMAIN_DB_NAME=kody2',
            'POSMAIN_DB_USER=posmain',
            'POSMAIN_BRANCH_UUID=replace-with-branch-uuid',
            'POSMAIN_CLOUD_BASE_URL=https://cloud.example.com',
            'POSMAIN_BRANCH_SYNC_SECRET=replace-with-protected-sync-secret',
            '',
        ]));

        try {
            $payload = $this->runReadiness('--json --skip-db --env-file=' . escapeshellarg($env) . ' --backup-file=' . escapeshellarg($backup), $code);
        } finally {
            @unlink($backup);
            @unlink($env);
        }

        $this->assertSame(2, $code);
        $this->assertFalse($payload['checks']['env_file']['ok']);
        $this->assertContains('env_file_placeholder_POSMAIN_BRANCH_UUID', $payload['blockers']);
        $this->assertContains('env_file_placeholder_POSMAIN_CLOUD_BASE_URL', $payload['blockers']);
        $this->assertContains('env_file_placeholder_POSMAIN_BRANCH_SYNC_SECRET', $payload['blockers']);
        $this->assertContains('branch_uuid_placeholder', $payload['blockers']);
        $this->assertContains('cloud_base_url_placeholder', $payload['blockers']);
        $this->assertContains('branch_sync_secret_placeholder', $payload['blockers']);
    }

    public function testEnvFileCanProvideBranchConfigWithoutProcessEnv(): void
    {
        $backup = $this->tempFile("-- backup\n");
        $env = $this->tempFile(implode("\n", [
            'POSMAIN_ROLE=branch',
            'POSMAIN_DB_HOST=127.0.0.1',
            'POSMAIN_DB_PORT=3306',
            'POSMAIN_DB_NAME=kody2',
            'POSMAIN_DB_USER=posmain',
            'POSMAIN_BRANCH_UUID=branch-test',
            'POSMAIN_CLOUD_BASE_URL=https://cloud.example.test',
            'POSMAIN_BRANCH_SYNC_SECRET=test-secret-from-file',
            '',
        ]));

        try {
            $payload = $this->runReadiness('--json --skip-db --env-file=' . escapeshellarg($env) . ' --backup-file=' . escapeshellarg($backup), $code);
        } finally {
            @unlink($backup);
            @unlink($env);
        }

        $this->assertSame(2, $code);
        $this->assertTrue($payload['checks']['env_file']['ok']);
        $this->assertTrue($payload['checks']['backup_file']['ok']);
        $this->assertContains('database_check_skipped', $payload['blockers']);
        $this->assertNotContains('branch_uuid_missing', $payload['blockers']);
        $this->assertNotContains('cloud_base_url_missing', $payload['blockers']);
        $this->assertNotContains('branch_sync_secret_missing', $payload['blockers']);
    }

    public function testMoovaApplyEnabledRequiresCashierAcceptanceEvidence(): void
    {
        $backup = $this->tempFile("-- backup\n");
        $env = $this->tempFile(implode("\n", [
            'POSMAIN_ROLE=branch',
            'POSMAIN_DB_HOST=127.0.0.1',
            'POSMAIN_DB_PORT=3306',
            'POSMAIN_DB_NAME=kody2',
            'POSMAIN_DB_USER=posmain',
            'POSMAIN_BRANCH_UUID=branch-test',
            'POSMAIN_CLOUD_BASE_URL=https://cloud.example.test',
            'POSMAIN_BRANCH_SYNC_SECRET=test-secret-from-file',
            'POSMAIN_MOOVA_MODE=queued_worker',
            'POSMAIN_MOOVA_APPLY_ENABLED=1',
            '',
        ]));

        try {
            $payload = $this->runReadiness('--json --skip-db --env-file=' . escapeshellarg($env) . ' --backup-file=' . escapeshellarg($backup), $code);
        } finally {
            @unlink($backup);
            @unlink($env);
        }

        $this->assertSame(2, $code);
        $this->assertTrue($payload['checks']['moova_acceptance']['required']);
        $this->assertFalse($payload['checks']['moova_acceptance']['ok']);
        $this->assertContains('moova_apply_acceptance_missing', $payload['blockers']);
    }

    public function testMoovaApplyAcceptanceEvidenceCanClearApplyGate(): void
    {
        $backup = $this->tempFile("-- backup\n");
        $acceptance = $this->tempFile("branch=branch-test\n" . implode("\n", $this->requiredAcceptanceMarkers()) . "\n");
        $env = $this->tempFile(implode("\n", [
            'POSMAIN_ROLE=branch',
            'POSMAIN_DB_HOST=127.0.0.1',
            'POSMAIN_DB_PORT=3306',
            'POSMAIN_DB_NAME=kody2',
            'POSMAIN_DB_USER=posmain',
            'POSMAIN_BRANCH_UUID=branch-test',
            'POSMAIN_CLOUD_BASE_URL=https://cloud.example.test',
            'POSMAIN_BRANCH_SYNC_SECRET=test-secret-from-file',
            'POSMAIN_MOOVA_MODE=queued_worker',
            'POSMAIN_MOOVA_APPLY_ENABLED=1',
            '',
        ]));

        try {
            $payload = $this->runReadiness(
                '--json --skip-db --env-file=' . escapeshellarg($env)
                . ' --backup-file=' . escapeshellarg($backup)
                . ' --moova-acceptance-file=' . escapeshellarg($acceptance),
                $code
            );
        } finally {
            @unlink($backup);
            @unlink($acceptance);
            @unlink($env);
        }

        $this->assertSame(2, $code);
        $this->assertTrue($payload['checks']['moova_acceptance']['required']);
        $this->assertTrue($payload['checks']['moova_acceptance']['ok']);
        $this->assertGreaterThan(0, $payload['checks']['moova_acceptance']['bytes']);
        $this->assertSame(24, $payload['checks']['moova_acceptance']['max_age_hours']);
        $this->assertArrayHasKey('age_seconds', $payload['checks']['moova_acceptance']);
        $this->assertSame([], $payload['checks']['moova_acceptance']['missing_markers']);
        $this->assertContains('database_check_skipped', $payload['blockers']);
        $this->assertNotContains('moova_apply_acceptance_missing', $payload['blockers']);
    }

    public function testMoovaApplyAcceptanceEvidenceRequiresAllPassMarkers(): void
    {
        $backup = $this->tempFile("-- backup\n");
        $acceptance = $this->tempFile("branch=branch-test\nqueued_new_order=pass\nqueued_edit_order=pass\n");
        $env = $this->tempFile(implode("\n", [
            'POSMAIN_ROLE=branch',
            'POSMAIN_DB_HOST=127.0.0.1',
            'POSMAIN_DB_PORT=3306',
            'POSMAIN_DB_NAME=kody2',
            'POSMAIN_DB_USER=posmain',
            'POSMAIN_BRANCH_UUID=branch-test',
            'POSMAIN_CLOUD_BASE_URL=https://cloud.example.test',
            'POSMAIN_BRANCH_SYNC_SECRET=test-secret-from-file',
            'POSMAIN_MOOVA_MODE=queued_worker',
            'POSMAIN_MOOVA_APPLY_ENABLED=1',
            '',
        ]));

        try {
            $payload = $this->runReadiness(
                '--json --skip-db --env-file=' . escapeshellarg($env)
                . ' --backup-file=' . escapeshellarg($backup)
                . ' --moova-acceptance-file=' . escapeshellarg($acceptance),
                $code
            );
        } finally {
            @unlink($backup);
            @unlink($acceptance);
            @unlink($env);
        }

        $this->assertSame(2, $code);
        $this->assertFalse($payload['checks']['moova_acceptance']['ok']);
        $this->assertContains('moova_acceptance_markers_missing', $payload['blockers']);
        $this->assertContains('queued_cancel_order=pass', $payload['checks']['moova_acceptance']['missing_markers']);
        $this->assertContains('pos_drop_recovery=pass', $payload['checks']['moova_acceptance']['missing_markers']);
        $this->assertContains('moova_drop_recovery=pass', $payload['checks']['moova_acceptance']['missing_markers']);
    }

    public function testStaleBackupFileBlocksGoLiveByDefault(): void
    {
        $backup = $this->tempFile("-- stale backup\n");
        touch($backup, time() - 25 * 3600);

        try {
            $payload = $this->runReadiness('--json --skip-db --backup-file=' . escapeshellarg($backup), $code);
        } finally {
            @unlink($backup);
        }

        $this->assertSame(2, $code);
        $this->assertFalse($payload['checks']['backup_file']['ok']);
        $this->assertSame('backup_file_too_old', $payload['checks']['backup_file']['blocker']);
        $this->assertContains('backup_file_too_old', $payload['blockers']);
    }

    public function testStaleBackupCanOnlyPassWithExplicitAgeOverride(): void
    {
        $backup = $this->tempFile("-- stale backup\n");
        touch($backup, time() - 25 * 3600);

        try {
            $payload = $this->runReadiness('--json --skip-db --max-backup-age-hours=0 --backup-file=' . escapeshellarg($backup), $code);
        } finally {
            @unlink($backup);
        }

        $this->assertSame(2, $code);
        $this->assertTrue($payload['checks']['backup_file']['ok']);
        $this->assertSame(0, $payload['checks']['backup_file']['max_age_hours']);
        $this->assertNotContains('backup_file_too_old', $payload['blockers']);
        $this->assertContains('database_check_skipped', $payload['blockers']);
    }

    public function testStaleMoovaAcceptanceEvidenceBlocksAutomaticApplyGoLive(): void
    {
        $backup = $this->tempFile("-- backup\n");
        $acceptance = $this->tempFile("branch=branch-test\n" . implode("\n", $this->requiredAcceptanceMarkers()) . "\n");
        touch($acceptance, time() - 25 * 3600);
        $env = $this->tempFile(implode("\n", [
            'POSMAIN_ROLE=branch',
            'POSMAIN_DB_HOST=127.0.0.1',
            'POSMAIN_DB_PORT=3306',
            'POSMAIN_DB_NAME=kody2',
            'POSMAIN_DB_USER=posmain',
            'POSMAIN_BRANCH_UUID=branch-test',
            'POSMAIN_CLOUD_BASE_URL=https://cloud.example.test',
            'POSMAIN_BRANCH_SYNC_SECRET=test-secret-from-file',
            'POSMAIN_MOOVA_MODE=queued_worker',
            'POSMAIN_MOOVA_APPLY_ENABLED=1',
            '',
        ]));

        try {
            $payload = $this->runReadiness(
                '--json --skip-db --env-file=' . escapeshellarg($env)
                . ' --backup-file=' . escapeshellarg($backup)
                . ' --moova-acceptance-file=' . escapeshellarg($acceptance),
                $code
            );
        } finally {
            @unlink($backup);
            @unlink($acceptance);
            @unlink($env);
        }

        $this->assertSame(2, $code);
        $this->assertFalse($payload['checks']['moova_acceptance']['ok']);
        $this->assertSame('moova_acceptance_file_too_old', $payload['checks']['moova_acceptance']['blocker']);
        $this->assertContains('moova_acceptance_file_too_old', $payload['blockers']);
    }

    private function runReadiness(string $args, ?int &$code): array
    {
        $cmd = implode(' ', [
            'POSMAIN_BRANCH_UUID=branch-test',
            'POSMAIN_CLOUD_BASE_URL=https://cloud.example.test',
            'POSMAIN_BRANCH_SYNC_SECRET=test-secret',
            'php',
            escapeshellarg($this->root() . '/tools/branch_go_live_readiness.php'),
            $args,
        ]);
        exec($cmd, $lines, $code);
        $payload = json_decode(implode("\n", $lines), true);
        $this->assertIsArray($payload, implode("\n", $lines));

        return $payload;
    }

    private function source(string $path): string
    {
        $source = file_get_contents($this->root() . '/' . $path);
        $this->assertIsString($source, $path);

        return $source;
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'posmain-readiness-');
        $this->assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }

    private function requiredAcceptanceMarkers(): array
    {
        return [
            'queued_new_order=pass',
            'queued_edit_order=pass',
            'queued_cancel_order=pass',
            'pos_drop_recovery=pass',
            'moova_drop_recovery=pass',
        ];
    }

    private function root(): string
    {
        $root = realpath(__DIR__ . '/../..');
        $this->assertNotFalse($root);

        return $root;
    }
}

class branch_go_live_readiness_test extends BranchGoLiveReadinessTest
{
}
