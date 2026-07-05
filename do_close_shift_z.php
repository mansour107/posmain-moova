<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftDrawerReconciliationService.php';
require_once __DIR__ . '/classes/Pos/Service/DrawerSessionService.php';

posmain_send_no_store_headers();

// التحقق من تسجيل الدخول
if (PHP_SAPI !== 'cli') {
    require_pos_authenticated();
    require_permission('pos.shift.close', $conn);
} elseif (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Invalid Request');
}
if (PHP_SAPI !== 'cli') {
    require_csrf('shift_close_z');
}

// حارس ضد الإغلاق المزدوج: إذا أُغلق الشيفت مسبقاً لهذه الجلسة لا تُدرج سجلاً جديداً.
if (PHP_SAPI !== 'cli' && !empty($_SESSION['pos_shift_closed_for_session'])) {
    $_SESSION['error_message'] = 'تم إغلاق هذا الشيفت مسبقاً. أعد فتح نقطة البيع لبدء شيفت جديد.';
    header('Location: closed_sessions.php');
    exit;
}

$user_id = $_SESSION['userid'];
$shift_date = date('Y-m-d');
$shift_time = date('H:i:s');

// استلام البيانات من النموذج
$sys_total_sales = floatval($_POST['sys_total_sales']);
$sys_total_cash = floatval($_POST['sys_total_cash']);
$sys_total_visa = floatval($_POST['sys_total_visa']);
$expenses = floatval($_POST['sys_expenses']);
$notes = isset($_POST['notes']) ? trim((string) $_POST['notes']) : '';

$actual_cash = floatval($_POST['actual_cash']);
$actual_visa = floatval($_POST['actual_visa']);
$requested_drawer_session_id = intval($_POST['drawer_session_id'] ?? 0);
$drawer_reconciliation = null;
$drawer_session_id = 0;
$has_server_reconciliation = false;

try {
    $reconciliation_scope = [
        'user_id' => $user_id,
        'tenant' => 0,
        'branch' => 0,
        'date' => $shift_date,
    ];
    if ($requested_drawer_session_id > 0) {
        $reconciliation_scope['drawer_session_id'] = $requested_drawer_session_id;
    } elseif (!empty($_SESSION['pos_drawer_session_id'])) {
        $reconciliation_scope['drawer_session_id'] = (int) $_SESSION['pos_drawer_session_id'];
    }

    $drawer_reconciliation = (new ShiftDrawerReconciliationService())->buildForUser($conn, $reconciliation_scope);
    $drawer_session_id = intval($drawer_reconciliation['drawer_session']['id'] ?? 0);
    $has_server_reconciliation = $drawer_session_id > 0 || floatval($drawer_reconciliation['payments']['total'] ?? 0) > 0;
    if ($has_server_reconciliation) {
        $sys_total_cash = floatval($drawer_reconciliation['payments']['cash'] ?? $sys_total_cash);
        $sys_total_visa = floatval($drawer_reconciliation['payments']['non_cash'] ?? $sys_total_visa);
    }
} catch (Throwable $e) {
    error_log('Shift drawer reconciliation failed: ' . $e->getMessage());
}

// الحسابات
$expected_cash = $drawer_session_id > 0 ? floatval($drawer_reconciliation['reconciliation']['expected_cash'] ?? 0) : $sys_total_cash - $expenses;
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
    'visa_diff' => $visa_deficit,
    'drawer_session_id' => $drawer_session_id,
    'drawer_expected_cash' => $expected_cash,
    'drawer_cash_difference' => floatval($drawer_reconciliation['reconciliation']['cash_difference'] ?? 0),
    'server_recomputed' => $has_server_reconciliation
];
$json_details = json_encode($details_array, JSON_UNESCAPED_UNICODE);

// جلب اسم المستخدم من نفس جدول تسجيل الدخول
$user_query = "SELECT uname FROM users WHERE id = ? LIMIT 1";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bind_param('i', $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_row = $user_result ? $user_result->fetch_assoc() : null;
$user_stmt->close();
$username = $user_row['uname'] ?? ($_SESSION['login'] ?? 'Unknown');

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
                 (?, ?, ?, ?,
                  ?, ?, ?, ?, ?,
                  ?, ?, 0,
                  ?, ?, ?, 1, ?)";
// ملاحظة: total_discount لم نمرره من النموذج، يمكن إضافته input hidden اذا اردنا

$insert_stmt = $conn->prepare($insert_query);
$insert_stmt->bind_param('ssssddddsddddds', $shift_number, $shift_date, $username, $shift_time, $sys_total_sales, $expenses, $actual_cash, $actual_cash, $notes, $sys_total_cash, $sys_total_visa, $actual_cash, $actual_visa, $total_deficit, $json_details);

try {
    $conn->begin_transaction();
    if (!$insert_stmt->execute()) {
        throw new RuntimeException('SHIFT_CLOSE_INSERT_FAILED');
    }

    if ($drawer_session_id > 0) {
        (new DrawerSessionService())->closeSession($conn, $drawer_session_id, [
            'closed_by' => $user_id,
            'counted_cash' => $actual_cash,
            'notes' => $notes,
        ]);
    }

    $conn->commit();
    // تسجيل الخروج أو رسالة نجاح
    // سنقوم بتوجيه لصفحة طباعة نهائية او العودة
    $_SESSION['success_message'] = "تم إغلاق الشيفت بنجاح. العجز/الزيادة: " . number_format($total_deficit, 2);
    posmain_clear_pos_shift_session(true);
    unset($_SESSION['pos_shift_session_token'], $_SESSION['pos_drawer_session_id']);
    if (function_exists('posmain_session_regenerate')) {
        posmain_session_regenerate();
    }
    
    // الخيار: تسجيل خروج المستخدم فوراً
    // include('do/do_logout.php'); // اذا اردنا
    
    // التوجيه لصفحة الجلسات المغلقة كما كان في السابق
    header('Location: closed_sessions.php');
} catch (Throwable $e) {
    $conn->rollback();
    error_log("Shift Close Error: " . $e->getMessage());
    $_SESSION['error_message'] = 'حدث خطأ أثناء إغلاق الشيفت';
    header('Location: z_report.php');
}
$insert_stmt->close();
?>
