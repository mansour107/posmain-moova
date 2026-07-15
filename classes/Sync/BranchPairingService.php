<?php

require_once __DIR__ . '/../Router/ShopRouter.php';
require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/CloudAuthService.php';
require_once __DIR__ . '/CloudBranchRegistryService.php';
require_once __DIR__ . '/SchemaReadinessGuard.php';
require_once __DIR__ . '/SyncHttpClient.php';
require_once __DIR__ . '/SyncRuntimeCrypto.php';
require_once __DIR__ . '/SyncRuntimeSettings.php';
require_once __DIR__ . '/ShopProvisioningService.php';
require_once __DIR__ . '/PairingStatusService.php';
require_once __DIR__ . '/BranchRestoreFromHostedService.php';

class BranchPairingService
{
    private const HOSTED_SYNC_DEFAULTS = [
        'POSMAIN_ROLE' => 'cloud',
        'POSMAIN_CLOUD_APPLY_ENABLED' => '1',
        'POSMAIN_CLOUD_PULL_ENABLED' => '1',
        'POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED' => '1',
        'POSMAIN_SYNC_WORKER_ENABLED' => '1',
    ];

    private const LOCAL_SYNC_DEFAULTS = [
        'POSMAIN_ROLE' => 'branch',
        'POSMAIN_SYNC_OUTBOX_ENABLED' => '1',
        'POSMAIN_BRANCH_SYNC_ENABLED' => '1',
        'POSMAIN_SYNC_WORKER_ENABLED' => '1',
        'POSMAIN_CLOUD_PULL_ENABLED' => '1',
    ];

    public function pairHosted(mysqli $contextConn, array $config, array $input): array
    {
        $branchUuid = strtolower(trim((string) ($input['branch_uuid'] ?? $input['POSMAIN_BRANCH_UUID'] ?? '')));
        $secret = (string) ($input['secret'] ?? $input['branch_secret'] ?? $input['POSMAIN_BRANCH_SYNC_SECRET'] ?? '');
        if (!SyncBranchIdentity::isUuid($branchUuid)) {
            throw new InvalidArgumentException('Branch UUID must be a valid UUID.');
        }
        if ($secret === '') {
            throw new InvalidArgumentException('Branch sync secret is required for pairing.');
        }

        $crypto = new SyncRuntimeCrypto();
        if (!$crypto->available()) {
            throw new RuntimeException(SyncRuntimeCrypto::ENV_KEY . ' is required before pairing hosted branches.');
        }

        $routerEnabled = function_exists('posmain_router_enabled') && posmain_router_enabled($config);
        $shopConn = $contextConn;
        $route = null;
        $legacyRegistered = null;
        $provisionMeta = null;
        $hostedStatus = null;

        if ($routerEnabled) {
            $router = new PosmainShopRouter();
            $routerConn = posmain_router_db_connect($config);
            try {
                if (!empty($input['provision_new_shop']) || !empty($input['auto_provision_shop'])) {
                    $provisioned = (new ShopProvisioningService())->provision($routerConn, $config, $input);
                    $shopId = (int) $provisioned['shop_id'];
                    $provisionMeta = $provisioned;
                } else {
                    $shopId = $this->resolveShopId($router, $routerConn, $config, $input, $contextConn);
                }
                $shopConn = $router->connectShopById($routerConn, $shopId);
                (new SyncSchemaReadinessGuard())->assertReady($shopConn);
                $route = $router->pairBranchRoute($routerConn, [
                    'shop_id' => $shopId,
                    'branch_uuid' => $branchUuid,
                    'secret' => $secret,
                    'status' => (string) ($input['branch_status'] ?? $input['status'] ?? 'active'),
                    'require_encryption' => true,
                ]);
            } finally {
                $routerConn->close();
            }
        } else {
            (new SyncSchemaReadinessGuard())->assertReady($contextConn);
            $legacyRegistered = (new CloudBranchRegistryService())->register($contextConn, [
                'branch_uuid' => $branchUuid,
                'secret' => $secret,
                'name' => $input['name'] ?? $input['branch_name'] ?? null,
                'tenant' => $input['tenant'] ?? $input['pos_tenant'] ?? null,
                'branch' => $input['branch'] ?? $input['pos_branch'] ?? null,
                'status' => $input['branch_status'] ?? 'active',
                'cloud_base_url' => $input['cloud_base_url'] ?? $input['POSMAIN_CLOUD_BASE_URL'] ?? '',
                'require_encryption' => true,
                'replace_existing_branches' => !empty($input['replace_existing_branches']) || !empty($input['replace_previous_branches']),
            ]);
        }

        try {
            (new SyncRuntimeSettings())->savePartial($shopConn, self::HOSTED_SYNC_DEFAULTS, [
                'POSMAIN_CLOUD_APPLY_ENABLED',
                'POSMAIN_CLOUD_PULL_ENABLED',
                'POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED',
                'POSMAIN_SYNC_WORKER_ENABLED',
            ]);
            (new SyncRuntimeSettings())->save($shopConn, ['role' => 'cloud']);
            $hostedStatus = (new PairingStatusService())->hostedDashboard($shopConn, $config, $branchUuid);
        } finally {
            if ($shopConn !== $contextConn) {
                $shopConn->close();
            }
        }

        return [
            'branch_uuid' => $branchUuid,
            'status' => (string) ($route['status'] ?? $legacyRegistered['status'] ?? 'active'),
            'router_enabled' => $routerEnabled,
            'router_route' => $route,
            'provisioned_shop' => $provisionMeta,
            'legacy_cloud_branch' => $legacyRegistered,
            'identity_source' => $routerEnabled ? 'router_branch_routes' : 'cloud_branches',
            'branches' => $this->listHostedBranches($contextConn, $config),
            'hosted_status' => $hostedStatus,
        ];
    }

