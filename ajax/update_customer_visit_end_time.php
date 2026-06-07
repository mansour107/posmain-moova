<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth_guard.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'invalid_method'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('customer_visits');

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$end_time = isset($_POST['end_time']) ? trim((string) $_POST['end_time']) : '';

if ($id <= 0 || $end_time === '') {
    echo json_encode(['success' => false, 'error' => 'Invalid data'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conn->prepare('UPDATE customer_visits SET end_time = ? WHERE id = ? AND isdeleted = 0');
$stmt->bind_param('si', $end_time, $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error], JSON_UNESCAPED_UNICODE);
}
$stmt->close();
