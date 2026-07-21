<?php
include __DIR__ . '/../includes/ajax_header.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/delivery_schema_guard.php';
require_once __DIR__ . '/../classes/Pos/Service/DeliverySettlementService.php';

header('Content-Type: application/json; charset=utf-8');
require_permission('delivery.settlements.manage', $conn);
try {
    posmain_require_delivery_schema_ready($conn);
    $service = new DeliverySettlementService();
    $scope = ['tenant' => (int) ($_SESSION['pos_tenant'] ?? 0), 'branch' => (int) ($_SESSION['pos_branch'] ?? 0)];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf('delivery_operations');
        $action = (string) ($_POST['action'] ?? 'preview');
        if ($action === 'reverse') {
            require_permission('delivery.settlements.reverse', $conn);
            $result = $service->reverse($conn, (int) ($_POST['settlement_id'] ?? 0), (string) ($_POST['reason'] ?? ''), [
                'user_id' => (int) ($_SESSION['userid'] ?? 0),
                'drawer_session_id' => (int) ($_POST['drawer_session_id'] ?? 0),
            ] + $scope);
            echo json_encode(['success' => true, 'settlement' => $result], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $workerId = (int) ($_POST['worker_id'] ?? 0);
        $start = (string) ($_POST['period_start'] ?? '');
        $end = (string) ($_POST['period_end'] ?? '');
        $options = [
            'user_id' => (int) ($_SESSION['userid'] ?? 0),
            'bonuses' => $_POST['bonuses'] ?? 0,
            'deductions' => $_POST['deductions'] ?? 0,
            'payment_method' => $_POST['payment_method'] ?? 'cash',
            'fund_account_id' => (int) ($_POST['fund_account_id'] ?? 0),
            'drawer_session_id' => (int) ($_POST['drawer_session_id'] ?? 0),
            'idempotency_key' => (string) ($_POST['idempotency_key'] ?? ''),
            'notes' => $_POST['notes'] ?? null,
        ] + $scope;
        $result = $action === 'finalize'
            ? $service->finalize($conn, $workerId, $start, $end, $options)
            : $service->preview($conn, $workerId, $start, $end, $options);
        echo json_encode(['success' => true, 'settlement' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => true, 'settlements' => $service->listSettlements($conn, $scope)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    posmain_emit_delivery_api_error($e);
}
