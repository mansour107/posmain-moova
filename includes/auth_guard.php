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
            'pos.recipe_stock_override' => ['edit_sales', 'edit_stock'],
            'pos.cancel.unpaid' => ['delete_sales', 'edit_sales'],
            'pos.void.paid' => ['delete_payment', 'edit_payment'],
            'pos.refund' => ['delete_payment', 'edit_payment'],
            'pos.split' => ['add_payment', 'add_sales'],
            'pos.shift.open' => ['add_sales', 'sid_sales'],
            'pos.shift.close' => ['edit_sales', 'sid_sales'],
            'pos.cashdrawer.count' => ['edit_payment', 'show_payment'],
            'menu.edit' => ['add_items', 'edit_items', 'add_item_groups', 'edit_item_groups'],
            'inventory.edit' => ['add_stock', 'edit_stock', 'add_items', 'edit_items'],
            'inventory.approve' => ['delete_stock'],
            'reports.view' => ['sid_reports', 'show_gl_reports', 'show_hr_report', 'show_payroll_report'],
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
        ];
    }
}

if (!function_exists('auth_guard_is_admin_session')) {
    function auth_guard_is_admin_session(?array $session = null, ?array $roleFlags = null): bool
    {
        $session = $session ?? $_SESSION;
        $roleValue = $session['usrole'] ?? $session['userrole'] ?? $session['role_id'] ?? null;
        if (is_numeric($roleValue) && (int) $roleValue === 1) {
            return true;
        }

        $userType = $session['usty'] ?? $session['usertype'] ?? null;
        if (is_numeric($userType) && (int) $userType === 2) {
            return true;
        }

        $roleName = strtolower((string) ($roleFlags['rollname'] ?? ''));
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

if (!function_exists('auth_guard_session_has_permission')) {
    function auth_guard_session_has_permission(string $permission, ?array $roleFlags = null, ?array $session = null): bool
    {
        $permission = trim($permission);
        if ($permission === '') {
            return false;
        }

        $session = $session ?? $_SESSION;
        $roleFlags = $roleFlags ?? [];
        if (auth_guard_is_admin_session($session, $roleFlags)) {
            return true;
        }

        $map = auth_guard_permission_map();
        if (!isset($map[$permission])) {
            return false;
        }

        return auth_guard_role_flags_allow($roleFlags, $map[$permission]);
    }
}

if (!function_exists('auth_guard_has_permission')) {
    function auth_guard_has_permission(string $permission, ?mysqli $conn = null): bool
    {
        return auth_guard_session_has_permission($permission, auth_guard_current_role_flags($conn), $_SESSION);
    }
}

if (!function_exists('deny_json_or_redirect')) {
    function deny_json_or_redirect(string $message = 'AUTH_REQUIRED', int $statusCode = 401, ?string $redirect = null): void
    {
        http_response_code($statusCode);

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
        if (!auth_guard_is_pos_authenticated()) {
            deny_json_or_redirect('AUTH_REQUIRED', 401);
        }
    }
}

if (!function_exists('require_permission')) {
    function require_permission(string $permission, ?mysqli $conn = null): void
    {
        require_login();
        if (!auth_guard_has_permission($permission, $conn)) {
            deny_json_or_redirect('PERMISSION_DENIED', 403);
        }
    }
}

if (!function_exists('require_admin_or_permission')) {
    function require_admin_or_permission(string $permission, ?mysqli $conn = null): void
    {
        require_login();
        $roleFlags = auth_guard_current_role_flags($conn);
        if (
            !auth_guard_is_admin_session($_SESSION, $roleFlags)
            && !auth_guard_session_has_permission($permission, $roleFlags, $_SESSION)
        ) {
            deny_json_or_redirect('PERMISSION_DENIED', 403);
        }
    }
}
