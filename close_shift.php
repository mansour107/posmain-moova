<?php
session_start();
include('includes/connect.php');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['userid'];
$shift_date = date('Y-m-d');
$shift_time = date('H:i:s');

try {
    // حساب مبيعات المستخدم الحالي لليوم
    $sales_query = "SELECT 
                        COUNT(*) as total_orders,
                        COALESCE(SUM(fat_net), 0) as total_sales
                    FROM ot_head 
                    WHERE DATE(pro_date) = '$shift_date' 
                    AND pro_tybe = 9 
                    AND isdeleted = 0
                    AND fat_net > 0
                    AND user = '$user_id'";
    
    $sales_result = $conn->query($sales_query);
    
    if (!$sales_result) {
        throw new Exception('خطأ في استعلام قاعدة البيانات: ' . $conn->error);
    }
    
    $sales_data = $sales_result->fetch_assoc();
    
    $total_orders = intval($sales_data['total_orders'] ?? 0);
    $total_sales = floatval($sales_data['total_sales'] ?? 0);
    
    // جلب اسم المستخدم
    $user_query = "SELECT aname FROM acc_head WHERE id = '$user_id'";
    $user_result = $conn->query($user_query);
    $username = $user_result ? $user_result->fetch_assoc()['aname'] : 'Unknown';
    
    // جلب بيانات إغلاق الشيفت من POST
    $expenses = floatval($_POST['expenses'] ?? 0);
    $exp_notes = $conn->real_escape_string($_POST['exp_notes'] ?? '');
    $cash = floatval($_POST['cash'] ?? $total_sales);
    $fund_after = floatval($_POST['fund_after'] ?? $total_sales);
    $notes = $conn->real_escape_string($_POST['notes'] ?? '');
    
    // Debug: لوج البيانات المستلمة
    error_log('POST data: ' . print_r($_POST, true));
    error_log('Processed data: expenses=' . $expenses . ', exp_notes=' . $exp_notes . ', cash=' . $cash . ', fund_after=' . $fund_after . ', notes=' . $notes);
    
    // إدراج سجل إغلاق الشيفت
    $shift_number = date('Ymd') . '_' . $user_id;
    $insert_query = "INSERT INTO closed_orders 
                     (shift, date, user, endtime, total_sales, expenses, exp_notes, cash, fund_after, info) 
                     VALUES 
                     ('$shift_number', '$shift_date', '$username', '$shift_time', '$total_sales', '$expenses', '$exp_notes', '$cash', '$fund_after', '$notes')";
    
    if ($conn->query($insert_query)) {
        // رسالة بسيطة
        if ($total_orders > 0) {
            $success_message = "تم إغلاق الشيفت بنجاح - إجمالي مبيعاتك: " . number_format($total_sales, 2) . " ج.م (" . $total_orders . " طلب)";
        } else {
            $success_message = "تم إغلاق الشيفت - لا توجد مبيعات لك اليوم";
        }
        
        $_SESSION['success_message'] = $success_message;
    } else {
        $_SESSION['error_message'] = 'حدث خطأ أثناء إغلاق الشيفت';
    }
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'حدث خطأ أثناء إغلاق الشيفت';
}

// إعادة التوجيه إلى صفحة الجلسات المغلقة
header('Location: closed_sessions.php');
exit;
?>