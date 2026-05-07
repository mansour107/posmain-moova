<?php
header('Content-Type: application/json');
include('../../includes/connect.php');

// التحقق من وجود معرف الفئة
if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['error' => 'معرف الفئة مطلوب']);
    exit;
}

$category_id = intval($_GET['id']);

try {
    // استخدام Prepared Statement للأمان
    $stmt = $conn->prepare("SELECT id, gname, info FROM item_group WHERE id = ? AND isdeleted = 0");
    if (!$stmt) {
        throw new Exception('فشل في تحضير الاستعلام: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $category = $result->fetch_assoc();
    $stmt->close();
    
    if ($category) {
        echo json_encode($category);
    } else {
        echo json_encode(['error' => 'لم يتم العثور على الفئة']);
    }
    
} catch (Exception $e) {
    error_log('خطأ في get_category.php: ' . $e->getMessage());
    echo json_encode(['error' => 'خطأ في جلب بيانات الفئة']);
}
?>