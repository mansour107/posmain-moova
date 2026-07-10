<?php

require_once __DIR__ . '/auth_guard.php';

if (!function_exists('page_guard_manifest_entry')) {
    function page_guard_manifest_entry(string $page): ?array
    {
        static $manifest = null;
        if ($manifest === null) {
            $path = __DIR__ . '/../config/rbac_page_manifest.php';
            $manifest = is_file($path) ? require $path : [];
            if (!is_array($manifest)) {
                $manifest = [];
            }
        }

        return $manifest[$page] ?? null;
    }
}

if (!function_exists('page_guard_permissions_satisfied')) {
    function page_guard_permissions_satisfied(array $entry, ?mysqli $conn = null): bool
    {
        $anyOf = $entry['any_of'] ?? [];
        if (is_array($anyOf) && $anyOf !== []) {
            if (!empty($entry['admin_or']) && auth_guard_is_admin_session()) {
                return true;
            }
            foreach ($anyOf as $permission) {
                $permission = trim((string) $permission);
                if ($permission !== '' && auth_guard_has_permission($permission, $conn)) {
                    return true;
                }
            }

            return false;
        }

        $permission = trim((string) ($entry['permission'] ?? ''));
        if ($permission === '') {
            return true;
        }

        if (!empty($entry['admin_or'])) {
            return auth_guard_is_admin_session() || auth_guard_has_permission($permission, $conn);
        }

        return auth_guard_has_permission($permission, $conn);
    }
}

if (!function_exists('page_guard')) {
    function page_guard(?string $permission = null, ?mysqli $conn = null, bool $adminOrPermission = false): void
    {
        require_login();
        if ($permission === null || $permission === '') {
            $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
            $entry = page_guard_manifest_entry($page);
            if (!is_array($entry)) {
                deny_json_or_redirect('RBAC_PAGE_UNCLASSIFIED', 403);
            }
            if (!page_guard_permissions_satisfied($entry, $conn)) {
                $required = trim((string) ($entry['permission'] ?? ''));
                $anyOf = $entry['any_of'] ?? [];
                if ($required === '' && is_array($anyOf) && $anyOf !== []) {
                    $required = (string) $anyOf[0];
                }
                deny_json_or_redirect(
                    'PERMISSION_DENIED',
                    403,
                    null,
                    $required !== '' ? $required : null
                );
            }
            return;
        }

        if ($adminOrPermission) {
            require_admin_or_permission($permission, $conn);
            return;
        }

        require_permission($permission, $conn);
    }
}

if (!function_exists('page_guard_from_manifest')) {
    function page_guard_from_manifest(string $page, ?mysqli $conn = null): void
    {
        require_login();
        $entry = page_guard_manifest_entry($page);
        if (!is_array($entry)) {
            deny_json_or_redirect('RBAC_PAGE_UNCLASSIFIED', 403);
        }

        if (!page_guard_permissions_satisfied($entry, $conn)) {
            $required = trim((string) ($entry['permission'] ?? ''));
            $anyOf = $entry['any_of'] ?? [];
            if ($required === '' && is_array($anyOf) && $anyOf !== []) {
                $required = (string) $anyOf[0];
            }
            deny_json_or_redirect('PERMISSION_DENIED', 403, null, $required !== '' ? $required : null);
        }
    }
}
