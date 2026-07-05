<?php

require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/csrf.php';

if (!function_exists('write_bootstrap_prepare_response')) {
    function write_bootstrap_prepare_response(): void
    {
        $script = (string) ($_SERVER['PHP_SELF'] ?? '');
        if (strpos($script, '/ajax/') !== false || strpos($script, 'ajax/') === 0) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
        }
    }
}

write_bootstrap_prepare_response();
