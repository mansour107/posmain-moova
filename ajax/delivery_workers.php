<?php
include __DIR__ . '/../includes/ajax_header.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/delivery_schema_guard.php';
require_once __DIR__ . '/../classes/Pos/Service/DeliveryWorkerService.php';

header('Content-Type: application/json; charset=utf-8');
$canRead = auth_guard_has_permission('delivery.assign', $conn)
    || auth_guard_has_permission('delivery.workers.manage', $conn)
    || auth_guard_has_permission('delivery.settlements.manage', $conn)
    || auth_guard_has_permission('delivery.reports.view', $conn);
if (!$canRead) deny_json_or_redirect('PERMISSION_DENIED', 403);

try {
    $scope = ['tenant' => (int) ($_SESSION['pos_tenant'] ?? 0), 'branch' => (int) ($_SESSION['pos_branch'] ?? 0)];
    posmain_require_delivery_schema_ready($conn);
    $service = new DeliveryWorkerService();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_permission('delivery.workers.manage', $conn);
        require_csrf('delivery_operations');
        $worker = $service->saveWorker($conn, $_POST, ['user_id' => (int) ($_SESSION['userid'] ?? 0)] + $scope);
        echo json_encode(['success' => true, 'worker' => $worker], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $canViewFinancials = auth_guard_has_permission('delivery.settlements.manage', $conn)
        || auth_guard_has_permission('delivery.reports.view', $conn);
    $workers = $service->listWorkers($conn, $scope, !empty($_GET['include_inactive']), $canViewFinancials);
    echo json_encode(['success' => true, 'workers' => $workers], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    posmain_emit_delivery_api_error($e);
}
