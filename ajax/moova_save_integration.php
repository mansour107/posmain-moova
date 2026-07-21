<?php

ob_start();
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
ob_clean();

require_once('../classes/MoovaPosIntegration.php');
require_once('../classes/Moova/MoovaPosMenuReconcileService.php');
require_once('../classes/Moova/MoovaPosPairingService.php');
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
    $envOrigin = '';
    if (function_exists('posmain_first_env')) {
        $envOrigin = trim((string) posmain_first_env([
            'POSMAIN_MOOVA_POS_PUBLIC_ORIGIN',
            'POSMAIN_PUBLIC_ORIGIN',
            'POS_PUBLIC_URL',
        ], '', true));
    }
    if ($envOrigin !== '') {
        return rtrim($envOrigin, '/');
    }

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
    global $conn;

    $posOrigin = moova_integration_current_origin();

    return (new MoovaPosMenuReconcileService())->reconcileAfterIntegrationSave(
        $conn,
        $saved,
        (string) $deviceToken,
        $posOrigin
    );
}

$pairingClaimed = false;
$pairingShouldReleaseOnFailure = false;
$claimedDeviceToken = '';
$claimedInstanceUuid = '';

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

    $existing = MoovaPosIntegration::findLatestLinkForScope($conn, $scope);
    $deviceToken = trim((string) ($payload['deviceToken'] ?? ''));
    $locale = trim((string) ($payload['locale'] ?? 'ar'));

    if ($deviceToken === '' && $existing) {
        $deviceToken = MoovaPosIntegration::deviceTokenForLink($existing);
    }

    if (strlen($deviceToken) < 8 || strlen($deviceToken) > 191) {
        moova_integration_response(422, ['success' => false, 'code' => 'INVALID_DEVICE_TOKEN', 'message' => 'Invalid Moova device token']);
    }

    if (!in_array($locale, ['ar', 'en'], true)) {
        $locale = 'ar';
    }

    if (!(new SyncRuntimeCrypto())->available()) {
        throw new RuntimeException('TOKEN_ENCRYPTION_UNAVAILABLE');
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

    $instanceUuid = trim((string) ($existing['pos_instance_uuid'] ?? ''));
    if (!MoovaPosIntegration::isUuid($instanceUuid)) {
        $instanceUuid = MoovaPosIntegration::generateUuid();
    }
    $existingMatchesClaim = $existing
        && (string) ($existing['status'] ?? '') === 'active'
        && hash_equals(
            (string) ($existing['moova_device_token_hash'] ?? ''),
            MoovaPosIntegration::hashDeviceToken($deviceToken)
        )
        && strtolower(trim((string) ($existing['pos_instance_uuid'] ?? ''))) === strtolower($instanceUuid);
    $pairing = (new MoovaPosPairingService())->claim(
        $deviceToken,
        $scope,
        $instanceUuid,
        moova_integration_current_origin(),
        $locale
    );
    $pairingClaimed = true;
    $pairingShouldReleaseOnFailure = !$existingMatchesClaim;
    $claimedDeviceToken = $deviceToken;
    $claimedInstanceUuid = $instanceUuid;

    $conn->begin_transaction();
    $saved = MoovaPosIntegration::saveActiveLinkForScope($conn, $scope, [
        'moova_shop_id' => $pairing['shopId'],
        'moova_branch_id' => $pairing['branchId'],
        'moova_device_token' => $deviceToken,
        'widget_url' => $pairing['widgetUrl'],
        'locale' => $locale,
        'moova_connection_id' => $pairing['connectionId'],
        'moova_branch_link_id' => $pairing['branchLinkId'],
        'pairing_id' => $pairing['pairingId'],
        'pos_instance_uuid' => $instanceUuid,
        'moova_shop_name' => $pairing['shopName'] ?? '',
        'moova_branch_name' => $pairing['branchName'] ?? '',
    ]);
    MoovaPosIntegration::enqueueCatalogSync($conn, $saved);
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
    $syncFingerprint = '';
    try {
        if (!defined('MOOVA_MENU_SYNC_LIBRARY_ONLY')) {
            define('MOOVA_MENU_SYNC_LIBRARY_ONLY', true);
        }
        require_once __DIR__ . '/moova_menu_sync_payload.php';
        $syncFingerprint = (string) (moova_menu_sync_fingerprint($conn)['fingerprint'] ?? '');
        MoovaPosIntegration::recordCatalogSyncResult($conn, (int) $saved['id'], $syncFingerprint, $autoSync);
    } catch (Throwable $syncStateError) {
        error_log('[Moova POS] failed to record initial catalog sync state: ' . $syncStateError->getMessage());
    }

    moova_integration_response(200, [
        'success' => true,
        'message' => 'Moova integration saved',
        'scope' => $scope,
        'link' => [
            'id' => $saved['id'],
            'moovaShopId' => $saved['moova_shop_id'],
            'moovaBranchId' => $saved['moova_branch_id'],
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

    if ($pairingClaimed && $pairingShouldReleaseOnFailure && $claimedDeviceToken !== '' && $claimedInstanceUuid !== '') {
        try {
            (new MoovaPosPairingService())->release($claimedDeviceToken, $claimedInstanceUuid);
        } catch (Throwable $releaseError) {
            error_log('[Moova POS] pairing compensation failed: ' . $releaseError->getMessage());
        }
    }

    $errorCode = strtok($e->getMessage(), ':') ?: 'MOOVA_INTEGRATION_SAVE_FAILED';
    $status = in_array($errorCode, ['PAIRING_ALREADY_CLAIMED', 'MAPPING_ALREADY_ACTIVE', 'PAIRING_BRANCH_REQUIRED'], true) ? 409 : 500;
    if (in_array($errorCode, ['INVALID_DEVICE_TOKEN', 'invalid_device_token'], true)) {
        $status = 401;
    }
    $errorPayload = posmain_exception_payload(
        $e,
        'تعذر حفظ إعدادات موفا الآن، يرجى المحاولة مرة أخرى أو التواصل مع الدعم',
        $errorCode,
        false,
        'moova_integration_save'
    );
    $errorPayload['code'] = $errorCode;
    moova_integration_response($status, $errorPayload);
}
