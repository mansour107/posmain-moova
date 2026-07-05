<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/reset_manual_prices.php');

// إعادة تعيين علامة التعديل اليدوي لجميع الأصناف
$result = $conn->query("UPDATE myitems SET manual_price_edit = 0");

if ($result) {
    echo json_encode(['success' => true, 'message' => 'تم إعادة تعيين حماية الأسعار بنجاح']);
} else {
    echo json_encode(['success' => false, 'message' => 'فشل في إعادة التعيين']);
}

$conn->close();
?>