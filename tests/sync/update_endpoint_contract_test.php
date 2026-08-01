<?php

require_once __DIR__ . '/../../api/admin/updates/_bootstrap.php';

$root = realpath(__DIR__ . '/../..');
updateEndpointAssert($root !== false, 'repo root should resolve');

$startSource = updateEndpointSource('api/admin/updates/start.php');
$statusSource = updateEndpointSource('api/admin/updates/status.php');
$checkSource = updateEndpointSource('api/admin/updates/check.php');
$bootstrapSource = updateEndpointSource('api/admin/updates/_bootstrap.php');

foreach ([
    "verify_csrf_from_post_or_header('system_update')" => $startSource,
    'posmainUpdateRequireAdmin()' => $startSource . $checkSource,
    'current_user_id()' => $startSource,
    'posmainDispatchUpdateWorker' => $startSource,
    'posmainUpdateAvailability' => $bootstrapSource . $checkSource,
    'posmainUpdatePrivilegedGitSyncState' => $bootstrapSource,
    'POSMAIN_UPDATE_GIT_CHECK_WRAPPER' => $bootstrapSource,
    "'system.tools.run'" => $bootstrapSource,
    'auth_guard_is_admin_session' => $bootstrapSource,
    'auth_guard_session_has_permission' => $bootstrapSource,
    'PosmainUpdateJobStore' => $startSource . $statusSource,
    'markDispatchFailed' => $startSource,
    "'update_worker_dispatch_failed'" => $startSource,
    'backup_cleanup' => updateEndpointSource('classes/Updates/UpdateOrchestrator.php'),
    'posix_get_last_error() === 1' => updateEndpointSource('classes/Updates/UpdateJobStore.php'),
] as $snippet => $source) {
    updateEndpointAssert(strpos($source, $snippet) !== false, 'missing update endpoint contract snippet: ' . $snippet);
}

foreach (['tools/run_migrations.php', 'tools/backup_database.php', 'git pull', 'systemctl', 'php-fpm'] as $forbidden) {
    updateEndpointAssert(strpos($startSource, $forbidden) === false, 'start endpoint should not run update step inline: ' . $forbidden);
}

$deployFiles = [
    'deploy/production/posmain-update-check.sh',
    'deploy/production/posmain-update-worker.sh',
    'deploy/production/posmain-update-recovery-worker.sh',
    'deploy/production/posmain-update-runtime-reload.sh',
];
foreach ($deployFiles as $deployFile) {
    $deployPath = $root . '/' . $deployFile;
    updateEndpointAssert(is_file($deployPath), 'missing updater deployment wrapper: ' . $deployFile);
    $syntaxOutput = [];
    $syntaxExit = 1;
    exec('/bin/bash -n ' . escapeshellarg($deployPath), $syntaxOutput, $syntaxExit);
    updateEndpointAssert($syntaxExit === 0, 'invalid updater deployment wrapper: ' . $deployFile);
}

$sudoersSource = updateEndpointSource('deploy/production/sudoers-posmain-update.example');
foreach ([
    '/usr/local/bin/posmain-update-check ""',
    '/usr/local/bin/posmain-update-worker *',
    '/usr/local/bin/posmain-update-recovery-worker *',
    '/usr/local/bin/posmain-update-runtime-reload ""',
] as $sudoersRule) {
    updateEndpointAssert(
        strpos($sudoersSource, $sudoersRule) !== false,
        'missing updater sudoers rule: ' . $sudoersRule
    );
}

putenv('POSMAIN_UPDATE_RUN_AS=posmain');
putenv('POSMAIN_UPDATE_WORKER_WRAPPER=/definitely/missing/posmain-update-worker');
putenv('POSMAIN_UPDATE_RECOVERY_WORKER_WRAPPER=/definitely/missing/posmain-update-recovery-worker');
try {
    updateEndpointAssert(
        posmainUpdateWorkerDispatchCommand('upd_20260730_120000_abcdef') === null,
        'configured run-as update dispatch must fail closed when its wrapper is missing'
    );
    updateEndpointAssert(
        posmainUpdateRecoveryWorkerDispatchCommand('upd_20260730_120000_abcdef') === null,
        'configured run-as recovery dispatch must fail closed when its wrapper is missing'
    );
} finally {
    putenv('POSMAIN_UPDATE_RUN_AS');
    putenv('POSMAIN_UPDATE_WORKER_WRAPPER');
    putenv('POSMAIN_UPDATE_RECOVERY_WORKER_WRAPPER');
}

$tmpDir = sys_get_temp_dir() . '/posmain_update_jobs_' . bin2hex(random_bytes(4));
$store = new PosmainUpdateJobStore($tmpDir);
$job = $store->create([
    'action' => 'apply',
    'target_version' => '1.6.0',
    'requested_by_user_id' => 42,
]);

$lockMode = fileperms($tmpDir . '/update.lock');
$jobMode = fileperms($tmpDir . '/' . $job['id'] . '.json');
updateEndpointAssert(
    is_int($lockMode) && ($lockMode & 0777) === 0644,
    'update lock must remain readable across the web and deploy users'
);
updateEndpointAssert(
    is_int($jobMode) && ($jobMode & 0777) === 0644,
    'update jobs must remain readable across the web and deploy users'
);

