<?php

require_once __DIR__ . '/entry_classification_guard.php';

if (!function_exists('posmain_enforce_entry_permission')) {
    function posmain_enforce_entry_permission(mysqli $conn): void
    {
        if (PHP_SAPI === 'cli' || defined('POSMAIN_ENTRY_PERMISSION_GUARDED')) {
            return;
        }
        define('POSMAIN_ENTRY_PERMISSION_GUARDED', true);

        $relative = posmain_entry_relative_path();
        $isRoute = str_starts_with($relative, 'ajax/')
            || str_starts_with($relative, 'do/')
            || str_starts_with($relative, 'print/');

        if ($isRoute) {
            require_once __DIR__ . '/csrf.php';
            require_once __DIR__ . '/rbac_route_guard.php';
            rbac_guard_route($relative, $conn);
            return;
        }

        require_once __DIR__ . '/page_guard.php';
        $page = basename($relative);
        $entry = page_guard_manifest_entry($page);
        if (is_array($entry) && !empty($entry['public'])) {
            return;
        }
        page_guard_from_manifest($page, $conn);
    }
}

