<?php

ob_start();
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
ob_clean();

require_once('../classes/MoovaPosIntegration.php');
require_once('../classes/Moova/MoovaPosMenuReconcileService.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Security/SecurityAuditLogger.php');

header('Content-Type: application/json; charset=utf-8');

function moova_integration_response($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function moova_integration_request()
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function moova_integration_audit($eventType, array $options = [])
{
    global $conn;

    try {
        (new SecurityAuditLogger())->record($conn, $eventType, $options);
    } catch (Throwable $ignored) {
        error_log('[moova_integration_audit] ' . $eventType . ' audit failed: ' . $ignored->getMessage());
    }
}

function moova_integration_header($name)
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? trim((string) $_SERVER[$key]) : '';
}

function moova_integration_origin_from_widget_url($widgetUrl)
{
    $parts = parse_url((string) $widgetUrl);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    return $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
}

function moova_integration_current_origin()
{
    $forwardedProto = strtolower(trim(strtok(moova_integration_header('X-Forwarded-Proto'), ',') ?: ''));
    $scheme = in_array($forwardedProto, ['http', 'https'], true)
        ? $forwardedProto
        : strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    }

    $forwardedHost = trim(strtok(moova_integration_header('X-Forwarded-Host'), ',') ?: '');
    $host = $forwardedHost !== '' ? $forwardedHost : trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(:\d{1,5})?$/', $host)) {
        return '';
    }

    return $scheme . '://' . $host;
}

function moova_integration_trigger_menu_sync_after_save(array $saved, $deviceToken)
{
    $posOrigin = moova_integration_current_origin();

    return (new MoovaPosMenuReconcileService())->reconcileAfterIntegrationSave($saved, (string) $deviceToken, $posOrigin);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    moova_integration_response(405, ['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed']);
}

$userId = current_user_id();
if ($userId < 1) {
    moova_integration_response(401, ['success' => false, 'code' => 'UNAUTHORIZED', 'message' => 'Please login first']);
}

$payload = moova_integration_request();
$csrf = (string) ($payload['csrf'] ?? csrf_request_token());
if (!verify_csrf_token($csrf, 'moova_integration')) {
    moova_integration_audit('permission_denied', [
        'user_id' => $userId,
        'target_type' => 'moova_integration',
        'metadata' => ['reason' => 'csrf_invalid', 'action' => 'save'],
    ]);
    moova_integration_response(403, ['success' => false, 'code' => 'INVALID_CSRF', 'message' => 'Invalid request token']);
}

