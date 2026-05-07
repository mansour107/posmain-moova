<?php
session_start();
include('../includes/connect.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = intval($_POST['item_id']);
    $price_field = $_POST['price_field']; // price1, cost_price, market_price, etc.
    $new_price = floatval($_POST['new_price']);
    
    // التحقق من صحة البيانات
    if ($item_id > 0 && !empty($price_field) && $new_price >= 0) {
        
        // قائمة الحقول المسموح بتعديلها
        $allowed_fields = ['price1', 'cost_price', 'market_price', 'last_price'];
        
        if (in_array($price_field, $allowed_fields)) {
            
            // تحديث السعر وتعيين علامة التعديل اليدوي
            $stmt = $conn->prepare("UPDATE myitems SET $price_field = ?, manual_price_edit = 1 WHERE id = ?");
            $stmt->bind_param("di", $new_price, $item_id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'تم تحديث السعر بنجاح']);
            } else {
                echo json_encode(['success' => false, 'message' => 'فشل في تحديث السعر']);
            }
            
            $stmt->close();
        } else {
            echo json_encode(['success' => false, 'message' => 'حقل غير مسموح']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صحيحة']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'طريقة طلب غير صحيحة']);
}

$conn->close();
?>