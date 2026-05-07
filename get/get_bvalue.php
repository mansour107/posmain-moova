<?php
include '../includes/connect.php';

$id = intval($_POST['id'] ?? 0);

if ($id > 0) {
    $stmt = $conn->prepare("SELECT value, qty FROM book_tybes WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    header('Content-Type: application/json');
    echo json_encode([
        'value' => $row['value'] ?? 0,
        'qty'   => $row['qty'] ?? 0
    ]);
} else {
    echo json_encode(['value' => 0, 'qty' => 0]);
}
