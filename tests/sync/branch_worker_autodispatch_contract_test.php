<?php

require_once __DIR__ . '/../../classes/Sync/BranchWorkerAutoDispatcher.php';

$connectSource = file_get_contents(__DIR__ . '/../../includes/connect.php');
$dispatcherSource = file_get_contents(__DIR__ . '/../../classes/Sync/BranchWorkerAutoDispatcher.php');
$healthSource = file_get_contents(__DIR__ . '/../../classes/Sync/SyncWorkerHealthService.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(strpos($connectSource, 'BranchWorkerAutoDispatcher::maybeDispatchFromWebRequest') !== false, 'connect should auto-dispatch branch worker on web requests');
$assert(strpos($dispatcherSource, 'register_shutdown_function') !== false, 'auto dispatcher should run after the web response');
$assert(strpos($dispatcherSource, '--once --only=') !== false, 'auto dispatcher should spawn one daemon cycle');
$assert(strpos($dispatcherSource, 'POSMAIN_BRANCH_WORKER_AUTODISPATCH') !== false, 'auto dispatcher should support env disable flag');

$branchConfig = [
    'role' => 'branch',
    'branch' => [
        'uuid' => '43333333-3333-4333-8333-333333333333',
        'cloud_base_url' => 'https://erp.withmoova.com',
    ],
    'sync' => [
        'worker_enabled' => true,
        'branch_sync_enabled' => true,
        'outbox_enabled' => true,
        'branch_secret' => 'secret',
    ],
];

$cloudConfig = [
    'role' => 'cloud',
    'branch' => ['uuid' => '43333333-3333-4333-8333-333333333333'],
    'sync' => ['worker_enabled' => true, 'branch_sync_enabled' => true, 'outbox_enabled' => true, 'branch_secret' => 'secret'],
];

$assert(BranchWorkerAutoDispatcher::shouldAutoDispatch($branchConfig), 'branch config with sync settings should auto-dispatch');
$assert(!BranchWorkerAutoDispatcher::shouldAutoDispatch($cloudConfig), 'hosted cloud role should not auto-dispatch');
$assert(BranchWorkerAutoDispatcher::enabledJobs($branchConfig) === ['sync_outbox'], 'default branch auto-dispatch should include sync_outbox');

$pullConfig = $branchConfig;
$pullConfig['sync']['cloud_pull_enabled'] = true;
$assert(in_array('cloud_sync_poller', BranchWorkerAutoDispatcher::enabledJobs($pullConfig), true), 'cloud pull should add cloud_sync_poller job');

$assert(strpos($healthSource, 'auto_dispatch_enabled') !== false, 'worker health should report auto-dispatch state');

echo "branch-worker-autodispatch-contract-ok\n";
