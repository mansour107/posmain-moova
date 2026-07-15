<?php

require_once __DIR__ . '/TeamHubService.php';
require_once __DIR__ . '/RolePermissionSyncService.php';
require_once __DIR__ . '/PinService.php';
require_once __DIR__ . '/UserLifecycleGuardService.php';
require_once __DIR__ . '/PermissionService.php';
require_once __DIR__ . '/SecurityAuditLogger.php';
require_once __DIR__ . '/../PasswordService.php';

class TeamHubMutationService
{
    public function __construct(
        private mysqli $conn,
        private TeamHubService $hub
    ) {
    }

    /** @param array<string, mixed> $input */
    public function createStaff(array $input, int $actorUserId): array
    {
        $displayName = trim((string) ($input['display_name'] ?? ''));
        $uname = trim((string) ($input['uname'] ?? ''));
        if ($uname === '' && $displayName !== '') {
            $uname = TeamHubService::slugifyUsername($displayName);
        }
        $userrole = (int) ($input['userrole'] ?? 0);
        $phone = trim((string) ($input['phone'] ?? ''));
        $pin = trim((string) ($input['pin'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($password === '') {
            $password = 'ChangeMe!' . random_int(1000, 9999);
        }
        $isWaiter = !empty($input['is_waiter']) ? 1 : 0;
        $lifecycle = new UserLifecycleGuardService();

        if ($uname === '' || $userrole < 1) {
            throw new InvalidArgumentException('INVALID_INPUT');
        }

        $lifecycle->assertDisplayNameUnique($this->conn, $displayName);
        $lifecycle->assertNoPrivilegeEscalation($this->conn, $actorUserId, null, $userrole);

        $hashpass = PasswordService::hashPassword($password);
        $img = '';
        $stmt = $this->conn->prepare(
            'INSERT INTO users (uname, password, usertype, userrole, img, is_waiter, display_name, phone)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssiisiss', $uname, $hashpass, $userrole, $userrole, $img, $isWaiter, $displayName, $phone);
        $stmt->execute();
        $newUserId = (int) $this->conn->insert_id;
        $stmt->close();

        if ($newUserId < 1) {
            throw new RuntimeException('CREATE_FAILED');
        }

        $pinService = new PinService();
        if ($pin === '') {
            $pin = $pinService->generateAvailablePin($this->conn);
        }
        try {
            $pinService->setPinForUser($this->conn, $newUserId, $pin, [
                'must_change' => false,
                'bump_auth_version' => true,
            ]);
        } catch (Throwable $exception) {
            $this->conn->query('UPDATE users SET isdeleted = 1 WHERE id = ' . $newUserId);
            throw $exception;
        }

        (new PermissionService($this->conn))->bumpPermissionsVersion();
        (new SecurityAuditLogger())->record($this->conn, 'user_created', [
            'target_type' => 'user',
            'target_id' => $newUserId,
            'metadata' => ['username' => $uname],
        ]);

        return [
            'success' => true,
            'user_id' => $newUserId,
            'pin' => $pin,
            'staff' => $this->hub->staffDetail($newUserId),
        ];
    }

    /** @param array<string, mixed> $input */
    public function updateStaff(array $input, int $actorUserId): array
    {
        $id = (int) ($input['user_id'] ?? 0);
        if ($id < 1) {
            throw new InvalidArgumentException('USER_ID_REQUIRED');
        }

        $displayName = trim((string) ($input['display_name'] ?? ''));
        $uname = trim((string) ($input['uname'] ?? ''));
        $userrole = (int) ($input['userrole'] ?? 0);
        $phone = trim((string) ($input['phone'] ?? ''));
        $pin = trim((string) ($input['pin'] ?? ''));
        $clearPin = !empty($input['clear_pin']);
        $isWaiter = !empty($input['is_waiter']) ? 1 : 0;
        $lifecycle = new UserLifecycleGuardService();

        $previousRole = 0;
        $previousIsWaiter = 0;
        $previousStmt = $this->conn->prepare(
            'SELECT userrole, is_waiter FROM users WHERE id = ? LIMIT 1'
        );
        $previousStmt->bind_param('i', $id);
        $previousStmt->execute();
        $previousRow = $previousStmt->get_result()->fetch_assoc();
        $previousStmt->close();
        if (!$previousRow) {
            throw new RuntimeException('USER_NOT_FOUND');
        }
        $previousRole = (int) ($previousRow['userrole'] ?? 0);
        $previousIsWaiter = (int) ($previousRow['is_waiter'] ?? 0);

        $lifecycle->assertDisplayNameUnique($this->conn, $displayName, $id);
        if ($userrole > 0) {
            $lifecycle->assertNoPrivilegeEscalation($this->conn, $actorUserId, $id, $userrole);
        }

        $fields = ['display_name = ?', 'phone = ?', 'is_waiter = ?'];
        $types = 'ssi';
        $values = [$displayName, $phone, $isWaiter];
        if ($uname !== '') {
            $fields[] = 'uname = ?';
            $types .= 's';
            $values[] = $uname;
        }
        if ($userrole > 0) {
            $fields[] = 'userrole = ?';
            $types .= 'i';
            $values[] = $userrole;
            $fields[] = 'usertype = ?';
            $types .= 'i';
            $values[] = $userrole;
        }
        $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $types .= 'i';
        $values[] = $id;
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();

        $pinService = new PinService();
        $revealedPin = null;
        if ($clearPin) {
            $pinService->clearPinForUser($this->conn, $id);
        } elseif ($pin !== '') {
            $pinService->setPinForUser($this->conn, $id, $pin, [
                'must_change' => false,
                'bump_auth_version' => true,
            ]);
            $revealedPin = $pin;
        }
        $securityIdentityChanged = ($userrole > 0 && $userrole !== $previousRole)
            || $isWaiter !== $previousIsWaiter;
        if ($securityIdentityChanged && !$clearPin && $pin === '') {
            $pinService->bumpAuthVersion($this->conn, $id);
        }

        (new SecurityAuditLogger())->record($this->conn, 'user_updated', [
            'target_type' => 'user',
            'target_id' => $id,
        ]);

        return [
            'success' => true,
            'pin' => $revealedPin,
            'staff' => $this->hub->staffDetail($id),
        ];
    }

    /** @param array<string, mixed> $input */
    public function createRole(array $input): array
    {
        $rollname = trim((string) ($input['rollname'] ?? ''));
        $info = trim((string) ($input['info'] ?? ''));
        $cloneFrom = trim((string) ($input['clone_from'] ?? 'cashier'));
        if ($rollname === '') {
            throw new InvalidArgumentException('ROLLNAME_REQUIRED');
        }

        $permissions = $this->resolveClonePermissions($input, $cloneFrom);
        $legacyValues = RolePermissionSyncService::legacyFlagValuesForPermissions($permissions);

        $insert = $this->conn->prepare(
            'INSERT INTO usr_pwrs (rollname, info, is_active, is_system, role_key) VALUES (?, ?, 1, 0, NULL)'
        );
        $insert->bind_param('ss', $rollname, $info);
        $insert->execute();
        $roleId = (int) $this->conn->insert_id;
        $insert->close();
        if ($roleId < 1) {
            throw new RuntimeException('CREATE_FAILED');
        }

        RolePermissionSyncService::applyLegacyFlagValuesToRole($this->conn, $roleId, $legacyValues);
        RolePermissionSyncService::syncRoleCapabilitiesForPermissions($this->conn, $roleId, $permissions);
        (new PermissionService($this->conn))->bumpPermissionsVersion();

        return [
            'success' => true,
            'role_id' => $roleId,
            'role' => $this->hub->roleDetail($roleId),
        ];
    }

    /** @param array<string, mixed> $input */
    public function saveRolePermissions(array $input): array
    {
        $roleId = (int) ($input['role_id'] ?? 0);
        if ($roleId < 1) {
            throw new InvalidArgumentException('ROLE_ID_REQUIRED');
        }

        (new PermissionService($this->conn))->assertRolePermissionsEditable($roleId);
        $enabled = $this->normalizePermissionList($input['permissions'] ?? []);
        $legacyValues = RolePermissionSyncService::legacyFlagValuesForPermissions($enabled);
        RolePermissionSyncService::applyLegacyFlagValuesToRole($this->conn, $roleId, $legacyValues, true);

        auth_guard_invalidate_capabilities_cache();
        (new PermissionService($this->conn))->bumpPermissionsVersion();

        $limitsPayload = $this->buildLimitsPayload(
            $enabled,
            $input['limit_value'] ?? [],
            $input['limit_unlimited'] ?? []
        );
        if ($limitsPayload !== []) {
            RolePermissionSyncService::syncRoleCapabilityLimits($this->conn, $roleId, $limitsPayload);
        }
        RolePermissionSyncService::syncRoleCapabilitiesForPermissions($this->conn, $roleId, $enabled);

        return ['success' => true, 'role' => $this->hub->roleDetail($roleId)];
    }

    public function restoreRolePreset(int $roleId, string $roleKey): array
    {
        if ($roleId < 1 || trim($roleKey) === '') {
            throw new InvalidArgumentException('INVALID_INPUT');
        }

        try {
            RolePermissionSyncService::restorePresetRole($this->conn, $roleKey);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('PRESET_UNKNOWN');
        }

        return ['success' => true, 'role' => $this->hub->roleDetail($roleId)];
    }

    public function deleteRole(int $roleId): array
    {
        if ($roleId < 1) {
            throw new InvalidArgumentException('ROLE_ID_REQUIRED');
        }

        (new PermissionService($this->conn))->assertRoleEditable($roleId);
        $countStmt = $this->conn->prepare(
            'SELECT COUNT(*) AS c FROM users WHERE userrole = ? AND COALESCE(isdeleted, 0) != 1'
        );
        $countStmt->bind_param('i', $roleId);
        $countStmt->execute();
        $count = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $countStmt->close();
        if ($count > 0) {
            throw new RuntimeException('ROLE_HAS_STAFF:' . $count);
        }

        $stmt = $this->conn->prepare('UPDATE usr_pwrs SET isdeleted = 1 WHERE id = ?');
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $stmt->close();
        (new PermissionService($this->conn))->bumpPermissionsVersion();

        return ['success' => true];
    }

    public function deactivateStaff(int $userId, int $actorUserId): array
    {
        if ($userId < 1 || $userId === $actorUserId) {
            throw new InvalidArgumentException('INVALID_USER');
        }

        $lifecycle = new UserLifecycleGuardService();
        $lifecycle->assertNoPrivilegeEscalation($this->conn, $actorUserId, $userId, null);
        $lifecycle->softDeleteUser($this->conn, $userId);
        (new PermissionService($this->conn))->bumpPermissionsVersion();

        return ['success' => true];
    }

    public function deleteStaff(int $userId, int $actorUserId): array
    {
        if ($userId < 1 || $userId === $actorUserId) {
            throw new InvalidArgumentException('INVALID_USER');
        }

        (new UserLifecycleGuardService())->permanentlyDeleteUser($this->conn, $userId);
        (new SecurityAuditLogger())->record($this->conn, 'user_deleted', [
            'target_type' => 'user',
            'target_id' => $userId,
            'metadata' => ['permanent' => true],
        ]);
        (new PermissionService($this->conn))->bumpPermissionsVersion();

        return ['success' => true];
    }

    public function reactivateStaff(int $userId, int $actorUserId): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('INVALID_USER');
        }

        (new UserLifecycleGuardService())->assertNoPrivilegeEscalation($this->conn, $actorUserId, $userId, null);
        $stmt = $this->conn->prepare('UPDATE users SET isdeleted = 0 WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
        (new PermissionService($this->conn))->bumpPermissionsVersion();

        return ['success' => true];
    }

    public function unlockPin(int $userId): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('INVALID_USER');
        }

        (new PinService())->clearUserFailures($this->conn, $userId);
        (new SecurityAuditLogger())->record($this->conn, 'user_pin_unlocked', [
            'target_type' => 'user',
            'target_id' => $userId,
        ]);

        $staff = $this->hub->staffDetail($userId);
        if (!$staff) {
            throw new RuntimeException('USER_NOT_FOUND');
        }

        return ['success' => true, 'staff' => $staff];
    }

