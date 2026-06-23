<?php

require_once __DIR__ . '/../../config/app_config.php';

$oldUuid = getenv('POSMAIN_BRANCH_UUID');
$oldPort = getenv('POSMAIN_TEST_UI_FALLBACK_PORT');
$oldBranchEnvFile = getenv('POSMAIN_BRANCH_WORKER_ENV_FILE');
$oldDisableRuntimeConfig = getenv('POSMAIN_DISABLE_UI_RUNTIME_CONFIG');
$oldCloudBaseUrl = getenv('POSMAIN_CLOUD_BASE_URL');
$oldBranchSecret = getenv('POSMAIN_BRANCH_SYNC_SECRET');
$oldMoovaMode = getenv('POSMAIN_MOOVA_MODE');
$oldMenuSync = getenv('POSMAIN_MENU_SYNC_ENABLED');
$oldDbPort = getenv('POSMAIN_DB_PORT');

$tmp = tempnam(sys_get_temp_dir(), 'posmain-env-fallback-');
if ($tmp === false) {
    fwrite(STDERR, "Unable to create temp env file\n");
    exit(1);
}

file_put_contents($tmp, implode("\n", [
    'POSMAIN_BRANCH_UUID=33333333-3333-4333-8333-333333333333',
    'POSMAIN_TEST_UI_FALLBACK_PORT=3317',
    'POSMAIN_EMPTY_UI_FALLBACK=',
    'POSMAIN_QUOTED_UI_FALLBACK="quoted value"',
    'POSMAIN_CLOUD_BASE_URL=https://cloud.example.test',
    'POSMAIN_BRANCH_SYNC_SECRET=branch-secret-from-file',
    'POSMAIN_MOOVA_MODE=direct_widget',
    'POSMAIN_MENU_SYNC_ENABLED=1',
    'POSMAIN_DB_PORT=9999',
]));

putenv('POSMAIN_BRANCH_UUID');
putenv('POSMAIN_TEST_UI_FALLBACK_PORT');

syncEnvFallbackAssert(
    posmain_first_env_or_file(['POSMAIN_BRANCH_UUID'], '', false, [$tmp]) === '33333333-3333-4333-8333-333333333333',
    'branch UUID should fall back to the env file'
);
syncEnvFallbackAssert(
    posmain_first_env_or_file(['POSMAIN_MISSING', 'POSMAIN_TEST_UI_FALLBACK_PORT'], '', false, [$tmp]) === '3317',
    'fallback should check all requested names'
);
syncEnvFallbackAssert(
    posmain_first_env_or_file(['POSMAIN_EMPTY_UI_FALLBACK'], 'fallback', false, [$tmp]) === 'fallback',
    'empty fallback values should be ignored unless allowed'
);
syncEnvFallbackAssert(
    posmain_first_env_or_file(['POSMAIN_EMPTY_UI_FALLBACK'], 'fallback', true, [$tmp]) === '',
    'empty fallback values should be returned when allowed'
);
syncEnvFallbackAssert(
    posmain_first_env_or_file(['POSMAIN_QUOTED_UI_FALLBACK'], '', false, [$tmp]) === 'quoted value',
    'quoted fallback values should be unwrapped'
);
syncEnvFallbackAssert(
    getenv('POSMAIN_TEST_UI_FALLBACK_PORT') === false,
    'reading the fallback file must not mutate process env'
);

foreach ([
    'POSMAIN_BRANCH_UUID',
    'POSMAIN_CLOUD_BASE_URL',
    'POSMAIN_BRANCH_SYNC_SECRET',
    'POSMAIN_MOOVA_MODE',
    'POSMAIN_MENU_SYNC_ENABLED',
    'POSMAIN_DB_PORT',
] as $key) {
    putenv($key);
    unset($_ENV[$key]);
}
putenv('POSMAIN_BRANCH_WORKER_ENV_FILE=' . $tmp);
putenv('POSMAIN_DISABLE_UI_RUNTIME_CONFIG=1');
$_ENV['POSMAIN_BRANCH_WORKER_ENV_FILE'] = $tmp;
$_ENV['POSMAIN_DISABLE_UI_RUNTIME_CONFIG'] = '1';

$config = posmain_app_config();
syncEnvFallbackAssert(
    $config['branch']['uuid'] === '',
    'branch UUID must not be loaded from branch-worker env files'
);
syncEnvFallbackAssert(
    $config['branch']['cloud_base_url'] === '',
    'cloud URL must not be loaded from branch-worker env files'
);
syncEnvFallbackAssert(
    $config['sync']['branch_secret'] === '',
    'branch secret must not be loaded from branch-worker env files'
);
syncEnvFallbackAssert(
    $config['moova']['mode'] === 'direct_widget' && $config['features']['moova_direct_apply'] === true,
    'web config should read Moova mode from branch env fallback'
);
syncEnvFallbackAssert(
    $config['sync']['menu_sync_enabled'] === true,
    'web config should read menu sync from branch env fallback'
);
syncEnvFallbackAssert(
    (int) $config['database']['port'] === 3306,
    'branch env DB port should not override the web container DB default'
);

putenv('POSMAIN_BRANCH_UUID=process-uuid');
$configWithProcessUuid = posmain_app_config();
syncEnvFallbackAssert(
    $configWithProcessUuid['branch']['uuid'] === 'process-uuid',
    'process env should still provide branch UUID when explicitly set'
);
syncEnvFallbackAssert(
    posmain_first_env_or_file(['POSMAIN_BRANCH_UUID'], '', false, [$tmp]) === 'process-uuid',
    'process env should win over file fallback'
);
putenv('POSMAIN_BRANCH_UUID');
unset($_ENV['POSMAIN_BRANCH_UUID']);

restoreSyncEnvFallbackTestEnv('POSMAIN_BRANCH_UUID', $oldUuid);
restoreSyncEnvFallbackTestEnv('POSMAIN_TEST_UI_FALLBACK_PORT', $oldPort);
restoreSyncEnvFallbackTestEnv('POSMAIN_BRANCH_WORKER_ENV_FILE', $oldBranchEnvFile);
restoreSyncEnvFallbackTestEnv('POSMAIN_DISABLE_UI_RUNTIME_CONFIG', $oldDisableRuntimeConfig);
restoreSyncEnvFallbackTestEnv('POSMAIN_CLOUD_BASE_URL', $oldCloudBaseUrl);
restoreSyncEnvFallbackTestEnv('POSMAIN_BRANCH_SYNC_SECRET', $oldBranchSecret);
restoreSyncEnvFallbackTestEnv('POSMAIN_MOOVA_MODE', $oldMoovaMode);
restoreSyncEnvFallbackTestEnv('POSMAIN_MENU_SYNC_ENABLED', $oldMenuSync);
restoreSyncEnvFallbackTestEnv('POSMAIN_DB_PORT', $oldDbPort);
putenv('POSMAIN_EMPTY_UI_FALLBACK');
putenv('POSMAIN_QUOTED_UI_FALLBACK');
@unlink($tmp);

echo "sync-env-file-fallback-ok\n";

function syncEnvFallbackAssert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, $message . "\n");
    exit(1);
}

function restoreSyncEnvFallbackTestEnv(string $name, $value): void
{
    if ($value === false) {
        putenv($name);
        return;
    }

    putenv($name . '=' . $value);
}
