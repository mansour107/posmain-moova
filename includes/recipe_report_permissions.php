<?php

require_once __DIR__ . '/recipe_permissions.php';

if (!function_exists('posmain_recipe_report_link_permissions')) {
    function posmain_recipe_report_link_permissions(?mysqli $conn = null, ?array $session = null, ?array $roleFlags = null): array
    {
        $session = $session ?? $_SESSION;
        $roleFlags = $roleFlags ?? auth_guard_current_role_flags($conn);

        $isAdmin = posmain_recipe_is_admin($session, $roleFlags);
        $canReports = auth_guard_session_has_permission('reports.view', $roleFlags, $session);
        $canMenu = auth_guard_session_has_permission('menu.edit', $roleFlags, $session);
        $canStockSensitive = posmain_recipe_has_stock_sensitive_access($conn, $session, $roleFlags);
        $canAccounting = posmain_recipe_has_accounting_access($conn, $session, $roleFlags);
        $canRecipeEdit = $isAdmin || $canMenu || $canStockSensitive;
        $canSensitiveRecipe = $isAdmin || $canStockSensitive || $canAccounting;

        return [
            'stock_reconciliation' => $isAdmin || $canReports || $canStockSensitive || $canAccounting,
            'audit' => $canSensitiveRecipe,
            'editor' => $isAdmin || $canMenu || $canStockSensitive || $canAccounting,
            'manage' => $canRecipeEdit,
            'production' => $isAdmin || $canMenu || $canStockSensitive || $canAccounting,
            'waste' => $canSensitiveRecipe,
            'operations' => $isAdmin || $canReports || $canStockSensitive || $canAccounting,
            'dashboard' => $canSensitiveRecipe,
        ];
    }
}

if (!function_exists('posmain_recipe_report_can_view_sales_reconciliation')) {
    function posmain_recipe_report_can_view_sales_reconciliation(?mysqli $conn = null, ?array $session = null, ?array $roleFlags = null): bool
    {
        $links = posmain_recipe_report_link_permissions($conn, $session, $roleFlags);

        return (bool) ($links['stock_reconciliation'] ?? false);
    }
}
