<?php

require_once __DIR__ . '/../Router/ShopRouter.php';
require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/BranchSecretProviderFactory.php';
require_once __DIR__ . '/CloudAuthService.php';
require_once __DIR__ . '/CloudBranchRegistryService.php';
require_once __DIR__ . '/SchemaManager.php';
require_once __DIR__ . '/SyncHttpClient.php';
require_once __DIR__ . '/SyncObservabilityService.php';
require_once __DIR__ . '/SyncWorkerHealthService.php';

class PairingStatusService
{
    public function localDashboard(?mysqli $conn, array $config, array $input = []): array
    {
        $branchUuid = strtolower(trim((string) ($input['branch_uuid'] ?? $input['POSMAIN_BRANCH_UUID'] ?? $config['branch']['uuid'] ?? '')));
        $secret = (string) ($input['branch_secret'] ?? $input['POSMAIN_BRANCH_SYNC_SECRET'] ?? $config['sync']['branch_secret'] ?? '');
        $cloudBaseUrl = rtrim(trim((string) ($input['cloud_base_url'] ?? $input['POSMAIN_CLOUD_BASE_URL'] ?? $config['branch']['cloud_base_url'] ?? '')), '/');

        $remote = null;
        $pairingOk = false;
        $pairingMessage = 'Pairing credentials are incomplete.';
        if ($cloudBaseUrl !== '' && SyncBranchIdentity::isUuid($branchUuid) && $secret !== '') {
            $remote = $this->remoteHostedStatus($cloudBaseUrl, $branchUuid, $secret, $config);
            $pushTest = $this->pushProbe($cloudBaseUrl, $branchUuid, $secret, $config);
            $pairingOk = !empty($remote['ok']) && !empty($pushTest['ok']);
            $pairingMessage = $pairingOk
                ? $this->pairedMessage($remote)
                : $this->failureMessage($remote, $pushTest);
        }

        return [
            'role' => 'branch',
            'pairing_ok' => $pairingOk,
            'pairing_message' => $pairingMessage,
            'branch_uuid' => $branchUuid,
            'cloud_base_url' => $cloudBaseUrl,
            'remote' => $remote,
            'observability' => $conn ? (new SyncObservabilityService())->localSummary($conn, $branchUuid) : [],
            'worker' => $conn ? (new SyncWorkerHealthService())->report($conn, $config) : null,
        ];
    }

    public function hostedDashboard(mysqli $conn, array $config, string $branchUuid): array
    {
        $branchUuid = strtolower(trim($branchUuid));
        $identity = $this->resolveHostedIdentity($conn, $config, $branchUuid);

        return [
            'role' => 'cloud',
            'pairing_ok' => !empty($identity['active']) && !empty($identity['secret_configured']),
            'pairing_message' => !empty($identity['active'])
                ? ('Hosted branch is active' . (!empty($identity['shop_db_name']) ? ' on ' . $identity['shop_db_name'] : '') . '.')
                : 'Hosted branch is not active or not paired.',
            'branch_uuid' => $branchUuid,
            'identity' => $identity,
            'observability' => (new SyncObservabilityService())->hostedSummary($conn, $branchUuid),
            'worker' => (new SyncWorkerHealthService())->report($conn, $config),
        ];
    }

    public function hostedProbe(mysqli $shopConn, array $config, string $branchUuid): array
    {
        $branchUuid = strtolower(trim($branchUuid));
        $identity = $this->resolveHostedIdentity($shopConn, $config, $branchUuid);
        $schemaPending = (new SyncSchemaManager())->pendingStatements($shopConn);

        return [
            'ok' => !empty($identity['active']) && !empty($identity['secret_configured']),
            'branch_uuid' => $branchUuid,
            'identity_source' => (string) ($identity['identity_source'] ?? ''),
            'shop_db_name' => (string) ($identity['shop_db_name'] ?? ''),
            'shop_display_name' => (string) ($identity['shop_display_name'] ?? ''),
            'route_status' => (string) ($identity['route_status'] ?? ''),
            'last_seen_at' => (string) ($identity['last_seen_at'] ?? ''),
            'sync_schema_ready' => empty($schemaPending),
            'schema_pending_count' => count($schemaPending),
        ];
    }

    private function remoteHostedStatus(string $cloudBaseUrl, string $branchUuid, string $secret, array $config): ?array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $query = http_build_query([
            'branch_uuid' => $branchUuid,
        ]);
        $signatureBody = 'GET /api/sync/pairing_status.php?' . $query;
        $headers = [
            'X-POSMAIN-Branch-UUID: ' . $branchUuid,
            'X-POSMAIN-Timestamp: ' . $timestamp,
            'X-POSMAIN-Nonce: ' . $nonce,
            'X-POSMAIN-Signature: ' . CloudAuthService::sign($secret, $timestamp, $nonce, $signatureBody),
        ];

        $connectTimeout = (int) ($config['sync']['http_connect_timeout_ms'] ?? 1500);
        $timeout = (int) ($config['sync']['http_timeout_ms'] ?? 5000);
        $response = (new SyncHttpClient())->get(
            $cloudBaseUrl . '/api/sync/pairing_status.php?' . $query,
            $headers,
            $connectTimeout,
            $timeout
        );

        if (empty($response['ok']) || !is_array($response['json'])) {
            return [
                'ok' => false,
                'reason' => 'remote_status_unreachable',
                'http_status' => $response['status'] ?? 0,
                'error' => $response['error'] ?? '',
                'response' => $response['json'] ?? null,
            ];
        }

