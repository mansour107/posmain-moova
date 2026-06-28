<?php

require_once __DIR__ . '/production_guard.php';

if (!function_exists('pos_order_api_router_guard_direct_access')) {
    function pos_order_api_router_guard_direct_access(string $route): void
    {
        if (!production_guard_env_bool('POSMAIN_ORDER_API_ROUTER_ONLY', false)) {
            return;
        }

        if ($route === 'do/doadd_invoice.php') {
            production_guard_deny_route($route, ['status' => 404]);
        }

        production_guard_deny_route($route, ['status' => 404]);
    }
}
