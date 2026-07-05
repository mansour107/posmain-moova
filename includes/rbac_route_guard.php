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
        $adminOr = !empty($entry['admin_or']);

        if ($lane === 'pos') {
            require_pos_authenticated();
        } else {
            require_login();
        }

        if ($permission !== '') {
            if ($adminOr) {
                require_admin_or_permission($permission, $conn);
            } else {
                require_permission($permission, $conn);
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