        return $response['json'];
    }

    private function pushProbe(string $cloudBaseUrl, string $branchUuid, string $secret, array $config): array
    {
        $body = json_encode([
            'schema_version' => 1,
            'branch_uuid' => $branchUuid,
            'sent_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'batch_uuid' => SyncBranchIdentity::generateUuidV4(),
            'events' => [],
        ], JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false, 'reason' => 'payload_encode_failed'];
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
            (int) ($config['sync']['http_connect_timeout_ms'] ?? 1500),
            (int) ($config['sync']['http_timeout_ms'] ?? 5000)
        );

        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        $reason = (string) ($json['reason'] ?? '');

        return [
            'ok' => !empty($response['ok']),
            'http_status' => $response['status'] ?? 0,
            'reason' => $reason,
            'human_reason' => $this->humanAuthReason($reason),
            'error' => $response['error'] ?? '',
        ];
    }

    private function resolveHostedIdentity(mysqli $conn, array $config, string $branchUuid): array
    {
        if (function_exists('posmain_router_enabled') && posmain_router_enabled($config)) {
            $routerConn = posmain_router_db_connect($config);
            try {
                $router = new PosmainShopRouter();
                $route = $router->resolveBranchRoute($routerConn, $branchUuid);
                if (!$route) {
                    return [
                        'identity_source' => 'router_branch_routes',
                        'active' => false,
                        'secret_configured' => false,
                    ];
                }

                $encryptedSelect = $this->routerColumnExists($routerConn, 'sync_secret_encrypted')
                    ? "CASE WHEN r.sync_secret_encrypted IS NULL OR r.sync_secret_encrypted = '' THEN 0 ELSE 1 END"
                    : '0';
                $lastSeenSelect = $this->routerColumnExists($routerConn, 'last_seen_at')
                    ? 'r.last_seen_at'
                    : 'NULL AS last_seen_at';
                $stmt = $routerConn->prepare("
                    SELECT r.status AS route_status,
                           {$encryptedSelect} AS has_encrypted_secret,
                           {$lastSeenSelect},
                           s.display_name,
                           s.db_name
                    FROM router_branch_routes r
                    INNER JOIN router_shops s ON s.id = r.shop_id
                    WHERE r.branch_uuid = ?
                    LIMIT 1
                ");
                $stmt->bind_param('s', $branchUuid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                return [
                    'identity_source' => 'router_branch_routes',
                    'active' => ($row['route_status'] ?? '') === 'active',
                    'secret_configured' => !empty($row['has_encrypted_secret']),
                    'route_status' => (string) ($row['route_status'] ?? ''),
                    'shop_db_name' => (string) ($row['db_name'] ?? ''),
                    'shop_display_name' => (string) ($row['display_name'] ?? ''),
                    'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
                ];
            } finally {
                $routerConn->close();
            }
        }

        $registry = new CloudBranchRegistryService();
        $branches = $registry->listBranches($conn);
        foreach ($branches as $branch) {
            if ((string) ($branch['branch_uuid'] ?? '') !== $branchUuid) {
                continue;
            }

            return [
                'identity_source' => 'cloud_branches',
                'active' => (string) ($branch['status'] ?? '') === 'active',
                'secret_configured' => !empty($branch['has_encrypted_secret']),
                'route_status' => (string) ($branch['status'] ?? ''),
                'shop_db_name' => $this->currentDatabaseName($conn),
                'shop_display_name' => (string) ($branch['branch_name'] ?? ''),
                'last_seen_at' => (string) ($branch['last_seen_at'] ?? ''),
            ];
        }

        return [
            'identity_source' => 'cloud_branches',
            'active' => false,
            'secret_configured' => false,
            'shop_db_name' => $this->currentDatabaseName($conn),
        ];
    }

    private function currentDatabaseName(mysqli $conn): string
    {
        $row = $conn->query('SELECT DATABASE() AS db_name')->fetch_assoc();

        return (string) ($row['db_name'] ?? '');
    }

    private function routerColumnExists(mysqli $routerConn, string $column): bool
    {
        $table = 'router_branch_routes';
        $stmt = $routerConn->prepare("
            SELECT COUNT(*) AS column_count
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ((int) ($row['column_count'] ?? 0)) > 0;
    }

    private function pairedMessage(?array $remote): string
    {
        if (!$remote || empty($remote['ok'])) {
            return 'Pairing failed.';
        }

        $dbName = (string) ($remote['shop_db_name'] ?? '');
        if ($dbName !== '') {
            return 'Paired with hosted shop database ' . $dbName . '.';
        }

        return 'Paired with hosted shop.';
    }

    private function failureMessage(?array $remote, array $pushTest): string
    {
        if (!empty($pushTest['human_reason'])) {
            return (string) $pushTest['human_reason'];
        }
        if (!empty($remote['response']['reason'])) {
            return $this->humanAuthReason((string) $remote['response']['reason']);
        }

        return 'Pairing failed. Check UUID, secret, and hosted URL.';
    }

    private function humanAuthReason(string $reason): string
    {
        switch ($reason) {
            case 'branch_inactive_or_secret_missing':
                return 'Hosted shop does not recognize this UUID/secret pair yet.';
            case 'signature_mismatch':
                return 'Secret does not match the hosted shop.';
            case 'unknown_branch_route':
                return 'Hosted router does not know this branch UUID.';
            case 'branch_uuid_required':
                return 'Branch UUID is missing from the sync request.';
            case 'invalid_role':
                return 'Hosted site is not running in cloud role.';
            default:
                return $reason !== '' ? $reason : 'Pairing failed.';
        }
    }
}
