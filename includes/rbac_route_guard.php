<?php

if (!function_exists('posmain_rbac_route_manifest')) {
    function posmain_rbac_route_manifest(): array
    {
        static $manifest = null;
        if ($manifest !== null) {
            return $manifest;
        }

        $path = __DIR__ . '/../config/rbac_route_manifest.php';
        $manifest = is_file($path) ? require $path : [];

        return is_array($manifest) ? $manifest : [];
    }
}

if (!function_exists('rbac_guard_route_permissions_satisfied')) {
    function rbac_guard_route_permissions_satisfied(array $entry, string $lane, ?mysqli $conn = null): bool
    {
        $anyOf = $entry['any_of'] ?? [];
        if (is_array($anyOf) && $anyOf !== []) {
            if (!empty($entry['admin_or']) && auth_guard_is_admin_session()) {
                return true;
            }
            foreach ($anyOf as $permission) {
                $permission = trim((string) $permission);
                if ($permission === '') {
                    continue;
                }
                $allowed = $lane === 'pos'
                    ? auth_guard_pos_lane_has_permission_or_override($permission, $conn)
                    : auth_guard_has_permission($permission, $conn);
                if ($allowed) {
                    return true;
                }
            }

            return false;
        }

        $permission = trim((string) ($entry['permission'] ?? ''));
        if ($permission === '') {
            return true;
        }

        if (!empty($entry['admin_or']) && auth_guard_is_admin_session()) {
            return true;
        }

        return $lane === 'pos'
            ? auth_guard_pos_lane_has_permission_or_override($permission, $conn)
            : auth_guard_has_permission($permission, $conn);
    }
}

if (!function_exists('rbac_guard_route')) {
    function rbac_guard_route(string $relativePath, ?mysqli $conn = null): void
    {
        require_once __DIR__ . '/write_bootstrap.php';

        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $manifest = posmain_rbac_route_manifest();
        $entry = $manifest[$relativePath] ?? null;
        if (!is_array($entry)) {
            require_login();
            return;
        }

        $lane = (string) ($entry['lane'] ?? 'erp');
        $permission = trim((string) ($entry['permission'] ?? ''));
        $csrf = trim((string) ($entry['csrf'] ?? ''));
        $anyOf = $entry['any_of'] ?? [];

        if ($lane === 'pos') {
            require_pos_authenticated();
        } else {
            require_login();
        }

        if ($permission !== '' || (is_array($anyOf) && $anyOf !== [])) {
            if (!rbac_guard_route_permissions_satisfied($entry, $lane, $conn)) {
                $denyPermission = $permission;
                if ($denyPermission === '' && is_array($anyOf) && $anyOf !== []) {
                    $denyPermission = (string) $anyOf[0];
                }
                deny_json_or_redirect('PERMISSION_DENIED', 403, null, $denyPermission !== '' ? $denyPermission : null);
            }
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($csrf !== '' && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            require_csrf($csrf);
        }
    }
}

if (!function_exists('rbac_guard_current_script')) {
    function rbac_guard_current_script(?mysqli $conn = null): void
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
        $script = ltrim(str_replace('\\', '/', $script), '/');
        $parts = explode('/', $script);
        if (count($parts) >= 2) {
            $relative = $parts[count($parts) - 2] . '/' . $parts[count($parts) - 1];
            rbac_guard_route($relative, $conn);
            return;
        }

        require_once __DIR__ . '/write_bootstrap.php';
        require_login();
    }
}
