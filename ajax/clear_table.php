<?php
include('../includes/connect.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير صحيحة']);
    exit;
}

$table_id = intval($_POST['table_id'] ?? 0);

if ($table_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'معرف الطاولة غير صحيح']);
    exit;
}

try {
    $conn->begin_transaction();
    
    // جلب اسم الطاولة
    $table_query = "SELECT tname FROM tables WHERE id = ?";
    $stmt = $conn->prepare($table_query);
    $stmt->bind_param("i", $table_id);
    $stmt->execute();
    $table_result = $stmt->get_result();
    
    if ($table_result->num_rows === 0) {
        throw new Exception('الطاولة غير موجودة');
    }
    
    $table_data = $table_result->fetch_assoc();
    $table_name = $table_data['tname'];
    
    // البحث عن الطلب النشط للطاولة وحذفه نهائياً
    $order_query = "SELECT * FROM ot_head WHERE info LIKE ? AND pro_tybe = 9 ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($order_query);
    $search_term = "%$table_name%";
    $stmt->bind_param("s", $search_term);
    $stmt->execute();
    $order_result = $stmt->get_result();
    
    if ($order_result->num_rows > 0) {
        $order_data = $order_result->fetch_assoc();
        $order_id = $order_data['id'];
        
        // حذف تفاصيل الطلب
        $delete_details = "DELETE FROM fat_details WHERE pro_id = ?";
        $stmt = $conn->prepare($delete_details);
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        
        // حذف الطلب نفسه
        $delete_order = "DELETE FROM ot_head WHERE id = ?";
        $stmt = $conn->prepare($delete_order);
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
    }
    
    // تحديث حالة الطاولة إلى فارغة
    $update_table = "UPDATE tables SET table_case = 0 WHERE id = ?";
    $stmt = $conn->prepare($update_table);
    $stmt->bind_param("i", $table_id);
    $stmt->execute();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'تم تفريغ الطاولة وحذف الطلب نهائياً'
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>