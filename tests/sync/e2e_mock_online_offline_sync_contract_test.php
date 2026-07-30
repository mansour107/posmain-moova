<?php

$root = realpath(__DIR__ . '/../..');
e2eMockSyncContractAssert(is_string($root) && $root !== '', 'repository root must resolve');

$lines = [];
$code = 1;
exec('php ' . escapeshellarg($root . '/tools/e2e_mock_online_offline_sync.php') . ' --help', $lines, $code);
$help = implode("\n", $lines);
e2eMockSyncContractAssert($code === 0, $help);

foreach ([
    'two-mock-server online/offline sync proof',
    'online_cloud_down_first_attempt',
    'offline_branch_back_cloud_event_delivered_and_acked',
    'report_path',
    'generated disposable database',
] as $needle) {
    e2eMockSyncContractAssert(strpos($help, $needle) !== false, 'harness help missing: ' . $needle);
}

$source = e2eMockSyncContractSource($root, 'tools/e2e_mock_online_offline_sync.php');
$scenarios = [
    'cloud_receive_only',
    'cloud_shadow_apply',
    'cloud_live_apply',
    'online_cloud_down_first_attempt',
    'online_cloud_back_retries_failed_event',
    'branch_worker_crash_lock_expires_and_reclaims',
    'offline_branch_down_first_attempt',
    'offline_branch_back_cloud_event_delivered_and_acked',
];
foreach ($scenarios as $scenario) {
    e2eMockSyncContractAssert(strpos($source, $scenario) !== false, 'harness scenario missing: ' . $scenario);
}
foreach ([
    "cleanupRows(\$conn, 'e2e:')",
    'function cleanupRows',
    'sync_outbox WHERE idempotency_key LIKE',
    'cloud_moova_branch_events WHERE idempotency_key LIKE',
    'pcntl_fork',
    'stream_socket_server',
    'createE2eDisposableDatabase',
    'dropE2eDisposableDatabase($disposableDb)',
    "'posmain_sync_e2e_' . getmypid()",
    'SYNC_E2E_LOCAL_DATABASE_REQUIRED',
] as $needle) {
    e2eMockSyncContractAssert(strpos($source, $needle) !== false, 'harness contract missing: ' . $needle);
}
e2eMockSyncContractAssert(
    strpos($source, "getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2'") === false,
    'harness must never fall back to kody2'
);

$doc = e2eMockSyncContractSource($root, 'docs/online_offline_mock_e2e.md');
$readiness = e2eMockSyncContractSource($root, 'docs/branch_go_live_readiness.md');
foreach ([
    'POSMAIN_TEST_MYSQL_PORT=3307 php tools/e2e_mock_online_offline_sync.php',
    'mock cloud server',
    'mock branch server',
    'Production Boundary',
] as $needle) {
    e2eMockSyncContractAssert(strpos($doc, $needle) !== false, 'sync E2E doc missing: ' . $needle);
}
foreach ($scenarios as $scenario) {
    e2eMockSyncContractAssert(strpos($doc, $scenario) !== false, 'sync E2E doc scenario missing: ' . $scenario);
}
e2eMockSyncContractAssert(strpos($readiness, 'docs/online_offline_mock_e2e.md') !== false, 'readiness must link the sync E2E doc');
e2eMockSyncContractAssert(strpos($readiness, 'php tools/e2e_mock_online_offline_sync.php') !== false, 'readiness must include the sync E2E command');

echo "e2e-mock-online-offline-sync-contract-ok\n";

function e2eMockSyncContractSource(string $root, string $path): string
{
    $source = file_get_contents($root . '/' . $path);
    e2eMockSyncContractAssert(is_string($source), 'unable to read ' . $path);

    return $source;
}

function e2eMockSyncContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
