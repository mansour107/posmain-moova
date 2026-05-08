<?php

ob_start();
session_start();
include('../includes/connect.php');
ob_clean();

require_once('../classes/MoovaPosIntegration.php');
require_once('../classes/PosOrderService.php');

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

function moova_change_decline_message($code)
{
    $messages = [
        'POS_ORDER_LINK_NOT_FOUND' => 'Order is not linked to the connected POS.',
        'POS_PROVIDER_ORDER_MISMATCH' => 'POS order identifier does not match the linked order.',
        'POS_STATE_UNKNOWN' => 'POS order state was not captured when the order was accepted.',
        'POS_ORDER_NOT_FOUND' => 'POS order was not found.',
        'POS_ORDER_DELETED' => 'POS order is already deleted.',
        'POS_ORDER_NOT_TABLE' => 'POS order is not an active table order.',
        'POS_ORDER_PAID' => 'POS order is already paid.',
        'POS_ORDER_NOT_ACTIVE' => 'POS order is no longer active.',
        'POS_ORDER_CHANGED' => 'POS order changed in the POS after the last Moova sync.',
        'POS_ORDER_LINES_CHANGED' => 'This Moova order lines changed in the POS after the last Moova sync.',
        'POS_ORDER_LINES_UNMAPPED' => 'This Moova order does not have POS line ownership mapping.',
        'ITEM_NOT_FOUND' => 'One or more edited order items are not available in the POS.',
        'NO_VALID_ITEMS' => 'Edited order has no valid POS items.',
    ];

    return $messages[$code] ?? 'POS declined the order change.';
}

