<?php

require_once __DIR__ . '/auth_guard.php';

if (!function_exists('posmain_recipe_role_flags')) {
    function posmain_recipe_role_flags(?mysqli $conn = null, ?array $roleFlags = null): array
    {
        return $roleFlags ?? auth_guard_current_role_flags($conn);
    }
}

if (!function_exists('posmain_recipe_is_admin')) {
    function posmain_recipe_is_admin(?array $session = null, ?array $roleFlags = null): bool
    {
        $session = $session ?? $_SESSION;
        $roleFlags = posmain_recipe_role_flags(null, $roleFlags);

        return auth_guard_is_admin_session($session, $roleFlags);
    }
}

if (!function_exists('posmain_recipe_role_has_any_flag')) {
    function posmain_recipe_role_has_any_flag(array $roleFlags, array $flags): bool
    {
        foreach ($flags as $flag) {
            if (array_key_exists($flag, $roleFlags) && (int) $roleFlags[$flag] === 1) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('posmain_recipe_has_stock_sensitive_access')) {
    function posmain_recipe_has_stock_sensitive_access(?mysqli $conn = null, ?array $session = null, ?array $roleFlags = null): bool
    {
        $session = $session ?? $_SESSION;
        $roleFlags = posmain_recipe_role_flags($conn, $roleFlags);

        return posmain_recipe_is_admin($session, $roleFlags)
            || posmain_recipe_role_has_any_flag($roleFlags, ['add_stock', 'edit_stock']);
    }
}

if (!function_exists('posmain_recipe_has_accounting_access')) {
    function posmain_recipe_has_accounting_access(?mysqli $conn = null, ?array $session = null, ?array $roleFlags = null): bool
    {
        $session = $session ?? $_SESSION;
        $roleFlags = posmain_recipe_role_flags($conn, $roleFlags);

        return auth_guard_session_has_permission('accounting.view', $roleFlags, $session);
    }
}

if (!function_exists('posmain_recipe_can_view_costs')) {
    function posmain_recipe_can_view_costs(?mysqli $conn = null, ?array $session = null, ?array $roleFlags = null): bool
    {
        return posmain_recipe_has_stock_sensitive_access($conn, $session, $roleFlags)
            || posmain_recipe_has_accounting_access($conn, $session, $roleFlags);
    }
}

if (!function_exists('posmain_recipe_can_view_sensitive_reports')) {
    function posmain_recipe_can_view_sensitive_reports(?mysqli $conn = null, ?array $session = null, ?array $roleFlags = null): bool
    {
        return posmain_recipe_can_view_costs($conn, $session, $roleFlags);
    }
}

if (!function_exists('posmain_recipe_can_manage_stock_operations')) {
    function posmain_recipe_can_manage_stock_operations(?mysqli $conn = null, ?array $session = null, ?array $roleFlags = null): bool
    {
        return posmain_recipe_has_stock_sensitive_access($conn, $session, $roleFlags);
    }
}
