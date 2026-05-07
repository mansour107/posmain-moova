<?php
session_start();
include('../includes/connect.php');

// إعادة تعيين علامة التعديل اليدوي لجميع الأصناف
$result = $conn->query("UPDATE myitems SET manual_price_edit = 0");

if ($result) {
    echo json_encode(['success' => true, 'message' => 'تم إعادة تعيين حماية الأسعار بنجاح']);
} else {
    echo json_encode(['success' => false, 'message' => 'فشل في إعادة التعيين']);
}

$conn->close();
?>