function moova_change_is_decline_code($code)
{
    return in_array($code, [
        'POS_ORDER_NOT_FOUND',
        'POS_ORDER_DELETED',
        'POS_ORDER_NOT_TABLE',
        'POS_ORDER_PAID',
        'POS_ORDER_NOT_ACTIVE',
        'POS_ORDER_CHANGED',
        'POS_ORDER_LINES_CHANGED',
        'POS_ORDER_LINES_UNMAPPED',
        'ITEM_NOT_FOUND',
        'NO_VALID_ITEMS',
        'TABLE_NOT_FOUND',
    ], true);
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

function moova_change_decline_response($action, $moovaOrderId, $idempotencyKey, $code, $message = null)
{
    return [
        'success' => true,
        'applied' => false,
        'retryable' => false,
        'action' => $action,
        'moovaOrderId' => $moovaOrderId,
        'providerReferenceId' => $idempotencyKey,
        'providerStatus' => 'declined',
        'code' => $code,
        'message' => $message ?: moova_change_decline_message($code),
    ];
}

function moova_change_fetch_order_link_for_update(mysqli $conn, $tenant, $branch, $moovaOrderId)
{
    $stmt = $conn->prepare("
        SELECT *
        FROM moova_pos_order_links
        WHERE pos_tenant = ?
          AND pos_branch = ?
          AND moova_order_id = ?
          AND pos_order_id IS NOT NULL
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param("iis", $tenant, $branch, $moovaOrderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function moova_change_fetch_action_for_update(mysqli $conn, $tenant, $branch, $idempotencyKey)
{
    $stmt = $conn->prepare("
        SELECT *
        FROM moova_pos_order_change_links
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

function moova_change_create_action(mysqli $conn, array $payload, array $link, $posOrderId, $idempotencyKey, $requestHash, $requestJson)
{
    $status = 'processing';
    $moovaOrderId = trim((string) ($payload['moovaOrderId'] ?? $payload['orderId'] ?? ''));
    $requestEventId = trim((string) ($payload['requestEventId'] ?? ''));
    $action = trim((string) ($payload['action'] ?? ''));
    $branchId = trim((string) ($payload['branchId'] ?? ''));
    if ($branchId === '' && !empty($link['moova_branch_id'])) {
        $branchId = trim((string) $link['moova_branch_id']);
    }
    $tenant = (int) $link['pos_tenant'];
    $branch = (int) $link['pos_branch'];
    $orderId = $posOrderId ? (int) $posOrderId : null;

    $stmt = $conn->prepare("
        INSERT INTO moova_pos_order_change_links (
            idempotency_key, request_hash, moova_order_id, moova_request_event_id,
            change_type, moova_branch_id, pos_tenant, pos_branch, pos_order_id,
            provider_status, request_payload
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "ssssssiiiss",
        $idempotencyKey,
        $requestHash,
        $moovaOrderId,
        $requestEventId,
        $action,
        $branchId,
        $tenant,
        $branch,
        $orderId,
        $status,
        $requestJson
    );
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}

function moova_change_update_action(mysqli $conn, $actionId, $status, array $response)
{
    $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $conn->prepare("
        UPDATE moova_pos_order_change_links
        SET provider_status = ?,
            response_payload = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssi", $status, $responseJson, $actionId);
    $stmt->execute();
    $stmt->close();
}

function moova_change_update_order_link_state(mysqli $conn, $linkId, $providerStatus, $stateHash, $statePayload)
{
    $statePayloadJson = $statePayload === null
        ? null
        : json_encode($statePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $conn->prepare("
        UPDATE moova_pos_order_links
        SET provider_status = ?,
            last_pos_state_hash = ?,
            last_pos_state_payload = ?
        WHERE id = ?
    ");
    $stmt->bind_param("sssi", $providerStatus, $stateHash, $statePayloadJson, $linkId);
    $stmt->execute();
    $stmt->close();
}

function moova_change_head_decline_code(array $snapshot)
{
    $head = $snapshot['payload']['head'] ?? [];
    if ((int) ($head['isdeleted'] ?? 0) === 1) {
        return 'POS_ORDER_DELETED';
    }
    if ((int) ($head['pro_tybe'] ?? 0) !== PosOrderService::TYPE_POS || (int) ($head['table_id'] ?? 0) < 1) {
        return 'POS_ORDER_NOT_TABLE';
    }
    $paymentStatus = strtolower(trim((string) ($head['payment_status'] ?? '')));
    $paidAmount = (float) ($head['paid_amount'] ?? 0);
    if (($paymentStatus !== '' && $paymentStatus !== 'unpaid') || $paidAmount > 0) {
        return 'POS_ORDER_PAID';
    }
    if ((float) ($head['fat_net'] ?? 0) <= 0) {
        return 'POS_ORDER_NOT_ACTIVE';
    }

    return null;
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

    $requestHash = MoovaPosIntegration::changePayloadHash($payload);
    $requestJson = json_encode(
        MoovaPosIntegration::normalizeChangePayloadForHash($payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $tenant = (int) $shopLink['pos_tenant'];
    $branch = (int) $shopLink['pos_branch'];
    $conn->begin_transaction();

    $changeLink = moova_change_fetch_action_for_update($conn, $tenant, $branch, $idempotencyKey);
    if ($changeLink) {
        if (!hash_equals((string) $changeLink['request_hash'], $requestHash)) {
            throw new MoovaOrderChangeHttpException('IDEMPOTENCY_PAYLOAD_CONFLICT', 409);
        }
        $responsePayload = json_decode((string) ($changeLink['response_payload'] ?? ''), true);
        if (is_array($responsePayload) && (string) ($changeLink['provider_status'] ?? '') !== 'processing') {
            $conn->commit();
            moova_change_json_response(200, $responsePayload);
        }
        $changeLinkId = (int) $changeLink['id'];
    } else {
        $changeLinkId = null;
    }

    $orderLink = moova_change_fetch_order_link_for_update($conn, $tenant, $branch, $moovaOrderId);
    if (!$changeLinkId) {
        $changeLinkId = moova_change_create_action(
            $conn,
            $payload,
            $shopLink,
            $orderLink['pos_order_id'] ?? null,
            $idempotencyKey,
            $requestHash,
            $requestJson
        );
    }

    $declineCode = null;
    if (!$orderLink) {
        $declineCode = 'POS_ORDER_LINK_NOT_FOUND';
    } else {
        $requestedProviderOrderId = trim((string) ($payload['providerOrderId'] ?? ''));
        $linkedProviderOrderId = trim((string) ($orderLink['pos_order_id'] ?? ''));
        if ($requestedProviderOrderId !== '' && $linkedProviderOrderId !== '' && $requestedProviderOrderId !== $linkedProviderOrderId) {
            $declineCode = 'POS_PROVIDER_ORDER_MISMATCH';
        }
    }
    $service = new PosOrderService();
    $currentSnapshot = null;
    $lineSnapshot = null;
    if (!$declineCode) {
        $currentSnapshot = $service->getMoovaOrderStateSnapshot($conn, $tenant, $branch, (int) $orderLink['pos_order_id']);
        if (!$currentSnapshot) {
            $declineCode = 'POS_ORDER_NOT_FOUND';
        }
    }
    if (!$declineCode) {
        $declineCode = moova_change_head_decline_code($currentSnapshot);
    }
    if (!$declineCode) {
        $lineSnapshot = $service->getMoovaOrderLineStateSnapshot($conn, $tenant, $branch, (int) $orderLink['pos_order_id'], $moovaOrderId);
        if (!$lineSnapshot || empty($lineSnapshot['lines'])) {
            $declineCode = 'POS_ORDER_LINES_UNMAPPED';
        }
    }
    if (!$declineCode && empty($orderLink['last_pos_state_hash'])) {
        $declineCode = 'POS_STATE_UNKNOWN';
    }
    if (!$declineCode && !hash_equals((string) $orderLink['last_pos_state_hash'], (string) $lineSnapshot['hash'])) {
        $declineCode = 'POS_ORDER_LINES_CHANGED';
    }

    if ($declineCode) {
        $response = moova_change_decline_response($action, $moovaOrderId, $idempotencyKey, $declineCode);
        moova_change_update_action($conn, $changeLinkId, 'declined', $response);
        $conn->commit();
        moova_change_json_response(200, $response);
    }

    try {
        if ($action === 'edit') {
            $payload['expectedStateHash'] = (string) $orderLink['last_pos_state_hash'];
            $result = $service->replaceMoovaTableOrder($conn, [
                'tenant' => $tenant,
                'branch' => $branch,
                'user_id' => (int) $_SESSION['userid'],
            ], (int) $orderLink['pos_order_id'], $payload);
            $providerStatus = 'edited';
            $response = [
                'success' => true,
                'applied' => true,
                'action' => 'edit',
                'moovaOrderId' => $moovaOrderId,
                'orderId' => (int) $result['order_id'],
                'providerOrderId' => (string) $result['order_id'],
                'providerReferenceId' => $idempotencyKey,
                'providerStatus' => $providerStatus,
                'stateHash' => $result['state_hash'] ?? null,
                'tableId' => (int) $result['table_id'],
                'tableName' => $result['table_name'],
                'totals' => [
                    'total' => (float) $result['total'],
                    'discount' => (float) $result['discount'],
                    'net' => (float) $result['net'],
                ],
            ];
            moova_change_update_order_link_state(
                $conn,
                (int) $orderLink['id'],
                $providerStatus,
                $result['state_hash'] ?? null,
                $result['state_payload'] ?? null
            );
        } else {
            $result = $service->cancelMoovaTableOrder($conn, [
                'tenant' => $tenant,
                'branch' => $branch,
                'user_id' => (int) $_SESSION['userid'],
            ], (int) $orderLink['pos_order_id'], $moovaOrderId, (string) $orderLink['last_pos_state_hash']);
            $providerStatus = 'cancelled';
            $response = [
                'success' => true,
                'applied' => true,
                'action' => 'cancel',
                'moovaOrderId' => $moovaOrderId,
                'orderId' => (int) $result['order_id'],
                'providerOrderId' => (string) $result['order_id'],
                'providerReferenceId' => $idempotencyKey,
                'providerStatus' => $providerStatus,
                'tableId' => (int) $result['table_id'],
            ];
            moova_change_update_order_link_state($conn, (int) $orderLink['id'], $providerStatus, null, null);
        }
    } catch (Exception $serviceError) {
        $code = moova_change_error_code($serviceError->getMessage());
        if (!moova_change_is_decline_code($code)) {
            throw $serviceError;
        }
        $response = moova_change_decline_response($action, $moovaOrderId, $idempotencyKey, $code);
        moova_change_update_action($conn, $changeLinkId, 'declined', $response);
        $conn->commit();
        moova_change_json_response(200, $response);
    }

    moova_change_update_action($conn, $changeLinkId, $providerStatus, $response);
    $conn->commit();
    moova_change_json_response(200, $response);
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
