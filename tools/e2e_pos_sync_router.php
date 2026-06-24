<?php

$root = dirname(__DIR__);
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

$routes = [
    '/api/sync/receive_branch_events.php' => $root . '/api/sync/receive_branch_events.php',
    '/api/sync/receive_branch_image.php' => $root . '/api/sync/receive_branch_image.php',
    '/api/sync/export_branch_restore.php' => $root . '/api/sync/export_branch_restore.php',
    '/api/sync/export_branch_image.php' => $root . '/api/sync/export_branch_image.php',
    '/api/sync/branch_events.php' => $root . '/api/sync/branch_events.php',
    '/api/sync/ack_branch_events.php' => $root . '/api/sync/ack_branch_events.php',
];

if (!isset($routes[$path])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'reason' => 'not_found', 'path' => $path], JSON_UNESCAPED_SLASHES);
    exit;
}

require $routes[$path];
