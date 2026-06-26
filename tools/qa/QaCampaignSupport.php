<?php

declare(strict_types=1);

/**
 * Shared helpers for the persona QA campaign (provision, run, report, teardown).
 */
final class QaCampaignSupport
{
    public const QA_DB_PREFIX = 'posmain_qa_';
    public const QA_SLUG_PREFIX = 'qa-campaign-';

    public static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function exampleConfigPath(): string
    {
        return self::repoRoot() . '/tests/qa/campaign_config.example.json';
    }

    public static function localConfigPath(): string
    {
        return self::repoRoot() . '/tests/qa/campaign_config.local.json';
    }

    public static function generateRunId(): string
    {
        return 'qa-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2));
    }

    public static function generateUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public static function generateSecret(): string
    {
        return 'qa-sync-' . bin2hex(random_bytes(16));
    }

    public static function artifactDir(string $runId): string
    {
        $dir = self::repoRoot() . '/var/qa/' . self::sanitizeRunId($runId);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    public static function sanitizeRunId(string $runId): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $runId) ?: 'qa-run';
    }

    /**
     * @return array<string,mixed>
     */
    public static function loadConfig(?string $path = null): array
    {
        $path = $path ?: self::localConfigPath();
        if (!is_file($path)) {
            throw new RuntimeException('Campaign config not found: ' . $path . ' — run provision first.');
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid campaign config JSON: ' . $path);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function saveConfig(array $config, ?string $path = null): void
    {
        $path = $path ?: self::localConfigPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $path,
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildFreshConfig(string $runId): array
    {
        $example = json_decode((string) file_get_contents(self::exampleConfigPath()), true);
        if (!is_array($example)) {
            throw new RuntimeException('Missing or invalid campaign_config.example.json');
        }

        $slugSuffix = substr(str_replace(['qa-', ':', '.'], '', $runId), 0, 24);
        $secret = self::generateSecret();
        $branchUuid = self::generateUuid();

        $config = $example;
        $config['run_id'] = $runId;
        $config['created_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
        $config['git_commit'] = trim((string) shell_exec('cd ' . escapeshellarg(self::repoRoot()) . ' && git rev-parse HEAD 2>/dev/null')) ?: '';

        $config['local']['shop_slug'] = self::QA_SLUG_PREFIX . 'local-' . $slugSuffix;
        $config['local']['branch_uuid'] = $branchUuid;
        $config['local']['branch_secret'] = $secret;
        $config['local']['base_url'] = getenv('POSMAIN_TEST_HTTP_BASE') ?: ($config['local']['base_url'] ?? 'http://127.0.0.1:8010');
        $config['local']['mysql_host'] = getenv('POSMAIN_TEST_MYSQL_HOST') ?: ($config['local']['mysql_host'] ?? '127.0.0.1');
        $config['local']['mysql_port'] = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: ($config['local']['mysql_port'] ?? 3307));
        $config['local']['mysql_user'] = getenv('POSMAIN_TEST_MYSQL_USER') ?: ($config['local']['mysql_user'] ?? 'root');
        $config['local']['mysql_pass'] = getenv('POSMAIN_TEST_MYSQL_PASS') !== false
            ? (string) getenv('POSMAIN_TEST_MYSQL_PASS')
            : (string) ($config['local']['mysql_pass'] ?? '');
        $config['local']['db_name'] = getenv('POSMAIN_DB_NAME') ?: ($config['local']['db_name'] ?? 'kody2');

        $config['hosted']['shop_slug'] = self::QA_SLUG_PREFIX . 'hosted-' . $slugSuffix;
        $config['hosted']['branch_uuid'] = $branchUuid;
        $config['hosted']['branch_secret'] = $secret;
        $config['hosted']['base_url'] = rtrim(
            (string) (getenv('POSMAIN_QA_HOSTED_BASE_URL') ?: ($config['hosted']['base_url'] ?? '')),
            '/'
        );
        $config['hosted']['ssh_host'] = (string) (getenv('POSMAIN_QA_SSH_HOST') ?: ($config['hosted']['ssh_host'] ?? ''));
        $config['hosted']['ssh_user'] = (string) (getenv('POSMAIN_QA_SSH_USER') ?: ($config['hosted']['ssh_user'] ?? ''));
        $config['hosted']['remote_app_path'] = (string) (
            getenv('POSMAIN_QA_REMOTE_APP_PATH') ?: ($config['hosted']['remote_app_path'] ?? '/var/www/posmain/current')
        );
        $config['hosted']['ssh_identity_file'] = (string) (
            getenv('POSMAIN_QA_SSH_IDENTITY_FILE') ?: ($config['hosted']['ssh_identity_file'] ?? '')
        );

        return $config;
    }

    public static function refuseProductionDb(string $dbName): void
    {
        $lower = strtolower($dbName);
        foreach (['kody2_prod', 'production', 'live_'] as $blocked) {
            if (strpos($lower, $blocked) !== false) {
                throw new RuntimeException('Refusing QA campaign against production-like database: ' . $dbName);
            }
        }
    }

    public static function isQaShopSlug(string $slug): bool
    {
        return strpos($slug, self::QA_SLUG_PREFIX) === 0;
    }

    public static function isQaDbName(string $dbName): bool
    {
        return strpos($dbName, self::QA_DB_PREFIX) === 0;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function seedLocalDemo(array $config): array
    {
        $local = $config['local'] ?? [];
        $dbName = (string) ($local['db_name'] ?? 'kody2');
        self::refuseProductionDb($dbName);

        putenv('POSMAIN_ENV=test');
        putenv('POSMAIN_PRODUCTION_MODE=0');
        putenv('POSMAIN_DB_HOST=' . ($local['mysql_host'] ?? '127.0.0.1'));
        putenv('POSMAIN_DB_PORT=' . (string) ($local['mysql_port'] ?? 3307));
        putenv('POSMAIN_DB_USER=' . ($local['mysql_user'] ?? 'root'));
        putenv('POSMAIN_DB_PASS=' . ($local['mysql_pass'] ?? ''));
        putenv('POSMAIN_DB_NAME=' . $dbName);

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::repoRoot() . '/tools/seed_demo_restaurant.php')
            . ' --apply --reset-demo --with-moova-dummy --json';
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        $raw = implode("\n", $output);
        $payload = json_decode($raw, true);

        return [
            'ok' => $code === 0,
            'exit_code' => $code,
            'payload' => is_array($payload) ? $payload : null,
            'output_tail' => self::tailLines($output),
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function pairLocalBranchToHosted(array $config): array
    {
        require_once self::repoRoot() . '/includes/db_bootstrap.php';
        require_once self::repoRoot() . '/classes/Sync/BranchPairingService.php';

        $local = $config['local'] ?? [];
        $hosted = $config['hosted'] ?? [];
        $cloudUrl = rtrim((string) ($hosted['base_url'] ?? ''), '/');
        if ($cloudUrl === '' || stripos($cloudUrl, 'example') !== false) {
            return ['ok' => true, 'skipped' => true, 'message' => 'No real hosted base URL; skipped local branch pairing'];
        }

        putenv('POSMAIN_ENV=test');
        putenv('POSMAIN_PRODUCTION_MODE=0');
        putenv('POSMAIN_DB_HOST=' . ($local['mysql_host'] ?? '127.0.0.1'));
        putenv('POSMAIN_DB_PORT=' . (string) ($local['mysql_port'] ?? 3307));
        putenv('POSMAIN_DB_USER=' . ($local['mysql_user'] ?? 'root'));
        putenv('POSMAIN_DB_PASS=' . ($local['mysql_pass'] ?? ''));
        putenv('POSMAIN_DB_NAME=' . ($local['db_name'] ?? 'kody2'));

        try {
            $conn = posmain_db_connect();
            $pairing = new BranchPairingService();
            $result = $pairing->pairLocal($conn, posmain_app_config(), [
                'branch_uuid' => (string) ($local['branch_uuid'] ?? ''),
                'secret' => (string) ($local['branch_secret'] ?? ''),
                'cloud_base_url' => $cloudUrl,
                'branch_name' => (string) ($local['shop_slug'] ?? 'QA Local Branch'),
            ]);
            $conn->close();

            return ['ok' => true, 'result' => $result];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function provisionHostedShop(array $config): array
    {
        $hosted = $config['hosted'] ?? [];
        $sshHost = trim((string) ($hosted['ssh_host'] ?? ''));
        if ($sshHost === '') {
            return ['ok' => true, 'skipped' => true, 'message' => 'POSMAIN_QA_SSH_HOST not set; skipped hosted provision'];
        }

        $remotePath = rtrim((string) ($hosted['remote_app_path'] ?? ''), '/');
        $runId = (string) ($config['run_id'] ?? '');
        $slug = (string) ($hosted['shop_slug'] ?? '');
        $branchUuid = (string) ($hosted['branch_uuid'] ?? '');
        $secret = (string) ($hosted['branch_secret'] ?? '');
        $baseUrl = rtrim((string) ($hosted['base_url'] ?? ''), '/');

        $sshUser = trim((string) ($hosted['ssh_user'] ?? ''));
        [$sshOpts, $sshTarget] = self::sshConnection($hosted);

        $remoteCmd = 'cd ' . escapeshellarg($remotePath)
            . ' && php tools/qa/provision_qa_campaign_shop.php'
            . ' --hosted-only'
            . ' --run-id=' . escapeshellarg($runId)
            . ' --slug=' . escapeshellarg($slug)
            . ' --branch-uuid=' . escapeshellarg($branchUuid)
            . ' --secret=' . escapeshellarg($secret)
            . ' --cloud-base-url=' . escapeshellarg($baseUrl)
            . ' --json';

        $cmd = 'ssh ' . $sshOpts . ' ' . escapeshellarg($sshTarget) . ' ' . escapeshellarg($remoteCmd);
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        $raw = implode("\n", $output);
        $payload = json_decode($raw, true);

        return [
            'ok' => $code === 0 && is_array($payload) && !empty($payload['ok']),
            'exit_code' => $code,
            'payload' => is_array($payload) ? $payload : null,
            'output_tail' => self::tailLines($output),
            'ssh_target' => $sshTarget,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function provisionHostedShopLocal(array $input): array
    {
        require_once self::repoRoot() . '/includes/db_bootstrap.php';
        require_once self::repoRoot() . '/classes/Sync/BranchPairingService.php';
        require_once self::repoRoot() . '/classes/Sync/ShopProvisioningService.php';

        $slug = (string) ($input['slug'] ?? '');
        $branchUuid = (string) ($input['branch_uuid'] ?? '');
        $secret = (string) ($input['secret'] ?? '');
        $cloudBaseUrl = rtrim((string) ($input['cloud_base_url'] ?? ''), '/');
        $dbName = self::QA_DB_PREFIX . preg_replace('/[^a-z0-9_]+/', '_', $slug);

        if (!self::isQaShopSlug($slug)) {
            throw new InvalidArgumentException('Refusing hosted provision for non-QA slug: ' . $slug);
        }

        $config = posmain_app_config();
        if (!function_exists('posmain_router_enabled') || !posmain_router_enabled($config)) {
            throw new RuntimeException('Hosted QA provision requires POSMAIN_ROUTER_ENABLED=1 on the server.');
        }

        $routerConn = posmain_router_db_connect($config);
        try {
            $dbApp = $config['database'] ?? [];
            $dbAdminUser = getenv('POSMAIN_QA_DB_ADMIN_USER') ?: 'root';
            $dbAdminPass = (string) (getenv('POSMAIN_QA_DB_ADMIN_PASS') ?: '');
            $dbHost = (string) ($dbApp['host'] ?? '127.0.0.1');
            $dbPort = (int) ($dbApp['port'] ?? 3306);
            $appUser = (string) ($dbApp['user'] ?? 'posmain_app');
            $appPass = (string) ($dbApp['pass'] ?? '');

            self::ensureQaDatabase($dbHost, $dbPort, $dbAdminUser, $dbAdminPass, $dbName, $appUser);

            $pairing = new BranchPairingService();
            $result = $pairing->pairHosted($routerConn, $config, [
                'provision_new_shop' => '1',
                'provision_shop_slug' => $slug,
                'provision_shop_name' => 'QA Campaign ' . $slug,
                'provision_db_name' => $dbName,
                'branch_uuid' => $branchUuid,
                'secret' => $secret,
                'cloud_base_url' => $cloudBaseUrl !== '' ? $cloudBaseUrl : ($config['sync']['cloud_base_url'] ?? ''),
                'skip_database_create' => '1',
                'db_user' => $appUser,
                'db_pass' => $appPass,
            ]);

            $shopId = (int) ($result['provisioned_shop']['shop_id'] ?? 0);
            if ($shopId <= 0) {
                throw new RuntimeException('Hosted shop provisioning did not return shop_id');
            }

            self::ensureHostedLoginAliases($routerConn, $shopId, $slug);

            $shopConn = (new PosmainShopRouter())->connectShopById($routerConn, $shopId);
            try {
                putenv('POSMAIN_ENV=test');
                putenv('POSMAIN_PRODUCTION_MODE=0');
                $seed = self::seedShopConnection($shopConn, $config);
            } finally {
                $shopConn->close();
            }

            return [
                'ok' => true,
                'shop_id' => $shopId,
                'db_name' => $dbName,
                'slug' => $slug,
                'login_aliases' => self::hostedLoginAliases($slug),
                'pairing' => $result,
                'seed' => $seed,
            ];
        } finally {
            $routerConn->close();
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function seedShopConnection(mysqli $conn, array $appConfig): array
    {
        require_once self::repoRoot() . '/tools/seed_demo_restaurant.php';

        $seeder = new Phase6DemoSeeder($conn, $appConfig, [
            'json' => false,
            'dry_run' => false,
            'apply' => true,
            'reset_demo' => true,
            'with_moova_dummy' => true,
            'no_moova_dummy' => false,
        ]);

        return $seeder->run();
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function teardown(array $config): array
    {
        $results = ['ok' => true, 'actions' => []];

        $hosted = $config['hosted'] ?? [];
        $slug = (string) ($hosted['shop_slug'] ?? '');
        if (self::isQaShopSlug($slug)) {
            $remote = self::teardownHostedViaSsh($config);
            $results['actions']['hosted_ssh'] = $remote;
            if (empty($remote['ok'])) {
                $results['ok'] = false;
            }
        }

        $local = $config['local'] ?? [];
        $localDb = (string) ($local['db_name'] ?? '');
        if ($localDb === 'kody2' || $localDb === '') {
            $reset = self::seedLocalDemo($config);
            $results['actions']['local_reset_demo'] = $reset;
        } elseif (self::isQaDbName($localDb)) {
            $drop = self::dropDatabase(
                (string) ($local['mysql_host'] ?? '127.0.0.1'),
                (int) ($local['mysql_port'] ?? 3307),
                (string) ($local['mysql_user'] ?? 'root'),
                (string) ($local['mysql_pass'] ?? ''),
                $localDb
            );
            $results['actions']['local_drop_db'] = $drop;
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function teardownHostedViaSsh(array $config): array
    {
        $hosted = $config['hosted'] ?? [];
        $sshHost = trim((string) ($hosted['ssh_host'] ?? ''));
        if ($sshHost === '') {
            return ['ok' => true, 'skipped' => true];
        }

        $slug = (string) ($hosted['shop_slug'] ?? '');
        $remotePath = rtrim((string) ($hosted['remote_app_path'] ?? ''), '/');
        [$sshOpts, $sshTarget] = self::sshConnection($hosted);

        $remoteCmd = 'cd ' . escapeshellarg($remotePath)
            . ' && php tools/qa/provision_qa_campaign_shop.php --teardown --slug=' . escapeshellarg($slug) . ' --json';
        $cmd = 'ssh ' . $sshOpts . ' ' . escapeshellarg($sshTarget) . ' ' . escapeshellarg($remoteCmd);
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);

        return [
            'ok' => $code === 0,
            'exit_code' => $code,
            'output_tail' => self::tailLines($output),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function dropDatabase(string $host, int $port, string $user, string $pass, string $dbName): array
    {
        if (!self::isQaDbName($dbName)) {
            return ['ok' => false, 'message' => 'Refusing to drop non-QA database: ' . $dbName];
        }

        try {
            $conn = new mysqli($host, $user, $pass, '', $port);
            $escaped = str_replace('`', '``', $dbName);
            $conn->query('DROP DATABASE IF EXISTS `' . $escaped . '`');
            $conn->close();

            return ['ok' => true, 'db_name' => $dbName];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function teardownHostedShopLocal(string $slug): array
    {
        if (!self::isQaShopSlug($slug)) {
            return ['ok' => false, 'message' => 'Refusing teardown for non-QA slug'];
        }

        require_once self::repoRoot() . '/includes/db_bootstrap.php';

        $config = posmain_app_config();
        if (!posmain_router_enabled($config)) {
            return ['ok' => true, 'skipped' => true, 'message' => 'Router not enabled'];
        }

        $router = new PosmainShopRouter();
        $routerConn = posmain_router_db_connect($config);
        try {
            $shop = $router->findShopBySlug($routerConn, $slug);
            if (!$shop) {
                return ['ok' => true, 'skipped' => true, 'message' => 'Shop not found: ' . $slug];
            }

            $dbName = (string) ($shop['db_name'] ?? '');
            $shopId = (int) ($shop['id'] ?? 0);
            $stmt = $routerConn->prepare('UPDATE router_shops SET status = ? WHERE id = ?');
            $status = 'inactive';
            $stmt->bind_param('si', $status, $shopId);
            $stmt->execute();
            $stmt->close();

            $drop = self::dropDatabase(
                (string) ($shop['db_host'] ?? '127.0.0.1'),
                (int) ($shop['db_port'] ?? 3306),
                (string) ($shop['db_user'] ?? 'root'),
                (string) ($shop['db_pass'] ?? ''),
                $dbName
            );

            return ['ok' => true, 'shop_id' => $shopId, 'db_name' => $dbName, 'drop' => $drop];
        } finally {
            $routerConn->close();
        }
    }

    public static function ensureQaDatabase(
        string $host,
        int $port,
        string $adminUser,
        string $adminPass,
        string $dbName,
        string $appUser
    ): void {
        if (!self::isQaDbName($dbName)) {
            throw new InvalidArgumentException('Refusing to create non-QA database: ' . $dbName);
        }

        $adminHost = getenv('POSMAIN_QA_DB_ADMIN_HOST') ?: ($adminUser === 'root' ? 'localhost' : $host);
        $admin = new mysqli($adminHost, $adminUser, $adminPass, '', $port);
        $escapedDb = str_replace('`', '``', $dbName);
        $admin->query("CREATE DATABASE IF NOT EXISTS `{$escapedDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $escapedUser = $admin->real_escape_string($appUser);
        $admin->query("GRANT ALL PRIVILEGES ON `{$escapedDb}`.* TO '{$escapedUser}'@'localhost'");
        $admin->query('FLUSH PRIVILEGES');
        $admin->close();
    }

    /**
     * @return list<string>
     */
    public static function hostedLoginAliases(string $slug): array
    {
        $users = ['p6_admin', 'p6_manager', 'p6_cashier', 'p6_waiter'];

        return array_map(static fn (string $user): string => $user . '@' . $slug, $users);
    }

    public static function ensureHostedLoginAliases(mysqli $routerConn, int $shopId, string $slug): void
    {
        $router = new PosmainShopRouter();
        foreach (self::hostedLoginAliases($slug) as $alias) {
            $targetUser = strstr($alias, '@', true) ?: $alias;
            try {
                $router->addLoginAlias($routerConn, [
                    'shop_id' => $shopId,
                    'alias' => $alias,
                    'target_uname' => $targetUser,
                    'status' => 'active',
                ]);
            } catch (Throwable $exception) {
                if (stripos($exception->getMessage(), 'Duplicate') === false) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function repairHostedLoginAliasesLocal(string $slug): array
    {
        require_once self::repoRoot() . '/includes/db_bootstrap.php';

        $slug = trim($slug);
        if (!self::isQaShopSlug($slug)) {
            throw new InvalidArgumentException('Refusing alias repair for non-QA slug: ' . $slug);
        }

        $config = posmain_app_config();
        if (!function_exists('posmain_router_enabled') || !posmain_router_enabled($config)) {
            throw new RuntimeException('Hosted QA alias repair requires POSMAIN_ROUTER_ENABLED=1 on the server.');
        }

        $router = new PosmainShopRouter();
        $routerConn = posmain_router_db_connect($config);
        try {
            $shop = $router->findShopBySlug($routerConn, $slug);
            if (!$shop) {
                throw new RuntimeException('QA shop not found in router: ' . $slug);
            }

            $shopId = (int) ($shop['id'] ?? 0);
            $aliases = [];
            foreach (self::hostedLoginAliases($slug) as $alias) {
                $targetUser = strstr($alias, '@', true) ?: $alias;
                $existing = $router->resolveLoginAlias($routerConn, $alias);
                if ($existing && (int) ($existing['id'] ?? 0) === $shopId) {
                    $aliases[] = [
                        'alias' => $alias,
                        'target_uname' => $existing['target_uname'] ?? $targetUser,
                        'status' => 'existing',
                    ];
                    continue;
                }

                $created = $router->addLoginAlias($routerConn, [
                    'shop_id' => $shopId,
                    'alias' => $alias,
                    'target_uname' => $targetUser,
                    'status' => 'active',
                ]);
                $created['status'] = 'created';
                $aliases[] = $created;
            }

            return [
                'ok' => true,
                'shop_id' => $shopId,
                'slug' => $slug,
                'aliases' => $aliases,
            ];
        } finally {
            $routerConn->close();
        }
    }

    /**
     * @param array<string,mixed> $hosted
     * @return array{0:string,1:string}
     */
    private static function sshConnection(array $hosted): array
    {
        $sshHost = trim((string) ($hosted['ssh_host'] ?? ''));
        $sshUser = trim((string) ($hosted['ssh_user'] ?? ''));
        $sshTarget = $sshUser !== '' ? ($sshUser . '@' . $sshHost) : $sshHost;
        $sshIdentity = trim((string) (
            getenv('POSMAIN_QA_SSH_IDENTITY_FILE') ?: ($hosted['ssh_identity_file'] ?? '')
        ));
        $sshOpts = '-o BatchMode=yes -o ConnectTimeout=20';
        if ($sshIdentity !== '') {
            $sshOpts .= ' -i ' . escapeshellarg($sshIdentity);
        }

        return [$sshOpts, $sshTarget];
    }

    /**
     * @param list<string> $lines
     */
    public static function tailLines(array $lines, int $limit = 40): string
    {
        return implode("\n", array_slice($lines, -$limit));
    }

    public static function writeJsonArtifact(string $runId, string $relativePath, array $payload): string
    {
        $path = self::artifactDir($runId) . '/' . ltrim($relativePath, '/');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
        );

        return $path;
    }

    public static function httpProbe(string $url): bool
    {
        try {
            $context = stream_context_create([
                'http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $body = file_get_contents(rtrim($url, '/') . '/index.php', false, $context);

            return is_string($body) && $body !== '';
        } catch (Throwable $exception) {
            return false;
        }
    }

    public static function mysqlProbe(string $host, int $port, string $user, string $pass): bool
    {
        try {
            $conn = new mysqli($host, $user, $pass, '', $port);
            if ($conn->connect_errno) {
                return false;
            }
            $conn->close();

            return true;
        } catch (Throwable $exception) {
            return false;
        }
    }
}
