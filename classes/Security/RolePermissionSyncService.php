<?php

class RolePermissionSyncService
{
    public static function permissionToLegacyColumns(): array
    {
        if (!function_exists('auth_guard_permission_map')) {
            require_once __DIR__ . '/../../includes/auth_guard.php';
        }

        $inverse = [];
        foreach (auth_guard_permission_map() as $permission => $legacyFlags) {
            foreach ($legacyFlags as $flag) {
                if ($flag === '__admin_only') {
                    continue;
                }
                $inverse[$permission][] = $flag;
            }
        }

        return $inverse;
    }

    public static function columnsForPermission(string $permission): array
    {
        $map = self::permissionToLegacyColumns();

        return $map[$permission] ?? [];
    }

    public static function legacyColumnsForPermissions(array $permissions): array
    {
        $columns = [];
        foreach ($permissions as $permission) {
            foreach (self::columnsForPermission($permission) as $column) {
                $columns[$column] = true;
            }
        }

        return array_keys($columns);
    }

    public static function allManagedLegacyColumns(): array
    {
        $columns = [];
        foreach (self::permissionToLegacyColumns() as $legacyFlags) {
            foreach ($legacyFlags as $column) {
                $columns[$column] = true;
            }
        }

        return array_keys($columns);
    }

    public static function legacyFlagValuesForPermissions(array $enabledPermissions): array
    {
        if (!function_exists('auth_guard_permission_map')) {
            require_once __DIR__ . '/../../includes/auth_guard.php';
        }

        $values = [];
        foreach (self::allManagedLegacyColumns() as $column) {
            $values[$column] = 0;
        }

        $map = auth_guard_permission_map();
        foreach ($enabledPermissions as $permission) {
            $permission = trim((string) $permission);
            foreach ($map[$permission] ?? [] as $column) {
                if ($column === '__admin_only') {
                    continue;
                }
                $values[$column] = 1;
            }
        }

        return $values;
    }

    public static function enabledPermissionsFromRoleFlags(array $roleFlags, ?mysqli $conn = null): array
    {
        if (!function_exists('auth_guard_permission_map')) {
            require_once __DIR__ . '/../../includes/auth_guard.php';
        }

        $roleId = (int) ($roleFlags['id'] ?? 0);
        if ($conn instanceof mysqli && $roleId > 0) {
            $fromCapabilities = self::enabledPermissionsFromCapabilities($conn, $roleId);
            if ($fromCapabilities !== null) {
                return $fromCapabilities;
            }
        }

        return self::enabledPermissionsFromLegacyFlags($roleFlags);
    }

    /**
     * Permissions that must be granted only via role_capabilities / preset sync,
     * never inferred from legacy usr_pwrs flags during backfill.
     *
     * @return list<string>
     */
    public static function legacyBackfillExcludedPermissions(): array
    {
        return [
            'pos.void.item_after_send',
            'pos.void.post_send',
            'pos.void.paid',
        ];
    }

    /** @return list<string> */
    public static function enabledPermissionsFromLegacyFlags(array $roleFlags): array
    {
        if (!function_exists('auth_guard_permission_map')) {
            require_once __DIR__ . '/../../includes/auth_guard.php';
        }

        $enabled = [];
        $excluded = array_fill_keys(self::legacyBackfillExcludedPermissions(), true);
        foreach (auth_guard_permission_map() as $permission => $legacyFlags) {
            if (in_array('__admin_only', $legacyFlags, true)) {
                continue;
            }
            if (isset($excluded[$permission])) {
                continue;
            }
            if (auth_guard_role_flags_allow($roleFlags, $legacyFlags)) {
                $enabled[] = $permission;
            }
        }

        return $enabled;
    }

