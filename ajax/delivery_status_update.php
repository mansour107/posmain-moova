<?php
include(__DIR__ . '/../includes/ajax_header.php');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../classes/Pos/Service/OrderFulfillmentService.php';
require_once __DIR__ . '/../classes/Pos/Service/PosOrderMutationService.php';
require_once __DIR__ . '/../classes/Financial/FinancialMoneyInput.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_csrf('delivery_dispatch');
require_login();
if (!empty($_POST['force'])) {
    if (!auth_guard_has_permission('pos.cancel.unpaid', $conn)) {
        deny_json_or_redirect('PERMISSION_DENIED', 403);
    }
} elseif (!auth_guard_has_permission('delivery.dispatch', $conn)) {
    deny_json_or_redirect('PERMISSION_DENIED', 403);
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$newStatus = trim((string) ($_POST['delivery_status'] ?? ''));
$driverName = trim((string) ($_POST['driver_name'] ?? ''));
$driverPhone = trim((string) ($_POST['driver_phone'] ?? ''));
$codAmountInput = $_POST['cod_amount'] ?? '0';
$driverTipInput = $_POST['driver_tip'] ?? '0';
$mutationVersion = (int) ($_POST['mutation_version'] ?? 0);

if ($orderId < 1 || $newStatus === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'order_id and delivery_status are required']);
    exit;
}

try {
    $codAmount = FinancialMoneyInput::moneyString($codAmountInput);
    $driverTip = FinancialMoneyInput::moneyString($driverTipInput);
    if ($newStatus === 'cancelled') {
        $mutation = new PosOrderMutationService();
        $conn->begin_transaction();
        try {
            $cancelResult = $mutation->cancelDeliveryOrder($conn, [
                'order_id' => $orderId,
                'reason' => trim((string) ($_POST['reason'] ?? '')) ?: 'delivery_dispatch_cancelled',
                'user_id' => (int) ($_SESSION['userid'] ?? 0),
                'force' => !empty($_POST['force']),
                'mutation_version' => $mutationVersion,
                'idempotency_key' => trim((string) ($_POST['idempotency_key'] ?? '')),
            ], [
                'in_transaction' => true,
                'force' => !empty($_POST['force']),
                'event_source' => 'delivery_dispatch',
                'tenant' => (int) ($_SESSION['pos_tenant'] ?? 0),
                'branch' => (int) ($_SESSION['pos_branch'] ?? 0),
            ]);
            $conn->commit();
            echo json_encode([
                'success' => true,
                'fulfillment' => $cancelResult['data']['fulfillment'] ?? null,
                'cancel' => $cancelResult['data'] ?? null,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $inner) {
            $conn->rollback();
            throw $inner;
        }
        exit;
    }

    $service = new OrderFulfillmentService();
    $result = $service->transitionDeliveryStatus($conn, $orderId, $newStatus, [
        'actor_user_id' => (int) ($_SESSION['userid'] ?? 0),
        'driver_name' => $driverName,
        'driver_phone' => $driverPhone,
        'cod_amount' => $codAmount,
        'driver_tip' => $driverTip,
        'mutation_version' => $mutationVersion,
        'require_mutation_version' => true,
        'require_outbox' => true,
        'force' => !empty($_POST['force']),
        'tenant' => (int) ($_SESSION['pos_tenant'] ?? 0),
        'branch' => (int) ($_SESSION['pos_branch'] ?? 0),
    ]);
    echo json_encode(['success' => true, 'fulfillment' => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
