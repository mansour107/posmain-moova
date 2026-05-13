<?php

require_once __DIR__ . '/BranchSecretProvider.php';

class DatabaseBranchSecretProvider implements BranchSecretProvider
{
    private mysqli $conn;
    private array $secrets;

    public function __construct(mysqli $conn, array $secrets)
    {
        $this->conn = $conn;
        $this->secrets = $secrets;
    }

    public static function fromConfig(mysqli $conn, array $config): self
    {
        $secrets = $config['sync']['cloud_branch_secrets'] ?? [];
        if (!is_array($secrets)) {
            $secrets = [];
        }

        $branchUuid = trim((string) ($config['branch']['uuid'] ?? ''));
        $branchSecret = (string) ($config['sync']['branch_secret'] ?? '');
        if ($branchUuid !== '' && $branchSecret !== '' && !array_key_exists($branchUuid, $secrets)) {
            $secrets[$branchUuid] = $branchSecret;
        }

        return new self($conn, $secrets);
    }

    public function getSecretForBranch(string $branchUuid): ?string
    {
        if (!$this->isBranchActive($branchUuid)) {
            return null;
        }

        if (!array_key_exists($branchUuid, $this->secrets)) {
            return null;
        }

        return (string) $this->secrets[$branchUuid];
    }

    public function isBranchActive(string $branchUuid): bool
    {
        $stmt = $this->conn->prepare("
            SELECT status
            FROM cloud_branches
            WHERE branch_uuid = ?
            LIMIT 1
        ");
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row && (string) $row['status'] === 'active';
    }

    public function touchLastSeen(string $branchUuid): void
    {
        $stmt = $this->conn->prepare("
            UPDATE cloud_branches
            SET last_seen_at = NOW(6)
            WHERE branch_uuid = ?
              AND status = 'active'
        ");
        $stmt->bind_param('s', $branchUuid);
        $stmt->execute();
        $stmt->close();
    }
}
