<?php

$setting = file_get_contents(__DIR__ . '/../../setting.php');
$ajax = file_get_contents(__DIR__ . '/../../ajax/sync_credentials.php');
$config = file_get_contents(__DIR__ . '/../../config/app_config.php');
$crypto = file_get_contents(__DIR__ . '/../../classes/Sync/SyncRuntimeCrypto.php');
$runtimeSettings = file_get_contents(__DIR__ . '/../../classes/Sync/SyncRuntimeSettings.php');
$moovaIntegration = file_get_contents(__DIR__ . '/../../moova_integration.php');

syncCredentialsUiAssert(is_string($setting), 'setting.php should be readable');
syncCredentialsUiAssert(is_string($ajax), 'ajax/sync_credentials.php should be readable');
syncCredentialsUiAssert(is_string($config), 'config/app_config.php should be readable');
syncCredentialsUiAssert(is_string($crypto), 'SyncRuntimeCrypto.php should be readable');
syncCredentialsUiAssert(is_string($runtimeSettings), 'SyncRuntimeSettings.php should be readable');
syncCredentialsUiAssert(is_string($moovaIntegration), 'moova_integration.php should be readable');

foreach ([
    'إعدادات المزامنة',
    'data-sync-role="<?= htmlspecialchars($syncRole',
    '$syncIsHosted = $syncRole === \'cloud\'',
    '$syncIsLocal = $syncRole === \'branch\'',
    "csrf_input('sync_credentials', 'sync_csrf_token')",
    'input[name="sync_csrf_token"]',
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
    'Show sync secret',
    'القيمة الحالية من env/.env',
    'Shared data',
    'Local data',
    'Hosted data',
    'Debug',
    'البيانات المشتركة بين المحلي والمستضاف',
    'sync-section-shared',
    'sync-section-local',
    'sync-section-hosted',
    'sync-section-debug',
    'rgba(23, 162, 184, 0.10)',
    'rgba(40, 167, 69, 0.10)',
    'rgba(0, 123, 255, 0.10)',
    'rgba(108, 117, 125, 0.10)',
    'font-size: 1rem',
    'font-weight: 800',
    'color: rgba(33, 37, 41, 0.86)',
    'قاعدة بيانات هذه النسخة',
    'الاتصال بالسحابة',
    'sync-shared-identity-fields',
    'syncSharedIdentityPayload',
    'syncDebugPayload',
    'initialDebugSignature',
    'POSMAIN_DB_CONFIG_DIRTY',
    'initialBranchSyncSecretValue',
    'POSMAIN_BRANCH_SYNC_SECRET_DIRTY',
    'sync-debug-panel',
    'sync-debug-form',
    'js-toggle-sync-debug',
    'data-form="#sync-debug-form"',
    'js-generate-uuid',
    'data-confirm-if-filled="1"',
    'Regenerating the branch UUID will replace the current branch identity.',
    'js-generate-secret',
    'js-sync-test-db',
    'js-sync-test-cloud',
    'POSMAIN_BRANCH_UUID',
    'POSMAIN_CLOUD_BASE_URL',
    'POSMAIN_BRANCH_SYNC_SECRET',
    'POSMAIN_CONFIG_ENCRYPTION_KEY',
    'sync-config-encryption-fields',
    'js-generate-config-key',
    'syncConfigKeyPayload',
    'generate_config_key',
    'Generate key',
    'Show encryption key',
    'Regenerating the encryption key can make previously encrypted database passwords and sync secrets unreadable',
    'Generate secret',
    'Test database connection',
    'Test cloud connection',
    'Sync all data to hosted',
    'js-sync-push-data',
    'push_supported_data_plan',
    'push_supported_data_phase',
    'push_supported_data_dispatch',
    'formatSyncProgressMessage',
    'runSupportedDataPushWithProgress',
    'Enable local branch sync',
    'Advanced settings',
    'Advanced local sync settings',
    'js-local-sync-master',
    'sync-switch-lg',
    'js-local-sync-core-toggle',
    'js-toggle-local-sync-advanced',
    'local-sync-advanced-settings',
    'settings-global-save-result',
    'ajaxSyncPromise',
    'initialLocalSyncSignature',
    'initialCloudSyncSignature',
    'submittingAfterSyncSave',
    'syncRuntimeRole',
    'js-register-hosted-branch',
    'Register branch on hosted POS',
    "syncRuntimeRole === 'branch'",
    "syncRuntimeRole === 'cloud'",
    'Sync changes saved. Saving page settings...',
    'Build hosting env values',
    'Enable hosted sync',
    'js-hosted-sync-master',
    'js-hosted-sync-core-toggle',
    'setHostedSyncCoreToggles(hostedSyncMaster.checked)',
    "branch_status: 'active'",
    'Branch was paired on the hosted POS.',
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
    'sync-status-panel',
    'js-refresh-sync-status',
    'loadSyncStatusPanel',
    'renderSyncStatusPanel',
    'pairing_status',
    'provision_new_shop',
    'provision_shop_slug',
    '$syncRouterEnabled',
    'Pairing &amp; sync health',
] as $snippet) {
    syncCredentialsUiAssert(strpos($setting, $snippet) !== false, 'setting.php missing sync UI snippet: ' . $snippet);
}

