<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftSessionService.php';

posmain_send_no_store_headers();

if (PHP_SAPI !== 'cli') {
    require_pos_authenticated();
    require_permission('pos.shift.close', $conn);
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

$user_id = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) $_SESSION['userid'];

try {
    $result = (new ShiftSessionService())->closeSimpleShift($conn, $user_id, [
        'expenses' => $_POST['expenses'] ?? 0,
        'exp_notes' => $_POST['exp_notes'] ?? '',
        'cash' => $_POST['cash'] ?? 0,
        'fund_after' => $_POST['fund_after'] ?? 0,
        'notes' => $_POST['notes'] ?? '',
    ]);

    if ((int) $result['total_orders'] > 0) {
        $_SESSION['success_message'] = 'تم إغلاق الشيفت بنجاح - إجمالي مبيعاتك: '
            . number_format((float) $result['total_sales'], 2)
            . ' ج.م ('
            . (int) $result['total_orders']
            . ' طلب)';
    } else {
        $_SESSION['success_message'] = 'تم إغلاق الشيفت - لا توجد مبيعات في هذه الجلسة';
    }
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'SHIFT_ALREADY_CLOSED') {
        $_SESSION['error_message'] = 'تم إغلاق هذا الشيفت مسبقاً. أعد فتح نقطة البيع لبدء شيفت جديد.';
    } else {
        error_log('Shift close rejected: ' . $exception->getMessage());
        $_SESSION['error_message'] = 'حدث خطأ أثناء إغلاق الشيفت';
    }
} catch (Throwable $exception) {
    error_log('Shift close exception: ' . $exception->getMessage());
    $_SESSION['error_message'] = 'حدث خطأ أثناء إغلاق الشيفت';
}

header('Location: closed_sessions.php');
exit;
