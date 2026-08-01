<?php

require_once __DIR__ . '/../../classes/Updates/UpdateOrchestrator.php';

$tmp = sys_get_temp_dir() . '/posmain_update_orchestrator_' . bin2hex(random_bytes(4));
$remote = $tmp . '/release.git';
$publisher = $tmp . '/publisher';
$checkout = $tmp . '/checkout';
$jobs = $tmp . '/jobs';
$maintenanceFlag = $tmp . '/maintenance.flag';

try {
    mkdir($tmp, 0750, true);
    updateOrchestratorRun(['git', 'init', '--bare', $remote], $tmp);
    updateOrchestratorRun(['git', 'init', '-b', 'main', $publisher], $tmp);
    updateOrchestratorRun(['git', 'config', 'user.email', 'update-test@example.invalid'], $publisher);
    updateOrchestratorRun(['git', 'config', 'user.name', 'POSMAIN Update Test'], $publisher);
    updateOrchestratorWriteRelease($publisher, '1.0.0', 'release-one');
    updateOrchestratorRun(['git', 'add', '.gitignore', 'version.txt', 'version.json', 'release-marker.txt'], $publisher);
    updateOrchestratorRun(['git', 'commit', '-m', 'release 1.0.0'], $publisher);
    updateOrchestratorRun(['git', 'remote', 'add', 'origin', $remote], $publisher);
    updateOrchestratorRun(['git', 'push', '-u', 'origin', 'main'], $publisher);
    updateOrchestratorRun(['git', 'clone', '--branch', 'main', $remote, $checkout], $tmp);

    updateOrchestratorWriteRelease($publisher, '1.1.0', 'release-two');
    updateOrchestratorRun(['git', 'add', 'version.txt', 'version.json', 'release-marker.txt'], $publisher);
    updateOrchestratorRun(['git', 'commit', '-m', 'release 1.1.0'], $publisher);
    updateOrchestratorRun(['git', 'push', 'origin', 'main'], $publisher);
    $releaseTwoCommit = trim(updateOrchestratorRun(['git', 'rev-parse', 'HEAD'], $publisher));

    putenv('POSMAIN_UPDATE_GIT_BRANCH=main');
    putenv('POSMAIN_UPDATE_GIT_REMOTE=origin');
    putenv('POSMAIN_UPDATE_DRAIN_SECONDS=0');
    putenv('POSMAIN_UPDATE_KEEP_BACKUP=0');

    $coordinator = new UpdateOrchestratorFakeCoordinator($checkout);
    $store = new PosmainUpdateJobStore($jobs);
    $maintenance = new PosmainUpdateMaintenance($maintenanceFlag);
    $hooks = [
        'restart_runtime' => static fn(): array => ['ok' => true, 'skipped' => false],
        'health_check' => static fn(): array => ['ok' => true, 'healthy' => true],
    ];
    $job = $store->create(['action' => 'apply', 'target_version' => '1.1.0']);
    $result = (new PosmainUpdateOrchestrator($store, $maintenance, $checkout, $coordinator, $hooks))
        ->run((string) $job['id']);

    updateOrchestratorAssert($result['status'] === 'completed', 'successful update must complete');
    updateOrchestratorAssert(!$maintenance->isEnabled(), 'successful update must disable maintenance');
    updateOrchestratorAssert(!empty($result['backup_deleted']), 'successful update must delete backup set');
    updateOrchestratorAssert(
        array_key_exists('backup_set', $result) && $result['backup_set'] === null,
        'deleted backup must be removed from job state'
    );
    updateOrchestratorAssert(
        trim(updateOrchestratorRun(['git', 'rev-parse', 'HEAD'], $checkout)) === $releaseTwoCommit,
        'successful update must activate exact fetched release commit'
    );
    updateOrchestratorAssert(trim((string) file_get_contents($checkout . '/version.txt')) === '1.1.0', 'release version must come from Git');
    updateOrchestratorAssert($coordinator->applyCalls === 1, 'database migrations must run once');
    updateOrchestratorAssert($coordinator->verifyCalls === 1, 'fresh database verification must run once');

    updateOrchestratorWriteRelease($publisher, '1.2.0', 'release-three');
    updateOrchestratorRun(['git', 'add', 'version.txt', 'version.json', 'release-marker.txt'], $publisher);
    updateOrchestratorRun(['git', 'commit', '-m', 'release 1.2.0'], $publisher);
    updateOrchestratorRun(['git', 'push', 'origin', 'main'], $publisher);

    $failingCoordinator = new UpdateOrchestratorFakeCoordinator($checkout);
    $failingCoordinator->failApply = true;
    $failedJob = $store->create(['action' => 'apply', 'target_version' => '1.2.0']);
    try {
        (new PosmainUpdateOrchestrator($store, $maintenance, $checkout, $failingCoordinator, $hooks))
            ->run((string) $failedJob['id']);
        updateOrchestratorAssert(false, 'migration failure must escape the worker');
    } catch (RuntimeException $exception) {
        updateOrchestratorAssert(
            $exception->getMessage() === 'SIMULATED_DATABASE_MIGRATION_FAILURE',
            'worker must report original migration failure'
        );
    }

    $failed = $store->find((string) $failedJob['id']);
    updateOrchestratorAssert(is_array($failed) && $failed['status'] === 'failed', 'failed update must be terminal');
    updateOrchestratorAssert(($failed['recovery_status'] ?? '') === 'recovered', 'failed update must report verified recovery');
    updateOrchestratorAssert(!empty($failed['rollback']['database_restore']['ok']), 'database backup must be restored');
    updateOrchestratorAssert(!empty($failed['rollback']['code_restore']['ok']), 'prior code commit must be restored');
    updateOrchestratorAssert(!empty($failed['rollback']['health_check']['ok']), 'restored system must pass health check');
    updateOrchestratorAssert(!$maintenance->isEnabled(), 'verified recovery must disable maintenance');
    updateOrchestratorAssert($failingCoordinator->restoreCalls === 1, 'recovery must restore every backed-up target');
    updateOrchestratorAssert(empty($failed['backup_deleted']), 'failure backup must be retained');
    updateOrchestratorAssert(
        trim(updateOrchestratorRun(['git', 'rev-parse', 'HEAD'], $checkout)) === $releaseTwoCommit,
        'failed update must reset exact prior commit'
    );
    updateOrchestratorAssert(trim((string) file_get_contents($checkout . '/version.txt')) === '1.1.0', 'rollback must restore prior release version');

    $staleCoordinator = new UpdateOrchestratorFakeCoordinator($checkout);
    $staleJob = $store->create(['action' => 'apply', 'target_version' => '1.1.0']);
    $staleBackup = $staleCoordinator->backupAll((string) $staleJob['id']);
    $store->mutate((string) $staleJob['id'], static function (array $current) use ($staleBackup, $releaseTwoCommit): array {
        $current['status'] = 'recovery_required';
        $current['phase'] = 'stale_recovery';
        $current['backup_set'] = $staleBackup;
        $current['code_commit_target'] = $releaseTwoCommit;
        $current['maintenance_enabled'] = false;
        foreach ($current['steps'] as $index => $step) {
            if (in_array($step['name'], [
                'database_verification',
                'release_verification',
                'health_check',
                'maintenance_off',
            ], true)) {
                $current['steps'][$index]['status'] = 'completed';
            }
        }
        return $current;
    });
    $staleResult = (new PosmainUpdateOrchestrator(
        $store,
        $maintenance,
        $checkout,
        $staleCoordinator,
        $hooks
    ))->recover((string) $staleJob['id']);
    updateOrchestratorAssert($staleResult['status'] === 'completed', 'stale worker after health verification must finalize success');
    updateOrchestratorAssert(
        ($staleResult['recovery_status'] ?? '') === 'completed_after_stale_cleanup',
        'stale successful update must report cleanup recovery'
    );
    updateOrchestratorAssert(!empty($staleResult['backup_deleted']), 'stale success finalization must delete its backup');
    updateOrchestratorAssert($staleCoordinator->restoreCalls === 0, 'stale success finalization must not roll back a healthy release');
    updateOrchestratorAssert($staleCoordinator->verifyCalls === 1, 'stale success finalization must reverify databases');

    $fallbackCoordinator = new UpdateOrchestratorFakeCoordinator($checkout);
    $fallbackJob = $store->create(['action' => 'apply', 'target_version' => '1.1.0']);
    $fallbackBackup = $fallbackCoordinator->backupAll((string) $fallbackJob['id']);
    $store->mutate((string) $fallbackJob['id'], static function (array $current) use (
        $fallbackBackup,
        $releaseTwoCommit
    ): array {
        $current['status'] = 'recovery_required';
        $current['phase'] = 'stale_recovery';
        $current['backup_set'] = $fallbackBackup;
        $current['code_commit_before'] = $releaseTwoCommit;
        $current['code_commit_target'] = $releaseTwoCommit;
        $current['maintenance_enabled'] = false;
        foreach ($current['steps'] as $index => $step) {
            if (in_array($step['name'], [
                'database_verification',
                'release_verification',
                'health_check',
                'maintenance_off',
            ], true)) {
                $current['steps'][$index]['status'] = 'completed';
            }
        }
        return $current;
    });
    $healthCalls = 0;
    $fallbackHooks = [
        'restart_runtime' => static fn(): array => ['ok' => true, 'skipped' => false],
        'health_check' => static function () use (&$healthCalls): array {
            $healthCalls++;
            return ['ok' => $healthCalls > 1, 'healthy' => $healthCalls > 1];
        },
    ];
    $fallbackResult = (new PosmainUpdateOrchestrator(
        $store,
        $maintenance,
        $checkout,
        $fallbackCoordinator,
        $fallbackHooks
    ))->recover((string) $fallbackJob['id']);
    updateOrchestratorAssert($fallbackResult['status'] === 'failed', 'failed stale finalization must be terminal');
    updateOrchestratorAssert(
        ($fallbackResult['recovery_status'] ?? '') === 'recovered',
        'failed stale finalization must fall through to verified rollback'
    );
    updateOrchestratorAssert(
        $fallbackCoordinator->restoreCalls === 1,
        'failed stale finalization must restore every backed-up target'
    );
    updateOrchestratorAssert(!$maintenance->isEnabled(), 'verified stale fallback recovery must disable maintenance');

    updateOrchestratorWriteRelease($publisher, '1.3.0', 'release-four');
    updateOrchestratorRun(['git', 'add', 'version.txt', 'version.json', 'release-marker.txt'], $publisher);
    updateOrchestratorRun(['git', 'commit', '-m', 'release 1.3.0'], $publisher);
    updateOrchestratorRun(['git', 'push', 'origin', 'main'], $publisher);

    $restartCoordinator = new UpdateOrchestratorFakeCoordinator($checkout);
    $restartJob = $store->create(['action' => 'apply', 'target_version' => '1.3.0']);
    $restartFailureHooks = [
        'restart_runtime' => static fn(): array => ['ok' => false],
        'health_check' => static fn(): array => ['ok' => true, 'healthy' => true],
    ];
    try {
        (new PosmainUpdateOrchestrator($store, $maintenance, $checkout, $restartCoordinator, $restartFailureHooks))
            ->run((string) $restartJob['id']);
        updateOrchestratorAssert(false, 'an explicit runtime restart failure must fail the update');
    } catch (RuntimeException $exception) {
        updateOrchestratorAssert(
            $exception->getMessage() === 'RUNTIME_RESTART_FAILED',
            'runtime restart failure must retain a stable diagnostic'
        );
    }
    $restartFailed = $store->find((string) $restartJob['id']);
    updateOrchestratorAssert(
        ($restartFailed['recovery_status'] ?? '') === 'recovery_failed',
        'rollback must not be reported recovered when its runtime restart fails'
    );
    updateOrchestratorAssert($maintenance->isEnabled(), 'incomplete runtime recovery must retain maintenance mode');

    echo "update-orchestrator-recovery-ok\n";
} finally {
    putenv('POSMAIN_UPDATE_GIT_BRANCH');
    putenv('POSMAIN_UPDATE_GIT_REMOTE');
    putenv('POSMAIN_UPDATE_DRAIN_SECONDS');
    putenv('POSMAIN_UPDATE_KEEP_BACKUP');
    updateOrchestratorRemoveTree($tmp);
}

