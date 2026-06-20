<?php

require_once __DIR__ . '/../Router/ShopRouter.php';
require_once __DIR__ . '/SchemaManager.php';

class ShopProvisioningService
{
    public function provision(mysqli $routerConn, array $config, array $input): array
    {
        $router = new PosmainShopRouter();
        $router->install($routerConn);

        $slug = $this->normalizeSlug((string) ($input['shop_slug'] ?? $input['provision_shop_slug'] ?? ''));
        if ($slug === '') {
            $branchUuid = strtolower(trim((string) ($input['branch_uuid'] ?? $input['POSMAIN_BRANCH_UUID'] ?? '')));
            $slug = $branchUuid !== '' ? ('shop-' . substr(str_replace('-', '', $branchUuid), 0, 12)) : '';
        }
        if ($slug === '') {
            throw new InvalidArgumentException('shop_slug is required when provisioning a new hosted shop database.');
        }

        $displayName = trim((string) ($input['shop_display_name'] ?? $input['provision_shop_name'] ?? $input['branch_name'] ?? $slug));
        $dbName = trim((string) ($input['db_name'] ?? $input['provision_db_name'] ?? ('posmain_' . $slug)));
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '_', $dbName) ?: ('posmain_' . $slug);

        $templateDb = $config['database'] ?? [];
        $dbHost = trim((string) ($input['db_host'] ?? $templateDb['host'] ?? '127.0.0.1'));
        $dbPort = (int) ($input['db_port'] ?? $templateDb['port'] ?? 3306);
        $dbUser = trim((string) ($input['db_user'] ?? $templateDb['user'] ?? 'root'));
        $dbPass = (string) ($input['db_pass'] ?? $templateDb['pass'] ?? '');
        $dbCharset = trim((string) ($input['db_charset'] ?? $templateDb['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';

        $this->createDatabase($dbHost, $dbPort, $dbUser, $dbPass, $dbName, $dbCharset);

        $shop = $router->registerShop($routerConn, [
            'slug' => $slug,
            'display_name' => $displayName,
            'status' => 'active',
            'database' => [
                'host' => $dbHost,
                'port' => $dbPort,
                'name' => $dbName,
                'user' => $dbUser,
                'pass' => $dbPass,
                'charset' => $dbCharset,
            ],
            'require_encryption' => true,
        ]);

        $shopConn = $router->connectShopById($routerConn, (int) $shop['id']);
        try {
            (new SyncSchemaManager())->apply($shopConn);
        } finally {
            $shopConn->close();
        }

        return [
            'shop_id' => (int) ($shop['id'] ?? 0),
            'slug' => (string) ($shop['slug'] ?? $slug),
            'display_name' => (string) ($shop['display_name'] ?? $displayName),
            'db_name' => $dbName,
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'provisioned' => true,
        ];
    }

    private function createDatabase(string $host, int $port, string $user, string $pass, string $dbName, string $charset): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $admin = new mysqli($host, $user, $pass, '', $port);
        $admin->set_charset($charset);
        $escaped = str_replace('`', '``', $dbName);
        $admin->query("CREATE DATABASE IF NOT EXISTS `{$escaped}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        $admin->close();
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
        $slug = trim((string) $slug, '-_');

        return substr($slug, 0, 80);
    }
}
