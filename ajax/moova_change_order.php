<?php

ob_start();
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
ob_clean();

require_once('../classes/MoovaPosIntegration.php');
require_once('../classes/Moova/MoovaLocalIngestService.php');
require_once('../classes/Pos/Service/PosOrderMutationService.php');

header('Content-Type: application/json; charset=utf-8');

class MoovaOrderChangeHttpException extends Exception
{
    private $statusCode;

    public function __construct($message, $statusCode = 400)
    {
        parent::__construct($message);
        $this->statusCode = (int) $statusCode;
    }

    public function statusCode()
    {
        return $this->statusCode;
    }
}

function moova_change_json_response($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function moova_change_header_value($name)
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$key] ?? ''));
}

function moova_change_error_code($message)
{
    $message = (string) $message;
    if (strpos($message, ':') !== false) {
        $message = substr($message, 0, strpos($message, ':'));
    }
    return preg_replace('/[^A-Z0-9_]/', '_', strtoupper($message ?: 'MOOVA_ORDER_CHANGE_FAILED'));
}

function moova_change_error_status($message)
{
    $code = moova_change_error_code($message);
    $map = [
        'INVALID_PAYLOAD' => 400,
        'INVALID_ACTION' => 400,
        'IDEMPOTENCY_REQUIRED' => 400,
        'MOOVA_ORDER_REQUIRED' => 400,
        'DEVICE_TOKEN_REQUIRED' => 401,
        'UNAUTHORIZED' => 401,
        'INTEGRATION_NOT_MAPPED' => 403,
        'TENANT_SCOPE_MISMATCH' => 403,
        'IDEMPOTENCY_PAYLOAD_CONFLICT' => 409,
    ];

    return $map[$code] ?? 500;
}

function moova_change_is_cashier_confirmed(array $payload)
{
    $reviewed = $payload['cashierReviewed'] ?? false;
    $reviewed = $reviewed === true
        || $reviewed === 1
        || $reviewed === '1'
        || strtolower(trim((string) $reviewed)) === 'true';
    $cashierAction = strtolower(trim((string) ($payload['cashierAction'] ?? '')));

    return $reviewed && $cashierAction === 'confirm';
}

function moova_change_encode_request_json(array $payload)
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{}';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    moova_change_json_response(405, ['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed']);
}

if (!isset($_SESSION['userid'])) {
    moova_change_json_response(401, ['success' => false, 'code' => 'UNAUTHORIZED', 'message' => 'Please login to POS first']);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    moova_change_json_response(400, ['success' => false, 'code' => 'INVALID_PAYLOAD', 'message' => 'Invalid JSON payload']);
}

$deviceToken = moova_change_header_value('X-Moova-Device-Token');
if ($deviceToken === '') {
    moova_change_json_response(401, ['success' => false, 'code' => 'DEVICE_TOKEN_REQUIRED', 'message' => 'Missing Moova device token']);
}

$idempotencyKey = trim((string) ($payload['idempotencyKey'] ?? moova_change_header_value('Idempotency-Key')));
if ($idempotencyKey === '') {
    moova_change_json_response(400, ['success' => false, 'code' => 'IDEMPOTENCY_REQUIRED', 'message' => 'Missing idempotency key']);
}

$action = strtolower(trim((string) ($payload['action'] ?? '')));
if ($action !== 'edit' && $action !== 'cancel') {
    moova_change_json_response(400, ['success' => false, 'code' => 'INVALID_ACTION', 'message' => 'Invalid order change action']);
}
$payload['action'] = $action;

$moovaOrderId = trim((string) ($payload['moovaOrderId'] ?? $payload['orderId'] ?? ''));
if ($moovaOrderId === '') {
    moova_change_json_response(400, ['success' => false, 'code' => 'MOOVA_ORDER_REQUIRED', 'message' => 'Missing Moova order id']);
}
$payload['moovaOrderId'] = $moovaOrderId;

if (!moova_change_is_cashier_confirmed($payload)) {
    moova_change_json_response(409, [
        'success' => false,
        'code' => 'CASHIER_REVIEW_REQUIRED',
        'message' => 'Order change must be confirmed by the cashier.',
        'retryable' => true,
    ]);
}

try {
    MoovaPosIntegration::ensureSchema($conn);

    $incomingBranchId = trim((string) ($payload['branchId'] ?? ''));
    $shopLink = $incomingBranchId === ''
        ? MoovaPosIntegration::findActiveLinkByToken($conn, $deviceToken)
        : MoovaPosIntegration::findActiveLinkByTokenAndBranch($conn, $deviceToken, $incomingBranchId);
    if (!$shopLink) {
        throw new MoovaOrderChangeHttpException('INTEGRATION_NOT_MAPPED', 403);
    }

    if (empty($payload['branchId']) && !empty($shopLink['moova_branch_id'])) {
        $payload['branchId'] = (string) $shopLink['moova_branch_id'];
    }

    if (!MoovaPosIntegration::userCanUseLink($conn, (int) $_SESSION['userid'], $shopLink)) {
        throw new MoovaOrderChangeHttpException('TENANT_SCOPE_MISMATCH', 403);
    }

    if (empty($payload['idempotencyKey']) && empty($payload['idempotency_key'])) {
        $payload['idempotencyKey'] = $idempotencyKey;
    }

    $ingest = new MoovaLocalIngestService();
    $eventType = $action === 'cancel' ? 'cancel_order' : 'edit_order';
    $idempotencyKey = $ingest->normalizeIdempotencyKey($payload, $eventType);
    $requestHash = $ingest->normalizePayloadHash($payload);
    $requestJson = moova_change_encode_request_json($payload);
    $posPayload = $ingest->normalizeChangeForPos($payload);

    $tenant = (int) $shopLink['pos_tenant'];
    $branch = (int) $shopLink['pos_branch'];
    $conn->begin_transaction();
    $result = (new PosOrderMutationService())->changeMoovaOrder($conn, [
        'link' => $shopLink,
        'payload' => $posPayload,
    ], [
        'idempotency_key' => $idempotencyKey,
        'request_hash' => $requestHash,
        'request_json' => $requestJson,
        'moova_order_id' => $moovaOrderId,
        'action' => $action,
        'user_id' => (int) $_SESSION['userid'],
        'response_mode' => 'direct',
    ]);
    $conn->commit();
    moova_change_json_response(200, $result['response']);
} catch (MoovaOrderChangeHttpException $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }
    moova_change_json_response($e->statusCode(), [
        'success' => false,
        'code' => moova_change_error_code($e->getMessage()),
        'message' => $e->getMessage(),
    ]);
} catch (Exception $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    $statusCode = moova_change_error_status($e->getMessage());
    moova_change_json_response($statusCode, [
        'success' => false,
        'code' => moova_change_error_code($e->getMessage()),
        'message' => $e->getMessage(),
    ]);
}
