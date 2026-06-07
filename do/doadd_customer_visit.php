<?php
declare(strict_types=1);

include '../includes/connect.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'invalid request']);
    exit;
}

if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    echo json_encode(['success' => false, 'message' => 'unauthorized']);
    exit;
}

$allowed_gender      = ['male', 'female'];
$allowed_age_group   = ['under18', '18_25', '25_40', 'over40'];
$allowed_mode        = ['solo', 'group'];
$allowed_order_value = ['under60', 'over60'];
$allowed_type        = ['new', 'returning', 'regular'];

$gender      = trim((string)($_POST['gender']      ?? ''));
$age_group   = trim((string)($_POST['age_group']   ?? ''));
$mode        = trim((string)($_POST['mode']        ?? ''));
$order_value = trim((string)($_POST['order_value'] ?? ''));
$type        = trim((string)($_POST['type']        ?? ''));

if (
    !in_array($gender,      $allowed_gender,      true) ||
    !in_array($age_group,   $allowed_age_group,   true) ||
    !in_array($mode,        $allowed_mode,        true) ||
    !in_array($order_value, $allowed_order_value, true) ||
    !in_array($type,        $allowed_type,        true)
) {
    echo json_encode(['success' => false, 'message' => 'invalid values']);
    exit;
}

$start_time = date('H:i:s');
$created_by = (int)($_SESSION['userid'] ?? 0);

$stmt = $conn->prepare(
    "INSERT INTO customer_visits (gender, age_group, mode, start_time, order_value, visit_type, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param('ssssssi', $gender, $age_group, $mode, $start_time, $order_value, $type, $created_by);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
