<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';

// التحقق من تسجيل الدخول
if (PHP_SAPI !== 'cli') {
    require_pos_authenticated();
} elseif (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pos_barcode.php');
    exit;
}
if (PHP_SAPI !== 'cli') {
    require_csrf('shift_close');
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
                    WHERE DATE(pro_date) = ?
                    AND pro_tybe = 9
                    AND isdeleted = 0
                    AND fat_net > 0
                    AND user = ?";
    $sales_stmt = $conn->prepare($sales_query);
    $sales_stmt->bind_param('si', $shift_date, $user_id);
    $sales_stmt->execute();
    $sales_result = $sales_stmt->get_result();
    
    $sales_data = $sales_result->fetch_assoc();
    $sales_stmt->close();
    
    $total_orders = intval($sales_data['total_orders'] ?? 0);
    $total_sales = floatval($sales_data['total_sales'] ?? 0);
    
    // جلب اسم المستخدم من نفس جدول تسجيل الدخول
    $user_query = "SELECT uname FROM users WHERE id = ? LIMIT 1";
    $user_stmt = $conn->prepare($user_query);
    $user_stmt->bind_param('i', $user_id);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user_row = $user_result ? $user_result->fetch_assoc() : null;
    $user_stmt->close();
    $username = $user_row['uname'] ?? ($_SESSION['login'] ?? 'Unknown');
    
    // جلب بيانات إغلاق الشيفت من POST
    $expenses = floatval($_POST['expenses'] ?? 0);
    $exp_notes = trim((string) ($_POST['exp_notes'] ?? ''));
    $cash = floatval($_POST['cash'] ?? $total_sales);
    $fund_after = floatval($_POST['fund_after'] ?? $total_sales);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    
    // إدراج سجل إغلاق الشيفت
    $shift_number = date('Ymd') . '_' . $user_id;
    $insert_query = "INSERT INTO closed_orders
                     (shift, date, user, endtime, total_sales, expenses, exp_notes, cash, fund_after, info)
                     VALUES
                     (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $insert_stmt = $conn->prepare($insert_query);
    $insert_stmt->bind_param('ssssddsdds', $shift_number, $shift_date, $username, $shift_time, $total_sales, $expenses, $exp_notes, $cash, $fund_after, $notes);
    
    if ($insert_stmt->execute()) {
        // رسالة بسيطة
        if ($total_orders > 0) {
            $success_message = "تم إغلاق الشيفت بنجاح - إجمالي مبيعاتك: " . number_format($total_sales, 2) . " ج.م (" . $total_orders . " طلب)";
        } else {
            $success_message = "تم إغلاق الشيفت - لا توجد مبيعات لك اليوم";
        }
        
        $_SESSION['success_message'] = $success_message;
        unset($_SESSION['pos_authenticated'], $_SESSION['pos_user_id'], $_SESSION['pos_user_name']);
    } else {
        error_log('Shift close insert failed: ' . $conn->error);
        $_SESSION['error_message'] = 'حدث خطأ أثناء إغلاق الشيفت';
    }
    $insert_stmt->close();
    
} catch (Exception $e) {
    error_log('Shift close exception: ' . $e->getMessage());
    $_SESSION['error_message'] = 'حدث خطأ أثناء إغلاق الشيفت';
}

// إعادة التوجيه إلى صفحة الجلسات المغلقة
header('Location: closed_sessions.php');
exit;
?>
