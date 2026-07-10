<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/shift_unresolved_list.php');

require_once __DIR__ . '/../classes/Pos/Service/ShiftCountService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $tenant = (int) ($_GET['tenant'] ?? $_SESSION['pos_tenant'] ?? 0);
    $branch = (int) ($_GET['branch'] ?? $_SESSION['pos_branch'] ?? 0);
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $varianceType = strtolower(trim((string) ($_GET['variance_type'] ?? '')));
    $options = ['offset' => $offset];
    if ($varianceType !== '' && $varianceType !== 'all') {
        $options['variance_type'] = $varianceType;
    }

    $service = new ShiftCountService();
    $rows = $service->unresolvedSessions($conn, $tenant, $branch, $limit, $options);
    $total = $service->countUnresolvedSessions($conn, $tenant, $branch, $options);

    echo json_encode([
        'success' => true,
        'data' => $rows,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'variance_type' => $varianceType !== '' ? $varianceType : 'all',
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
