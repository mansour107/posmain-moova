<?php
include('../includes/connect.php'); // تأكد أن ملف الاتصال موجود

header('Content-Type: application/json');

$barcode = $_GET['barcode'] ?? '';

if ($barcode) {
    $stmt = $conn->prepare("SELECT iname, price1 FROM myitems WHERE barcode = ?");
    $stmt->bind_param("s", $barcode);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            'iname' => $row['iname'],
            'price1' => $row['price1']
        ]);
    } else {
        echo json_encode(['iname' => null, 'price1' => null]);
    }
} else {
    echo json_encode(['iname' => null, 'price1' => null]);
}
?>
