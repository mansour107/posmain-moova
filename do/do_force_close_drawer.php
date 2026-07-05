<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_force_close_drawer.php');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Pos/Service/ShiftSessionService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../closed_sessions.php');
    exit;
}

require_csrf('shift_close');

$sessionId = (int) ($_POST['drawer_session_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));
$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) $_SESSION['userid'];

try {
    if ($sessionId < 1) {
        throw new RuntimeException('DRAWER_SESSION_REQUIRED');
    }
    (new ShiftSessionService())->forceCloseDrawerForUser($conn, $userId, $sessionId, [
        'reason' => $reason,
        'manager_approval_id' => $_POST['manager_approval_id'] ?? null,
    ]);
    $_SESSION['success_message'] = 'تم إغلاق جلسة الدرج بالقوة بنجاح';
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'MANAGER_APPROVAL_REQUIRED') {
        $_SESSION['error_message'] = 'يتطلب اعتماد مدير لإغلاق شيفت مستخدم آخر';
    } else {
        $_SESSION['error_message'] = 'تعذر إغلاق جلسة الدرج: ' . $exception->getMessage();
    }
}

header('Location: ../closed_sessions.php');
exit;