    public function resetPin(int $userId, string $pin = ''): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('INVALID_USER');
        }

        $pinService = new PinService();
        if ($pin === '') {
            $pin = $pinService->generateAvailablePin($this->conn, $userId);
        }

        $pinService->setPinForUser($this->conn, $userId, $pin, [
            'must_change' => false,
            'bump_auth_version' => true,
        ]);
        (new SecurityAuditLogger())->record($this->conn, 'user_pin_reset', [
            'target_type' => 'user',
            'target_id' => $userId,
        ]);

        // One-time display only — never persisted reversibly.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['posmain_one_time_pin_reveal'] = [
                'user_id' => $userId,
                'pin' => $pin,
                'expires' => time() + 120,
            ];
        }

        $staff = $this->hub->staffDetail($userId);
        if (!$staff) {
            throw new RuntimeException('USER_NOT_FOUND');
        }

        return [
            'success' => true,
            'pin' => $pin,
            'pin_once' => true,
            'must_change' => false,
            'staff' => $staff,
        ];
    }

    /** @param array<string, mixed> $input */
    public function saveUserPermissions(array $input, int $actorUserId): array
    {
        $userId = (int) ($input['user_id'] ?? 0);
        if ($userId < 1) {
            throw new InvalidArgumentException('INVALID_INPUT');
        }

        $permissionMode = ($input['permission_mode'] ?? 'role_only') === 'role_with_overrides'
            ? 'role_with_overrides'
            : 'role_only';
        $stmt = $this->conn->prepare(
            'UPDATE users SET permission_mode = ? WHERE id = ? AND COALESCE(isdeleted, 0) != 1'
        );
        $stmt->bind_param('si', $permissionMode, $userId);
        $stmt->execute();
        $stmt->close();

        if (!class_exists('UserPermissionGrantService', false)) {
            require_once __DIR__ . '/UserPermissionGrantService.php';
        }
        $grantService = new UserPermissionGrantService();
        if ($grantService->tableExists($this->conn)) {
            $this->conn->query('DELETE FROM user_permission_grants WHERE user_id = ' . (int) $userId);
            if ($permissionMode === 'role_with_overrides') {
                $this->insertUserGrants($userId, $actorUserId, $input['grant'] ?? [], 'grant');
                $this->insertUserGrants($userId, $actorUserId, $input['deny'] ?? [], 'deny');
            }
        }

        auth_guard_invalidate_capabilities_cache();
        $grantService->invalidateSessionCapabilities();
        (new PermissionService($this->conn))->bumpPermissionsVersion();
        (new PinService())->bumpAuthVersion($this->conn, $userId);
        (new SecurityAuditLogger())->record($this->conn, 'user_permissions_updated', [
            'target_type' => 'user',
            'target_id' => $userId,
            'metadata' => ['permission_mode' => $permissionMode],
        ]);

        return [
            'success' => true,
            'permissions' => $this->hub->userPermissionsDetail($userId),
        ];
    }

    /** @param array<string, mixed> $input */
    private function resolveClonePermissions(array $input, string $cloneFrom): array
    {
        $definitions = RolePermissionSyncService::presetRoleDefinitions();
        if ($cloneFrom !== '' && $cloneFrom !== 'empty' && isset($definitions[$cloneFrom])) {
            return $definitions[$cloneFrom]['permissions'] ?? [];
        }
        if ($cloneFrom === 'empty') {
            return [];
        }

        $cloneRoleId = (int) ($input['clone_role_id'] ?? 0);
        if ($cloneRoleId < 1) {
            return [];
        }

        $cloneStmt = $this->conn->prepare(
            'SELECT * FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1'
        );
        $cloneStmt->bind_param('i', $cloneRoleId);
        $cloneStmt->execute();
        $flags = $cloneStmt->get_result()->fetch_assoc() ?: [];
        $cloneStmt->close();

        return RolePermissionSyncService::enabledPermissionsFromRoleFlags($flags, $this->conn);
    }

    /** @return list<string> */
    private function normalizePermissionList(mixed $submitted): array
    {
        if (!is_array($submitted)) {
            return [];
        }

        $enabled = [];
        foreach ($submitted as $permissionKey) {
            $permissionKey = trim((string) $permissionKey);
            if ($permissionKey !== '') {
                $enabled[] = $permissionKey;
            }
        }

        return $enabled;
    }

    /** @return array<string, array{is_unlimited: bool, limit_value: float|null}> */
    private function buildLimitsPayload(array $enabled, mixed $limitValues, mixed $limitUnlimited): array
    {
        if (!is_array($limitValues) && !is_array($limitUnlimited)) {
            return [];
        }

        $limitsPayload = [];
        foreach (RolePermissionSyncService::limitablePermissions() as $limitPermission) {
            if (!in_array($limitPermission, $enabled, true)) {
                continue;
            }
            $unlimited = is_array($limitUnlimited) && !empty($limitUnlimited[$limitPermission]);
            $rawLimit = is_array($limitValues) ? ($limitValues[$limitPermission] ?? null) : null;
            $limitsPayload[$limitPermission] = [
                'is_unlimited' => $unlimited,
                'limit_value' => $unlimited ? null : (float) $rawLimit,
            ];
        }

        return $limitsPayload;
    }

    /** @param list<mixed> $permissionKeys */
    private function insertUserGrants(int $userId, int $actorUserId, array $permissionKeys, string $effect): void
    {
        $insert = $this->conn->prepare(
            'INSERT INTO user_permission_grants (user_id, permission_key, effect, created_by, tenant, branch)
             VALUES (?, ?, ?, ?, 0, 0)'
        );
        foreach ($permissionKeys as $permissionKey) {
            $permissionKey = trim((string) $permissionKey);
            if ($permissionKey === '') {
                continue;
            }
            $insert->bind_param('issi', $userId, $permissionKey, $effect, $actorUserId);
            $insert->execute();
        }
        $insert->close();
    }
}
