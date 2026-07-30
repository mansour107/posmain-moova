<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
require_once __DIR__ . '/includes/shift_handover_idempotency.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/classes/Pos/Service/ShiftCloseService.php';
require_once __DIR__ . '/classes/Pos/Service/DrawerSessionCloseSummaryService.php';

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
    // Additive mixed-version safety: provision the close snapshot before the
    // idempotency transaction starts, never through DDL inside that transaction.
    (new DrawerSessionCloseSummaryService())->ensureSchema($conn);
    $closePayload = [
        'expenses' => $_POST['expenses'] ?? 0,
        'exp_notes' => $_POST['exp_notes'] ?? '',
        'cash' => $_POST['cash'] ?? 0,
        'fund_after' => $_POST['fund_after'] ?? 0,
        'counted_cash' => $_POST['counted_cash'] ?? ($_POST['fund_after'] ?? 0),
        'notes' => $_POST['notes'] ?? '',
        'close_token' => $_POST['close_token'] ?? '',
        'matched' => !empty($_POST['matched']),
        'drawer_session_id' => (int) ($_POST['drawer_session_id'] ?? 0),
    ];

    $response = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.close',
        $_POST,
        $_SERVER,
        $user_id,
        static function (array $txContext = []) use ($conn, $user_id, $closePayload): array {
            $result = (new ShiftSessionService())->closeSimpleShift($conn, $user_id, $closePayload, $txContext);

            return [
                'success' => true,
                'result' => $result,
            ];
        }
    );

    if (($response['code'] ?? '') === 'IDEMPOTENCY_CONFLICT') {
        $_SESSION['error_message'] = 'تم إرسال طلب الإغلاق مسبقاً ببيانات مختلفة';
        header('Location: pos_barcode.php');
        exit;
    }

    $result = $response['result'] ?? [];
    if (($result['status'] ?? '') === 'counted_pending_review') {
        $_SESSION['error_message'] = 'تم تسجيل عد الإغلاق، لكن الشيفت ما زال مفتوحًا حتى يعتمد مستخدم مخول فرق الدرج.';
        header('Location: pos_barcode.php');
        exit;
    }

    $variance = round((float) ($result['variance'] ?? 0), 3);
    $matched = !empty($result['matched']) || abs($variance) <= 0.010;
    $totalOrders = (int) ($result['total_orders'] ?? 0);
    $totalSales = (float) ($result['total_sales'] ?? 0);

    if ($matched) {
        $title = 'تم إغلاق الشيفت بنجاح';
        $detail = $totalOrders > 0
            ? ('إجمالي مبيعاتك: ' . number_format($totalSales, 2) . ' ج.م (' . $totalOrders . ' طلب)')
            : 'لا توجد مبيعات في هذه الجلسة';
        $tone = 'success';
    } elseif ($variance > 0) {
        $title = 'تم إغلاق الشيفت — زيادة في الدرج';
        $detail = 'الفرق: +' . number_format(abs($variance), 2) . ' ج.م (سيتم مراجعته من الإدارة)';
        $tone = 'over';
    } else {
        $title = 'تم إغلاق الشيفت — عجز في الدرج';
        $detail = 'الفرق: −' . number_format(abs($variance), 2) . ' ج.م (سيتم مراجعته من الإدارة)';
        $tone = 'under';
    }

    $_SESSION['pos_shift_close_result'] = [
        'tone' => $tone,
        'title' => $title,
        'detail' => $detail,
        'variance' => $variance,
        'matched' => $matched,
        'total_orders' => $totalOrders,
        'total_sales' => $totalSales,
        'counted_cash' => (float) ($result['counted_cash'] ?? 0),
    ];
    // Keep a short flash for any non-POS surfaces that still read success_message.
    $_SESSION['success_message'] = $title . ' — ' . $detail;
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'SHIFT_ALREADY_CLOSED') {
        $_SESSION['error_message'] = 'تم إغلاق هذا الشيفت مسبقاً. أعد فتح نقطة البيع لبدء شيفت جديد.';
    } elseif ($exception->getMessage() === 'SHIFT_FINANCIAL_RECONCILIATION_MISMATCH') {
        $_SESSION['error_message'] = 'تعذر إغلاق الشيفت: يوجد اختلاف بين المبيعات وطرق الدفع أو حركة الدرج. اطلب من المدير مراجعة التسوية قبل الإغلاق.';
    } else {
        error_log('Shift close rejected: ' . $exception->getMessage());
        $_SESSION['error_message'] = 'حدث خطأ أثناء إغلاق الشيفت';
    }
} catch (Throwable $exception) {
    error_log('Shift close exception: ' . $exception->getMessage());
    $_SESSION['error_message'] = 'حدث خطأ أثناء إغلاق الشيفت';
}

// Stay on the POS terminal: lock out selling and show the close result on unlock screen.
// Do not send cashiers to the admin closed_sessions page.
header('Location: pos_barcode.php?logout=1');
exit;
