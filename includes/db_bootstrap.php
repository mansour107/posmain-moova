<?php

require_once __DIR__ . '/../config/app_config.php';

if (!function_exists('posmain_db_connect')) {
    function posmain_db_connect(array $overrides = []): mysqli
    {
        $config = posmain_app_config($overrides);
        $timezone = trim((string) ($config['timezone'] ?? 'Africa/Cairo'));
        if ($timezone !== '') {
            date_default_timezone_set($timezone);
        }

        $db = $config['database'];

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(
            (string) $db['host'],
            (string) $db['user'],
            (string) $db['pass'],
            (string) $db['name'],
            (int) $db['port']
        );
        $conn->set_charset((string) ($db['charset'] ?: 'utf8mb4'));

        return $conn;
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
