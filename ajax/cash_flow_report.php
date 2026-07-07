<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../includes/pos_cache_control.php';
require_once __DIR__ . '/../classes/Pos/Service/CashFlowPeriodService.php';

posmain_send_no_store_headers();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['userid'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'code' => 'LOGIN_REQUIRED'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!auth_guard_has_permission('reports.cash_flow', $conn)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'PERMISSION_DENIED'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = new CashFlowPeriodService();
    $filters = [
        'date_from' => $_GET['date_from'] ?? date('Y-m-d'),
        'date_to' => $_GET['date_to'] ?? ($_GET['date_from'] ?? date('Y-m-d')),
        'tenant' => (int) ($_GET['tenant'] ?? $_SESSION['pos_tenant'] ?? 0),
        'branch' => (int) ($_GET['branch'] ?? $_SESSION['pos_branch'] ?? 0),
        'cashier_id' => (int) ($_GET['cashier_id'] ?? 0),
        'drawer_session_id' => (int) ($_GET['drawer_session_id'] ?? 0),
        'movement_type' => trim((string) ($_GET['movement_type'] ?? '')),
        'include_unassigned' => !empty($_GET['include_unassigned']),
        'only_unassigned' => !empty($_GET['only_unassigned']),
        'limit' => (int) ($_GET['limit'] ?? 100),
        'offset' => (int) ($_GET['offset'] ?? 0),
    ];

    echo json_encode([
        'success' => true,
        'data' => [
            'summary' => $service->summary($conn, $filters),
            'sessions' => $service->sessions($conn, $filters),
            'movements' => $service->movements($conn, $filters),
            'payment_breakdown' => $service->paymentBreakdown($conn, $filters),
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'code' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'code' => 'CASH_FLOW_REPORT_FAILED',
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
