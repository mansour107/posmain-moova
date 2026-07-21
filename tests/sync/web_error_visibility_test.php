<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_bootstrap.php';

function webErrorVisibilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

webErrorVisibilityAssert(
    function_exists('posmain_configure_web_error_visibility'),
    'web error visibility guard should exist'
);

$previousDisplayErrors = ini_get('display_errors');
$previousDisplayStartupErrors = ini_get('display_startup_errors');
$previousLogErrors = ini_get('log_errors');

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '0');
posmain_configure_web_error_visibility('fpm-fcgi');

webErrorVisibilityAssert(ini_get('display_errors') === '0', 'browser requests should not display raw PHP errors');
webErrorVisibilityAssert(ini_get('display_startup_errors') === '0', 'browser requests should not display raw PHP startup errors');
webErrorVisibilityAssert(ini_get('log_errors') === '1', 'browser requests should keep PHP error logging enabled');

ini_set('display_errors', (string) $previousDisplayErrors);
ini_set('display_startup_errors', (string) $previousDisplayStartupErrors);
ini_set('log_errors', (string) $previousLogErrors);

echo "web-error-visibility-ok\n";
