<?php

ob_start();
session_start();
include('../includes/connect.php');
ob_clean();

require_once('../classes/MoovaPosIntegration.php');
require_once('../classes/PosOrderService.php');

header('Content-Type: application/json; charset=utf-8');

class MoovaOrderHttpException extends Exception
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

function moova_json_response($statusCode, array $payload)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function moova_header_value($name)
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string) ($_SERVER[$key] ?? ''));
}

function moova_error_code($message)
{
    $message = (string) $message;
    if (strpos($message, ':') !== false) {
        $message = substr($message, 0, strpos($message, ':'));
    }
    return preg_replace('/[^A-Z0-9_]/', '_', strtoupper($message ?: 'MOOVA_ORDER_FAILED'));
}

function moova_error_status($message)
{
    $code = moova_error_code($message);
    $map = [
        'INVALID_PAYLOAD' => 400,
        'IDEMPOTENCY_REQUIRED' => 400,
        'DEVICE_TOKEN_REQUIRED' => 401,
        'UNAUTHORIZED' => 401,
        'INTEGRATION_NOT_MAPPED' => 403,
        'TENANT_SCOPE_MISMATCH' => 403,
        'TABLE_REQUIRED' => 422,
        'TABLE_NOT_FOUND' => 422,
        'TABLE_MAPPING_AMBIGUOUS' => 409,
        'ITEM_NOT_FOUND' => 422,
        'NO_VALID_ITEMS' => 422,
        'MISSING_TENANT_SETTINGS' => 500,
        'MISSING_DEFAULTS' => 500,
        'IDEMPOTENCY_PAYLOAD_CONFLICT' => 409,
    ];

    return $map[$code] ?? 500;
}

