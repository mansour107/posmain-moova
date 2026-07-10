<?php

require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/pos_cache_control.php';
require_once __DIR__ . '/includes/pos_shift_guard.php';

posmain_send_no_store_headers();
header('Content-Type: application/json; charset=utf-8');

$conn = null;
if (file_exists(__DIR__ . '/includes/connect.php')) {
    include __DIR__ . '/includes/connect.php';
}

require_login();
if ($conn instanceof mysqli) {
    require_permission('pos.open', $conn);
}

require_once __DIR__ . '/classes/Pos/Service/ShiftSessionService.php';

$status = (new ShiftSessionService())->sessionStatus($conn instanceof mysqli ? $conn : null);

if (is_array($status)) {
    $status['acting_user_id'] = pos_acting_user_id();
    $status['terminal_user_id'] = pos_terminal_user_id();
    $status['acting_user_name'] = (string) ($_SESSION['pos_acting_user_name'] ?? $_SESSION['pos_user_name'] ?? '');
    $identity = (new ShiftSessionService())->resolvePosIdentity($conn instanceof mysqli ? $conn : null);
    $status['identity'] = $identity;
    if (($status['acting_user_name'] ?? '') === '' && !empty($identity['cashier_name'])) {
        $status['acting_user_name'] = (string) $identity['cashier_name'];
    }
}

if (auth_guard_is_logged_in() && $conn instanceof mysqli) {
    $posCapabilities = [
        'pos.shift.close' => auth_guard_has_permission('pos.shift.close', $conn),
        'pos.cashdrawer.count' => auth_guard_has_permission('pos.cashdrawer.count', $conn),
        'pos.cancel.unpaid' => auth_guard_has_permission('pos.cancel.unpaid', $conn),
        'pos.refund' => auth_guard_has_permission('pos.refund', $conn),
        'pos.recipe_stock_override' => auth_guard_has_permission('pos.recipe_stock_override', $conn),
    ];
    if (is_array($status)) {
        $status['capabilities'] = $posCapabilities;
    }
}

echo json_encode($status, JSON_UNESCAPED_UNICODE);
