<?php

$root = dirname(__DIR__, 2);
$runner = file_get_contents($root . '/scripts/run_commercial_lifecycle_pack.sh');

commercialLifecycleIsolationAssert(is_string($runner) && $runner !== '', 'commercial lifecycle runner must be readable');

foreach ([
    '127.0.0.1|localhost|mysql',
    'COMMERCIAL_LIFECYCLE_PACK_LOCAL_DATABASE_REQUIRED',
    'POSMAIN_TEST_MYSQL_DB="posmain_commercial_lifecycle_forbidden_default"',
    'POSMAIN_DB_NAME="posmain_commercial_lifecycle_forbidden_default"',
    'COMMERCIAL_LIFECYCLE_PACK_TEST_MISSING',
    'COMMERCIAL_LIFECYCLE_PACK_TEST_FAILED',
    'COMMERCIAL_LIFECYCLE_PACK_COVERAGE_SKIPPED',
] as $needle) {
    commercialLifecycleIsolationAssert(
        strpos($runner, $needle) !== false,
        'runner must contain fail-closed isolation control: ' . $needle
    );
}

preg_match_all('/^  (tests\\/sync\\/[^\\s]+\\.php)$/m', $runner, $matches);
$tests = array_values(array_unique($matches[1] ?? []));
commercialLifecycleIsolationAssert(count($tests) === 15, 'commercial lifecycle pack must contain the exact 15 reviewed tests');

$databaseTests = [];
foreach ($tests as $test) {
    $source = file_get_contents($root . '/' . $test);
    commercialLifecycleIsolationAssert(is_string($source) && $source !== '', 'pack test must be readable: ' . $test);

    if (strpos($source, 'new mysqli') === false) {
        continue;
    }

    $databaseTests[] = $test;
    commercialLifecycleIsolationAssert(
        strpos($source, 'getmypid()') !== false,
        'database test must generate a process-scoped schema: ' . $test
    );
    commercialLifecycleIsolationAssert(
        strpos($source, 'CREATE DATABASE') !== false && strpos($source, 'DROP DATABASE IF EXISTS') !== false,
        'database test must create and drop its complete fixture schema: ' . $test
    );
    commercialLifecycleIsolationAssert(
        strpos($source, "?: 'kody2'") === false,
        'database test must not fall back to kody2: ' . $test
    );
}

commercialLifecycleIsolationAssert(
    count($databaseTests) === 9,
    'commercial lifecycle pack database-test mapping changed; review isolation before accepting it'
);

echo 'commercial-lifecycle-pack-isolation-ok tests=' . count($tests)
    . ' database_tests=' . count($databaseTests) . "\n";

function commercialLifecycleIsolationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
