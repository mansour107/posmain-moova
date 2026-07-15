<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_force_close_drawer.php');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/shift_handover_idempotency.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftSessionService.php';
require_once __DIR__ . '/../classes/Pos/Service/DrawerSessionCloseSummaryService.php';
require_once __DIR__ . '/../includes/cash_shift_navigation.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../cash_flow_report.php?tab=shifts');
    exit;
}

require_csrf('shift_close');

$sessionId = (int) ($_POST['drawer_session_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));
$countedCash = trim((string) ($_POST['counted_cash'] ?? '0'));
$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) $_SESSION['userid'];
$idempotencyKey = trim((string) ($_POST['idempotency_key'] ?? ''));
if ($idempotencyKey === '') {
    $idempotencyKey = 'force-close:' . $sessionId . ':' . bin2hex(random_bytes(8));
    $_POST['idempotency_key'] = $idempotencyKey;
}

try {
    if ($sessionId < 1) {
        throw new RuntimeException('DRAWER_SESSION_REQUIRED');
    }
    if ($reason === '') {
        throw new RuntimeException('FORCE_CLOSE_REASON_REQUIRED');
    }

    (new DrawerSessionCloseSummaryService())->ensureSchema($conn);

    pos_shift_handover_idempotent(
        $conn,
        'pos.shift.force_close',
        $_POST,
        $_SERVER,
        $userId,
        static function (array $txContext = []) use ($conn, $userId, $sessionId, $reason, $countedCash): array {
            $closed = (new ShiftSessionService())->forceCloseDrawerForUser($conn, $userId, $sessionId, [
                'reason' => $reason,
                'counted_cash' => $countedCash !== '' ? $countedCash : '0',
                'manager_approval_id' => $_POST['manager_approval_id'] ?? null,
                'in_transaction' => !empty($txContext['in_transaction']),
            ]);

            return [
                'success' => true,
                'data' => [
                    'closed_session_id' => (int) ($closed['id'] ?? $sessionId),
                    'status' => (string) ($closed['status'] ?? 'forced_closed'),
                ],
            ];
        }
    );
    $_SESSION['success_message'] = 'تم إغلاق جلسة الدرج بالقوة بنجاح';
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'MANAGER_APPROVAL_REQUIRED') {
        $_SESSION['error_message'] = 'يتطلب اعتماد مدير لإغلاق شيفت مستخدم آخر';
    } elseif ($exception->getMessage() === 'FORCE_CLOSE_REASON_REQUIRED') {
        $_SESSION['error_message'] = 'يجب إدخال سبب الإغلاق القسري';
    } elseif ($exception->getMessage() === 'DRAWER_SESSION_SCOPE_MISMATCH') {
        $_SESSION['error_message'] = 'جلسة الدرج خارج نطاق الفرع الحالي';
    } else {
        $_SESSION['error_message'] = 'تعذر إغلاق جلسة الدرج: ' . $exception->getMessage();
    }
}

$returnTo = posmain_cash_shift_safe_return_to(
    $_POST['return_to'] ?? null,
    'cash_flow_report.php?tab=shifts'
);
header('Location: ../' . $returnTo);
exit;
