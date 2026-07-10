<?php

require_once __DIR__ . '/session_bootstrap.php';

if (!function_exists('auth_guard_is_json_request')) {
    function auth_guard_is_json_request(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $script = (string) ($_SERVER['PHP_SELF'] ?? '');

        return strpos($accept, 'application/json') !== false
            || strpos($contentType, 'application/json') !== false
            || $requestedWith === 'xmlhttprequest'
            || strpos($script, '/ajax/') !== false
            || strpos($script, 'ajax/') === 0;
    }
}

if (!function_exists('current_user_id')) {
    function current_user_id(): int
    {
        foreach (['userid', 'user_id'] as $key) {
            if (isset($_SESSION[$key]) && (int) $_SESSION[$key] > 0) {
                return (int) $_SESSION[$key];
            }
        }

        return 0;
    }
}

if (!function_exists('current_user_role')) {
    function current_user_role()
    {
        foreach (['usrole', 'userrole', 'role_id'] as $key) {
            if (isset($_SESSION[$key]) && $_SESSION[$key] !== '') {
                return is_numeric($_SESSION[$key]) ? (int) $_SESSION[$key] : (string) $_SESSION[$key];
            }
        }

        return null;
    }
}

if (!function_exists('auth_guard_is_logged_in')) {
    function auth_guard_is_logged_in(?array $session = null): bool
    {
        $session = $session ?? $_SESSION;

        return !empty($session['login'])
            && (
                (isset($session['userid']) && (int) $session['userid'] > 0)
                || (isset($session['user_id']) && (int) $session['user_id'] > 0)
            );
    }
}

if (!function_exists('auth_guard_is_pos_authenticated')) {
    function auth_guard_is_pos_authenticated(?array $session = null): bool
    {
        $session = $session ?? $_SESSION;
        if (auth_guard_is_logged_in($session)) {
            return true;
        }

        return !empty($session['user_logged_in'])
            && !empty($session['is_waiter'])
            && (
                (isset($session['waiter_id']) && (int) $session['waiter_id'] > 0)
                || (isset($session['userid']) && (int) $session['userid'] > 0)
            );
    }
}

if (!function_exists('auth_guard_user_id_from_session')) {
    function auth_guard_user_id_from_session(?array $session = null): int
    {
        $session = $session ?? $_SESSION;
        foreach (['userid', 'user_id'] as $key) {
            if (isset($session[$key]) && (int) $session[$key] > 0) {
                return (int) $session[$key];
            }
        }

        return 0;
    }
}

if (!function_exists('auth_guard_is_pos_barcode_unlocked')) {
    function auth_guard_is_pos_barcode_unlocked(?array $session = null): bool
    {
        $session = $session ?? $_SESSION;
        if (!auth_guard_is_logged_in($session)) {
            return false;
        }

        $userId = auth_guard_user_id_from_session($session);
        if ($userId < 1 && (int) ($session['pos_user_id'] ?? 0) < 1) {
            return false;
        }

        return !empty($session['pos_authenticated'])
            && $session['pos_authenticated'] === true
            && (int) ($session['pos_user_id'] ?? 0) > 0
            && empty($session['pos_shift_closed_for_session']);
    }
}

if (!function_exists('auth_guard_is_waiter_pos_session')) {
    function auth_guard_is_waiter_pos_session(?array $session = null): bool
    {
        $session = $session ?? $_SESSION;

        return !empty($session['user_logged_in'])
            && !empty($session['is_waiter'])
            && (
                (isset($session['waiter_id']) && (int) $session['waiter_id'] > 0)
                || auth_guard_user_id_from_session($session) > 0
            );
    }
}

if (!function_exists('auth_guard_is_pos_write_authorized')) {
    function auth_guard_is_pos_write_authorized(?array $session = null): bool
    {
        $session = $session ?? $_SESSION;

        // A closed shift blocks POS order writes for every session type. This must be
        // checked before the waiter short-circuit below; otherwise a waiter session
        // bypasses the closed-shift flag and can keep creating orders after close.
        if (!empty($session['pos_shift_closed_for_session'])) {
            return false;
        }

        if (auth_guard_is_waiter_pos_session($session)) {
            return true;
        }

        return auth_guard_is_pos_barcode_unlocked($session);
    }
}