final class UpdateOrchestratorFakeCoordinator
{
    public bool $failApply = false;
    public int $applyCalls = 0;
    public int $verifyCalls = 0;
    public int $restoreCalls = 0;
    public int $preflightCalls = 0;
    private string $root;

    public function __construct(string $root)
    {
        $this->root = $root;
    }

    public function publicTargets(): array
    {
        return [['key' => 'test', 'kind' => 'default', 'label' => 'test', 'database' => 'fixture']];
    }

    public function preflight(): array
    {
        $this->preflightCalls++;

        return ['ok' => true, 'target_count' => 1, 'targets' => [['ok' => true]]];
    }

    public function plan(): array
    {
        return ['ok' => true, 'target_count' => 1, 'pending_count' => 1, 'targets' => []];
    }

    public function backupAll(string $jobId): array
    {
        $directory = $this->root . '/backup/updates/' . $jobId;
        mkdir($directory, 0750, true);
        $file = $directory . '/fixture.sql';
        file_put_contents($file, "verified fixture backup\n", LOCK_EX);

        return [
            'job_id' => $jobId,
            'directory' => $directory,
            'artifacts' => [['target' => ['key' => 'test'], 'file' => $file]],
        ];
    }

    public function applyAll(array $backupSet): array
    {
        $this->applyCalls++;
        if ($this->failApply) {
            throw new RuntimeException('SIMULATED_DATABASE_MIGRATION_FAILURE');
        }

        return ['ok' => true, 'targets' => [['ok' => true]]];
    }

