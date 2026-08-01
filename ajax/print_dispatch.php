<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/print_dispatch.php', $conn ?? null);

require_once __DIR__ . '/../classes/Pos/Service/SilentPrintDispatchService.php';
require_once __DIR__ . '/../classes/Pos/Service/PrintUserMessageService.php';
require_once __DIR__ . '/../config/app_config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        throw new RuntimeException('METHOD_NOT_ALLOWED');
    }
    $config = posmain_app_config();
    if (($config['printing']['mode'] ?? 'legacy') !== 'silent') {
        throw new RuntimeException('SILENT_PRINT_DISABLED');
    }

    $raw = (string) file_get_contents('php://input');
    $request = json_decode($raw, true);
    if (!is_array($request)) {
        throw new InvalidArgumentException('PRINT_REQUEST_INVALID');
    }

    $jobType = strtolower(trim((string) ($request['job_type'] ?? 'document')));
    $orderId = (int) ($request['order_id'] ?? 0);
    $requestKey = trim((string) ($request['request_key'] ?? ''));
    $userId = isset($_SESSION['userid']) ? (int) $_SESSION['userid'] : null;
    $scope = [
        'tenant' => max(0, (int) ($_SESSION['pos_tenant'] ?? $config['branch']['pos_tenant'] ?? 0)),
        'branch' => max(0, (int) ($_SESSION['pos_branch'] ?? $config['branch']['pos_branch'] ?? 0)),
    ];

    $dispatch = new SilentPrintDispatchService();
    if (in_array($jobType, ['receipt', 'kot', 'kitchen'], true)) {
        if (
            !auth_guard_pos_lane_has_permission_or_override('pos.open', $conn)
            && !auth_guard_pos_lane_has_permission_or_override('pos.reprint', $conn)
        ) {
            throw new RuntimeException('PERMISSION_DENIED');
        }
        if ($orderId < 1) {
            throw new InvalidArgumentException('ORDER_ID_REQUIRED');
        }
        $result = $dispatch->dispatchOrder(
            $conn,
            $jobType,
            $orderId,
            $requestKey,
            $scope,
            $userId
        );
    } else {
        $canPrintReport = auth_guard_has_permission('reports.view', $conn)
            || auth_guard_has_permission('reports.own_shift', $conn)
            || auth_guard_has_permission('pos.shift.close', $conn)
            || auth_guard_pos_lane_has_permission_or_override('pos.open', $conn);
        if (!$canPrintReport) {
            throw new RuntimeException('PERMISSION_DENIED');
        }
        $result = $dispatch->dispatchDocument(
            $conn,
            $jobType,
            (string) ($request['title'] ?? 'POSMAIN'),
            (string) ($request['content_text'] ?? ''),
            $requestKey,
            $scope,
            $userId
        );
    }

    echo json_encode([
        'success' => true,
        'mode' => 'silent',
        'result' => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    $code = PrintUserMessageService::code((string) $exception->getMessage());
    PrintUserMessageService::log($exception, 'print-dispatch');
    $status = in_array($code, ['PERMISSION_DENIED'], true)
        ? 403
        : (in_array($code, ['METHOD_NOT_ALLOWED'], true) ? 405 : 422);
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => PrintUserMessageService::forCode($code),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
