<?php

require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../classes/Sync/BranchPairingService.php';
require_once __DIR__ . '/../classes/Sync/CloudBranchRegistryService.php';
require_once __DIR__ . '/../classes/Sync/PairingStatusService.php';
require_once __DIR__ . '/../classes/Sync/SyncWorkerHealthService.php';
require_once __DIR__ . '/../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../classes/Sync/SyncHttpClient.php';
require_once __DIR__ . '/../classes/Sync/SyncRuntimeCrypto.php';
require_once __DIR__ . '/../classes/Sync/SyncRuntimeDbConfigFile.php';
require_once __DIR__ . '/../classes/Sync/SyncRuntimeSettings.php';
require_once __DIR__ . '/../classes/Sync/BranchRestoreFromHostedService.php';
require_once __DIR__ . '/../classes/Sync/BranchCatalogPushService.php';
require_once __DIR__ . '/../classes/Router/ShopRouter.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('Invalid request method.');
    }
    require_csrf('sync_credentials');

    if (function_exists('session_write_close')) {
        session_write_close();
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save_moova') {
        require_admin_or_permission('moova.manage', $conn);
    } else {
        require_admin_or_permission('system.tools.run', $conn);
    }

    switch ($action) {
        case 'generate_uuid':
            syncCredentialsJson(['ok' => true, 'uuid' => SyncBranchIdentity::generateUuidV4()]);
            break;

        case 'generate_secret':
            syncCredentialsJson(['ok' => true, 'secret' => syncCredentialsGenerateSecret()]);
            break;

        case 'generate_config_key':
            syncCredentialsJson(['ok' => true, 'key' => SyncRuntimeCrypto::generateKeyMaterial()]);
            break;

        case 'test_db':
            syncCredentialsJson((new SyncRuntimeDbConfigFile())->testDatabase(syncCredentialsDbInput($_POST)));
            break;

        case 'export_hosted_env':
            syncCredentialsJson([
                'ok' => true,
                'env_block' => (new SyncRuntimeDbConfigFile())->exportEnv(syncCredentialsDbInput($_POST)),
            ]);
            break;

        case 'save_local':
            $dbConfigDirty = !empty($_POST['POSMAIN_DB_CONFIG_DIRTY']);
            $db = syncCredentialsDbInput($_POST, true);
            $savedKeyPath = syncCredentialsSaveEncryptionKey($_POST);
            if ($dbConfigDirty || !empty($_POST['POSMAIN_BRANCH_SYNC_SECRET_DIRTY'])) {
                syncCredentialsRequireEncryption();
            }
            if ($dbConfigDirty) {
                $dbTest = (new SyncRuntimeDbConfigFile())->testDatabase($db);
                if (empty($dbTest['ok'])) {
                    throw new InvalidArgumentException('Settings cannot be saved before the database connection test succeeds: ' . ($dbTest['message'] ?? ''));
                }
                $targetConn = syncCredentialsConnectToTargetDb($db);
                (new SyncRuntimeSettings())->save($targetConn, syncCredentialsSettingsInput($_POST, 'branch'));
                $targetConn->close();
                (new SyncRuntimeDbConfigFile())->save($db);
            } else {
                (new SyncRuntimeSettings())->save($conn, syncCredentialsSettingsInput($_POST, 'branch'));
            }
            $pairingResult = null;
            if (syncCredentialsShouldPairLocal($_POST)) {
                $pairingResult = (new BranchPairingService())->pairLocal($conn, $appConfig, $_POST);
            }
            syncCredentialsAudit($conn, 'sync_credentials_local_saved', ['role' => 'branch']);
            syncCredentialsJson([
                'ok' => true,
                'message' => 'Local sync settings were saved successfully.',
                'runtime_config_file' => SyncRuntimeDbConfigFile::defaultPath(),
                'config_key_file' => $savedKeyPath,
                'pairing' => $pairingResult,
            ]);
            break;

        case 'save_cloud':
            $savedKeyPath = syncCredentialsSaveEncryptionKey($_POST);
            (new SyncRuntimeSettings())->save($conn, syncCredentialsSettingsInput($_POST, 'cloud'));
            syncCredentialsAudit($conn, 'sync_credentials_cloud_saved', ['role' => 'cloud']);
            syncCredentialsJson([
                'ok' => true,
                'message' => 'Hosted sync settings were saved successfully.',
                'config_key_file' => $savedKeyPath,
            ]);
            break;

        case 'save_moova':
            (new SyncRuntimeSettings())->savePartial($conn, syncCredentialsSettingsInput($_POST, 'current'), [
                'POSMAIN_MOOVA_POLLER_ENABLED',
                'POSMAIN_MOOVA_APPLY_ENABLED',
            ]);
            syncCredentialsAudit($conn, 'sync_credentials_moova_saved', ['section' => 'moova']);
            syncCredentialsJson([
                'ok' => true,
                'message' => 'Moova sync settings were saved successfully.',
            ]);
            break;

        case 'register_cloud_branch':
        case 'pair_hosted_branch':
            syncCredentialsSaveEncryptionKey($_POST);
            syncCredentialsRequireEncryption();
            $pairing = new BranchPairingService();
            $result = $pairing->pairHosted($conn, $appConfig, array_merge($_POST, [
                'cloud_base_url' => syncCredentialsCloudBaseUrl($_POST),
            ]));
            syncCredentialsAudit($conn, 'sync_branch_paired_hosted', [
                'branch_uuid' => $result['branch_uuid'],
                'identity_source' => $result['identity_source'],
            ]);
            $registry = new CloudBranchRegistryService();
            syncCredentialsJson([
                'ok' => true,
                'message' => 'Branch was paired on the hosted POS.',
                'branch' => syncCredentialsPublicBranch($result),
                'local_env_block' => $registry->envBlock([
                    'POSMAIN_BRANCH_UUID' => $result['branch_uuid'],
                    'POSMAIN_BRANCH_SYNC_SECRET' => (string) ($_POST['branch_secret'] ?? $_POST['POSMAIN_BRANCH_SYNC_SECRET'] ?? ''),
                    'POSMAIN_CLOUD_BASE_URL' => syncCredentialsCloudBaseUrl($_POST),
                ]),
                'branches' => $result['branches'],
                'pairing' => $result,
            ]);
            break;

        case 'pair_local_branch':
            syncCredentialsJson([
                'ok' => true,
                'pairing' => (new BranchPairingService())->pairLocal($conn, $appConfig, $_POST),
            ]);
            break;

        case 'test_pairing':
            syncCredentialsJson([
                'ok' => true,
                'pairing_test' => (new BranchPairingService())->testPairing($_POST, $appConfig, $conn),
            ]);
            break;

        case 'pairing_status':
            $branchUuid = syncCredentialsResolveHostedBranchUuid($conn, $appConfig, $_POST);
            $role = strtolower(trim((string) ($appConfig['role'] ?? 'branch')));
            if ($role === 'cloud') {
                syncCredentialsJson([
                    'ok' => true,
                    'dashboard' => (new PairingStatusService())->hostedDashboard($conn, $appConfig, $branchUuid),
                ]);
            } else {
                syncCredentialsJson([
                    'ok' => true,
                    'dashboard' => (new PairingStatusService())->localDashboard($conn, $appConfig, $_POST),
                ]);
            }
            break;

        case 'worker_status':
            syncCredentialsJson([
                'ok' => true,
                'worker' => (new SyncWorkerHealthService())->report($conn, $appConfig),
            ]);
            break;

        case 'test_cloud':
            syncCredentialsJson(syncCredentialsTestCloud($_POST));
            break;

        case 'restore_from_hosted':
            syncCredentialsJson([
                'ok' => true,
                'restore' => (new BranchRestoreFromHostedService())->restore(
                    $conn,
                    syncCredentialsBranchRuntimeConfig($appConfig, $_POST),
                    [
                        'apply' => !empty($_POST['apply']),
                        'limit' => isset($_POST['limit']) ? max(1, (int) $_POST['limit']) : 50,
                    ]
                ),
            ]);
            break;

        case 'push_catalog_to_hosted':
        case 'push_supported_data_to_hosted':
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            $push = (new BranchCatalogPushService())->pushToHosted(
                $conn,
                syncCredentialsBranchRuntimeConfig($appConfig, $_POST),
                [
                    'catalog' => true,
                    'tables' => true,
                    'orders' => true,
                    'operational' => true,
                    'drain_outbox' => true,
                ]
            );
            syncCredentialsAudit($conn, 'sync_supported_data_pushed_to_hosted', [
                'branch_uuid' => $push['branch_uuid'] ?? '',
                'queued' => $push['queue']['queued'] ?? 0,
                'synced' => $push['dispatch']['synced'] ?? 0,
                'pending_outbox' => $push['pending_outbox'] ?? 0,
            ]);
            syncCredentialsJson([
                'ok' => true,
                'message' => 'Supported data sync finished.',
                'push' => $push,
            ]);
            break;

        case 'push_supported_data_plan':
            syncCredentialsJson([
                'ok' => true,
                'plan' => (new BranchCatalogPushService())->planPushToHosted($conn, [
                    'catalog' => true,
                    'tables' => true,
                    'orders' => true,
                    'operational' => true,
                ]),
            ]);
            break;

        case 'push_supported_data_phase':
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            $phase = trim((string) ($_POST['push_phase'] ?? ''));
            $phaseResult = (new BranchCatalogPushService())->runPushPhase(
                $conn,
                syncCredentialsBranchRuntimeConfig($appConfig, $_POST),
                $phase,
                [
                    'catalog' => true,
                    'tables' => true,
                    'orders' => true,
                    'operational' => true,
                ]
            );
            syncCredentialsJson([
                'ok' => true,
                'phase' => $phaseResult['phase'] ?? $phase,
                'queue' => $phaseResult['queue'] ?? [],
            ]);
            break;

        case 'push_supported_data_dispatch':
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            $dispatchResult = (new BranchCatalogPushService())->runPushDispatchBatch(
                $conn,
                syncCredentialsBranchRuntimeConfig($appConfig, $_POST),
                ['batch_size' => 50]
            );
            syncCredentialsJson([
                'ok' => true,
                'dispatch' => $dispatchResult['dispatch'] ?? [],
                'pending_outbox' => $dispatchResult['pending_outbox'] ?? 0,
                'done' => !empty($dispatchResult['done']),
            ]);
            break;

        default:
            throw new InvalidArgumentException('Unknown sync credentials action.');
    }
} catch (Throwable $e) {
    $message = function_exists('posmain_safe_exception_message')
        ? posmain_safe_exception_message($e, 'An error occurred while saving sync settings')
        : $e->getMessage();
    syncCredentialsJson([
        'ok' => false,
        'message' => $message,
    ], $e instanceof InvalidArgumentException ? 422 : 500);
}

function syncCredentialsJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function syncCredentialsRequireEncryption(): void
{
    if (!(new SyncRuntimeCrypto())->available()) {
        throw new RuntimeException(SyncRuntimeCrypto::ENV_KEY . ' is required before saving sync credentials from the UI.');
    }
}

function syncCredentialsGenerateSecret(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function syncCredentialsSaveEncryptionKey(array $input): ?string
{
    if (!array_key_exists(SyncRuntimeCrypto::ENV_KEY, $input)) {
        return null;
    }

    $key = trim((string) $input[SyncRuntimeCrypto::ENV_KEY]);
    if ($key === '') {
        return null;
    }

    return (new SyncRuntimeCrypto())->saveKeyMaterial($key);
}

function syncCredentialsDbInput(array $input, bool $preserveBlankPassword = false): array
{
    global $appConfig;

    $pass = (string) ($input['db_pass'] ?? '');
    if ($preserveBlankPassword && $pass === '' && isset($appConfig['database']['pass'])) {
        $pass = (string) $appConfig['database']['pass'];
    }

    return [
        'host' => trim((string) ($input['db_host'] ?? '')),
        'port' => (int) ($input['db_port'] ?? 3306),
        'name' => trim((string) ($input['db_name'] ?? '')),
        'user' => trim((string) ($input['db_user'] ?? '')),
        'pass' => $pass,
        'charset' => trim((string) ($input['db_charset'] ?? 'utf8mb4')),
    ];
}

function syncCredentialsSettingsInput(array $input, string $role): array
{
    $branchSecretDirty = !empty($input['POSMAIN_BRANCH_SYNC_SECRET_DIRTY']);
    $keys = [
        'POSMAIN_BRANCH_UUID',
        'POSMAIN_CLOUD_BASE_URL',
        'POSMAIN_BRANCH_SYNC_SECRET',
        'POSMAIN_SYNC_OUTBOX_ENABLED',
        'POSMAIN_BRANCH_SYNC_ENABLED',
        'POSMAIN_SYNC_WORKER_ENABLED',
        'POSMAIN_MENU_SYNC_ENABLED',
        'POSMAIN_CLOUD_APPLY_ENABLED',
        'POSMAIN_CLOUD_PULL_ENABLED',
        'POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED',
        'POSMAIN_MOOVA_POLLER_ENABLED',
        'POSMAIN_MOOVA_APPLY_ENABLED',
    ];

    $settings = ['role' => $role];
    foreach ($keys as $key) {
        if ($key === 'POSMAIN_BRANCH_SYNC_SECRET' && !$branchSecretDirty) {
            continue;
        }
        if (array_key_exists($key, $input)) {
            $settings[$key] = $input[$key];
        }
    }

    return $settings;
}

function syncCredentialsConnectToTargetDb(array $db): mysqli
{
    $targetConn = new mysqli(
        (string) $db['host'],
        (string) $db['user'],
        (string) $db['pass'],
        (string) $db['name'],
        (int) $db['port']
    );
    $targetConn->set_charset((string) ($db['charset'] ?: 'utf8mb4'));

    return $targetConn;
}

function syncCredentialsCloudBaseUrl(array $input): string
{
    $baseUrl = rtrim(trim((string) ($input['cloud_base_url'] ?? $input['POSMAIN_CLOUD_BASE_URL'] ?? '')), '/');
    if ($baseUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $baseUrl = $host === '' ? '' : $scheme . '://' . $host;
    }
    if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
        throw new InvalidArgumentException('Hosted POS URL is required and must start with http:// or https://.');
    }

    return $baseUrl;
}

