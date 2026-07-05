<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();
require_csrf('pos_pin');

$actingId = pos_acting_user_id();
posmain_clear_pos_shift_session(false);
pos_clear_acting_user();

try {
    if ($actingId > 0) {
        (new SecurityAuditLogger())->record($conn, 'pos_terminal_locked', [
            'user_id' => $actingId,
            'metadata' => ['terminal_user_id' => pos_terminal_user_id()],
        ]);
    }
} catch (Throwable $ignored) {
}

echo json_encode(['success' => true, 'code' => 'POS_LOCKED'], JSON_UNESCAPED_UNICODE);
