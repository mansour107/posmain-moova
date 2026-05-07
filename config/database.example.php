<?php
// Copy this file to config/database.php and adjust for the target environment.
$host = getenv('POSMAIN_API_DB_HOST') ?: '127.0.0.1';
$username = getenv('POSMAIN_API_DB_USER') ?: 'root';
$password = getenv('POSMAIN_API_DB_PASS') ?: '';
$database = getenv('POSMAIN_API_DB_NAME') ?: 'kody2';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode([
        'status' => 'error',
        'message' => 'Database connection failed',
    ]));
}

$conn->set_charset('utf8mb4');