    public function pairLocal(mysqli $conn, array $config, array $input): array
    {
        $branchUuid = strtolower(trim((string) ($input['branch_uuid'] ?? $input['POSMAIN_BRANCH_UUID'] ?? '')));
        $secret = (string) ($input['secret'] ?? $input['branch_secret'] ?? $input['POSMAIN_BRANCH_SYNC_SECRET'] ?? '');
        $cloudBaseUrl = rtrim(trim((string) ($input['cloud_base_url'] ?? $input['POSMAIN_CLOUD_BASE_URL'] ?? '')), '/');

        if (!SyncBranchIdentity::isUuid($branchUuid)) {
            throw new InvalidArgumentException('Branch UUID must be a valid UUID.');
        }
        if ($secret === '') {
            throw new InvalidArgumentException('Branch sync secret is required for pairing.');
        }
        if ($cloudBaseUrl === '' || !preg_match('#^https?://#i', $cloudBaseUrl)) {
            throw new InvalidArgumentException('Cloud base URL must start with http:// or https://.');
        }

        (new SyncSchemaReadinessGuard())->assertReady($conn);

        if ((new SyncRuntimeCrypto())->available()) {
            (new SyncRuntimeSettings())->save($conn, array_merge(self::LOCAL_SYNC_DEFAULTS, [
                'role' => 'branch',
                'POSMAIN_BRANCH_UUID' => $branchUuid,
                'POSMAIN_CLOUD_BASE_URL' => $cloudBaseUrl,
                'POSMAIN_BRANCH_SYNC_SECRET' => $secret,
            ]));
        } else {
            (new SyncRuntimeSettings())->save($conn, array_merge(self::LOCAL_SYNC_DEFAULTS, [
                'role' => 'branch',
                'POSMAIN_BRANCH_UUID' => $branchUuid,
                'POSMAIN_CLOUD_BASE_URL' => $cloudBaseUrl,
                'POSMAIN_BRANCH_SYNC_SECRET_EXTERNAL' => '1',
            ]));
        }

        (new SyncBranchIdentity())->ensure($conn, [
            'branch' => [
                'uuid' => $branchUuid,
                'cloud_base_url' => $cloudBaseUrl,
            ],
        ]);
        $dashboard = (new PairingStatusService())->localDashboard($conn, $config, [
            'branch_uuid' => $branchUuid,
            'branch_secret' => $secret,
            'cloud_base_url' => $cloudBaseUrl,
        ]);

        $restoreFromHosted = null;
        $autoRestore = !array_key_exists('auto_restore_from_hosted', $input) || !empty($input['auto_restore_from_hosted']);
        if ($autoRestore && !empty($dashboard['pairing_ok']) && BranchRestoreFromHostedService::localNeedsRestore($conn)) {
            try {
                $restoreFromHosted = (new BranchRestoreFromHostedService())->restore($conn, array_merge($config, [
                    'branch' => array_merge((array) ($config['branch'] ?? []), [
                        'uuid' => $branchUuid,
                        'cloud_base_url' => $cloudBaseUrl,
                    ]),
                    'sync' => array_merge((array) ($config['sync'] ?? []), [
                        'branch_secret' => $secret,
                    ]),
                ]), ['apply' => true]);
            } catch (Throwable $e) {
                $restoreFromHosted = [
                    'apply' => true,
                    'failed' => 1,
                    'errors' => [['message' => $e->getMessage()]],
                ];
            }
        }

        return [
            'branch_uuid' => $branchUuid,
            'cloud_base_url' => $cloudBaseUrl,
            'pairing_test' => [
                'ok' => !empty($dashboard['pairing_ok']),
                'message' => (string) ($dashboard['pairing_message'] ?? ''),
                'remote' => $dashboard['remote'] ?? null,
            ],
            'dashboard' => $dashboard,
            'restore_from_hosted' => $restoreFromHosted,
        ];
    }