try {
    MoovaPosIntegration::ensureSchema($conn);

    if (!MoovaPosIntegration::userCanManageIntegration($conn, $userId) && !auth_guard_has_permission('moova.manage', $conn)) {
        moova_integration_audit('permission_denied', [
            'user_id' => $userId,
            'target_type' => 'moova_integration',
            'metadata' => ['permission' => 'moova.manage', 'action' => 'save'],
        ]);
        moova_integration_response(403, ['success' => false, 'code' => 'FORBIDDEN', 'message' => 'You do not have permission to manage this integration']);
    }

    $scope = MoovaPosIntegration::getCurrentUserScope($conn, $userId);
    if (!$scope) {
        moova_integration_response(401, ['success' => false, 'code' => 'INVALID_USER_SCOPE', 'message' => 'Invalid POS user scope']);
    }

    $existing = MoovaPosIntegration::findActiveLinkForScope($conn, $scope);
    $shopId = trim((string) ($payload['moovaShopId'] ?? ''));
    $branchId = trim((string) ($payload['moovaBranchId'] ?? ''));
    $deviceToken = trim((string) ($payload['deviceToken'] ?? ''));
    $widgetUrl = trim((string) ($payload['widgetUrl'] ?? ''));
    $locale = trim((string) ($payload['locale'] ?? 'ar'));

    if ($deviceToken === '' && $existing && !empty($existing['moova_device_token'])) {
        $deviceToken = (string) $existing['moova_device_token'];
    }

    if ($branchId === '' && $existing && !empty($existing['moova_branch_id'])) {
        $branchId = (string) $existing['moova_branch_id'];
    }

    if ($branchId !== '' && (strlen($branchId) > 128 || !preg_match('/^[A-Za-z0-9._:\\-]+$/', $branchId))) {
        moova_integration_response(422, ['success' => false, 'code' => 'INVALID_BRANCH', 'message' => 'Invalid Moova branch id']);
    }

    if ($shopId !== '' && (strlen($shopId) > 128 || !preg_match('/^[A-Za-z0-9._:\\-]+$/', $shopId))) {
        moova_integration_response(422, ['success' => false, 'code' => 'INVALID_SHOP', 'message' => 'Invalid Moova shop id']);
    }

    if (strlen($deviceToken) < 8 || strlen($deviceToken) > 191) {
        moova_integration_response(422, ['success' => false, 'code' => 'INVALID_DEVICE_TOKEN', 'message' => 'Invalid Moova device token']);
    }

    if (strlen($widgetUrl) > 255) {
        moova_integration_response(422, ['success' => false, 'code' => 'INVALID_WIDGET_URL', 'message' => 'Widget URL is too long']);
    }
    $parts = parse_url($widgetUrl);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!$parts || !in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
        moova_integration_response(422, ['success' => false, 'code' => 'INVALID_WIDGET_URL', 'message' => 'Widget URL must be a valid http or https URL']);
    }

    if (!in_array($locale, ['ar', 'en'], true)) {
        $locale = 'ar';
    }

    $remoteLink = MoovaPosIntegration::findActiveLinkByToken($conn, $deviceToken);
    if (
        $remoteLink
        && ((int) $remoteLink['pos_tenant'] !== (int) $scope['tenant']
            || (int) $remoteLink['pos_branch'] !== (int) $scope['branch'])
    ) {
        moova_integration_response(409, [
            'success' => false,
            'code' => 'MAPPING_ALREADY_ACTIVE',
            'message' => 'This Moova device token is already linked to another POS branch',
        ]);
    }

    $conn->begin_transaction();
    $saved = MoovaPosIntegration::saveActiveLinkForScope($conn, $scope, [
        'moova_shop_id' => $shopId,
        'moova_branch_id' => $branchId,
        'moova_device_token' => $deviceToken,
        'widget_url' => $widgetUrl,
        'locale' => $locale,
    ]);
    $conn->commit();

    moova_integration_audit('moova_integration_saved', [
        'user_id' => $userId,
        'tenant' => (int) ($scope['tenant'] ?? 0),
        'branch' => (int) ($scope['branch'] ?? 0),
        'target_type' => 'moova_pos_shop_link',
        'target_id' => (int) ($saved['id'] ?? 0),
        'metadata' => [
            'moova_branch_id' => $saved['moova_branch_id'] ?? '',
            'device_token_last4' => $saved['moova_device_token_last4'] ?? '',
            'widget_url' => $saved['widget_url'] ?? '',
        ],
    ]);

    $autoSync = moova_integration_trigger_menu_sync_after_save($saved, $deviceToken);

    moova_integration_response(200, [
        'success' => true,
        'message' => 'Moova integration saved',
        'scope' => $scope,
        'link' => [
            'id' => $saved['id'],
            'moovaShopId' => $saved['moova_shop_id'],
            'moovaBranchId' => $saved['moova_branch_id'],
            'deviceToken' => $deviceToken,
            'deviceTokenMasked' => MoovaPosIntegration::maskDeviceToken($deviceToken),
            'widgetUrl' => $saved['widget_url'],
            'locale' => $saved['locale'],
            'status' => $saved['status'],
        ],
        'autoSync' => $autoSync,
    ]);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    moova_integration_response(500, posmain_exception_payload(
        $e,
        'تعذر حفظ إعدادات موفا الآن، يرجى المحاولة مرة أخرى أو التواصل مع الدعم',
        'MOOVA_INTEGRATION_SAVE_FAILED',
        false,
        'moova_integration_save'
    ));
}