function moova_fetch_order_link_for_update(mysqli $conn, $tenant, $branch, $idempotencyKey)
{
    $stmt = $conn->prepare("
        SELECT *
        FROM moova_pos_order_links
        WHERE pos_tenant = ?
          AND pos_branch = ?
          AND idempotency_key = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param("iis", $tenant, $branch, $idempotencyKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function moova_create_order_link(mysqli $conn, array $payload, array $link, $idempotencyKey, $requestHash, $requestJson)
{
    $status = 'processing';
    $stmt = $conn->prepare("
        INSERT INTO moova_pos_order_links (
            idempotency_key, request_hash, moova_order_id, moova_branch_id,
            pos_tenant, pos_branch, provider_status, request_payload
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $moovaOrderId = trim((string) ($payload['cofeOrderId'] ?? ''));
    $branchId = trim((string) ($payload['branchId'] ?? ''));
    if ($branchId === '' && !empty($link['moova_branch_id'])) {
        $branchId = trim((string) $link['moova_branch_id']);
    }
    $tenant = (int) $link['pos_tenant'];
    $branch = (int) $link['pos_branch'];
    $stmt->bind_param("ssssiiss", $idempotencyKey, $requestHash, $moovaOrderId, $branchId, $tenant, $branch, $status, $requestJson);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

function moova_update_order_link_success(mysqli $conn, $linkId, $orderId, $providerStatus, array $response)
{
    $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $conn->prepare("
        UPDATE moova_pos_order_links
        SET pos_order_id = ?,
            provider_status = ?,
            response_payload = ?
        WHERE id = ?
    ");
    $stmt->bind_param("issi", $orderId, $providerStatus, $responseJson, $linkId);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    moova_json_response(405, ['success' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed']);
}

if (!isset($_SESSION['userid'])) {
    moova_json_response(401, ['success' => false, 'code' => 'UNAUTHORIZED', 'message' => 'Please login to POS first']);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    moova_json_response(400, ['success' => false, 'code' => 'INVALID_PAYLOAD', 'message' => 'Invalid JSON payload']);
}

$deviceToken = moova_header_value('X-Moova-Device-Token');
if ($deviceToken === '') {
    moova_json_response(401, ['success' => false, 'code' => 'DEVICE_TOKEN_REQUIRED', 'message' => 'Missing Moova device token']);
}

$idempotencyKey = trim((string) ($payload['idempotencyKey'] ?? moova_header_value('Idempotency-Key')));
if ($idempotencyKey === '') {
    moova_json_response(400, ['success' => false, 'code' => 'IDEMPOTENCY_REQUIRED', 'message' => 'Missing idempotency key']);
}

$branchId = trim((string) ($payload['branchId'] ?? ''));

try {
    MoovaPosIntegration::ensureSchema($conn);

    $shopLink = MoovaPosIntegration::findActiveLinkByToken($conn, $deviceToken);
    if (!$shopLink) {
        throw new MoovaOrderHttpException('INTEGRATION_NOT_MAPPED', 403);
    }

    if ($branchId === '' && !empty($shopLink['moova_branch_id'])) {
        $payload['branchId'] = (string) $shopLink['moova_branch_id'];
    }

    if (!MoovaPosIntegration::userCanUseLink($conn, (int) $_SESSION['userid'], $shopLink)) {
        throw new MoovaOrderHttpException('TENANT_SCOPE_MISMATCH', 403);
    }

    $requestHash = MoovaPosIntegration::payloadHash($payload);
    $requestJson = json_encode(
        MoovaPosIntegration::normalizePayloadForHash($payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $conn->begin_transaction();
    $orderLink = moova_fetch_order_link_for_update(
        $conn,
        (int) $shopLink['pos_tenant'],
        (int) $shopLink['pos_branch'],
        $idempotencyKey
    );

    if ($orderLink) {
        if (!hash_equals((string) $orderLink['request_hash'], $requestHash)) {
            throw new MoovaOrderHttpException('IDEMPOTENCY_PAYLOAD_CONFLICT', 409);
        }

        if ((int) ($orderLink['pos_order_id'] ?? 0) > 0) {
            $responsePayload = json_decode((string) ($orderLink['response_payload'] ?? ''), true);
            if (!is_array($responsePayload)) {
                $responsePayload = [
                    'success' => true,
                    'orderId' => (int) $orderLink['pos_order_id'],
                    'providerOrderId' => (string) $orderLink['pos_order_id'],
                    'providerReferenceId' => $idempotencyKey,
                    'providerStatus' => (string) ($orderLink['provider_status'] ?: 'created'),
                ];
            }
            $conn->commit();
            moova_json_response(200, $responsePayload);
        }

        $orderLinkId = (int) $orderLink['id'];
    } else {
        $orderLinkId = moova_create_order_link($conn, $payload, $shopLink, $idempotencyKey, $requestHash, $requestJson);
    }

    $service = new PosOrderService();
    $order = $service->createOrMergeMoovaTableOrder($conn, [
        'tenant' => (int) $shopLink['pos_tenant'],
        'branch' => (int) $shopLink['pos_branch'],
        'user_id' => (int) $_SESSION['userid'],
    ], $payload);

    $providerStatus = $order['merged'] ? 'updated' : 'created';
    $response = [
        'success' => true,
        'orderId' => (int) $order['order_id'],
        'providerOrderId' => (string) $order['order_id'],
        'providerReferenceId' => $idempotencyKey,
        'providerStatus' => $providerStatus,
        'merged' => (bool) $order['merged'],
        'receiptUrl' => 'print/receipt.php?id=' . (int) $order['order_id'],
        'tableId' => (int) $order['table_id'],
        'tableName' => $order['table_name'],
        'totals' => [
            'total' => (float) $order['total'],
            'discount' => (float) $order['discount'],
            'net' => (float) $order['net'],
        ],
    ];

    moova_update_order_link_success($conn, $orderLinkId, (int) $order['order_id'], $providerStatus, $response);

    $conn->commit();
    moova_json_response(200, $response);
} catch (MoovaOrderHttpException $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }
    moova_json_response($e->statusCode(), [
        'success' => false,
        'code' => moova_error_code($e->getMessage()),
        'message' => $e->getMessage(),
    ]);
} catch (Exception $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    $statusCode = moova_error_status($e->getMessage());
    moova_json_response($statusCode, [
        'success' => false,
        'code' => moova_error_code($e->getMessage()),
        'message' => $e->getMessage(),
    ]);
}