    /** @return list<string>|null */
    public static function enabledPermissionsFromCapabilities(mysqli $conn, int $roleId): ?array
    {
        $tableResult = $conn->query("SHOW TABLES LIKE 'role_capabilities'");
        if (!$tableResult || $tableResult->num_rows < 1) {
            return null;
        }

        $countStmt = $conn->prepare('SELECT COUNT(*) AS c FROM role_capabilities WHERE role_id = ?');
        $countStmt->bind_param('i', $roleId);
        $countStmt->execute();
        $count = (int) ($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
        $countStmt->close();
        if ($count < 1) {
            return null;
        }

        $stmt = $conn->prepare(
            'SELECT permission_key FROM role_capabilities WHERE role_id = ? AND is_enabled = 1'
        );
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $rows = $stmt->get_result();
        $enabled = [];
        while ($row = $rows->fetch_assoc()) {
            $key = trim((string) ($row['permission_key'] ?? ''));
            if ($key !== '') {
                $enabled[] = $key;
            }
        }
        $stmt->close();

        return $enabled;
    }

    public static function permissionGroups(): array
    {
        return [
            'POS' => ['pos.open', 'pos.sell.takeaway', 'pos.table.open', 'pos.table.move', 'pos.table.merge', 'pos.payment.take', 'pos.discount.apply', 'pos.discount.manager_override', 'pos.discount.manual_pct.limit', 'pos.price.override', 'pos.recipe_stock_override', 'pos.cancel.unpaid', 'pos.void.post_send', 'pos.void.item_after_send', 'pos.order.modify_others', 'pos.split', 'pos.shift.open', 'pos.shift.close', 'pos.shift.force_close', 'pos.shift.force_close_others', 'pos.shift.resolve_variance', 'pos.shift.set_opening_baseline', 'pos.cashdrawer.count', 'pos.drawer.no_sale', 'pos.drawer.payin', 'pos.drawer.safe_drop', 'pos.payout.over_limit', 'pos.drawer.payout.limit', 'pos.credit.sale', 'pos.credit.sell', 'pos.reprint', 'pos.refund.limit', 'pos.void.paid', 'pos.refund'],
            'Inventory & menu' => ['menu.edit', 'inventory.edit', 'inventory.approve'],
            'Delivery & KDS' => ['moova.manage', 'moova.accept', 'delivery.dispatch', 'delivery.zones.manage', 'kds.view', 'kds.complete', 'kds.manage'],
            'Accounting & reports' => ['accounting.view', 'reports.view', 'reports.own_shift', 'reports.branch_daily', 'reports.costs', 'reports.cash_flow'],
            'Administration' => ['users.manage', 'roles.manage', 'customers.manage', 'system.health.view', 'system.tools.run'],
        ];
    }

    /** @return list<string> */
    public static function limitablePermissions(): array
    {
        return [
            'pos.discount.apply',
            'pos.refund.limit',
            'pos.payout.over_limit',
            'pos.drawer.payout.limit',
        ];
    }

    public static function syncRoleCapabilityLimits(mysqli $conn, int $roleId, array $limitsByPermission): void
    {
        $result = $conn->query("SHOW TABLES LIKE 'role_capabilities'");
        if (!$result || $result->num_rows < 1) {
            return;
        }

        foreach ($limitsByPermission as $permissionKey => $limitData) {
            $permissionKey = trim((string) $permissionKey);
            if ($permissionKey === '' || !in_array($permissionKey, self::limitablePermissions(), true)) {
                continue;
            }
            $isUnlimited = !empty($limitData['is_unlimited']) ? 1 : 0;
            $limitValue = $isUnlimited ? null : ($limitData['limit_value'] ?? null);
            $limitParam = $limitValue !== null ? (float) $limitValue : null;

            $stmt = $conn->prepare("
                INSERT INTO role_capabilities (role_id, permission_key, is_enabled, limit_value, is_unlimited)
                VALUES (?, ?, 1, ?, ?)
                ON DUPLICATE KEY UPDATE
                    limit_value = VALUES(limit_value),
                    is_unlimited = VALUES(is_unlimited)
            ");
            $stmt->bind_param('isdi', $roleId, $permissionKey, $limitParam, $isUnlimited);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * @return array<string, array{rollname: string, permissions: list<string>, capabilities?: array<string, array{limit_value?: float|null, is_unlimited?: bool}>}>
     */
    public static function presetRoleDefinitions(): array
    {
        if (!function_exists('auth_guard_permission_map')) {
            require_once __DIR__ . '/../../includes/auth_guard.php';
        }

        $allPermissions = array_keys(auth_guard_permission_map());

        return [
            'owner' => [
                'rollname' => 'مالك',
                'permissions' => $allPermissions,
            ],
            'manager' => [
                'rollname' => 'مدير',
                'permissions' => [
                    'pos.open', 'pos.sell.takeaway', 'pos.table.open', 'pos.table.move', 'pos.table.merge',
                    'pos.payment.take', 'pos.discount.apply', 'pos.discount.manager_override', 'pos.recipe_stock_override',
                    'pos.cancel.unpaid', 'pos.split', 'pos.shift.open', 'pos.shift.close', 'pos.shift.force_close',
                    'pos.shift.force_close_others', 'pos.shift.override', 'pos.shift.resolve_variance', 'pos.shift.set_opening_baseline',
                    'pos.cashdrawer.count', 'pos.drawer.no_sale', 'pos.drawer.payin', 'pos.drawer.safe_drop',
                    'menu.edit', 'inventory.edit', 'reports.view', 'reports.cash_flow', 'moova.manage', 'moova.accept',
                    'delivery.dispatch', 'delivery.zones.manage', 'kds.view', 'kds.complete',
                ],
                'capabilities' => [
                    'pos.discount.apply' => ['limit_value' => 25.0, 'is_unlimited' => false],
                    'pos.discount.manager_override' => ['is_unlimited' => true],
                ],
            ],
            'cashier' => [
                'rollname' => 'كاشير',
                'permissions' => [
                    'pos.open', 'pos.sell.takeaway', 'pos.table.open', 'pos.payment.take',
                    'pos.discount.apply', 'pos.cancel.unpaid', 'pos.split',
                    'pos.shift.open', 'pos.shift.close', 'pos.cashdrawer.count',
                    'moova.accept',
                ],
                'capabilities' => [
                    'pos.discount.apply' => ['limit_value' => 10.0, 'is_unlimited' => false],
                    'pos.payout.over_limit' => ['limit_value' => 100.0, 'is_unlimited' => false],
                    'pos.drawer.payout.limit' => ['limit_value' => 100.0, 'is_unlimited' => false],
                ],
            ],
            'waiter' => [
                'rollname' => 'ويتر',
                'permissions' => [
                    'pos.open', 'pos.sell.takeaway', 'pos.table.open', 'pos.table.move', 'pos.cancel.unpaid',
                ],
            ],
            'kitchen' => [
                'rollname' => 'مطبخ',
                'permissions' => ['kds.view', 'kds.complete'],
            ],
        ];
    }

    public static function seedPresetRoles(mysqli $conn): array
    {
        $seeded = [];
        foreach (self::presetRoleDefinitions() as $roleKey => $definition) {
            $roleId = self::upsertPresetRole($conn, $roleKey, $definition);
            if ($roleId > 0) {
                self::syncRoleCapabilities($conn, $roleId, $definition);
                $seeded[$roleKey] = $roleId;
            }
        }

        self::bumpPermissionsVersion($conn);

        return $seeded;
    }

    public static function restorePresetRole(mysqli $conn, string $roleKey): int
    {
        $definitions = self::presetRoleDefinitions();
        $roleKey = trim($roleKey);
        if ($roleKey === '' || !isset($definitions[$roleKey])) {
            throw new InvalidArgumentException('PRESET_ROLE_UNKNOWN');
        }

        $definition = $definitions[$roleKey];
        $roleId = self::upsertPresetRole($conn, $roleKey, $definition);
        if ($roleId > 0) {
            self::syncRoleCapabilities($conn, $roleId, $definition);
            self::bumpPermissionsVersion($conn);
        }

        return $roleId;
    }

    /**
     * Re-sync preset capabilities when legacy backfill leaked void/refund powers.
     */
    public static function repairPresetRoleCapabilitiesIfNeeded(mysqli $conn, int $roleId): void
    {
        if ($roleId < 1) {
            return;
        }

        $stmt = $conn->prepare(
            'SELECT role_key FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1'
        );
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $roleKey = trim((string) ($row['role_key'] ?? ''));
        $definitions = self::presetRoleDefinitions();
        if ($roleKey === '' || !isset($definitions[$roleKey])) {
            return;
        }

        $definition = $definitions[$roleKey];
        $allowed = array_fill_keys($definition['permissions'] ?? [], true);
        $needsRepair = false;
        foreach (self::legacyBackfillExcludedPermissions() as $permission) {
            if (!empty($allowed[$permission])) {
                continue;
            }
            $capStmt = $conn->prepare(
                'SELECT is_enabled FROM role_capabilities WHERE role_id = ? AND permission_key = ? LIMIT 1'
            );
            $capStmt->bind_param('is', $roleId, $permission);
            $capStmt->execute();
            $capRow = $capStmt->get_result()->fetch_assoc();
            $capStmt->close();
            if ((int) ($capRow['is_enabled'] ?? 0) === 1) {
                $needsRepair = true;
                break;
            }
        }

        if (!$needsRepair) {
            foreach (array_keys($allowed) as $permission) {
                $capStmt = $conn->prepare(
                    'SELECT is_enabled FROM role_capabilities WHERE role_id = ? AND permission_key = ? LIMIT 1'
                );
                $capStmt->bind_param('is', $roleId, $permission);
                $capStmt->execute();
                $capRow = $capStmt->get_result()->fetch_assoc();
                $capStmt->close();
                if ((int) ($capRow['is_enabled'] ?? 0) !== 1) {
                    $needsRepair = true;
                    break;
                }
            }
        }

        if ($needsRepair) {
            self::syncRoleCapabilities($conn, $roleId, $definition);
        }
    }

    private static function upsertPresetRole(mysqli $conn, string $roleKey, array $definition): int
    {
        $roleKey = trim($roleKey);
        if ($roleKey === '') {
            return 0;
        }

        $stmt = $conn->prepare(
            'SELECT id FROM usr_pwrs WHERE role_key = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1'
        );
        $stmt->bind_param('s', $roleKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $legacyValues = self::legacyFlagValuesForPermissions($definition['permissions'] ?? []);
        $rollname = (string) ($definition['rollname'] ?? $roleKey);

        if ($row) {
            $roleId = (int) $row['id'];
            self::updateRoleLegacyFlags($conn, $roleId, $legacyValues, $rollname, 1, $roleKey);
            return $roleId;
        }

        if ($roleKey === 'owner') {
            $adminStmt = $conn->prepare('SELECT id FROM usr_pwrs WHERE id = 1 AND COALESCE(isdeleted, 0) != 1 LIMIT 1');
            $adminStmt->execute();
            $adminRow = $adminStmt->get_result()->fetch_assoc();
            $adminStmt->close();
            if ($adminRow) {
                $roleId = 1;
                self::updateRoleLegacyFlags($conn, $roleId, $legacyValues, $rollname, 1, $roleKey);
                return $roleId;
            }
        }

        $columns = array_merge(['rollname' => $rollname, 'is_active' => 1, 'role_key' => $roleKey, 'is_system' => 1], $legacyValues);
        $columnNames = array_keys($columns);
        $placeholders = implode(', ', array_fill(0, count($columnNames), '?'));
        $sql = 'INSERT INTO usr_pwrs (' . implode(', ', $columnNames) . ') VALUES (' . $placeholders . ')';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $types = '';
        $values = [];
        foreach ($columns as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } else {
                $types .= 's';
                $value = (string) $value;
            }
            $values[] = $value;
        }
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $roleId = (int) $conn->insert_id;
        $stmt->close();

        return $roleId;
    }

    private static function usrPwrsColumnExists(mysqli $conn, string $column): bool
    {
        static $cache = [];
        if ($cache === []) {
            $result = $conn->query('SHOW COLUMNS FROM usr_pwrs');
            if ($result instanceof mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    $cache[(string) ($row['Field'] ?? '')] = true;
                }
            }
        }

        return isset($cache[$column]);
    }

    public static function applyLegacyFlagValuesToRole(
        mysqli $conn,
        int $roleId,
        array $legacyValues,
        bool $activeOnly = false
    ): void {
        if ($roleId < 1 || $legacyValues === []) {
            return;
        }

        $setParts = [];
        $types = '';
        $bindValues = [];
        foreach ($legacyValues as $column => $value) {
            if (!preg_match('/^[a-z0-9_]+$/', (string) $column)) {
                continue;
            }
            if (!self::usrPwrsColumnExists($conn, (string) $column)) {
                continue;
            }
            $setParts[] = '`' . $column . '` = ?';
            $types .= 'i';
            $bindValues[] = (int) $value;
        }
        if ($setParts === []) {
            return;
        }

        $sql = 'UPDATE usr_pwrs SET ' . implode(', ', $setParts) . ' WHERE id = ?';
        if ($activeOnly) {
            $sql .= ' AND COALESCE(isdeleted, 0) != 1';
        }
        $types .= 'i';
        $bindValues[] = $roleId;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$bindValues);
        $stmt->execute();
        $stmt->close();
    }

    private static function updateRoleLegacyFlags(
        mysqli $conn,
        int $roleId,
        array $legacyValues,
        string $rollname,
        int $isSystem,
        string $roleKey
    ): void {
        $sets = ['rollname = ?', 'is_system = ?', 'role_key = ?'];
        $types = 'sis';
        $params = [$rollname, $isSystem, $roleKey];

        foreach ($legacyValues as $column => $value) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $column)) {
                continue;
            }
            if (!self::usrPwrsColumnExists($conn, $column)) {
                continue;
            }
            $sets[] = '`' . $column . '` = ?';
            $types .= 'i';
            $params[] = (int) $value;
        }

