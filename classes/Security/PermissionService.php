<?php

class PermissionService
{
    public const ADMIN_ROLE_IMMUTABLE = true;

    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public static function forConnection(mysqli $conn): self
    {
        return new self($conn);
    }

    public function assertKnownPermissionKey(string $permissionKey): void
    {
        $permissionKey = trim($permissionKey);
        if ($permissionKey === '' || !isset($this->permissionMap()[$permissionKey])) {
            throw new InvalidArgumentException('PERMISSION_KEY_UNKNOWN');
        }
    }

    public function check(int $userId, string $permissionKey, ?int $roleId = null): bool
    {
        $this->assertKnownPermissionKey($permissionKey);

        $roleFlags = $this->resolveRoleFlags($userId, $roleId);
        $session = [
            'userid' => $userId,
            'login' => true,
            'usrole' => $roleFlags['id'] ?? $roleId ?? 0,
        ];

        return auth_guard_session_has_permission($permissionKey, $roleFlags, $session, $this->conn);
    }

    /**
     * @return array{limit_value: ?float, is_unlimited: bool}|null
     */
    public function limit(int $userId, string $permissionKey, ?int $roleId = null): ?array
    {
        $this->assertKnownPermissionKey($permissionKey);

        if ($this->isAdminUser($userId, $roleId)) {
            return ['limit_value' => null, 'is_unlimited' => true];
        }

        $grantLimit = $this->userGrantLimit($userId, $permissionKey);
        if ($grantLimit !== null) {
            return $grantLimit;
        }

        $resolvedRoleId = $roleId ?? $this->roleIdForUser($userId);
        if ($resolvedRoleId > 0) {
            $limits = $this->roleCapabilityLimits($resolvedRoleId);
            if (isset($limits[$permissionKey])) {
                return $limits[$permissionKey];
            }
        }

        return null;
    }

    public function checkAmount(int $userId, string $permissionKey, float $amount, ?int $roleId = null): bool
    {
        if (!$this->check($userId, $permissionKey, $roleId)) {
            return false;
        }

        $limit = $this->limit($userId, $permissionKey, $roleId);
        if ($limit === null || !empty($limit['is_unlimited'])) {
            return true;
        }

        if ($limit['limit_value'] === null) {
            return true;
        }

        return $amount <= (float) $limit['limit_value'];
    }

