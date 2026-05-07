<?php
include '../includes/connect.php';

$code = trim($_POST['code'] ?? '');

if ($code !== '') {
    $stmt = $conn->prepare("SELECT id, client, remain, todate FROM booking_cards WHERE barcode = ? AND isdeleted = 0 LIMIT 1");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'client'  => $row['client'],
            'remain'  => $row['remain'],
            'id'  => $row['id'],
            'todate'  => $row['todate']
        ]);
    } else {
        echo json_encode(['success' => false]);
    }
} else {
    echo json_encode(['success' => false]);
}