syncCredentialsUiAssert(strpos($setting, '<form id="sync-') === false, 'sync UI must not nest forms inside the existing settings save form');
syncCredentialsUiAssert(strpos($setting, 'id="sync-settings-tabs"') === false, 'sync UI should use one integrated panel instead of local/hosted tabs');
syncCredentialsUiAssert(strpos($setting, 'id="sync-cloud-pane"') === false, 'hosted sync controls should not be hidden behind a separate tab');
syncCredentialsUiAssert(strpos($setting, 'name="db_pass" value="<?= htmlspecialchars((string)($syncDb[\'pass\'] ?? \'\')') !== false, 'database password should be available to the eye reveal when loaded by the server');
syncCredentialsUiAssert(strpos($setting, '#sync-shared-section [name="POSMAIN_BRANCH_UUID"]') !== false, 'shared payload should include POSMAIN_BRANCH_UUID');
syncCredentialsUiAssert(strpos($setting, '#sync-shared-section [name="POSMAIN_BRANCH_SYNC_SECRET"]') !== false, 'shared payload should include POSMAIN_BRANCH_SYNC_SECRET');
syncCredentialsUiAssert(strpos($setting, '#sync-branch-register-form [name=\'POSMAIN_BRANCH_UUID\']') === false, 'hosted allowed branch form should use the shared POSMAIN_BRANCH_UUID field');
syncCredentialsUiAssert(strpos($setting, '#sync-branch-register-form [name=\'POSMAIN_BRANCH_SYNC_SECRET\']') === false, 'hosted allowed branch form should use the shared POSMAIN_BRANCH_SYNC_SECRET field');
syncCredentialsUiAssert(strpos($setting, 'id="sync-branch-register-form"') === false, 'hosted branch registration uses the shared identity fields and explicit register button');
syncCredentialsUiAssert(strpos($setting, 'name="cloud_base_url"') === false, 'hosted allowed branch form should reuse POSMAIN_CLOUD_BASE_URL instead of showing a duplicate URL field');
syncCredentialsUiAssert(strpos($setting, "syncSharedIdentityPayload(),") !== false, 'hosted branch registration should read shared identity values');
syncCredentialsUiAssert(strpos($setting, "Object.assign(payload, syncConfigKeyPayload())") !== false, 'hosted save/register actions should include the config encryption key');
syncCredentialsUiAssert(strpos($setting, '#sync-local-section [name="POSMAIN_CLOUD_BASE_URL"]') !== false, 'hosted branch registration should include the existing POSMAIN_CLOUD_BASE_URL value');
syncCredentialsUiAssert(strpos($setting, "Object.assign(payload, debugPayload)") !== false, 'local save should include hidden debug database credentials');
syncCredentialsUiAssert(strpos($setting, "querySelector('input[name=\"csrf_token\"]')") === false, 'sync AJAX token lookup must not collide with the main settings CSRF token');
syncCredentialsUiAssert(strpos($setting, '<?php if ($syncIsLocal): ?>') !== false, 'local-only sync section should render only on branch/local role');
syncCredentialsUiAssert(strpos($setting, '<?php if ($syncIsHosted): ?>') !== false, 'hosted-only sync section should render only on cloud/hosted role');
syncCredentialsUiAssert(strpos($setting, '<div id="sync-debug-panel" class="mt-3" style="display:none;">') !== false, 'debug database tools should be minimized by default');
syncCredentialsUiAssert(strpos($setting, 'setLocalSyncCoreToggles(localSyncMaster.checked)') !== false, 'local branch sync master should toggle core sync settings together');
syncCredentialsUiAssert(strpos($setting, 'persistLocalSyncToggles') !== false, 'local branch sync toggles should auto-save when changed');
syncCredentialsUiAssert(strpos($setting, 'save_local_sync_toggles') !== false, 'local branch sync toggles should use a dedicated save action');
syncCredentialsUiAssert(strpos($ajax, 'save_local_sync_toggles') !== false, 'sync credentials ajax should support saving local sync toggles without full identity validation');
syncCredentialsUiAssert(strpos($ajax, 'localBranchSyncToggleKeys') !== false, 'local save should persist sync toggles before full identity save');
syncCredentialsUiAssert(strpos($runtimeSettings, 'localBranchSyncToggleKeys') !== false, 'SyncRuntimeSettings should expose local branch sync toggle keys');
syncCredentialsUiAssert(strpos($setting, 'localSyncAdvanced.style.display') !== false, 'advanced local sync settings should stay hidden until opened');
syncCredentialsUiAssert(strpos($setting, 'custom-switch sync-switch-lg mb-0') !== false, 'local sync switch should use the large shared switch style');
syncCredentialsUiAssert(substr_count($setting, 'custom-switch sync-switch-lg mb-0') >= 2, 'local and hosted sync switches should use the same large switch style');
syncCredentialsUiAssert(strpos($setting, 'sync-switch-hosted') === false, 'hosted sync should not have a separate switch size style');
syncCredentialsUiAssert(strpos($setting, 'js-save-sync') === false, 'sync settings should be saved by the global page save button');
syncCredentialsUiAssert(strpos($setting, 'Save current instance settings') === false, 'local sync should not have a dedicated save button');
syncCredentialsUiAssert(strpos($setting, 'Save hosted-only settings') === false, 'hosted sync should not have a dedicated save button');
syncCredentialsUiAssert(strpos($setting, 'settingsMainForm.addEventListener(\'submit\'') !== false, 'global save should intercept sync changes before normal submit');
foreach ([
    'قيم تحتاجها النسختان أو يمكن استخدامها في أي نسخة تعمل الآن.',
    'مفتاح تشفير أسرار هذه النسخة',
    'هذا المفتاح خاص بهذه النسخة فقط، ويستخدم فعلياً لتشفير وفك تشفير كلمات المرور وأسرار المزامنة المحفوظة من الواجهة.',
    'نفس الـ UUID والسر يجب أن يتطابقا بين الفرع المحلي والنسخة المستضافة.',
    'مشترك: هوية مزامنة الفرع',
    'يشغّل الإعدادات المطلوبة لإرسال بيانات هذا الفرع إلى النسخة المستضافة.',
    'بيانات النسخة المحلية',
    'كل ما يحتاجه الفرع المحلي حتى يرسل بياناته إلى النسخة المستضافة.',
    'الفرع المحلي المسموح له بالمزامنة',
    'Save allowed branch on hosted POS',
    'Copy local settings',
    'js-register-cloud-branch',
    'js-copy-local-install-env',
    'local-install-env-output',
] as $removedText) {
    syncCredentialsUiAssert(strpos($setting, $removedText) === false, 'shared section should not show redundant text: ' . $removedText);
}
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
    "if (\$action === 'save_moova')",
    "require_admin_or_permission('moova.manage', \$conn)",
    "require_admin_or_permission('system.tools.run', \$conn)",
    "require_csrf('sync_credentials')",
    'POSMAIN_DB_CONFIG_DIRTY',
    'POSMAIN_BRANCH_SYNC_SECRET_DIRTY',
    "\$branchSecretDirty = !empty(\$input['POSMAIN_BRANCH_SYNC_SECRET_DIRTY'])",
    "if (\$key === 'POSMAIN_BRANCH_SYNC_SECRET' && !\$branchSecretDirty)",
    'SyncRuntimeCrypto::ENV_KEY',
    'syncCredentialsSaveEncryptionKey($_POST)',
    'SyncRuntimeCrypto::generateKeyMaterial()',
    'array_merge($_POST',
    'BranchPairingService',
    'pair_hosted_branch',
    'register_cloud_branch',
    'pair_local_branch',
    'test_pairing',
    'pairing_status',
    'worker_status',
    'PairingStatusService',
    'SyncWorkerHealthService',
    'syncCredentialsShouldPairLocal',
    'export_hosted_env',
    'test_cloud',
    'push_supported_data_to_hosted',
    'BranchCatalogPushService',
    'save_moova',
    'Cloud connection succeeded.',
    'Cloud connection test failed.',
    'Local sync settings were saved successfully.',
    'Hosted sync settings were saved successfully.',
    'Moova sync settings were saved successfully.',
    'Branch was paired on the hosted POS.',
    'SecurityAuditLogger',
] as $snippet) {
    syncCredentialsUiAssert(strpos($ajax, $snippet) !== false, 'sync AJAX endpoint missing snippet: ' . $snippet);
}

