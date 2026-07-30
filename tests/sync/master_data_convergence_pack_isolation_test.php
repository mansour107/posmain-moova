<?php

$root = dirname(__DIR__, 2);
$runner = (string) file_get_contents($root . '/scripts/run_master_data_convergence_pack.sh');

function masterPackIsolationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([
    '127.0.0.1|localhost|mysql',
    'MASTER_DATA_PACK_LOCAL_DATABASE_REQUIRED',
    'MASTER_DATA_PACK_DISPOSABLE_DATABASE_REQUIRED',
    '^posmain_master_sync_[a-z0-9_]+$',
    'MASTER_DATA_PACK_TEST_MISSING',
    'MASTER_DATA_PACK_TEST_FAILED',
    'MASTER_DATA_PACK_COVERAGE_SKIPPED',
] as $needle) {
    masterPackIsolationAssert(
        strpos($runner, $needle) !== false,
        'master-data pack must contain fail-closed control: ' . $needle
    );
}

foreach ([
    'tests/sync/item_form_input_test.php',
    'tests/sync/item_unit_profile_builder_test.php',
    'tests/sync/branch_cloud_master_boundary_contract_test.php',
    'tests/sync/master_clock_drift_runtime_test.php',
    'tests/sync/recipe_editor_atomic_outbox_contract_test.php',
    'tests/sync/master_data_convergence_runtime_test.php',
    'tests/sync/recipe_editor_atomic_outbox_runtime_test.php',
] as $mandatoryTest) {
    masterPackIsolationAssert(
        strpos($runner, $mandatoryTest) !== false,
        'master-data pack must include mandatory proof: ' . $mandatoryTest
    );
    masterPackIsolationAssert(
        is_file($root . '/' . $mandatoryTest),
        'master-data pack test must exist: ' . $mandatoryTest
    );
}

masterPackIsolationAssert(
    strpos($runner, 'kody2') === false,
    'master-data pack must never default to or name the standing shop database'
);
masterPackIsolationAssert(
    strpos($runner, 'POSMAIN_MASTER_SYNC_TEST_DB:-') !== false,
    'master-data pack must require the caller to name its disposable database explicitly'
);

echo "master-data-convergence-pack-isolation-ok tests=7\n";
