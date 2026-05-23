<?php

$setting = file_get_contents(__DIR__ . '/../../setting.php');
$ajax = file_get_contents(__DIR__ . '/../../ajax/sync_credentials.php');
$config = file_get_contents(__DIR__ . '/../../config/app_config.php');
$runtimeSettings = file_get_contents(__DIR__ . '/../../classes/Sync/SyncRuntimeSettings.php');
$moovaIntegration = file_get_contents(__DIR__ . '/../../moova_integration.php');

syncCredentialsUiAssert(is_string($setting), 'setting.php should be readable');
syncCredentialsUiAssert(is_string($ajax), 'ajax/sync_credentials.php should be readable');
syncCredentialsUiAssert(is_string($config), 'config/app_config.php should be readable');
syncCredentialsUiAssert(is_string($runtimeSettings), 'SyncRuntimeSettings.php should be readable');
syncCredentialsUiAssert(is_string($moovaIntegration), 'moova_integration.php should be readable');

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
    'Show database password',
    'Show hosted branch sync secret',
    'القيمة الحالية من env/.env',
    'قاعدة بيانات هذه النسخة',
    'خاص بالنسخة المحلية: هوية الفرع والاتصال بالسحابة',
    'خاص بالنسخة المستضافة: قيم بيئة التشغيل',
    'خاص بالنسخة المستضافة: سلوك المزامنة السحابية',
    'خاص بالنسخة المستضافة: الفرع المحلي المسموح له بالمزامنة',
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
    'Enable local branch sync',
    'Advanced settings',
    'Advanced local sync settings',
    'js-local-sync-master',
    'js-local-sync-core-toggle',
    'js-toggle-local-sync-advanced',
    'local-sync-advanced-settings',
    'Save current instance settings',
    'Build hosting env values',
    'Save hosted-only settings',
    'سلوك المزامنة السحابية',
    'Enable hosted sync',
    'js-hosted-sync-master',
    'js-hosted-sync-core-toggle',
    'setHostedSyncCoreToggles(hostedSyncMaster.checked)',
    'Save allowed branch on hosted POS',
    'Copy local settings',
    'قاعدة بيانات هذه النسخة',
    'name="db_host"',
    'name="db_port"',
    'name="db_name"',
    'name="db_user"',
    'name="db_pass"',
    'name="db_charset"',
    '<label>Generated hosting env block',
    'POSMAIN_SYNC_OUTBOX_ENABLED',
    'POSMAIN_BRANCH_SYNC_ENABLED',
    'POSMAIN_SYNC_WORKER_ENABLED',
    'POSMAIN_MENU_SYNC_ENABLED',
] as $snippet) {
    syncCredentialsUiAssert(strpos($setting, $snippet) !== false, 'setting.php missing sync UI snippet: ' . $snippet);
}