$saveCloudStart = strpos($ajax, "case 'save_cloud':");
$saveMoovaStart = strpos($ajax, "case 'save_moova':");
syncCredentialsUiAssert($saveCloudStart !== false && $saveMoovaStart !== false && $saveMoovaStart > $saveCloudStart, 'sync AJAX endpoint should keep a clear save_cloud block');
$saveCloudBlock = substr($ajax, $saveCloudStart, $saveMoovaStart - $saveCloudStart);
syncCredentialsUiAssert(strpos($saveCloudBlock, 'syncCredentialsRequireEncryption()') === false, 'hosted sync toggles should save without requiring an encryption key');

foreach ([
    "public const KEY_FILE_ENV = 'POSMAIN_CONFIG_ENCRYPTION_KEY_FILE'",
    'function saveKeyMaterial',
    'function currentKeyMaterial',
    'function generateKeyMaterial',
    '/var/posmain-config-encryption.key',
] as $snippet) {
    syncCredentialsUiAssert(strpos($crypto, $snippet) !== false, 'SyncRuntimeCrypto missing key-file snippet: ' . $snippet);
}

syncCredentialsUiAssert(strpos($runtimeSettings, 'function savePartial') !== false, 'SyncRuntimeSettings should support partial saves for Moova switches');
syncCredentialsUiAssert(strpos($runtimeSettings, "'POSMAIN_ROLE' =>") !== false, 'full sync saves should keep role handling');
syncCredentialsUiAssert(strpos($config, 'branchIdentityEnv') !== false, 'branch UUID/secret/cloud URL must not fall back to branch-worker env files');
syncCredentialsUiAssert(strpos($runtimeSettings, 'savePartial') < strpos($runtimeSettings, 'fetchConfigOverrides'), 'partial save should stay separate from config loading');

foreach ([
    'إعدادات مزامنة Moova',
    'moovaSyncSettingsForm',
    'action" value="save_moova"',
    'POSMAIN_MOOVA_POLLER_ENABLED',
    'POSMAIN_MOOVA_APPLY_ENABLED',
    "\$moovaSyncBool('POSMAIN_MOOVA_POLLER_ENABLED', true)",
    "\$moovaSyncBool('POSMAIN_MOOVA_APPLY_ENABLED', false)",
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
    "\$branchEnv(['POSMAIN_MOOVA_MODE'], 'direct_widget')",
    "\$branchEnv(['POSMAIN_MOOVA_APPLY_ENABLED'], null)",
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