    public function verifyAllFresh(): array
    {
        $this->verifyCalls++;

        return ['ok' => true, 'target_count' => 1, 'targets' => [['ok' => true]]];
    }

    public function restoreAll(array $backupSet): array
    {
        $this->restoreCalls++;

        return ['ok' => true, 'targets' => [['ok' => true]]];
    }

    public function deleteBackupSet(array $backupSet): bool
    {
        $directory = (string) ($backupSet['directory'] ?? '');
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($directory . '/' . $entry);
            }
        }

        return rmdir($directory);
    }
}

function updateOrchestratorWriteRelease(string $directory, string $version, string $marker): void
{
    file_put_contents($directory . '/.gitignore', "backup/\n", LOCK_EX);
    file_put_contents($directory . '/version.txt', $version . PHP_EOL, LOCK_EX);
    file_put_contents(
        $directory . '/version.json',
        json_encode(['version' => $version, 'min_php' => '8.0'], JSON_PRETTY_PRINT) . PHP_EOL,
        LOCK_EX
    );
    file_put_contents($directory . '/release-marker.txt', $marker . PHP_EOL, LOCK_EX);
}

function updateOrchestratorRun(array $command, string $cwd): string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('test command failed to start');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('test command failed: ' . implode(' ', $command) . ': ' . trim($stderr ?: $stdout));
    }

    return $stdout;
}

function updateOrchestratorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function updateOrchestratorRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . '/' . $entry;
        if (is_dir($child) && !is_link($child)) {
            updateOrchestratorRemoveTree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}
