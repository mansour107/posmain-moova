<?php

require_once __DIR__ . '/auth_guard.php';

if (!function_exists('page_guard')) {
    function page_guard(?string $permission = null, ?mysqli $conn = null, bool $adminOrPermission = false): void
    {
        require_login();
        if ($permission === null || $permission === '') {
            return;
        }

        if ($adminOrPermission) {
            require_admin_or_permission($permission, $conn);
            return;
        }

        require_permission($permission, $conn);
    }
}
