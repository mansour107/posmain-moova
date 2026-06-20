<?php

require_once __DIR__ . '/BranchSecretProvider.php';
require_once __DIR__ . '/SyncRuntimeCrypto.php';

class RouterBranchSecretProvider implements BranchSecretProvider
{
    private mysqli $routerConn;
    private array $secretCache = [];

    public function __construct(mysqli $routerConn)
    {
        $this->routerConn = $routerConn;
    }

    public function getSecretForBranch(string $branchUuid): ?string
    {
        if (!$this->isBranchActive($branchUuid)) {
            return null;
        }

        return $this->secretForBranch($branchUuid);
    }

    public function isBranchActive(string $branchUuid): bool
    {
        $branchUuid = strtolower(trim($branchUuid));
        if ($branchUuid === '' || !$this->tableExists('router_branch_routes')) {
            return false;
        }

        $stmt = $this->routerConn->prepare("
            SELECT r.status AS route_status, s.status AS shop_status
            FROM router_branch_routes r
            INNER JOIN router_shops s ON s.id = r.shop_id
            WHERE r.branch_uuid = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row
            && (string) ($row['route_status'] ?? '') === 'active'
            && (string) ($row['shop_status'] ?? '') === 'active';
    }

    public function touchLastSeen(string $branchUuid): void
    {
        if (!$this->columnExists('router_branch_routes', 'last_seen_at')) {
            return;
        }

        $branchUuid = strtolower(trim($branchUuid));
        $stmt = $this->routerConn->prepare("
            UPDATE router_branch_routes
            SET last_seen_at = NOW(6)
            WHERE branch_uuid = ?
              AND status = 'active'
        ");
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $stmt->close();
    }

    private function secretForBranch(string $branchUuid): ?string
    {
        $branchUuid = strtolower(trim($branchUuid));
        if (array_key_exists($branchUuid, $this->secretCache)) {
            return $this->secretCache[$branchUuid];
        }

        if (!$this->columnExists('router_branch_routes', 'sync_secret_encrypted')) {
            $this->secretCache[$branchUuid] = null;
            return null;
        }

        $stmt = $this->routerConn->prepare("
            SELECT sync_secret_encrypted
            FROM router_branch_routes
            WHERE branch_uuid = ?
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $encrypted = trim((string) ($row['sync_secret_encrypted'] ?? ''));
        if ($encrypted === '') {
            $this->secretCache[$branchUuid] = null;
            return null;
        }

        try {
            $this->secretCache[$branchUuid] = (new SyncRuntimeCrypto())->decrypt($encrypted);
        } catch (Throwable $e) {
            error_log('Unable to decrypt router branch sync secret for ' . $branchUuid . ': ' . $e->getMessage());
            $this->secretCache[$branchUuid] = null;
        }

        return $this->secretCache[$branchUuid];
    }

    private function tableExists(string $table): bool
    {
        $escaped = $this->routerConn->real_escape_string($table);
        $result = $this->routerConn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result && $result->num_rows > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->routerConn->prepare("
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
}