        $sql = 'UPDATE usr_pwrs SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $types .= 'i';
        $params[] = $roleId;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    public static function syncRoleCapabilitiesForPermissions(mysqli $conn, int $roleId, array $enabledPermissions): void
    {
        $result = $conn->query("SHOW TABLES LIKE 'role_capabilities'");
        if (!$result || $result->num_rows < 1) {
            return;
        }

        if (!function_exists('auth_guard_permission_map')) {
            require_once __DIR__ . '/../../includes/auth_guard.php';
        }

        $enabledSet = [];
        foreach ($enabledPermissions as $permission) {
            $permission = trim((string) $permission);
            if ($permission !== '') {
                $enabledSet[$permission] = true;
            }
        }

        $limitsByPermission = [];
        foreach (self::limitablePermissions() as $limitPermission) {
            if (!empty($enabledSet[$limitPermission])) {
                $limitsByPermission[$limitPermission] = ['is_unlimited' => true];
            }
        }

        foreach (auth_guard_permission_map() as $permission => $legacyFlags) {
            $explicitlyEnabled = !empty($enabledSet[$permission]);
            // Admin-only legacy flags still allow explicit preset grants (e.g. manager force_close).
            if (in_array('__admin_only', $legacyFlags, true) && !$explicitlyEnabled) {
                continue;
            }
            $isEnabled = $explicitlyEnabled ? 1 : 0;
            $override = $limitsByPermission[$permission] ?? [];
            // Enabled limitable permissions are synced as unlimited in this path.
            $isUnlimited = array_key_exists('is_unlimited', $override)
                ? (int) (bool) $override['is_unlimited']
                : 1;
            $limitParam = null;

            $stmt = $conn->prepare("
                INSERT INTO role_capabilities (role_id, permission_key, is_enabled, limit_value, is_unlimited)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    is_enabled = VALUES(is_enabled),
                    limit_value = VALUES(limit_value),
                    is_unlimited = VALUES(is_unlimited)
            ");
            $stmt->bind_param('isidi', $roleId, $permission, $isEnabled, $limitParam, $isUnlimited);
            $stmt->execute();
            $stmt->close();
        }
    }

    public static function backfillRoleCapabilitiesFromLegacyFlags(mysqli $conn): int
    {
        $result = $conn->query('SELECT * FROM usr_pwrs WHERE COALESCE(isdeleted, 0) != 1');
        if (!$result) {
            return 0;
        }

        $count = 0;
        while ($row = $result->fetch_assoc()) {
            $roleId = (int) ($row['id'] ?? 0);
            if ($roleId < 1) {
                continue;
            }
            // Preset/system roles are authoritative via seedPresetRoles/restorePresetRole.
            $roleKey = trim((string) ($row['role_key'] ?? ''));
            if ($roleKey !== '' || (int) ($row['is_system'] ?? 0) === 1) {
                continue;
            }
            $enabled = self::enabledPermissionsFromLegacyFlags($row);
            self::syncRoleCapabilitiesForPermissions($conn, $roleId, $enabled);
            $count++;
        }

        return $count;
    }

    private static function syncRoleCapabilities(mysqli $conn, int $roleId, array $definition): void
    {
        $result = $conn->query("SHOW TABLES LIKE 'role_capabilities'");
        if (!$result || $result->num_rows < 1) {
            return;
        }

        $permissions = $definition['permissions'] ?? [];
        $capabilityOverrides = $definition['capabilities'] ?? [];

        self::syncRoleCapabilitiesForPermissions($conn, $roleId, $permissions);

        foreach ($permissions as $permission) {
            $permission = trim((string) $permission);
            if ($permission === '' || !isset($capabilityOverrides[$permission])) {
                continue;
            }

            $override = $capabilityOverrides[$permission];
            $isUnlimited = array_key_exists('is_unlimited', $override) ? (int) (bool) $override['is_unlimited'] : 1;
            $limitValue = $isUnlimited ? null : ($override['limit_value'] ?? null);
            $limitParam = $limitValue !== null ? (float) $limitValue : null;

            $stmt = $conn->prepare("
                UPDATE role_capabilities
                SET limit_value = ?, is_unlimited = ?
                WHERE role_id = ? AND permission_key = ?
            ");
            $stmt->bind_param('diis', $limitParam, $isUnlimited, $roleId, $permission);
            $stmt->execute();
            $stmt->close();
        }

        foreach ($capabilityOverrides as $permission => $override) {
            $permission = trim((string) $permission);
            if ($permission === '' || in_array($permission, $permissions, true)) {
                continue;
            }
            if (!in_array($permission, self::limitablePermissions(), true)) {
                continue;
            }
            $isUnlimited = array_key_exists('is_unlimited', $override) ? (int) (bool) $override['is_unlimited'] : 1;
            $limitValue = $isUnlimited ? null : ($override['limit_value'] ?? null);
            $limitParam = $limitValue !== null ? (float) $limitValue : null;
            $stmt = $conn->prepare("
                INSERT INTO role_capabilities (role_id, permission_key, is_enabled, limit_value, is_unlimited)
                VALUES (?, ?, 1, ?, ?)
                ON DUPLICATE KEY UPDATE
                    limit_value = VALUES(limit_value),
                    is_unlimited = VALUES(is_unlimited)
            ");
            $stmt->bind_param('isdi', $roleId, $permission, $limitParam, $isUnlimited);
            $stmt->execute();
            $stmt->close();
        }
    }

    private static function bumpPermissionsVersion(mysqli $conn): void
    {
        $result = $conn->query("SHOW TABLES LIKE 'app_settings'");
        if (!$result || $result->num_rows < 1) {
            return;
        }

        $stmt = $conn->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES ('permissions_version', '1')
             ON DUPLICATE KEY UPDATE setting_value = CAST(setting_value AS UNSIGNED) + 1"
        );
        $stmt->execute();
        $stmt->close();
    }
}
