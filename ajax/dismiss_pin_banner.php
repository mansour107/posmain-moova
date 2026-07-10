<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();
$_SESSION['posmain_pin_banner_dismissed'] = true;
echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
