<?php

$setting = file_get_contents(__DIR__ . '/../../setting.php');
$ajax = file_get_contents(__DIR__ . '/../../ajax/sync_credentials.php');
$config = file_get_contents(__DIR__ . '/../../config/app_config.php');

syncCredentialsUiAssert(is_string($setting), 'setting.php should be readable');
syncCredentialsUiAssert(is_string($ajax), 'ajax/sync_credentials.php should be readable');
syncCredentialsUiAssert(is_string($config), 'config/app_config.php should be readable');

foreach ([
    'إعدادات المزامنة',
    "csrf_input('sync_credentials')",
    'find(\':input\').serializeArray()',
    'loadForUi($conn, true)',
    'syncEffectiveValue',
    'syncSecretEffectiveValue',
    'sync-source-hint',
    'js-sync-help',
    'data-content',
    'popover({',
    "trigger: 'manual'",
    'js-toggle-secret-visibility',
    'fa-eye',
    'القيمة الحالية من env/.env',
    'js-generate-uuid',
    'data-confirm-if-filled="1"',
    'Regenerating the branch UUID will replace the current branch identity.',
    'js-generate-secret',
    'js-sync-test-db',
    'js-sync-test-cloud',
    'js-register-cloud-branch',
    'js-copy-local-install-env',
    'POSMAIN_BRANCH_UUID',
    'POSMAIN_CLOUD_BASE_URL',
    'POSMAIN_BRANCH_SYNC_SECRET',
    'POSMAIN_CONFIG_ENCRYPTION_KEY',
    'Generate secret',
    'Test database connection',
    'Test cloud connection',
    'Save local settings',
    'Build hosting env values',
    'Save hosted settings',
    'Hosted sync behavior',
    'Apply branch events to cloud',
    'Allow cloud pull',
    'Publish events to branches',
    'Poll Moova events from cloud',
    'Apply Moova through worker',
    'Register branch on hosted POS',
    'Copy local settings',
    '<label>POSMAIN_DB_HOST',
    '<label>POSMAIN_DB_PORT',
    '<label>POSMAIN_DB_NAME',
    '<label>POSMAIN_DB_USER',
    '<label>POSMAIN_DB_PASS',
    '<label>POSMAIN_DB_CHARSET',
    '<label>Generated hosting env block',
    'POSMAIN_SYNC_OUTBOX_ENABLED',
    'POSMAIN_BRANCH_SYNC_ENABLED',
    'POSMAIN_SYNC_WORKER_ENABLED',
    'POSMAIN_MENU_SYNC_ENABLED',
] as $snippet) {
    syncCredentialsUiAssert(strpos($setting, $snippet) !== false, 'setting.php missing sync UI snippet: ' . $snippet);
}

syncCredentialsUiAssert(strpos($setting, '<form id="sync-') === false, 'sync UI must not nest forms inside the existing settings save form');
syncCredentialsUiAssert(strpos($setting, 'Generate sync secret') === false, 'local sync secret field should not show a generate button');
syncCredentialsUiAssert(strpos($setting, "'POSMAIN_MOOVA_POLLER_ENABLED' => ['Poll Moova events from cloud', true") !== false, 'hosted Moova poller should default on');
syncCredentialsUiAssert(strpos($setting, "'POSMAIN_MOOVA_APPLY_ENABLED' => ['Apply Moova through worker', false") !== false, 'hosted Moova apply should stay off by default');
foreach ([
    'name="db_host" placeholder',
    'name="db_port" type="number" value="3306" placeholder',
    'name="db_name" placeholder',
    'name="db_user" placeholder',
    'name="db_pass" type="password" placeholder',
    'name="db_charset" value="utf8mb4" placeholder',
    'id="hosted-env-output" rows="7" readonly placeholder',
] as $snippet) {
    syncCredentialsUiAssert(strpos($setting, $snippet) === false, 'hosted DB credential fields should use labels, not placeholders: ' . $snippet);
}

foreach ([
    'POSMAIN_BRANCH_NAME',
    'POSMAIN_POS_TENANT',
    'POSMAIN_POS_BRANCH',
    'POSMAIN_SYNC_STATUS_TOKEN',
] as $excluded) {
    syncCredentialsUiAssert(strpos($setting, $excluded) === false, 'optional field should not be mandatory in sync UI: ' . $excluded);
}

foreach ([
    "require_admin_or_permission('system.tools.run', \$conn)",
    "require_csrf('sync_credentials')",
    'SyncRuntimeCrypto::ENV_KEY',
    "\$GLOBALS['appConfig']['sync']['branch_secret']",
    'register_cloud_branch',
    'export_hosted_env',
    'test_cloud',
    'Cloud connection succeeded.',
    'Cloud connection test failed.',
    'Local sync settings were saved successfully.',
    'Hosted sync settings were saved successfully.',
    'SecurityAuditLogger',
] as $snippet) {
    syncCredentialsUiAssert(strpos($ajax, $snippet) !== false, 'sync AJAX endpoint missing snippet: ' . $snippet);
}

foreach ([
    'SyncRuntimeDbConfigFile',
    'SyncRuntimeSettings',
    'posmain_runtime_file_database_overrides',
    'posmain_runtime_db_settings_overrides',
] as $snippet) {
    syncCredentialsUiAssert(strpos($config, $snippet) !== false, 'app_config missing runtime override snippet: ' . $snippet);
}

echo "sync-credentials-ui-contract-ok\n";

function syncCredentialsUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
