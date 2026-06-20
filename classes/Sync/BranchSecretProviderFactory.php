<?php

require_once __DIR__ . '/BranchSecretProvider.php';
require_once __DIR__ . '/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/RouterBranchSecretProvider.php';

class BranchSecretProviderFactory
{
    public static function fromConfig(mysqli $shopConn, array $config): BranchSecretProvider
    {
        return new UnifiedBranchSecretProvider($shopConn, $config);
    }
}

class UnifiedBranchSecretProvider implements BranchSecretProvider
{
    private mysqli $shopConn;
    private array $config;
    private array $envSecrets;
    private ?RouterBranchSecretProvider $routerProvider = null;
    private ?DatabaseBranchSecretProvider $legacyProvider = null;

    public function __construct(mysqli $shopConn, array $config)
    {
        $this->shopConn = $shopConn;
        $this->config = $config;
        $this->envSecrets = self::envSecretsFromConfig($config);
    }

    public function getSecretForBranch(string $branchUuid): ?string
    {
        if (!$this->isBranchActive($branchUuid)) {
            return null;
        }

        $branchUuid = strtolower(trim($branchUuid));
        if ($branchUuid !== '' && array_key_exists($branchUuid, $this->envSecrets)) {
            return (string) $this->envSecrets[$branchUuid];
        }

        $routerProvider = $this->routerProvider();
        if ($routerProvider !== null) {
            $secret = $routerProvider->getSecretForBranch($branchUuid);
            if ($secret !== null && $secret !== '') {
                return $secret;
            }
        }

        return $this->legacyProvider()->readStoredSecret($branchUuid);
    }

    public function isBranchActive(string $branchUuid): bool
    {
        $branchUuid = strtolower(trim($branchUuid));
        if ($branchUuid === '') {
            return false;
        }

        if (array_key_exists($branchUuid, $this->envSecrets)) {
            return true;
        }

        $routerProvider = $this->routerProvider();
        if ($routerProvider !== null && $routerProvider->isBranchActive($branchUuid)) {
            return true;
        }

        return $this->legacyProvider()->isBranchActive($branchUuid);
    }

    public function touchLastSeen(string $branchUuid): void
    {
        $branchUuid = strtolower(trim($branchUuid));
        $routerProvider = $this->routerProvider();
        if ($routerProvider !== null && $routerProvider->isBranchActive($branchUuid)) {
            $routerProvider->touchLastSeen($branchUuid);
            return;
        }

        $this->legacyProvider()->touchLastSeen($branchUuid);
    }

    private function routerProvider(): ?RouterBranchSecretProvider
    {
        if ($this->routerProvider !== null) {
            return $this->routerProvider;
        }

        if (!function_exists('posmain_router_enabled') || !posmain_router_enabled($this->config)) {
            return null;
        }

        if (!function_exists('posmain_router_db_connect')) {
            return null;
        }

        try {
            $this->routerProvider = new RouterBranchSecretProvider(posmain_router_db_connect($this->config));
        } catch (Throwable $e) {
            error_log('Router branch secret provider unavailable: ' . $e->getMessage());
            return null;
        }

        return $this->routerProvider;
    }

    private function legacyProvider(): DatabaseBranchSecretProvider
    {
        if ($this->legacyProvider === null) {
            $this->legacyProvider = new DatabaseBranchSecretProvider($this->shopConn, []);
        }

        return $this->legacyProvider;
    }

    private static function envSecretsFromConfig(array $config): array
    {
        $secrets = $config['sync']['cloud_branch_secrets'] ?? [];
        if (!is_array($secrets)) {
            $secrets = [];
        }

        $branchUuid = strtolower(trim((string) ($config['branch']['uuid'] ?? '')));
        $branchSecret = (string) ($config['sync']['branch_secret'] ?? '');
        if ($branchUuid !== '' && $branchSecret !== '' && !array_key_exists($branchUuid, $secrets)) {
            $secrets[$branchUuid] = $branchSecret;
        }

        return $secrets;
    }
}
