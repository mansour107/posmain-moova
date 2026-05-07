<?php
include('../includes/connect.php');

header('Content-Type: application/json');

try {
    if (!isset($_POST['order_id']) || !$_POST['order_id']) {
        throw new Exception('معرف الطلب مطلوب');
    }
    
    if (!isset($_POST['table_id']) || !$_POST['table_id']) {
        throw new Exception('معرف الطاولة مطلوب');
    }
    
    $order_id = intval($_POST['order_id']);
    $table_id = intval($_POST['table_id']);
    
    // التحقق من وجود الطلب
    $check_query = "SELECT id, pro_tybe FROM ot_head WHERE id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("i", $order_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        throw new Exception('الطلب غير موجود');
    }
    
    $order = $check_result->fetch_assoc();
    
    // التحقق من أن الطلب لم يتم سداده بعد
    if ($order['pro_tybe'] == 1) {
        throw new Exception('لا يمكن إلغاء طلب تم سداده');
    }
    
    // بدء المعاملة
    $conn->begin_transaction();
    
    try {
        // حذف تفاصيل الطلب
        $delete_details = "UPDATE fat_details SET isdeleted = 1 WHERE pro_id = ?";
        $details_stmt = $conn->prepare($delete_details);
        $details_stmt->bind_param("i", $order_id);
        
        if (!$details_stmt->execute()) {
            throw new Exception('خطأ في حذف تفاصيل الطلب');
        }
        
        // حذف رأس الطلب
        $delete_order = "UPDATE ot_head SET isdeleted = 1 WHERE id = ?";
        $order_stmt = $conn->prepare($delete_order);
        $order_stmt->bind_param("i", $order_id);
        
        if (!$order_stmt->execute()) {
            throw new Exception('خطأ في حذف الطلب');
        }
        
        // تفريغ الطاولة
        $update_table = "UPDATE tables SET table_case = 0 WHERE id = ?";
        $table_stmt = $conn->prepare($update_table);
        $table_stmt->bind_param("i", $table_id);
        
        if (!$table_stmt->execute()) {
            throw new Exception('خطأ في تحديث حالة الطاولة');
        }
        
        // إضافة سجل في جدول السجلات إذا كان موجوداً
        $log_query = "INSERT INTO order_logs (order_id, action, notes, created_at) 
                     VALUES (?, 'cancelled', 'تم إلغاء الطلب من نظام الطاولات', NOW())";
        $log_stmt = $conn->prepare($log_query);
        $log_stmt->bind_param("i", $order_id);
        $log_stmt->execute(); // لا نتوقف إذا فشل هذا
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'تم إلغاء الطلب بنجاح'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>