<?php

class SyncBranchIdentity
{
    private const LOCAL_ROW_ID = 1;

    public function ensure(mysqli $conn, array $config = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $this->assertSchemaExists($conn);

        $branchConfig = $config['branch'] ?? $config;
        $configuredUuid = trim((string) ($branchConfig['uuid'] ?? ''));
        $existing = $this->find($conn);

        if ($existing) {
            if ($configuredUuid !== '' && $configuredUuid !== (string) $existing['branch_uuid']) {
                throw new RuntimeException('Configured POSMAIN_BRANCH_UUID conflicts with stored sync_branch_identity.branch_uuid.');
            }

            $this->updateMetadata($conn, $branchConfig, (string) $existing['branch_uuid']);
            return $this->find($conn);
        }

        $branchUuid = $configuredUuid !== '' ? $configuredUuid : self::generateUuidV4();
        if (!self::isUuid($branchUuid)) {
            throw new InvalidArgumentException('Branch UUID must be a valid UUID string.');
        }

        $branchName = $this->nullableString($branchConfig['name'] ?? null);
        $posTenant = $this->nullableInt($branchConfig['pos_tenant'] ?? null);
        $posBranch = $this->nullableInt($branchConfig['pos_branch'] ?? null);
        $cloudBaseUrl = $this->nullableString($branchConfig['cloud_base_url'] ?? null);

        $stmt = $conn->prepare("
            INSERT INTO sync_branch_identity (
                id, branch_uuid, branch_name, pos_tenant, pos_branch, cloud_base_url
            ) VALUES (1, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssiis', $branchUuid, $branchName, $posTenant, $posBranch, $cloudBaseUrl);
        $stmt->execute();
        $stmt->close();

        return $this->find($conn);
    }

    public function current(mysqli $conn): array
    {
        $this->assertSchemaExists($conn);

        $row = $this->find($conn);
        if (!$row) {
            throw new RuntimeException('sync_branch_identity id=1 is missing. Run ensure() before starting sync workers.');
        }

        return $row;
    }

    public function find(mysqli $conn): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
            FROM sync_branch_identity
            WHERE id = ?
            LIMIT 1
        ");
        $id = self::LOCAL_ROW_ID;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public static function isUuid(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }

    public static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function assertSchemaExists(mysqli $conn): void
    {
        $result = $conn->query("SHOW TABLES LIKE 'sync_branch_identity'");
        if (!$result || $result->num_rows < 1) {
            throw new RuntimeException('sync_branch_identity table is missing. Run tools/run_migrations.php --apply first.');
        }
    }

    private function updateMetadata(mysqli $conn, array $branchConfig, string $branchUuid): void
    {
        $branchName = $this->nullableString($branchConfig['name'] ?? null);
        $posTenant = $this->nullableInt($branchConfig['pos_tenant'] ?? null);
        $posBranch = $this->nullableInt($branchConfig['pos_branch'] ?? null);
        $cloudBaseUrl = $this->nullableString($branchConfig['cloud_base_url'] ?? null);

        $stmt = $conn->prepare("
            UPDATE sync_branch_identity
            SET branch_name = COALESCE(?, branch_name),
                pos_tenant = COALESCE(?, pos_tenant),
                pos_branch = COALESCE(?, pos_branch),
                cloud_base_url = COALESCE(?, cloud_base_url)
            WHERE id = 1
              AND branch_uuid = ?
        ");
        $stmt->bind_param('siiss', $branchName, $posTenant, $posBranch, $cloudBaseUrl, $branchUuid);
        $stmt->execute();
        $stmt->close();
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
