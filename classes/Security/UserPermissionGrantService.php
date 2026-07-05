<?php

class UserPermissionGrantService
{
    public function tableExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'user_permission_grants'");
        return $result && $result->num_rows > 0;
    }

    public function userUsesOverrides(mysqli $conn, int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $stmt = $conn->prepare("SELECT permission_mode FROM users WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return ($row['permission_mode'] ?? 'role_only') === 'role_with_overrides';
    }

    /**
     * @return array<string, string> permission_key => effect (grant|deny)
     */
    public function activeOverridesForUser(mysqli $conn, int $userId, int $tenant = 0, int $branch = 0): array
    {
        if ($userId < 1 || !$this->tableExists($conn) || !$this->userUsesOverrides($conn, $userId)) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT permission_key, effect
              FROM user_permission_grants
             WHERE user_id = ?
               AND tenant = ?
               AND branch = ?
               AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->bind_param('iii', $userId, $tenant, $branch);
        $stmt->execute();
        $result = $stmt->get_result();
        $overrides = [];
        while ($row = $result->fetch_assoc()) {
            $key = trim((string) ($row['permission_key'] ?? ''));
            $effect = strtolower(trim((string) ($row['effect'] ?? '')));
            if ($key !== '' && in_array($effect, ['grant', 'deny'], true)) {
                $overrides[$key] = $effect;
            }
        }
        $stmt->close();

        return $overrides;
    }

    public function invalidateSessionCapabilities(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['posmain_capabilities_cache'], $_SESSION['posmain_capabilities_version']);
        }
    }
}
