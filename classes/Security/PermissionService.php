<?php

require_once __DIR__ . '/../Financial/Decimal.php';

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
     * @return array{limit_value: ?string, is_unlimited: bool}|null
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

    public function checkAmount(int $userId, string $permissionKey, $amount, ?int $roleId = null): bool
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

        $amountDecimal = FinancialDecimal::normalize(
            is_float($amount) ? sprintf('%.6F', $amount) : $amount,
            6
        );
        $limitDecimal = FinancialDecimal::normalize((string) $limit['limit_value'], 6);

        return FinancialDecimal::compare($amountDecimal, $limitDecimal, 6) <= 0;
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
     * @return array<string, array{limit_value: ?string, is_unlimited: bool}>
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
                'limit_value' => $row['limit_value'] !== null ? (string) $row['limit_value'] : null,
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

    public function assertRolePermissionsEditable(int $roleId): void
    {
        if ($roleId < 1) {
            throw new RuntimeException('ROLE_NOT_FOUND');
        }

        if ($this->isOwnerRole($roleId)) {
            throw new RuntimeException('ADMIN_ROLE_IMMUTABLE');
        }
    }

    public function assertRoleEditable(int $roleId): void
    {
        $this->assertRolePermissionsEditable($roleId);

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

    /**
     * Roles that can approve a manager override for this permission
     * (same gate as authenticateManagerOverride, without user-level grants).
     *
     * @return list<array{id: int, role_key: ?string, name: string}>
     */
    public function approverRolesForPermission(string $permissionKey): array
    {
        $permissionKey = trim($permissionKey);
        if ($permissionKey === '' || !isset($this->permissionMap()[$permissionKey])) {
            return [];
        }

        if (!function_exists('auth_guard_session_has_permission')) {
            require_once __DIR__ . '/../../includes/auth_guard.php';
        }

        $holders = [];
        foreach ($this->activeRoles() as $role) {
            $roleId = (int) ($role['id'] ?? 0);
            if ($roleId < 1) {
                continue;
            }
            $session = ['userid' => 0, 'login' => true, 'usrole' => $roleId];
            if (!auth_guard_is_admin_session($session, $role)
                && !auth_guard_session_has_permission($permissionKey, $role, $session, $this->conn)) {
                continue;
            }
            $name = trim((string) ($role['rollname'] ?? ''));
            $roleKey = trim((string) ($role['role_key'] ?? ''));
            $holders[] = [
                'id' => $roleId,
                'role_key' => $roleKey !== '' ? $roleKey : null,
                'name' => $name !== '' ? $name : ('دور #' . $roleId),
            ];
        }

        return $this->sortApproverRoles($holders);
    }

    /**
     * Compact permission → approver role labels for POS override PIN UI.
     *
     * @return array<string, list<array{role_key: ?string, name: string}>>
     */
    public function approverRoleIndex(): array
    {
        $index = [];
        foreach (array_keys($this->permissionMap()) as $permissionKey) {
            $roles = $this->approverRolesForPermission($permissionKey);
            if ($roles === []) {
                continue;
            }
            $index[$permissionKey] = array_map(static function (array $role): array {
                return [
                    'role_key' => $role['role_key'],
                    'name' => $role['name'],
                ];
            }, $roles);
        }

        return $index;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeRoles(): array
    {
        $result = $this->conn->query(
            'SELECT *
               FROM usr_pwrs
              WHERE COALESCE(isdeleted, 0) != 1
                AND COALESCE(is_active, 1) = 1
              ORDER BY id ASC'
        );
        if (!$result instanceof mysqli_result) {
            return [];
        }

        $roles = [];
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
        }

        return $roles;
    }

    /**
     * @param list<array{id: int, role_key: ?string, name: string}> $roles
     * @return list<array{id: int, role_key: ?string, name: string}>
     */
    private function sortApproverRoles(array $roles): array
    {
        $rank = [
            'owner' => 0,
            'manager' => 1,
            'cashier' => 2,
            'waiter' => 3,
            'kitchen' => 4,
        ];
        usort($roles, static function (array $a, array $b) use ($rank): int {
            $keyA = strtolower((string) ($a['role_key'] ?? ''));
            $keyB = strtolower((string) ($b['role_key'] ?? ''));
            $rankA = $rank[$keyA] ?? 100;
            $rankB = $rank[$keyB] ?? 100;
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $roles;
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
     * @return array{limit_value: ?string, is_unlimited: bool}|null
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
            'limit_value' => $row['limit_value'] !== null ? (string) $row['limit_value'] : null,
            'is_unlimited' => (int) ($row['is_unlimited'] ?? 1) === 1,
        ];
    }

    private function roleFlagsById(int $roleId): array
    {
        if (!$this->tableExists('usr_pwrs')) {
            return [];
        }
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
        if (!$this->tableExists('users') || !$this->tableExists('usr_pwrs')) {
            return [];
        }
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
        return $this->tableExists('app_settings');
    }

    private function tableExists(string $table): bool
    {
        $escaped = $this->conn->real_escape_string($table);
        $result = $this->conn->query("SHOW TABLES LIKE '{$escaped}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