function syncCredentialsTestCloud(array $input): array
{
    $cloudBaseUrl = rtrim(trim((string) ($input['cloud_base_url'] ?? $input['POSMAIN_CLOUD_BASE_URL'] ?? '')), '/');
    $branchUuid = trim((string) ($input['branch_uuid'] ?? $input['POSMAIN_BRANCH_UUID'] ?? ''));
    $secret = (string) ($input['branch_secret'] ?? $input['POSMAIN_BRANCH_SYNC_SECRET'] ?? '');
    if ($cloudBaseUrl === '' || $branchUuid === '' || $secret === '') {
        throw new InvalidArgumentException('Cloud URL, branch UUID, and sync secret are required to test the cloud connection.');
    }

    $body = json_encode([
        'schema_version' => 1,
        'branch_uuid' => $branchUuid,
        'sent_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'batch_uuid' => SyncBranchIdentity::generateUuidV4(),
        'events' => [],
    ], JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('Unable to prepare the cloud connection test.');
    }

    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(12));
    $headers = [
        'Content-Type: application/json',
        'X-POSMAIN-Branch-UUID: ' . $branchUuid,
        'X-POSMAIN-Timestamp: ' . $timestamp,
        'X-POSMAIN-Nonce: ' . $nonce,
        'X-POSMAIN-Signature: ' . CloudAuthService::sign($secret, $timestamp, $nonce, $body),
    ];

    $response = (new SyncHttpClient())->postJson(
        $cloudBaseUrl . '/api/sync/receive_branch_events.php',
        $body,
        $headers,
        1500,
        5000
    );

    return [
        'ok' => !empty($response['ok']),
        'message' => !empty($response['ok']) ? 'Cloud connection succeeded.' : 'Cloud connection test failed.',
        'http_status' => $response['status'] ?? 0,
        'response' => $response['json'] ?? null,
        'error' => $response['error'] ?? '',
    ];
}

