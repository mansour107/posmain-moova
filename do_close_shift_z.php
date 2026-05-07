<?php
session_start();
include('includes/connect.php');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid Request');
}

$user_id = $_SESSION['userid'];
$shift_date = date('Y-m-d');
$shift_time = date('H:i:s');

// استلام البيانات من النموذج
$sys_total_sales = floatval($_POST['sys_total_sales']);
$sys_total_cash = floatval($_POST['sys_total_cash']);
$sys_total_visa = floatval($_POST['sys_total_visa']);
$expenses = floatval($_POST['sys_expenses']);
$notes = isset($_POST['notes']) ? $conn->real_escape_string($_POST['notes']) : '';

$actual_cash = floatval($_POST['actual_cash']);
$actual_visa = floatval($_POST['actual_visa']);

// الحسابات
$expected_cash = $sys_total_cash - $expenses;
$cash_deficit = $actual_cash - $expected_cash;

$expected_visa = $sys_total_visa;
$visa_deficit = $actual_visa - $expected_visa;

$total_deficit = $cash_deficit + $visa_deficit;

// إعداد بيانات JSON للتفاصيل الإضافية (مثل الدفرنس لكل نوع)
$details_array = [
    'sys_cash' => $sys_total_cash,
    'sys_visa' => $sys_total_visa,
    'sys_expenses' => $expenses,
    'actual_cash' => $actual_cash,
    'actual_visa' => $actual_visa,
    'cash_diff' => $cash_deficit,
    'visa_diff' => $visa_deficit
];
$json_details = json_encode($details_array, JSON_UNESCAPED_UNICODE);

// جلب اسم المستخدم
$user_query = "SELECT aname FROM acc_head WHERE id = '$user_id'";
$user_result = $conn->query($user_query);
$username = $user_result ? $user_result->fetch_assoc()['aname'] : 'Unknown';

// رقم الشيفت
$shift_number = date('Ymd') . '_' . $user_id;

// إدخال البيانات في جدول closed_orders
// ملاحظة: نستخدم الأعمدة الجديدة التي أضفناها في الانتقال
// إذا لم تكن الأعمدة موجودة ستفشل العملية، لذا يجب تشغيل ملف SQL أولاً
$insert_query = "INSERT INTO closed_orders 
                 (shift, date, user, endtime, 
                  total_sales, expenses, cash, fund_after, info,
                  total_cash, total_visa, total_discount, 
                  actual_cash, actual_visa, deficit, status, json_details) 
                 VALUES 
                 ('$shift_number', '$shift_date', '$username', '$shift_time', 
                  '$sys_total_sales', '$expenses', '$actual_cash', '$actual_cash', '$notes',
                  '$sys_total_cash', '$sys_total_visa', 0, 
                  '$actual_cash', '$actual_visa', '$total_deficit', 1, '$json_details')";
// ملاحظة: total_discount لم نمرره من النموذج، يمكن إضافته input hidden اذا اردنا

if ($conn->query($insert_query)) {
    // تسجيل الخروج أو رسالة نجاح
    // سنقوم بتوجيه لصفحة طباعة نهائية او العودة
    $_SESSION['success_message'] = "تم إغلاق الشيفت بنجاح. العجز/الزيادة: " . number_format($total_deficit, 2);
    
    // الخيار: تسجيل خروج المستخدم فوراً
    // include('do/do_logout.php'); // اذا اردنا
    
    // التوجيه لصفحة الجلسات المغلقة كما كان في السابق
    header('Location: closed_sessions.php');
} else {
    echo "خطأ في الإغلاق: " . $conn->error;
    // للتصحيح في حال فشل الاستعلام بسبب نقص الأعمدة
    error_log("Shift Close Error: " . $conn->error);
}
?>
