<?php

ob_start();
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
ob_clean();

require_once('../classes/MoovaPosIntegration.php');
require_once('../classes/Moova/MoovaPosPairingService.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Security/SecurityAuditLogger.php');

header('Content-Type: application/json; charset=utf-8');

function moova_disconnect_response($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function moova_disconnect_audit($eventType, array $options = [])
{
    global $conn;

    try {
        (new SecurityAuditLogger())->record($conn, $eventType, $options);
    } catch (Throwable $ignored) {
        error_log('[moova_disconnect_audit] ' . $eventType . ' audit failed: ' . $ignored->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    moova_disconnect_response(405, ['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed']);
}

$userId = current_user_id();
if ($userId < 1) {
    moova_disconnect_response(401, ['success' => false, 'code' => 'UNAUTHORIZED', 'message' => 'Please login first']);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = [];
}

$csrf = (string) ($payload['csrf'] ?? csrf_request_token());
if (!verify_csrf_token($csrf, 'moova_integration')) {
    moova_disconnect_audit('permission_denied', [
        'user_id' => $userId,
        'target_type' => 'moova_integration',
        'metadata' => ['reason' => 'csrf_invalid', 'action' => 'disconnect'],
    ]);
    moova_disconnect_response(403, ['success' => false, 'code' => 'INVALID_CSRF', 'message' => 'Invalid request token']);
}

try {
    MoovaPosIntegration::ensureSchema($conn);

    if (!MoovaPosIntegration::userCanManageIntegration($conn, $userId) && !auth_guard_has_permission('moova.manage', $conn)) {
        moova_disconnect_audit('permission_denied', [
            'user_id' => $userId,
            'target_type' => 'moova_integration',
            'metadata' => ['permission' => 'moova.manage', 'action' => 'disconnect'],
        ]);
        moova_disconnect_response(403, ['success' => false, 'code' => 'FORBIDDEN', 'message' => 'You do not have permission to manage this integration']);
    }

    $scope = MoovaPosIntegration::getCurrentUserScope($conn, $userId);
    if (!$scope) {
        moova_disconnect_response(401, ['success' => false, 'code' => 'INVALID_USER_SCOPE', 'message' => 'Invalid POS user scope']);
    }

    $link = MoovaPosIntegration::findActiveLinkForScope($conn, $scope);
    if ($link) {
        (new MoovaPosPairingService())->release(
            MoovaPosIntegration::deviceTokenForLink($link),
            (string) ($link['pos_instance_uuid'] ?? '')
        );
    }

    $conn->begin_transaction();
    $affected = MoovaPosIntegration::disconnectScope($conn, $scope);
    $conn->commit();

    moova_disconnect_audit('moova_integration_disconnected', [
        'user_id' => $userId,
        'tenant' => (int) ($scope['tenant'] ?? 0),
        'branch' => (int) ($scope['branch'] ?? 0),
        'target_type' => 'moova_integration',
        'metadata' => ['removed' => $affected],
    ]);

    moova_disconnect_response(200, [
        'success' => true,
        'message' => 'Moova integration disconnected',
        'removed' => $affected,
        'scope' => $scope,
    ]);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    moova_disconnect_response(500, posmain_exception_payload(
        $e,
        'تعذر فصل ربط موفا الآن، يرجى المحاولة مرة أخرى أو التواصل مع الدعم',
        'MOOVA_INTEGRATION_DISCONNECT_FAILED',
        false,
        'moova_integration_disconnect'
    ));
}
