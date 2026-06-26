<?php

require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../classes/Router/ShopRouter.php';

if (!function_exists('posmain_apply_db_timezone')) {
    function posmain_apply_db_timezone(mysqli $conn, ?string $timezone = null): void
    {
        $timezone = trim($timezone ?? date_default_timezone_get() ?: 'Africa/Cairo');
        if ($timezone === '') {
            return;
        }

        // Compute the numeric offset first so we never depend on MySQL's named
        // time zone tables (which are empty on minimal/MariaDB installs). Strict
        // mysqli mode makes @ suppression ineffective, so all SET time_zone calls
        // are wrapped to avoid throwing on unsupported names.
        try {
            $dateTimeZone = new DateTimeZone($timezone);
            $offsetSeconds = $dateTimeZone->getOffset(new DateTime('now', $dateTimeZone));
        } catch (Throwable $ignored) {
            $offsetSeconds = 2 * 3600;
        }

        $sign = $offsetSeconds >= 0 ? '+' : '-';
        $abs = abs($offsetSeconds);
        $hours = intdiv($abs, 3600);
        $minutes = intdiv($abs % 3600, 60);
        $offset = sprintf('%s%02d:%02d', $sign, $hours, $minutes);
        $offsetEscaped = $conn->real_escape_string($offset);

        // Prefer the named zone when the server actually supports it; otherwise
        // fall back to the numeric offset, which always works.
        $escaped = $conn->real_escape_string($timezone);
        try {
            if (@$conn->query("SET time_zone = '{$escaped}'")) {
                return;
            }
        } catch (Throwable $ignored) {
            // Named time zone not supported; continue to numeric fallback.
        }

        try {
            @$conn->query("SET time_zone = '{$offsetEscaped}'");
        } catch (Throwable $ignored) {
            // Last-resort: leave the connection timezone as the server default.
        }
    }
}

if (!function_exists('posmain_raw_db_connect')) {
    function posmain_raw_db_connect(array $db): mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(
            (string) $db['host'],
            (string) $db['user'],
            (string) $db['pass'],
            (string) $db['name'],
            (int) $db['port']
        );
        $conn->set_charset((string) ($db['charset'] ?: 'utf8mb4'));
        posmain_apply_db_timezone($conn);

        return $conn;
    }
}

if (!function_exists('posmain_router_enabled')) {
    function posmain_router_enabled(?array $config = null): bool
    {
        return PosmainShopRouter::enabled($config);
    }
}

if (!function_exists('posmain_router_web_context')) {
    function posmain_router_web_context(): bool
    {
        return PHP_SAPI !== 'cli';
    }
}

if (!function_exists('posmain_router_db_connect')) {
    function posmain_router_db_connect(?array $config = null): mysqli
    {
        return PosmainShopRouter::connectRouter($config ?: posmain_app_config());
    }
}

if (!function_exists('posmain_session_db_connect')) {
    function posmain_session_db_connect(array $config = []): mysqli
    {
        $config = $config ?: posmain_app_config();
        if (posmain_router_enabled($config)) {
            return posmain_router_db_connect($config);
        }

        return posmain_raw_db_connect($config['database']);
    }
}

if (!function_exists('posmain_shop_db_connect')) {
    function posmain_shop_db_connect(int $shopId, ?array $config = null): mysqli
    {
        $config = $config ?: posmain_app_config();
        $routerConn = posmain_router_db_connect($config);
        try {
            return (new PosmainShopRouter())->connectShopById($routerConn, $shopId);
        } finally {
            $routerConn->close();
        }
    }
}

if (!function_exists('posmain_db_connect_for_branch_uuid')) {
    function posmain_db_connect_for_branch_uuid(string $branchUuid, ?array $config = null): mysqli
    {
        $config = $config ?: posmain_app_config();
        if (!posmain_router_enabled($config)) {
            return posmain_db_connect();
        }

        $router = new PosmainShopRouter();
        $routerConn = posmain_router_db_connect($config);
        try {
            $route = $router->resolveBranchRoute($routerConn, $branchUuid);
            if (!$route) {
                throw new InvalidArgumentException('Unknown or inactive branch route.');
            }

            return $router->connectShopFromRoute($route);
        } finally {
            $routerConn->close();
        }
    }
}

if (!function_exists('posmain_db_connect')) {
    function posmain_db_connect(array $overrides = []): mysqli
    {
        $config = posmain_app_config($overrides);
        $timezone = trim((string) ($config['timezone'] ?? 'Africa/Cairo'));
        if ($timezone !== '') {
            date_default_timezone_set($timezone);
        }

        $db = $config['database'];

        if (!$overrides && posmain_router_enabled($config) && posmain_router_web_context()) {
            $shopId = PosmainShopRouter::activeSessionShopId();
            if ($shopId > 0) {
                return posmain_shop_db_connect($shopId, $config);
            }

            // Router metadata DB has no POS schema (settings, sales, etc.).
            return posmain_raw_db_connect($db);
        }

        return posmain_raw_db_connect($db);
    }
}

if (!function_exists('posmain_db')) {
    function posmain_db(array $overrides = []): mysqli
    {
        return posmain_db_connect($overrides);
    }
}

if (!function_exists('posmain_db_error_is_missing_database')) {
    function posmain_db_error_is_missing_database(Throwable $e): bool
    {
        return strpos($e->getMessage(), 'Unknown database') !== false
            || (int) $e->getCode() === 1049;
    }
}
