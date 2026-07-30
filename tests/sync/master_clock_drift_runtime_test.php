<?php

require_once __DIR__ . '/../../classes/Sync/BranchCloudSyncPollWorker.php';

function masterClockRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$worker = new BranchCloudSyncPollWorker();
$method = new ReflectionMethod(BranchCloudSyncPollWorker::class, 'cloudClockError');

$current = $method->invoke($worker, [
    'server_time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
]);
masterClockRuntimeAssert($current === null, 'current authenticated server time must be accepted');

$drifted = $method->invoke($worker, [
    'server_time_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 61),
]);
masterClockRuntimeAssert(
    $drifted === 'cloud server clock drift exceeds 60 seconds',
    'server drift beyond 60 seconds must be held before event application'
);

$missing = $method->invoke($worker, []);
masterClockRuntimeAssert(
    $missing === 'cloud server clock attestation missing or invalid',
    'missing server clock attestation must fail closed'
);

echo "master-clock-drift-runtime-ok\n";
