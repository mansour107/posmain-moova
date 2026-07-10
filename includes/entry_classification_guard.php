<?php

/**
 * Earliest fail-closed boundary for HTTP PHP entry points.
 *
 * Named permission and CSRF checks run once a database connection is available;
 * this guard prevents an unclassified or unauthenticated script from executing
 * before that point.
 */
if (!function_exists('posmain_entry_relative_path')) {
    function posmain_entry_relative_path(): string
    {
        $root = realpath(dirname(__DIR__));
        $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if ($root === false || $script === false || !str_starts_with($script, $root . DIRECTORY_SEPARATOR)) {
            return '';
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($script, strlen($root) + 1));
    }
}

if (!function_exists('posmain_entry_deny_early')) {
    function posmain_entry_deny_early(string $code, int $status, bool $json): never
    {
        http_response_code($status);
        header('Cache-Control: no-store, private');
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => $code], JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo $code;
        }
        exit;
    }
}

if (!function_exists('posmain_enforce_entry_classification')) {
    function posmain_enforce_entry_classification(): void
    {
        if (PHP_SAPI === 'cli' || defined('POSMAIN_ENTRY_CLASSIFICATION_GUARDED')) {
            return;
        }
        define('POSMAIN_ENTRY_CLASSIFICATION_GUARDED', true);

        $relative = posmain_entry_relative_path();
        if ($relative === '') {
            posmain_entry_deny_early('ENTRY_PATH_INVALID', 403, false);
        }

        $isRoute = str_starts_with($relative, 'ajax/')
            || str_starts_with($relative, 'do/')
            || str_starts_with($relative, 'print/');
        $manifestPath = $isRoute
            ? dirname(__DIR__) . '/config/rbac_route_manifest.php'
            : dirname(__DIR__) . '/config/rbac_page_manifest.php';
        $manifest = is_file($manifestPath) ? require $manifestPath : [];
        $key = $isRoute ? $relative : basename($relative);
        $entry = is_array($manifest) ? ($manifest[$key] ?? null) : null;

        if (!is_array($entry)) {
            posmain_entry_deny_early(
                $isRoute ? 'RBAC_ROUTE_UNCLASSIFIED' : 'RBAC_PAGE_UNCLASSIFIED',
                403,
                $isRoute
            );
        }
        if (!empty($entry['quarantined'])) {
            posmain_entry_deny_early('ENDPOINT_QUARANTINED', 410, $isRoute);
        }
        if (!empty($entry['public'])) {
            return;
        }
        if ((int) ($_SESSION['userid'] ?? 0) > 0 && isset($_SESSION['login'])) {
            return;
        }

        if ($isRoute) {
            posmain_entry_deny_early('AUTH_REQUIRED', 401, true);
        }
        header('Location: index.php');
        exit;
    }
}