    public function testPairing(array $input, array $config = [], ?mysqli $conn = null): array
    {
        $cloudBaseUrl = rtrim(trim((string) ($input['cloud_base_url'] ?? $input['POSMAIN_CLOUD_BASE_URL'] ?? '')), '/');
        $branchUuid = strtolower(trim((string) ($input['branch_uuid'] ?? $input['POSMAIN_BRANCH_UUID'] ?? '')));
        $secret = (string) ($input['secret'] ?? $input['branch_secret'] ?? $input['POSMAIN_BRANCH_SYNC_SECRET'] ?? '');
        if ($cloudBaseUrl === '' || $branchUuid === '' || $secret === '') {
            throw new InvalidArgumentException('Cloud URL, branch UUID, and sync secret are required to test pairing.');
        }

        $dashboard = (new PairingStatusService())->localDashboard($conn, $config, $input);

        return [
            'ok' => !empty($dashboard['pairing_ok']),
            'message' => (string) ($dashboard['pairing_message'] ?? ''),
            'remote' => $dashboard['remote'] ?? null,
            'dashboard' => $dashboard,
        ];
    }

    public function listHostedBranches(mysqli $contextConn, array $config): array
    {
        if (function_exists('posmain_router_enabled') && posmain_router_enabled($config)) {
            $routerConn = posmain_router_db_connect($config);
            try {
                return (new PosmainShopRouter())->listBranchRoutes($routerConn);
            } finally {
                $routerConn->close();
            }
        }

        return (new CloudBranchRegistryService())->listBranches($contextConn);
    }

    private function resolveShopId(PosmainShopRouter $router, mysqli $routerConn, array $config, array $input, mysqli $contextConn): int
    {
        $shopId = max(0, (int) ($input['shop_id'] ?? $input['router_shop_id'] ?? 0));
        if ($shopId > 0) {
            return $shopId;
        }

        $sessionShopId = PosmainShopRouter::activeSessionShopId();
        if ($sessionShopId > 0 && $router->findShopById($routerConn, $sessionShopId)) {
            return $sessionShopId;
        }

        foreach ($this->databaseNames($config, $contextConn) as $dbName) {
            $shop = $router->findShopByDatabaseName($routerConn, $dbName);
            if ($shop) {
                return (int) $shop['id'];
            }
        }

        $shops = $router->listActiveShops($routerConn);
        if (count($shops) === 1) {
            return (int) $shops[0]['id'];
        }

        throw new InvalidArgumentException(
            'Unable to determine the hosted shop database for pairing. Log into the target shop or provide router_shop_id.'
        );
    }

    private function databaseNames(array $config, mysqli $contextConn): array
    {
        $names = [];
        $configured = trim((string) ($config['database']['name'] ?? ''));
        if ($configured !== '') {
            $names[] = $configured;
        }

        $result = $contextConn->query('SELECT DATABASE() AS db_name');
        $row = $result ? $result->fetch_assoc() : null;
        $current = trim((string) ($row['db_name'] ?? ''));
        if ($current !== '') {
            $names[] = $current;
        }

        return array_values(array_unique($names));
    }
}
