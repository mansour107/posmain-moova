<?php
// Example compatibility shim for legacy API files that expect $conn.
// Runtime credentials must come from environment variables or .env, not this file.
require_once __DIR__ . '/../includes/db_bootstrap.php';

try {
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    http_response_code(500);
    die(json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
    ]));
}
