<?php

$root = dirname(__DIR__, 2);
$runner = file_get_contents($root . '/scripts/run_sync_reliability_pack.sh');
$harness = file_get_contents($root . '/tools/e2e_mock_online_offline_sync.php');

syncReliabilityIsolationAssert(is_string($runner) && $runner !== '', 'sync reliability runner must be readable');
syncReliabilityIsolationAssert(is_string($harness) && $harness !== '', 'sync outage harness must be readable');

foreach ([
    '127.0.0.1|localhost|mysql',
    'SYNC_RELIABILITY_PACK_LOCAL_DATABASE_REQUIRED',
    'POSMAIN_TEST_MYSQL_DB="posmain_sync_pack_forbidden_default"',
    'POSMAIN_DB_NAME="posmain_sync_pack_forbidden_default"',
    'SYNC_RELIABILITY_PACK_TEST_MISSING',
    'SYNC_RELIABILITY_PACK_TEST_FAILED',
    'SYNC_RELIABILITY_PACK_COVERAGE_SKIPPED',
    'SYNC_RELIABILITY_HARNESS_RESULT_INVALID',
    'SYNC_RELIABILITY_SCENARIO_NOT_GREEN',
] as $needle) {
    syncReliabilityIsolationAssert(
        strpos($runner, $needle) !== false,
        'runner must contain fail-closed control: ' . $needle
    );
}

preg_match_all('/^  (tests\\/sync\\/[^\\s]+\\.php)$/m', $runner, $matches);
$tests = array_values(array_unique($matches[1] ?? []));
syncReliabilityIsolationAssert(count($tests) === 8, 'sync reliability pack must contain the exact eight reviewed tests');

foreach ($tests as $test) {
    $source = file_get_contents($root . '/' . $test);
    syncReliabilityIsolationAssert(is_string($source) && $source !== '', 'pack test must be readable: ' . $test);
    if (strpos($source, 'new mysqli') === false) {
        continue;
    }
    syncReliabilityIsolationAssert(strpos($source, 'getmypid()') !== false, 'database test must generate process-scoped schemas: ' . $test);
    syncReliabilityIsolationAssert(strpos($source, 'CREATE DATABASE') !== false, 'database test must create a fixture schema: ' . $test);
    syncReliabilityIsolationAssert(strpos($source, 'DROP DATABASE IF EXISTS') !== false, 'database test must drop its fixture schema: ' . $test);
    syncReliabilityIsolationAssert(strpos($source, "?: 'kody2'") === false, 'database test must not fall back to kody2: ' . $test);
}

foreach ([
    'createE2eDisposableDatabase',
    'dropE2eDisposableDatabase($disposableDb)',
    "'posmain_sync_e2e_' . getmypid()",
    'SYNC_E2E_LOCAL_DATABASE_REQUIRED',
    'SYNC_E2E_DISPOSABLE_DATABASE_REQUIRED',
] as $needle) {
    syncReliabilityIsolationAssert(
        strpos($harness, $needle) !== false,
        'outage harness must contain whole-database isolation control: ' . $needle
    );
}
syncReliabilityIsolationAssert(
    strpos($harness, "getenv('POSMAIN_TEST_MYSQL_DB') ?: 'kody2'") === false,
    'outage harness must never fall back to the standing kody2 database'
);

echo 'sync-reliability-pack-isolation-ok tests=' . count($tests) . "\n";

function syncReliabilityIsolationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
