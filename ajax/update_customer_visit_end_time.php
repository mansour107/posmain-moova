<?php
session_start();
include('../includes/connect.php');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $end_time = isset($_POST['end_time']) ? trim($_POST['end_time']) : '';

    if ($id > 0 && $end_time !== '') {
        $sql = "UPDATE customer_visits SET end_time = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $end_time, $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $stmt->error]);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
    }
}
?>
