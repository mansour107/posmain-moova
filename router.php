<?php

/**
 * PHP built-in server router for Commercial V1.
 * Denies prohibited utilities, private directories, and non-public tooling.
 *
 * Return false to let the built-in server serve the requested file as usual.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$relative = ltrim($uri, '/');

$prohibitedExact = [];
$prohibitedFile = __DIR__ . '/config/prohibited_web_routes.php';
if (is_file($prohibitedFile)) {
    $loaded = require $prohibitedFile;
    if (is_array($loaded)) {
        $prohibitedExact = $loaded;
    }
}

$deniedPrefixes = [
    'tools/',
    'tests/',
    'docs/',
    'cli/',
    'deploy/',
    'backup/',
    'logs/',
    'var/',
    'db/',
    'dbase/',
    'update/',
    'scripts/',
    'output/',
    '.git/',
    '.github/',
    '.cursor/',
    'node_modules/',
    'vendor/bin/',
];

$denied = false;
foreach ($deniedPrefixes as $prefix) {
    if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
        $denied = true;
        break;
    }
}

$pathOnly = explode('?', $relative, 2)[0];
foreach ($prohibitedExact as $exact) {
    $exact = ltrim((string) $exact, '/');
    if ($exact !== '' && ($pathOnly === $exact || str_starts_with($pathOnly, $exact . '/'))) {
        $denied = true;
        break;
    }
}

$base = basename($pathOnly);
if (str_starts_with($pathOnly, 'uploads/')
    && preg_match('/\.(?:php\d*|phtml|phar)(?:$|\/)/i', $pathOnly) === 1
) {
    $denied = true;
}
if (preg_match('/^(debug_|check_|fix_|repair_)/i', $base) === 1
    || preg_match('/^(run_migrations|run_.*_updates|setup_demo)/i', $base) === 1
) {
    $denied = true;
}

if ($denied) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Not Found';
    return true;
}

$file = __DIR__ . '/' . $pathOnly;
if ($pathOnly !== '' && is_file($file)) {
    return false;
}

if ($pathOnly === '' || substr($pathOnly, -1) === '/') {
    $index = rtrim($file, '/') . '/index.php';
    if (is_file($index)) {
        require $index;
        return true;
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not Found';
return true;