updateEndpointAssert(preg_match('/^upd_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', (string) $job['id']) === 1, 'job id should be bounded and predictable');
updateEndpointAssert($job['status'] === 'queued', 'new update job should be queued');
updateEndpointAssert($job['target_version'] === '1.6.0', 'target version should be recorded');
updateEndpointAssert($job['requested_by_user_id'] === 42, 'actor id should be recorded');

$loaded = $store->find((string) $job['id']);
updateEndpointAssert(is_array($loaded), 'stored job should be readable');
updateEndpointAssert($loaded['id'] === $job['id'], 'stored job id should match');

$active = $store->activeJob();
updateEndpointAssert(is_array($active) && $active['id'] === $job['id'], 'queued job should be active');

try {
    $store->create(['action' => 'apply']);
    updateEndpointAssert(false, 'second active update should be rejected');
} catch (RuntimeException $e) {
    updateEndpointAssert(strpos($e->getMessage(), 'UPDATE_ALREADY_RUNNING:') === 0, 'second active update should report active lock');
}

$store->markDispatching((string) $job['id']);
$claimed = $store->claim((string) $job['id'], 12345);
updateEndpointAssert($claimed['status'] === 'running', 'worker must atomically claim a starting job');
updateEndpointAssert($claimed['worker_pid'] === 12345, 'claimed job must record worker pid');
updateEndpointAssert(strlen((string) $claimed['claim_token']) === 32, 'claim must record an execution token');
try {
    $store->claim((string) $job['id'], 54321);
    updateEndpointAssert(false, 'a second worker must not claim the same job');
} catch (RuntimeException $e) {
    updateEndpointAssert(
        $e->getMessage() === 'UPDATE_JOB_NOT_CLAIMABLE:running',
        'duplicate claim must report the running state'
    );
}
$heartbeat = $store->heartbeat((string) $job['id']);
updateEndpointAssert($heartbeat['heartbeat_at_utc'] !== '', 'running worker must refresh its heartbeat');
$store->mutate((string) $job['id'], static function (array $current): array {
    $current['status'] = 'completed';
    return $current;
});

$liveWorkerJob = $store->create(['action' => 'apply']);
$store->mutate((string) $liveWorkerJob['id'], static function (array $current): array {
    $current['status'] = 'running';
    $current['phase'] = 'database_migrations';
    $current['worker_pid'] = getmypid();
    $current['heartbeat_at_utc'] = '2000-01-01T00:00:00Z';
    return $current;
});
updateEndpointAssert(
    $store->expireStaleJobs() === [],
    'a live worker process must not be recovered merely because a long migration has an old heartbeat'
);
$store->mutate((string) $liveWorkerJob['id'], static function (array $current): array {
    $current['status'] = 'completed';
    return $current;
});

$dispatchFailure = $store->create(['action' => 'apply']);
$store->markDispatching((string) $dispatchFailure['id']);
$dispatchFailure = $store->markDispatchFailed((string) $dispatchFailure['id'], 'simulated dispatch failure');
updateEndpointAssert($dispatchFailure['status'] === 'failed', 'dispatch failure must be terminal');
updateEndpointAssert($store->activeJob() === null, 'dispatch failure must release the single-flight lock');

$staleJob = $store->create(['action' => 'apply']);
$store->mutate((string) $staleJob['id'], static function (array $current): array {
    $current['heartbeat_at_utc'] = '2000-01-01T00:00:00Z';
    return $current;
});
$expired = $store->expireStaleJobs();
updateEndpointAssert($expired === [$staleJob['id']], 'stale active job must be terminalized');
updateEndpointAssert($store->find((string) $staleJob['id'])['status'] === 'failed', 'expired job must remain diagnosable');

$recoverableJob = $store->create(['action' => 'apply']);
$store->mutate((string) $recoverableJob['id'], static function (array $current): array {
    $current['status'] = 'running';
    $current['phase'] = 'database_migrations';
    $current['backup_set'] = ['directory' => '/verified/test-backup'];
    $current['heartbeat_at_utc'] = '2000-01-01T00:00:00Z';
    return $current;
});
$expired = $store->expireStaleJobs();
$recoverableJob = $store->find((string) $recoverableJob['id']);
updateEndpointAssert($expired === [$recoverableJob['id']], 'stale mutating job must be detected');
updateEndpointAssert($recoverableJob['status'] === 'recovery_required', 'stale mutating job must block new updates until recovery');
$recoverableJob = $store->markRecoveryDispatching((string) $recoverableJob['id']);
$recoverableJob = $store->claimRecovery((string) $recoverableJob['id'], 67890);
updateEndpointAssert($recoverableJob['status'] === 'recovering', 'recovery worker must atomically claim stale job');
updateEndpointAssert($recoverableJob['recovery_worker_pid'] === 67890, 'recovery claim must record worker pid');

updateEndpointRmDir($tmpDir);
echo "update-endpoint-contract-ok\n";

function updateEndpointSource(string $path): string
{
    $source = file_get_contents(__DIR__ . '/../../' . $path);
    updateEndpointAssert(is_string($source), 'unable to read ' . $path);

    return $source;
}

function updateEndpointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function updateEndpointRmDir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . '/' . $entry;
        if (is_dir($child)) {
            updateEndpointRmDir($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
}