    public function permissionsVersion(): string
    {
        if (!$this->appSettingsTableExists()) {
            return '0';
        }

        $stmt = $this->conn->prepare(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'permissions_version' LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $version = trim((string) ($row['setting_value'] ?? '0'));

        return $version !== '' ? $version : '0';
    }

    public function bumpPermissionsVersion(): void
    {
        if (!$this->appSettingsTableExists()) {
            return;
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES ('permissions_version', '1')
             ON DUPLICATE KEY UPDATE setting_value = CAST(setting_value AS UNSIGNED) + 1"
        );
        $stmt->execute();
        $stmt->close();

        if (function_exists('auth_guard_invalidate_capabilities_cache')) {
            auth_guard_invalidate_capabilities_cache();
        }
    }

    public function posAutolockSeconds(): int
    {
        if (!$this->appSettingsTableExists()) {
            return 90;
        }

        $stmt = $this->conn->prepare(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'pos_autolock_seconds' LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $seconds = (int) ($row['setting_value'] ?? 90);

        return $seconds > 0 ? $seconds : 90;
    }

    /**
     * @return array<string, bool>
     */
    public function effectivePermissionsForUser(int $userId, ?int $roleId = null): array
    {
        if (!function_exists('auth_guard_permission_map')) {
            require_once __DIR__ . '/../../includes/auth_guard.php';
        }

        $roleFlags = $roleId !== null && $roleId > 0
            ? $this->roleFlagsById($roleId)
            : $this->roleFlagsForUser($userId);

        $permissions = [];
        foreach (array_keys(auth_guard_permission_map()) as $permission) {
            $permissions[$permission] = auth_guard_session_has_permission(
                $permission,
                $roleFlags,
                ['userid' => $userId, 'login' => true],
                $this->conn
            );
        }

        return $permissions;
    }

    /**
     * @return array<string, array{limit_value: ?float, is_unlimited: bool}>
     */
    public function roleCapabilityLimits(int $roleId): array
    {
        $result = $this->conn->query("SHOW TABLES LIKE 'role_capabilities'");
        if (!$result || $result->num_rows < 1) {
            return [];
        }

        $stmt = $this->conn->prepare(
            'SELECT permission_key, limit_value, is_unlimited
               FROM role_capabilities
              WHERE role_id = ? AND is_enabled = 1'
        );
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $rows = $stmt->get_result();
        $limits = [];
        while ($row = $rows->fetch_assoc()) {
            $key = trim((string) ($row['permission_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $limits[$key] = [
                'limit_value' => $row['limit_value'] !== null ? (float) $row['limit_value'] : null,
                'is_unlimited' => (int) ($row['is_unlimited'] ?? 1) === 1,
            ];
        }
        $stmt->close();

        return $limits;
    }

    public function isSystemRole(int $roleId): bool
    {
        $stmt = $this->conn->prepare(
            'SELECT is_system FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1'
        );
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['is_system'] ?? 0) === 1;
    }

    public function assertRoleEditable(int $roleId): void
    {
        if ($roleId < 1) {
            throw new RuntimeException('ROLE_NOT_FOUND');
        }

        if (self::ADMIN_ROLE_IMMUTABLE && $this->isOwnerRole($roleId)) {
            throw new RuntimeException('ADMIN_ROLE_IMMUTABLE');
        }

        if ($this->isSystemRole($roleId)) {
            throw new RuntimeException('SYSTEM_ROLE_LOCKED');
        }
    }

    public function isOwnerRole(int $roleId): bool
    {
        if ($roleId === 1) {
            return true;
        }

        $stmt = $this->conn->prepare(
            "SELECT role_key FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1"
        );
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return strtolower((string) ($row['role_key'] ?? '')) === 'owner';
    }

    private function permissionMap(): array
    {
        if (!function_exists('auth_guard_permission_map')) {
            require_once __DIR__ . '/../../includes/auth_guard.php';
        }

        return auth_guard_permission_map();
    }

    private function resolveRoleFlags(int $userId, ?int $roleId): array
    {
        if ($roleId !== null && $roleId > 0) {
            return $this->roleFlagsById($roleId);
        }

        return $this->roleFlagsForUser($userId);
    }

    private function roleIdForUser(int $userId): int
    {
        $flags = $this->roleFlagsForUser($userId);

        return (int) ($flags['id'] ?? 0);
    }

    private function isAdminUser(int $userId, ?int $roleId): bool
    {
        if (!function_exists('auth_guard_is_admin_session')) {
            require_once __DIR__ . '/../../includes/auth_guard.php';
        }

        $roleFlags = $this->resolveRoleFlags($userId, $roleId);

        return auth_guard_is_admin_session(['userid' => $userId, 'login' => true, 'usrole' => $roleFlags['id'] ?? $roleId], $roleFlags);
    }

    /**
     * @return array{limit_value: ?float, is_unlimited: bool}|null
     */
    private function userGrantLimit(int $userId, string $permissionKey): ?array
    {
        if ($userId < 1) {
            return null;
        }

        $result = $this->conn->query("SHOW TABLES LIKE 'user_permission_grants'");
        if (!$result || $result->num_rows < 1) {
            return null;
        }

        if (!class_exists('UserPermissionGrantService', false)) {
            require_once __DIR__ . '/UserPermissionGrantService.php';
        }
        if (!(new UserPermissionGrantService())->userUsesOverrides($this->conn, $userId)) {
            return null;
        }

        $stmt = $this->conn->prepare(
            "SELECT limit_value, is_unlimited, effect
               FROM user_permission_grants
              WHERE user_id = ?
                AND permission_key = ?
                AND tenant = 0
                AND branch = 0
                AND (expires_at IS NULL OR expires_at > NOW())
              LIMIT 1"
        );
        $stmt->bind_param('is', $userId, $permissionKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || strtolower((string) ($row['effect'] ?? '')) !== 'grant') {
            return null;
        }

        return [
            'limit_value' => $row['limit_value'] !== null ? (float) $row['limit_value'] : null,
            'is_unlimited' => (int) ($row['is_unlimited'] ?? 1) === 1,
        ];
    }

    private function roleFlagsById(int $roleId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1'
        );
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }

    private function roleFlagsForUser(int $userId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT p.* FROM users u
              INNER JOIN usr_pwrs p ON p.id = u.userrole
              WHERE u.id = ? AND COALESCE(u.isdeleted, 0) != 1
                AND COALESCE(p.isdeleted, 0) != 1
              LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }

    private function appSettingsTableExists(): bool
    {
        $result = $this->conn->query("SHOW TABLES LIKE 'app_settings'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
