<?php

require_once __DIR__ . '/../../../config/app_config.php';

class PosOrderAccessPolicy
{
    public static function permissionForRoute(string $route): string
    {
        $map = [
            'orders.table' => 'pos.table.open',
            'orders.takeaway' => 'pos.sell.takeaway',
            'orders.delivery' => 'pos.sell.delivery',
            'orders.payment' => 'pos.payment.take',
            'orders.split-payment' => 'pos.split',
            'orders.edit' => 'pos.sell.takeaway',
            'orders.table.free' => 'pos.table.open',
            'integrations.cofe.orders' => 'moova.accept',
        ];

        return $map[$route] ?? 'pos.open';
    }

    public static function requireRoutePermission(mysqli $conn, string $route): void
    {
        if (!function_exists('require_pos_lane_permission')) {
            require_once __DIR__ . '/../../../includes/auth_guard.php';
        }

        require_pos_lane_permission(self::permissionForRoute($route), $conn);
    }
}
