<?php

require_once __DIR__ . '/BranchIdentity.php';
require_once __DIR__ . '/SchemaManager.php';
require_once __DIR__ . '/SyncRuntimeCrypto.php';

class CloudBranchRegistryService
{
    public function register(mysqli $conn, array $options): array
    {
        $branchUuid = strtolower(trim((string) ($options['branch-uuid'] ?? $options['branch_uuid'] ?? '')));
        $secret = (string) ($options['secret'] ?? '');
        if (!SyncBranchIdentity::isUuid($branchUuid)) {
            throw new InvalidArgumentException('--branch-uuid must be a valid UUID.');
        }
        if ($secret === '') {
            throw new InvalidArgumentException('--secret is required.');
        }

        $requireEncryption = !empty($options['require-encryption']) || !empty($options['require_encryption']);
        $crypto = new SyncRuntimeCrypto();
        if ($requireEncryption && !$crypto->available()) {
            throw new RuntimeException(SyncRuntimeCrypto::ENV_KEY . ' is required before registering hosted branch secrets.');
        }

        $branchName = $this->nullableString($options['name'] ?? null);
        $tenant = $this->nullableInt($options['tenant'] ?? null);
        $branch = $this->nullableInt($options['branch'] ?? null);
        $status = !empty($options['disabled']) ? 'disabled' : (string) ($options['status'] ?? 'active');
        $status = $status === 'disabled' ? 'disabled' : 'active';
        $secretHash = hash('sha256', $secret);
        $encryptedSecret = $crypto->available() ? $crypto->encrypt($secret) : null;

        (new SyncSchemaManager())->apply($conn);

        $stmt = $conn->prepare("
            INSERT INTO cloud_branches (
                branch_uuid,
                branch_name,
                pos_tenant,
                pos_branch,
                status,
                sync_secret_hash,
                sync_secret_encrypted
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE branch_name = VALUES(branch_name),
                                    pos_tenant = VALUES(pos_tenant),
                                    pos_branch = VALUES(pos_branch),
                                    status = VALUES(status),
                                    sync_secret_hash = VALUES(sync_secret_hash),
                                    sync_secret_encrypted = VALUES(sync_secret_encrypted),
                                    updated_at = NOW(6)
        ");
        $stmt->bind_param('ssiisss', $branchUuid, $branchName, $tenant, $branch, $status, $secretHash, $encryptedSecret);
        $stmt->execute();
        $stmt->close();

        $cloudBaseUrl = rtrim((string) ($options['cloud-base-url'] ?? $options['cloud_base_url'] ?? ''), '/');

        return [
            'branch_uuid' => $branchUuid,
            'branch_name' => $branchName,
            'pos_tenant' => $tenant,
            'pos_branch' => $branch,
            'status' => $status,
            'sync_secret_hash' => $secretHash,
            'secret_encrypted' => $encryptedSecret !== null,
            'branch_env' => $this->branchEnv($branchUuid, $secret, $cloudBaseUrl),
        ];
    }

    public function listBranches(mysqli $conn): array
    {
        if (!$this->tableExists($conn, 'cloud_branches')) {
            return [];
        }

        $encryptedSelect = $this->columnExists($conn, 'cloud_branches', 'sync_secret_encrypted')
            ? "CASE WHEN sync_secret_encrypted IS NULL OR sync_secret_encrypted = '' THEN 0 ELSE 1 END"
            : '0';

        $result = $conn->query("
            SELECT branch_uuid, branch_name, pos_tenant, pos_branch, status, sync_secret_hash,
                   {$encryptedSelect} AS has_encrypted_secret,
                   last_seen_at, updated_at
            FROM cloud_branches
            ORDER BY updated_at DESC, id DESC
            LIMIT 100
        ");

        $branches = [];
        while ($row = $result->fetch_assoc()) {
            $branches[] = [
                'branch_uuid' => (string) $row['branch_uuid'],
                'branch_name' => (string) ($row['branch_name'] ?? ''),
                'pos_tenant' => $row['pos_tenant'] === null ? null : (int) $row['pos_tenant'],
                'pos_branch' => $row['pos_branch'] === null ? null : (int) $row['pos_branch'],
                'status' => (string) $row['status'],
                'sync_secret_hash' => (string) ($row['sync_secret_hash'] ?? ''),
                'has_encrypted_secret' => !empty($row['has_encrypted_secret']),
                'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $branches;
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS table_count
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ((int) ($row['table_count'] ?? 0)) > 0;
    }

    private function columnExists(mysqli $conn, string $table, string $column): bool
    {
        $stmt = $conn->prepare("
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

    public function branchEnv(string $branchUuid, string $secret, string $cloudBaseUrl): array
    {
        return [
            'POSMAIN_ROLE' => 'branch',
            'POSMAIN_BRANCH_UUID' => $branchUuid,
            'POSMAIN_CLOUD_BASE_URL' => $cloudBaseUrl,
            'POSMAIN_BRANCH_SYNC_SECRET' => $secret,
            'POSMAIN_SYNC_OUTBOX_ENABLED' => '1',
            'POSMAIN_BRANCH_SYNC_ENABLED' => '1',
            'POSMAIN_SYNC_WORKER_ENABLED' => '1',
            'POSMAIN_MENU_SYNC_ENABLED' => '1',
        ];
    }

    public function envBlock(array $env): string
    {
        $lines = [];
        foreach ($env as $key => $value) {
            $lines[] = $key . '=' . (string) $value;
        }

        return implode("\n", $lines);
    }

    private function nullableString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
