<?php

require_once __DIR__ . '/../../config/app_config.php';

$oldUuid = getenv('POSMAIN_BRANCH_UUID');
$oldPort = getenv('POSMAIN_TEST_UI_FALLBACK_PORT');

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

putenv('POSMAIN_BRANCH_UUID=process-uuid');
syncEnvFallbackAssert(
    posmain_first_env_or_file(['POSMAIN_BRANCH_UUID'], '', false, [$tmp]) === 'process-uuid',
    'process env should win over file fallback'
);

restoreSyncEnvFallbackTestEnv('POSMAIN_BRANCH_UUID', $oldUuid);
restoreSyncEnvFallbackTestEnv('POSMAIN_TEST_UI_FALLBACK_PORT', $oldPort);
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
