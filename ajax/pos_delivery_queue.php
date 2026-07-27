<?php

include __DIR__ . '/../includes/ajax_header.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/delivery_schema_guard.php';
require_once __DIR__ . '/../classes/Pos/Service/DeliveryWorkerService.php';
require_once __DIR__ . '/../classes/Pos/Service/OrderFulfillmentService.php';
require_once __DIR__ . '/../classes/Pos/Service/OrderMutationSideEffectsService.php';

header('Content-Type: application/json; charset=utf-8');
require_permission('pos.open', $conn);

$scope = [
    'tenant' => (int) ($_SESSION['pos_tenant'] ?? 0),
    'branch' => (int) ($_SESSION['pos_branch'] ?? 0),
];
$userId = (int) ($_SESSION['userid'] ?? 0);
$fulfillment = new OrderFulfillmentService();

try {
    posmain_require_delivery_schema_ready($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $orders = $fulfillment->listActiveDeliveryOrders($conn, ['limit' => 100] + $scope);
        $workers = (new DeliveryWorkerService())->listWorkers($conn, $scope, false, false);
        $waiting = 0;
        $out = 0;
        foreach ($orders as $order) {
            if (($order['delivery_status'] ?? '') === 'picked_up') {
                $out++;
            } else {
                $waiting++;
            }
        }
        echo json_encode([
            'success' => true,
            'orders' => $orders,
            'workers' => $workers,
            'summary' => [
                'active' => count($orders),
                'waiting' => $waiting,
                'out' => $out,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_csrf('pos_browser');
    $action = trim((string) ($_POST['action'] ?? ''));
    $orderId = max(0, (int) ($_POST['order_id'] ?? 0));
    if ($orderId < 1) {
        throw new InvalidArgumentException('ORDER_ID_REQUIRED');
    }
    if (!posmain_cashier_delivery_order_in_scope($conn, $orderId, $scope['tenant'], $scope['branch'])) {
        throw new InvalidArgumentException('DELIVERY_ORDER_NOT_FOUND');
    }

    (new OrderMutationSideEffectsService())->preflightSyncIdentity($conn);
    $context = [
        'actor_user_id' => $userId,
        'user_id' => $userId,
        'tenant' => $scope['tenant'],
        'branch' => $scope['branch'],
        'event_source' => 'pos_cashier_delivery_queue',
        'source_system' => 'pos_cashier_delivery_queue',
    ];

    if ($action === 'dispatch') {
        $workerMode = trim((string) ($_POST['worker_mode'] ?? 'registered'));
        if (!in_array($workerMode, ['registered', 'external'], true)) {
            throw new InvalidArgumentException('DELIVERY_WORKER_MODE_INVALID');
        }
        $current = $fulfillment->fulfillmentForOrder($conn, $orderId);
        if (!$current) {
            throw new InvalidArgumentException('DELIVERY_ORDER_NOT_FOUND');
        }
        if (($current['delivery_status'] ?? '') === 'picked_up') {
            echo json_encode(['success' => true, 'fulfillment' => $current, 'replayed' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $workerId = max(0, (int) ($_POST['delivery_worker_id'] ?? 0));
        $driverName = trim((string) ($_POST['driver_name'] ?? ''));
        $driverPhone = trim((string) ($_POST['driver_phone'] ?? ''));
        if ($workerMode === 'registered' && $workerId < 1) {
            throw new InvalidArgumentException('DELIVERY_WORKER_REQUIRED_BEFORE_PICKUP');
        }
        if ($workerMode === 'external' && $driverName === '') {
            $driverName = 'مندوب خارجي';
        }

        $conn->begin_transaction();
        try {
            (new DeliveryWorkerService())->assignOrder(
                $conn,
                $orderId,
                $workerMode === 'registered' ? $workerId : null,
                ['in_transaction' => true] + $context
            );
            $result = $fulfillment->transitionDeliveryStatus($conn, $orderId, 'picked_up', [
                'in_transaction' => true,
                'cashier_dispatch' => true,
                'courier_source' => $workerMode === 'registered' ? 'in_house' : 'external',
                'driver_name' => $driverName,
                'driver_phone' => $driverPhone,
            ] + $context);
            $conn->commit();
        } catch (Throwable $inner) {
            $conn->rollback();
            throw $inner;
        }
        echo json_encode(['success' => true, 'fulfillment' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delivered') {
        $result = $fulfillment->transitionDeliveryStatus($conn, $orderId, 'delivered', $context);
        echo json_encode(['success' => true, 'fulfillment' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'failed') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $result = $fulfillment->transitionDeliveryStatus($conn, $orderId, 'failed', [
            'failure_reason' => $reason,
        ] + $context);
        echo json_encode(['success' => true, 'fulfillment' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new InvalidArgumentException('DELIVERY_CASHIER_ACTION_INVALID');
} catch (Throwable $exception) {
    posmain_emit_delivery_api_error($exception);
}

function posmain_cashier_delivery_order_in_scope(mysqli $conn, int $orderId, int $tenant, int $branch): bool
{
    $columns = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ot_head' AND COLUMN_NAME IN ('tenant','branch')");
    $scopeColumns = [];
    while ($columns && ($row = $columns->fetch_assoc())) {
        $scopeColumns[(string) $row['COLUMN_NAME']] = true;
    }
    if (isset($scopeColumns['tenant'], $scopeColumns['branch'])) {
        $stmt = $conn->prepare("SELECT f.order_id FROM order_fulfillment f INNER JOIN ot_head o ON o.id = f.order_id WHERE f.order_id = ? AND f.fulfillment_type = 'delivery' AND o.tenant = ? AND o.branch = ? LIMIT 1");
        $stmt->bind_param('iii', $orderId, $tenant, $branch);
    } else {
        $stmt = $conn->prepare("SELECT order_id FROM order_fulfillment WHERE order_id = ? AND fulfillment_type = 'delivery' LIMIT 1");
        $stmt->bind_param('i', $orderId);
    }
    $stmt->execute();
    $found = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}
