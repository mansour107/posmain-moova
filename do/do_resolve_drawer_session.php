<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_resolve_drawer_session.php');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/shift_handover_idempotency.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftCountService.php';
require_once __DIR__ . '/../includes/cash_shift_navigation.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../cash_flow_report.php?tab=shifts');
    exit;
}

require_csrf('shift_resolve');

$sessionId = (int) ($_POST['drawer_session_id'] ?? 0);
$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);

try {
    if ($sessionId < 1) {
        throw new RuntimeException('DRAWER_SESSION_REQUIRED');
    }

    if (empty($_POST['idempotency_key'])) {
        $_POST['idempotency_key'] = 'pos.shift.resolve:' . $sessionId . ':' . bin2hex(random_bytes(8));
    }

    $response = pos_shift_handover_idempotent(
        $conn,
        'pos.shift.resolve_variance',
        $_POST,
        $_SERVER,
        $userId,
        static function (array $txContext = []) use ($conn, $userId, $sessionId, $appConfig): array {
            $service = new ShiftCountService();
            $txContext['financial_certified_mode'] = !empty($appConfig['financial']['certified_mode']);
            $data = $service->resolveSession($conn, $userId, $sessionId, [
                'resolution_reason_code' => $_POST['resolution_reason_code'] ?? '',
                'resolution_notes' => $_POST['resolution_notes'] ?? '',
            ], $txContext);

            return ['success' => true, 'data' => $data];
        }
    );

    if (($response['code'] ?? '') === 'IDEMPOTENCY_CONFLICT') {
        $_SESSION['error_message'] = 'تم إرسال طلب الحل مسبقاً ببيانات مختلفة';
    } elseif (!($response['success'] ?? false)) {
        $_SESSION['error_message'] = 'تعذر حل الحالة';
    } else {
        $_SESSION['success_message'] = 'تم حل حالة الفرق بنجاح';
    }
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'PERMISSION_DENIED') {
        $_SESSION['error_message'] = 'يتطلب صلاحية مدير لحل حالات الفرق';
    } elseif ($exception->getMessage() === 'RESOLUTION_REASON_CODE_REQUIRED') {
        $_SESSION['error_message'] = 'اختر سبب الفرق من القائمة قبل التأكيد';
    } elseif ($exception->getMessage() === 'RESOLUTION_NOTES_REQUIRED') {
        $_SESSION['error_message'] = 'اخترت "سبب آخر" — اكتب التفاصيل قبل التأكيد';
    } elseif (in_array($exception->getMessage(), ['LEDGER_SUBSYSTEM_UNAVAILABLE', 'FUND_ACCOUNT_REQUIRED', 'VARIANCE_JOURNAL_REQUIRED'], true)) {
        $_SESSION['error_message'] = 'تعذر تسجيل قيد الفرق في الحسابات — لم يتم اعتماد المراجعة';
    } else {
        $_SESSION['error_message'] = 'تعذر حل الحالة: ' . $exception->getMessage();
    }
}

$returnTo = posmain_cash_shift_safe_return_to(
    $_POST['return_to'] ?? null,
    'cash_flow_report.php?tab=shifts&status=needs_review&scope=backlog'
);
header('Location: ../' . $returnTo);
exit;