function syncCredentialsAudit(mysqli $conn, string $eventType, array $metadata): void
{
    try {
        (new SecurityAuditLogger())->record($conn, $eventType, [
            'target_type' => 'sync_credentials',
            'metadata' => $metadata,
        ]);
    } catch (Throwable $e) {
        error_log('Sync credential audit skipped: ' . $e->getMessage());
    }
}

function syncCredentialsPublicBranch(array $branch): array
{
    unset($branch['branch_env'], $branch['router_route'], $branch['legacy_cloud_branch'], $branch['branches'], $branch['identity_source']);
    return $branch;
}

function syncCredentialsResolveHostedBranchUuid(mysqli $conn, array $config, array $input): string
{
    $branchUuid = strtolower(trim((string) ($input['branch_uuid'] ?? $input['POSMAIN_BRANCH_UUID'] ?? '')));
    if (SyncBranchIdentity::isUuid($branchUuid)) {
        return $branchUuid;
    }

    if (!function_exists('posmain_router_enabled') || !posmain_router_enabled($config)) {
        return '';
    }

    $routerConn = posmain_router_db_connect($config);
    try {
        $router = new PosmainShopRouter();
        $shopId = PosmainShopRouter::activeSessionShopId();
        if ($shopId < 1) {
            $dbName = trim((string) ($config['database']['name'] ?? ''));
            if ($dbName === '') {
                $row = $conn->query('SELECT DATABASE() AS db_name')?->fetch_assoc();
                $dbName = trim((string) ($row['db_name'] ?? ''));
            }
            $shop = $dbName !== '' ? $router->findShopByDatabaseName($routerConn, $dbName) : null;
            $shopId = $shop ? (int) $shop['id'] : 0;
        }
        if ($shopId < 1) {
            return '';
        }

        $stmt = $routerConn->prepare("
            SELECT branch_uuid
            FROM router_branch_routes
            WHERE shop_id = ?
              AND status = 'active'
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $shopId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return strtolower(trim((string) ($row['branch_uuid'] ?? '')));
    } finally {
        $routerConn->close();
    }
}

function syncCredentialsShouldPairLocal(array $input): bool
{
    $branchUuid = trim((string) ($input['POSMAIN_BRANCH_UUID'] ?? $input['branch_uuid'] ?? ''));
    $cloudBaseUrl = trim((string) ($input['POSMAIN_CLOUD_BASE_URL'] ?? $input['cloud_base_url'] ?? ''));
    $secret = (string) ($input['POSMAIN_BRANCH_SYNC_SECRET'] ?? $input['branch_secret'] ?? '');
    $secretDirty = !empty($input['POSMAIN_BRANCH_SYNC_SECRET_DIRTY']);

    if ($branchUuid === '' || $cloudBaseUrl === '') {
        return false;
    }

    return $secret !== '' || $secretDirty;
}

function syncCredentialsBranchRuntimeConfig(array $config, array $input): array
{
    $secret = (string) ($input['branch_secret'] ?? $input['POSMAIN_BRANCH_SYNC_SECRET'] ?? '');
    $cloudBaseUrl = rtrim(trim((string) ($input['cloud_base_url'] ?? $input['POSMAIN_CLOUD_BASE_URL'] ?? '')), '/');
    $branchUuid = strtolower(trim((string) ($input['branch_uuid'] ?? $input['POSMAIN_BRANCH_UUID'] ?? '')));

    if ($secret !== '') {
        $config['sync']['branch_secret'] = $secret;
    }
    if ($cloudBaseUrl !== '') {
        $config['branch']['cloud_base_url'] = $cloudBaseUrl;
    }
    if ($branchUuid !== '') {
        $config['branch']['uuid'] = $branchUuid;
    }

    return $config;
}
