<?php

$root = dirname(__DIR__, 2);
$runner = (string) file_get_contents($root . '/scripts/run_stage2_pre_certification_pack.sh');

stage2IsolationAssert($runner !== '', 'Stage 2 runner must be readable');

foreach ([
    '127.0.0.1|localhost|mysql',
    'STAGE2_PACK_LOCAL_DATABASE_REQUIRED',
    'POSMAIN_TEST_MYSQL_DB="posmain_stage2_pack_forbidden_default"',
    'POSMAIN_DB_NAME="posmain_stage2_pack_forbidden_default"',
    'STAGE2_PACK_TEST_MISSING',
    'STAGE2_PACK_TEST_FAILED',
    'STAGE2_PACK_COVERAGE_SKIPPED',
] as $needle) {
    stage2IsolationAssert(
        strpos($runner, $needle) !== false,
        'Stage 2 runner must contain fail-closed control: ' . $needle
    );
}

foreach ([
    'tests/sync/inventory_quantity_only_runtime_test.php',
    'tests/sync/inventory_moving_average_concurrency_runtime_test.php',
    'tests/sync/recipe_inventory_kernel_contract_test.php',
    'tests/sync/recipe_sale_refund_reversal_truth_table_runtime_test.php',
    'tests/sync/inventory_phase12_accounting_service_test.php',
    'tests/sync/inventory_phase15_cutover_service_test.php',
    'tests/sync/inventory_reconciliation_repair_service_test.php',
] as $mandatoryTest) {
    stage2IsolationAssert(
        strpos($runner, $mandatoryTest) !== false,
        'Stage 2 pack must contain mandatory proof: ' . $mandatoryTest
    );
}

preg_match_all('/^  (tests\\/sync\\/[A-Za-z0-9_]+_test\\.php)$/m', $runner, $matches);
$tests = array_values(array_unique($matches[1] ?? []));
stage2IsolationAssert(count($tests) >= 35, 'Stage 2 pack must contain the complete reviewed matrix');

$databaseTests = 0;
foreach ($tests as $relativePath) {
    $path = $root . '/' . $relativePath;
    stage2IsolationAssert(is_file($path), 'Stage 2 pack test must exist: ' . $relativePath);
    $source = (string) file_get_contents($path);
    stage2IsolationAssert($source !== '', 'Stage 2 pack test must be readable: ' . $relativePath);
    stage2IsolationAssert(
        strpos($source, "?: 'kody2'") === false
            && strpos($source, '?? \'kody2\'') === false
            && strpos($source, '"kody2"') === false,
        'Stage 2 pack test must not name the standing shop database: ' . $relativePath
    );

    if (strpos($source, 'CREATE DATABASE') === false) {
        continue;
    }
    $databaseTests++;
    stage2IsolationAssert(
        strpos($source, 'getmypid()') !== false,
        'database test must use a process-scoped fixture: ' . $relativePath
    );
    stage2IsolationAssert(
        strpos($source, 'DROP DATABASE IF EXISTS') !== false,
        'database test must clean its generated fixture: ' . $relativePath
    );
}

stage2IsolationAssert($databaseTests >= 25, 'expected the full disposable-database Stage 2 matrix');

echo 'stage2-pre-certification-pack-isolation-ok tests=' . count($tests)
    . ' database_tests=' . $databaseTests . "\n";

function stage2IsolationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