syncCredentialsUiAssert(strpos($setting, '<form id="sync-') === false, 'sync UI must not nest forms inside the existing settings save form');
syncCredentialsUiAssert(strpos($setting, 'id="sync-settings-tabs"') === false, 'sync UI should use one integrated panel instead of local/hosted tabs');
syncCredentialsUiAssert(strpos($setting, 'id="sync-cloud-pane"') === false, 'hosted sync controls should not be hidden behind a separate tab');
syncCredentialsUiAssert(strpos($setting, 'name="db_pass" value="<?= htmlspecialchars((string)($syncDb[\'pass\'] ?? \'\')') !== false, 'database password should be available to the eye reveal when loaded by the server');
syncCredentialsUiAssert(strpos($setting, '#sync-branch-register-form [name=\'POSMAIN_BRANCH_UUID\']') !== false, 'hosted allowed branch form should expose POSMAIN_BRANCH_UUID directly');
syncCredentialsUiAssert(strpos($setting, '#sync-branch-register-form [name=\'POSMAIN_BRANCH_SYNC_SECRET\']') !== false, 'hosted allowed branch form should expose POSMAIN_BRANCH_SYNC_SECRET directly');
syncCredentialsUiAssert(strpos($setting, 'setLocalSyncCoreToggles(localSyncMaster.checked)') !== false, 'local branch sync master should toggle core sync settings together');
syncCredentialsUiAssert(strpos($setting, 'localSyncAdvanced.style.display') !== false, 'advanced local sync settings should stay hidden until opened');
syncCredentialsUiAssert(strpos($setting, 'Generate sync secret') === false, 'local sync secret field should not show a generate button');
syncCredentialsUiAssert(strpos($setting, 'Poll Moova events from cloud') === false, 'Moova poller control should move out of sync settings');
syncCredentialsUiAssert(strpos($setting, 'Apply Moova through worker') === false, 'Moova apply control should move out of sync settings');
foreach ([
    'name="db_host" placeholder',
    'name="db_port" type="number" value="3306" placeholder',
    'name="db_name" placeholder',
    'name="db_user" placeholder',
    'name="db_pass" type="password" placeholder',
    'name="db_charset" value="utf8mb4" placeholder',
    'id="hosted-env-output" rows="7" readonly placeholder',
    'id="sync-hosted-db-form"',
] as $snippet) {
    syncCredentialsUiAssert(strpos($setting, $snippet) === false, 'sync DB credential fields should use the integrated current-instance panel: ' . $snippet);
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
    "require_admin_or_permission(\$action === 'save_moova' ? 'moova.manage' : 'system.tools.run', \$conn)",
    "require_csrf('sync_credentials')",
    'SyncRuntimeCrypto::ENV_KEY',
    "\$GLOBALS['appConfig']['sync']['branch_secret']",
    "\$_POST['branch_uuid'] ?? \$_POST['POSMAIN_BRANCH_UUID']",
    "\$_POST['branch_secret'] ?? \$_POST['POSMAIN_BRANCH_SYNC_SECRET']",
    'register_cloud_branch',
    'export_hosted_env',
    'test_cloud',
    'save_moova',
    'Cloud connection succeeded.',
    'Cloud connection test failed.',
    'Local sync settings were saved successfully.',
    'Hosted sync settings were saved successfully.',
    'Moova sync settings were saved successfully.',
    'SecurityAuditLogger',
] as $snippet) {
    syncCredentialsUiAssert(strpos($ajax, $snippet) !== false, 'sync AJAX endpoint missing snippet: ' . $snippet);
}

syncCredentialsUiAssert(strpos($runtimeSettings, 'function savePartial') !== false, 'SyncRuntimeSettings should support partial saves for Moova switches');
syncCredentialsUiAssert(strpos($runtimeSettings, "'POSMAIN_ROLE' =>") !== false, 'full sync saves should keep role handling');
syncCredentialsUiAssert(strpos($runtimeSettings, 'savePartial') < strpos($runtimeSettings, 'fetchConfigOverrides'), 'partial save should stay separate from config loading');

foreach ([
    'إعدادات مزامنة Moova',
    'moovaSyncSettingsForm',
    'action" value="save_moova"',
    'POSMAIN_MOOVA_POLLER_ENABLED',
    'POSMAIN_MOOVA_APPLY_ENABLED',
    "\$moovaSyncBool('POSMAIN_MOOVA_POLLER_ENABLED', true)",
    "\$moovaSyncBool('POSMAIN_MOOVA_APPLY_ENABLED', true)",
    'Poll Moova events from cloud',
    'Apply Moova through worker',
    'ajax/sync_credentials.php',
] as $snippet) {
    syncCredentialsUiAssert(strpos($moovaIntegration, $snippet) !== false, 'moova_integration.php missing Moova sync setting snippet: ' . $snippet);
}
syncCredentialsUiAssert(strpos($moovaIntegration, "\$appConfig['sync']") === false, 'Moova UI defaults should not be forced off by appConfig defaults');

foreach ([
    'SyncRuntimeDbConfigFile',
    'SyncRuntimeSettings',
    'posmain_runtime_file_database_overrides',
    'posmain_runtime_db_settings_overrides',
    "\$branchEnv(['POSMAIN_ENABLE_MOOVA_QUEUED_APPLY', 'POSMAIN_MOOVA_QUEUED_APPLY_ENABLED'], null),\n            true",
    "\$branchEnv(['POSMAIN_MOOVA_APPLY_ENABLED', 'POSMAIN_ENABLE_MOOVA_QUEUED_APPLY'], null),\n            true",
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
