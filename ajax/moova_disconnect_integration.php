<?php

ob_start();
session_start();
include('../includes/connect.php');
ob_clean();

require_once('../classes/MoovaPosIntegration.php');

header('Content-Type: application/json; charset=utf-8');

function moova_disconnect_response($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    moova_disconnect_response(405, ['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed']);
}

$userId = (int) ($_SESSION['userid'] ?? 0);
if ($userId < 1) {
    moova_disconnect_response(401, ['success' => false, 'code' => 'UNAUTHORIZED', 'message' => 'Please login first']);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = [];
}

$csrf = (string) ($payload['csrf'] ?? '');
if ($csrf === '' || !hash_equals((string) ($_SESSION['moova_integration_csrf'] ?? ''), $csrf)) {
    moova_disconnect_response(403, ['success' => false, 'code' => 'INVALID_CSRF', 'message' => 'Invalid request token']);
}

try {
    MoovaPosIntegration::ensureSchema($conn);

    if (!MoovaPosIntegration::userCanManageIntegration($conn, $userId)) {
        moova_disconnect_response(403, ['success' => false, 'code' => 'FORBIDDEN', 'message' => 'You do not have permission to manage this integration']);
    }

    $scope = MoovaPosIntegration::getCurrentUserScope($conn, $userId);
    if (!$scope) {
        moova_disconnect_response(401, ['success' => false, 'code' => 'INVALID_USER_SCOPE', 'message' => 'Invalid POS user scope']);
    }

    $conn->begin_transaction();
    $affected = MoovaPosIntegration::disconnectScope($conn, $scope);
    $conn->commit();

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

    moova_disconnect_response(500, [
        'success' => false,
        'code' => 'MOOVA_INTEGRATION_DISCONNECT_FAILED',
        'message' => $e->getMessage(),
    ]);
}