if (!function_exists('auth_guard_permission_map')) {
    function auth_guard_permission_map(): array
    {
        return [
            'pos.open' => ['show_sales', 'sid_sales'],
            'pos.sell.takeaway' => ['add_sales', 'show_sales', 'sid_sales'],
            'pos.table.open' => ['add_sales', 'show_sales', 'sid_sales'],
            'pos.table.move' => ['edit_sales', 'show_sales', 'sid_sales'],
            'pos.table.merge' => ['edit_sales', 'show_sales', 'sid_sales'],
            'pos.payment.take' => ['add_payment', 'show_payment', 'add_sales'],
            'pos.discount.apply' => ['edit_sales', 'add_sales'],
            'pos.discount.manager_override' => ['edit_sales'],
            'pos.discount.manual_pct.limit' => ['edit_sales', 'add_sales'],
            'pos.price.override' => ['edit_sales'],
            'pos.drawer.no_sale' => ['edit_payment', 'show_payment'],
            'pos.drawer.payin' => ['edit_payment', 'add_payment'],
            'pos.drawer.safe_drop' => ['edit_payment', 'add_payment'],
            'pos.payout.over_limit' => ['edit_payment'],
            'pos.drawer.payout.limit' => ['edit_payment'],
            'pos.credit.sale' => ['add_sales', 'add_payment'],
            'pos.credit.sell' => ['add_sales', 'add_payment'],
            'pos.order.modify_others' => ['edit_sales', 'delete_sales'],
            'pos.void.item_after_send' => ['edit_sales', 'delete_sales'],
            'pos.credit.settle' => ['add_payment', 'edit_payment'],
            'pos.reprint' => ['show_sales', 'sid_sales'],
            'pos.reprint.receipt' => ['show_sales', 'sid_sales'],
            'pos.reprint.kitchen_ticket' => ['show_sales', 'sid_sales'],
            'pos.shift.force_close' => ['__admin_only'],
            'pos.shift.force_close_others' => ['edit_sales', 'sid_sales'],
            'pos.shift.resolve_variance' => ['edit_sales', 'sid_sales', 'show_gl_reports'],
            'pos.shift.set_opening_baseline' => ['edit_sales', 'sid_sales', 'show_gl_reports'],
            'pos.void.post_send' => ['edit_sales', 'delete_sales'],
            'pos.recipe_stock_override' => ['edit_sales', 'edit_stock'],
            'pos.cancel.unpaid' => ['delete_sales', 'edit_sales'],
            'pos.void.paid' => ['__admin_only'],
            'pos.refund' => ['__admin_only'],
            'pos.refund.limit' => ['__admin_only'],
            'pos.split' => ['add_payment', 'add_sales'],
            'pos.shift.open' => ['add_sales', 'sid_sales'],
            'pos.shift.close' => ['edit_sales', 'sid_sales'],
            'pos.cashdrawer.count' => ['edit_payment', 'show_payment'],
            'menu.edit' => ['add_items', 'edit_items', 'add_item_groups', 'edit_item_groups'],
            'inventory.edit' => ['add_stock', 'edit_stock', 'add_items', 'edit_items'],
            'inventory.approve' => ['delete_stock'],
            'reports.view' => ['sid_reports', 'show_gl_reports', 'show_hr_report', 'show_payroll_report'],
            'reports.own_shift' => ['sid_reports', 'show_gl_reports'],
            'reports.branch_daily' => ['sid_reports', 'show_gl_reports'],
            'reports.costs' => ['sid_reports', 'show_gl_reports'],
            'reports.cash_flow' => ['sid_reports', 'show_gl_reports'],
            'accounting.view' => ['sid_accounts', 'show_gl_reports', 'show_journals'],
            'users.manage' => ['add_users', 'edit_users', 'delete_users'],
            'roles.manage' => ['add_users', 'edit_users', 'delete_users'],
            'moova.manage' => ['edit_sales', 'sid_sales'],
            'moova.accept' => ['add_sales', 'sid_sales'],
            'delivery.dispatch' => ['edit_sales', 'show_sales', 'sid_sales', 'add_sales'],
            'delivery.zones.manage' => ['edit_sales', 'edit_items', 'add_items'],
            'system.health.view' => ['sid_reports', 'sid_accounts'],
            'system.tools.run' => ['__admin_only'],
            'customers.manage' => ['__admin_only'],
            'kds.view' => ['sid_kds'],
            'kds.complete' => ['sid_kds'],
            'kds.manage' => ['__admin_only'],
            'erp.module.entry' => ['sid_entry'],
            'erp.module.stock' => ['sid_stock'],
            'erp.module.sales' => ['sid_sales'],
            'erp.module.cards' => ['sid_cards'],
            'erp.module.purchases' => ['sid_purchases'],
            'erp.module.vouchers' => ['sid_vouchers'],
            'erp.module.hr' => ['sid_hr'],
            'erp.module.pulse' => ['sid_pulse'],
            'erp.module.rents' => ['sid_rents'],
            'erp.module.clinics' => ['sid_clinics'],
            'erp.module.payroll' => ['sid_payroll'],
            'erp.module.crm' => ['sid_crm'],
            'erp.module.accounts' => ['sid_accounts'],
            'erp.module.assets' => ['sid_assets'],
            'erp.module.reports' => ['sid_reports'],
            'erp.dashboard.main_cards' => ['show_main_cards'],
            'erp.dashboard.main_elements' => ['show_main_elements'],
            'erp.dashboard.main_tables' => ['show_main_tables'],
            'erp.clients.create' => ['add_clients'],
            'erp.clients.profile' => ['show_client_profile'],
            'erp.suppliers.create' => ['add_suppliers'],
            'erp.funds.create' => ['add_funds'],
            'erp.banks.create' => ['add_banks'],
            'erp.expenses.create' => ['add_expenses'],
            'erp.revenues.create' => ['add_revenuses'],
            'erp.credits.create' => ['add_credits'],
            'erp.deposits.create' => ['add_depits'],
            'erp.partners.create' => ['add_partners'],
            'erp.assets.create' => ['add_assets'],
            'erp.employees.create' => ['add_employees'],
            'erp.rentables.create' => ['add_rentables'],
            'erp.attendance.view' => ['show_attandance'],
            'erp.attendance.create' => ['add_attandance'],
            'erp.reservations.ended' => ['show_ended_reservation'],
            'erp.reservations.totals' => ['show_total_reservation'],
        ];
    }
}

