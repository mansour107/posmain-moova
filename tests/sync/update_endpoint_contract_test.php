<?php

require_once __DIR__ . '/../../classes/Updates/UpdateJobStore.php';

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
    "'system.tools.run'" => $bootstrapSource,
    'auth_guard_is_admin_session' => $bootstrapSource,
    'auth_guard_session_has_permission' => $bootstrapSource,
    'PosmainUpdateJobStore' => $startSource . $statusSource,
] as $snippet => $source) {
    updateEndpointAssert(strpos($source, $snippet) !== false, 'missing update endpoint contract snippet: ' . $snippet);
}

foreach (['tools/run_migrations.php', 'tools/backup_database.php', 'git pull', 'systemctl', 'php-fpm'] as $forbidden) {
    updateEndpointAssert(strpos($startSource, $forbidden) === false, 'start endpoint should not run update step inline: ' . $forbidden);
}

$tmpDir = sys_get_temp_dir() . '/posmain_update_jobs_' . bin2hex(random_bytes(4));
$store = new PosmainUpdateJobStore($tmpDir);
$job = $store->create([
    'action' => 'apply',
    'target_version' => '1.6.0',
    'requested_by_user_id' => 42,
]);

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