if (!function_exists('auth_guard_permissions_for_legacy_flag')) {
    function auth_guard_permissions_for_legacy_flag(string $flag): array
    {
        $flag = trim($flag);
        if ($flag === '') {
            return [];
        }

        $matches = [];
        foreach (auth_guard_permission_map() as $permission => $legacyFlags) {
            if (in_array($flag, $legacyFlags, true)) {
                $matches[] = $permission;
            }
        }

        return $matches;
    }
}

if (!function_exists('auth_guard_has_legacy_flag')) {
    function auth_guard_has_legacy_flag(string $flag, ?mysqli $conn = null): bool
    {
        foreach (auth_guard_permissions_for_legacy_flag($flag) as $permission) {
            if (auth_guard_has_permission($permission, $conn)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('auth_guard_is_admin_session')) {
    function auth_guard_is_admin_session(?array $session = null, ?array $roleFlags = null): bool
    {
        $session = $session ?? $_SESSION;
        if ($roleFlags === null) {
            global $role;
            if (isset($role) && is_array($role) && $role !== []) {
                $roleFlags = $role;
            } else {
                global $conn;
                if (isset($conn) && $conn instanceof mysqli) {
                    $roleFlags = auth_guard_current_role_flags($conn);
                }
            }
        }
        $roleValue = $session['usrole'] ?? $session['userrole'] ?? $session['role_id'] ?? null;
        if (is_numeric($roleValue) && (int) $roleValue === 1) {
            return true;
        }

        $userType = $session['usty'] ?? $session['usertype'] ?? null;
        if (is_numeric($userType) && (int) $userType === 2) {
            return true;
        }

        $roleName = strtolower((string) ($roleFlags['rollname'] ?? ''));
        $roleKey = strtolower((string) ($roleFlags['role_key'] ?? ''));
        if ($roleKey === 'owner' || stripos((string) ($roleFlags['rollname'] ?? ''), 'مالك') !== false) {
            return true;
        }

        return $roleName !== '' && (strpos($roleName, 'admin') !== false || strpos($roleName, 'owner') !== false);
    }
}

if (!function_exists('auth_guard_current_role_flags')) {
    function auth_guard_current_role_flags(?mysqli $conn = null): array
    {
        global $role;

        if (isset($role) && is_array($role)) {
            return $role;
        }

        $roleId = current_user_role();
        if (!$conn || !is_numeric($roleId) || (int) $roleId < 1) {
            return [];
        }

        $stmt = $conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1');
        $roleId = (int) $roleId;
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: [];
    }
}

if (!function_exists('auth_guard_role_flags_allow')) {
    function auth_guard_role_flags_allow(array $roleFlags, array $legacyFlags): bool
    {
        foreach ($legacyFlags as $flag) {
            if ($flag === '__admin_only') {
                continue;
            }

            if (array_key_exists($flag, $roleFlags) && (int) $roleFlags[$flag] === 1) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('auth_guard_role_has_capability_rows')) {
    function auth_guard_role_has_capability_rows(array $roleFlags, ?mysqli $conn = null): bool
    {
        $roleId = (int) ($roleFlags['id'] ?? 0);
        if ($roleId < 1 || !$conn instanceof mysqli) {
            return false;
        }

        $tableResult = $conn->query("SHOW TABLES LIKE 'role_capabilities'");
        if (!$tableResult || $tableResult->num_rows < 1) {
            return false;
        }

        $stmt = $conn->prepare('SELECT 1 FROM role_capabilities WHERE role_id = ? LIMIT 1');
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (bool) $row;
    }
}

if (!function_exists('auth_guard_role_capability_enabled')) {
    function auth_guard_role_capability_enabled(string $permission, array $roleFlags, ?mysqli $conn = null): ?bool
    {
        $roleId = (int) ($roleFlags['id'] ?? 0);
        if ($roleId < 1 || !$conn instanceof mysqli) {
            return null;
        }

        $tableResult = $conn->query("SHOW TABLES LIKE 'role_capabilities'");
        if (!$tableResult || $tableResult->num_rows < 1) {
            return null;
        }

        $stmt = $conn->prepare(
            'SELECT is_enabled FROM role_capabilities WHERE role_id = ? AND permission_key = ? LIMIT 1'
        );
        $stmt->bind_param('is', $roleId, $permission);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return (int) ($row['is_enabled'] ?? 0) === 1;
    }
}

if (!function_exists('auth_guard_session_has_permission')) {
    function auth_guard_session_has_permission(string $permission, ?array $roleFlags = null, ?array $session = null, ?mysqli $conn = null): bool
    {
        $permission = trim($permission);
        if ($permission === '') {
            return false;
        }

        $session = $session ?? $_SESSION;
        $roleFlags = $roleFlags ?? [];

        $override = auth_guard_user_override_effect($permission, $session, $conn);
        if ($override === 'deny') {
            return false;
        }
        if ($override === 'grant') {
            return true;
        }

        if (auth_guard_is_admin_session($session, $roleFlags)) {
            return true;
        }

        $map = auth_guard_permission_map();
        if (!isset($map[$permission])) {
            return false;
        }

        $legacyFlags = $map[$permission];
        $adminOnly = in_array('__admin_only', $legacyFlags, true);
        $usesCapabilities = auth_guard_role_has_capability_rows($roleFlags, $conn);

        $roleCapability = auth_guard_role_capability_enabled($permission, $roleFlags, $conn);
        if ($usesCapabilities) {
            if ($roleCapability === false) {
                return false;
            }
            if ($roleCapability === true) {
                return true;
            }

            return false;
        }

        if ($adminOnly) {
            return false;
        }

        return auth_guard_role_flags_allow($roleFlags, $legacyFlags);
    }
}

if (!function_exists('auth_guard_user_override_effect')) {
    function auth_guard_user_override_effect(string $permission, ?array $session = null, ?mysqli $conn = null): ?string
    {
        static $cache = [];
        $session = $session ?? $_SESSION;
        $userId = auth_guard_user_id_from_session($session);
        if ($userId < 1 || !$conn instanceof mysqli) {
            return null;
        }

        if (!class_exists('UserPermissionGrantService', false)) {
            require_once __DIR__ . '/../classes/Security/UserPermissionGrantService.php';
        }
        if (!class_exists('PermissionService', false)) {
            require_once __DIR__ . '/../classes/Security/PermissionService.php';
        }

        $permissionsVersion = '0';
        try {
            $permissionsVersion = (new PermissionService($conn))->permissionsVersion();
        } catch (Throwable) {
            $permissionsVersion = '0';
        }

        $cacheKey = $userId . ':' . $permission . ':' . $permissionsVersion;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $service = new UserPermissionGrantService();
        $overrides = $service->activeOverridesForUser($conn, $userId);
        $effect = $overrides[$permission] ?? null;
        $cache[$cacheKey] = $effect;

        return $effect;
    }
}

if (!function_exists('auth_guard_has_permission')) {
    function auth_guard_has_permission(string $permission, ?mysqli $conn = null): bool
    {
        return auth_guard_session_has_permission(
            $permission,
            auth_guard_current_role_flags($conn),
            $_SESSION,
            $conn
        );
    }
}

if (!function_exists('auth_guard_effective_permissions')) {
    function auth_guard_effective_permissions(?mysqli $conn = null, bool $useSessionCache = true): array
    {
        $expectedVersion = auth_guard_capabilities_version($conn);

        if (
            $useSessionCache
            && session_status() === PHP_SESSION_ACTIVE
            && isset($_SESSION['posmain_capabilities_cache'], $_SESSION['posmain_capabilities_version'])
            && is_array($_SESSION['posmain_capabilities_cache'])
            && hash_equals((string) $_SESSION['posmain_capabilities_version'], $expectedVersion)
        ) {
            return $_SESSION['posmain_capabilities_cache'];
        }

        $permissions = [];
        foreach (array_keys(auth_guard_permission_map()) as $permission) {
            $permissions[$permission] = auth_guard_has_permission($permission, $conn);
        }

        if ($useSessionCache && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['posmain_capabilities_cache'] = $permissions;
            $_SESSION['posmain_capabilities_version'] = $expectedVersion;
        }

        return $permissions;
    }
}

if (!function_exists('auth_guard_capabilities_version')) {
    function auth_guard_capabilities_version(?mysqli $conn = null): string
    {
        $userId = auth_guard_user_id_from_session();
        $roleId = (string) (current_user_role() ?? '0');
        $permissionsVersion = '0';

        if ($conn instanceof mysqli) {
            if (!class_exists('PermissionService', false)) {
                require_once __DIR__ . '/../classes/Security/PermissionService.php';
            }
            try {
                $permissionsVersion = (new PermissionService($conn))->permissionsVersion();
            } catch (Throwable $ignored) {
                $permissionsVersion = '0';
            }
        }

        return hash('sha256', $userId . ':' . $roleId . ':' . $permissionsVersion);
    }
}

if (!function_exists('auth_guard_invalidate_capabilities_cache')) {
    function auth_guard_invalidate_capabilities_cache(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['posmain_capabilities_cache'], $_SESSION['posmain_capabilities_version']);
        }
    }
}

if (!function_exists('auth_guard_record_permission_denied')) {
    function auth_guard_record_permission_denied(string $permission, ?mysqli $conn = null): void
    {
        if (!$conn instanceof mysqli) {
            return;
        }

        if (!class_exists('SecurityAuditLogger', false)) {
            require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
        }

        try {
            $logger = new SecurityAuditLogger();
            $logger->recordPermissionDenied($conn, $permission, [
                'metadata' => [
                    'route' => (string) ($_SERVER['PHP_SELF'] ?? ''),
                    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
                ],
            ]);
        } catch (Throwable $ignored) {
            // Audit must not block authorization responses.
        }
    }
}

if (!function_exists('auth_guard_user_is_active')) {
    function auth_guard_user_is_active(mysqli $conn, int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }

        $stmt = $conn->prepare('SELECT 1 FROM users WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (bool) $row;
    }
}

if (!function_exists('auth_guard_end_session_for_deactivated_user')) {
    function auth_guard_end_session_for_deactivated_user(): void
    {
        if (function_exists('posmain_clear_pos_shift_session')) {
            posmain_clear_pos_shift_session(false);
        }

        foreach ([
            'login',
            'userid',
            'usrole',
            'usty',
            'userrole',
            'posmain_capabilities_cache',
            'posmain_capabilities_version',
        ] as $sessionKey) {
            unset($_SESSION[$sessionKey]);
        }
    }
}

if (!function_exists('auth_guard_enforce_active_session_user')) {
    function auth_guard_enforce_active_session_user(mysqli $conn): void
    {
        if (!auth_guard_is_logged_in()) {
            return;
        }

        $userId = auth_guard_user_id_from_session();
        if ($userId < 1 || auth_guard_user_is_active($conn, $userId)) {
            return;
        }

        auth_guard_end_session_for_deactivated_user();

        if (auth_guard_is_json_request()) {
            deny_json_or_redirect('USER_DEACTIVATED', 403);
        }

        if (!headers_sent()) {
            header('Location: index.php?error=user_deactivated');
        }
        exit;
    }
}

if (!function_exists('pos_revoke_unlock_for_inactive_acting_user')) {
    function pos_revoke_unlock_for_inactive_acting_user(mysqli $conn, string $message): void
    {
        posmain_clear_pos_shift_session(false);
        $_SESSION['pos_login_error'] = $message;

        if (auth_guard_is_json_request()) {
            deny_json_or_redirect('POS_ACTING_USER_INACTIVE', 403, 'pos_barcode.php');
        }

        if (!headers_sent()) {
            header('Location: pos_barcode.php');
        }
        exit;
    }
}

if (!function_exists('pos_enforce_active_pos_lane')) {
    function pos_enforce_active_pos_lane(mysqli $conn): void
    {
        if (!auth_guard_is_pos_barcode_unlocked()) {
            return;
        }

        $actingUserId = pos_acting_user_id();
        if ($actingUserId < 1) {
            pos_revoke_unlock_for_inactive_acting_user($conn, 'يجب اختيار موظف نشط لفتح نقطة البيع');
        }

        if (!auth_guard_user_is_active($conn, $actingUserId)) {
            pos_revoke_unlock_for_inactive_acting_user($conn, 'هذا الحساب موقوف ولا يمكنه استخدام نقطة البيع');
        }

        if (!class_exists('PermissionService', false)) {
            require_once __DIR__ . '/../classes/Security/PermissionService.php';
        }

        if (!(new PermissionService($conn))->check($actingUserId, 'pos.open')) {
            pos_revoke_unlock_for_inactive_acting_user($conn, 'ليس لديك صلاحية فتح نقطة البيع');
        }
    }
}

if (!function_exists('deny_json_or_redirect')) {
    function deny_json_or_redirect(string $message = 'AUTH_REQUIRED', int $statusCode = 401, ?string $redirect = null, ?string $permission = null): void
    {
        global $conn;
        if ($message === 'PERMISSION_DENIED' && $permission !== null && $permission !== '' && isset($conn) && $conn instanceof mysqli) {
            auth_guard_record_permission_denied($permission, $conn);
        }

        http_response_code($statusCode);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (auth_guard_is_json_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'code' => $message,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $redirect = $redirect ?: 'index.php';
        header('Location: ' . $redirect);
        exit;
    }
}

if (!function_exists('require_login')) {
    function require_login(): void
    {
        if (!auth_guard_is_logged_in()) {
            deny_json_or_redirect('AUTH_REQUIRED', 401);
        }
    }
}

if (!function_exists('require_pos_authenticated')) {
    function require_pos_authenticated(): void
    {
        global $conn;
        if (isset($conn) && $conn instanceof mysqli) {
            pos_enforce_active_pos_lane($conn);
        }

        if (!auth_guard_is_pos_write_authorized()) {
            deny_json_or_redirect('POS_AUTH_REQUIRED', 403, 'pos_barcode.php?logout=1');
        }
    }
}

if (!function_exists('pos_terminal_user_id')) {
    function pos_terminal_user_id(): int
    {
        return auth_guard_user_id_from_session();
    }
}

if (!function_exists('pos_acting_user_id')) {
    function pos_acting_user_id(?array $session = null): int
    {
        $session = $session ?? $_SESSION;
        if (isset($session['pos_acting_user_id']) && (int) $session['pos_acting_user_id'] > 0) {
            return (int) $session['pos_acting_user_id'];
        }
        if (isset($session['pos_user_id']) && (int) $session['pos_user_id'] > 0) {
            return (int) $session['pos_user_id'];
        }

        return auth_guard_user_id_from_session($session);
    }
}

if (!function_exists('pos_set_acting_user')) {
    function pos_set_acting_user(int $userId, ?string $displayName = null): void
    {
        if ($userId < 1) {
            return;
        }

        $previousId = isset($_SESSION['pos_acting_user_id']) ? (int) $_SESSION['pos_acting_user_id'] : 0;
        if ($previousId > 0 && $previousId !== $userId) {
            $_SESSION['pos_previous_acting_user_id'] = $previousId;
            $_SESSION['pos_cart_park_required'] = true;
        }

        $_SESSION['pos_acting_user_id'] = $userId;
        if ($displayName !== null && $displayName !== '') {
            $_SESSION['pos_acting_user_name'] = $displayName;
        }
    }
}

if (!function_exists('pos_clear_acting_user')) {
    function pos_clear_acting_user(): void
    {
        unset($_SESSION['pos_acting_user_id'], $_SESSION['pos_acting_user_name']);
    }
}

if (!function_exists('posmain_begin_pos_shift_session')) {
    function posmain_begin_pos_shift_session(int $userId): void
    {
        $_SESSION['pos_authenticated'] = true;
        $_SESSION['pos_user_id'] = $userId;
        if (!isset($_SESSION['pos_acting_user_id']) || (int) $_SESSION['pos_acting_user_id'] < 1) {
            $_SESSION['pos_acting_user_id'] = $userId;
        }
        $_SESSION['pos_last_activity_at'] = time();
        unset($_SESSION['pos_shift_closed_for_session'], $_SESSION['pos_shift_session_token']);
        $_SESSION['pos_shift_session_token'] = bin2hex(random_bytes(16));
    }
}

if (!function_exists('posmain_clear_pos_shift_session')) {
    function posmain_clear_pos_shift_session(bool $markClosed = true): void
    {
        if ($markClosed) {
            $_SESSION['pos_shift_closed_for_session'] = true;
        }
        unset(
            $_SESSION['pos_authenticated'],
            $_SESSION['pos_user_id'],
            $_SESSION['pos_user_name'],
            $_SESSION['pos_drawer_session_id'],
            $_SESSION['pos_acting_user_id'],
            $_SESSION['pos_acting_user_name'],
            $_SESSION['pos_last_activity_at']
        );
    }
}

if (!function_exists('pos_touch_activity')) {
    function pos_touch_activity(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['pos_last_activity_at'] = time();
        }
    }
}

if (!function_exists('auth_guard_pos_lane_has_permission')) {
    function auth_guard_pos_lane_has_permission(string $permission, ?mysqli $conn = null): bool
    {
        $actingUserId = pos_acting_user_id();
        if ($actingUserId > 0 && $conn instanceof mysqli) {
            if (!class_exists('PermissionService', false)) {
                require_once __DIR__ . '/../classes/Security/PermissionService.php';
            }

            try {
                return PermissionService::forConnection($conn)->check($actingUserId, $permission);
            } catch (InvalidArgumentException) {
                return false;
            }
        }

        return auth_guard_has_permission($permission, $conn);
    }
}

if (!function_exists('auth_guard_manager_approval_id_from_request')) {
    function auth_guard_manager_approval_id_from_request(?array $source = null): int
    {
        $source = $source ?? array_merge($_GET, $_POST);

        return (int) ($source['manager_approval_id'] ?? $source['approval_id'] ?? 0);
    }
}

if (!function_exists('auth_guard_pos_lane_has_permission_or_override')) {
    function auth_guard_pos_lane_has_permission_or_override(string $permission, ?mysqli $conn = null): bool
    {
        if (auth_guard_pos_lane_has_permission($permission, $conn)) {
            return true;
        }
        if (!$conn instanceof mysqli) {
            return false;
        }

        $approvalId = auth_guard_manager_approval_id_from_request();
        if ($approvalId < 1) {
            return false;
        }

        if (!class_exists('ManagerApprovalService', false)) {
            require_once __DIR__ . '/../classes/Pos/Service/ManagerApprovalService.php';
        }

        try {
            (new ManagerApprovalService())->validateApprovedPermissionOverride(
                $conn,
                $approvalId,
                $permission,
                pos_acting_user_id()
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}

if (!function_exists('pos_consume_lane_permission_override_if_needed')) {
    function pos_consume_lane_permission_override_if_needed(mysqli $conn, string $permission, int $userId): void
    {
        if ($permission === '' || $userId < 1 || auth_guard_pos_lane_has_permission($permission, $conn)) {
            return;
        }

        $approvalId = auth_guard_manager_approval_id_from_request();
        if ($approvalId < 1) {
            return;
        }

        if (!class_exists('ManagerApprovalService', false)) {
            require_once __DIR__ . '/../classes/Pos/Service/ManagerApprovalService.php';
        }

        $service = new ManagerApprovalService();
        $service->validateApprovedPermissionOverride($conn, $approvalId, $permission, $userId);
        $service->consumeApproval($conn, $approvalId, $userId);
    }
}

if (!function_exists('require_pos_lane_permission')) {
    function require_pos_lane_permission(string $permission, ?mysqli $conn = null): void
    {
        require_login();
        if (!auth_guard_pos_lane_has_permission_or_override($permission, $conn)) {
            $code = auth_guard_manager_approval_id_from_request() > 0
                ? 'MANAGER_APPROVAL_INVALID'
                : 'MANAGER_APPROVAL_REQUIRED';
            deny_json_or_redirect($code, 403, null, $permission);
        }
    }
}

if (!function_exists('require_permission')) {
    function require_permission(string $permission, ?mysqli $conn = null): void
    {
        require_login();
        if (!auth_guard_has_permission($permission, $conn)) {
            deny_json_or_redirect('PERMISSION_DENIED', 403, null, $permission);
        }
    }
}

if (!function_exists('require_admin_or_permission')) {
    function require_admin_or_permission(string $permission, ?mysqli $conn = null): void
    {
        require_login();
        if (!auth_guard_has_permission($permission, $conn)) {
            deny_json_or_redirect('PERMISSION_DENIED', 403, null, $permission);
        }
    }
}
