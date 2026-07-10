<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_set_business_day_cutoff.php');

require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Pos/Service/BusinessDayService.php';
require_once __DIR__ . '/../includes/business_day.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('business_day_cutoff');

$userId = function_exists('pos_acting_user_id') ? pos_acting_user_id() : (int) ($_SESSION['userid'] ?? 0);
$cutoffRaw = trim((string) ($_POST['business_day_cutoff_hour'] ?? $_POST['cutoff_hour'] ?? ''));

try {
    if ($userId < 1) {
        throw new RuntimeException('AUTH_REQUIRED');
    }
    $canConfigure = auth_guard_has_permission('reports.cash_flow', $conn)
        || auth_guard_has_permission('pos.shift.close', $conn);
    if (!$canConfigure) {
        throw new RuntimeException('PERMISSION_DENIED');
    }
    if ($cutoffRaw === '' || !ctype_digit($cutoffRaw)) {
        throw new RuntimeException('CUTOFF_HOUR_REQUIRED');
    }

    $cutoffHour = (int) $cutoffRaw;
    if ($cutoffHour < 0 || $cutoffHour > 23) {
        throw new RuntimeException('CUTOFF_HOUR_INVALID');
    }

    $postedTenant = isset($_POST['pos_tenant']) ? (int) $_POST['pos_tenant'] : null;
    $postedBranch = isset($_POST['pos_branch']) ? (int) $_POST['pos_branch'] : null;
    $scope = posmain_business_day_resolve_scope($postedTenant, $postedBranch);
    $tenant = (int) $scope['tenant'];
    $branch = (int) $scope['branch'];

    if ($tenant > 0) {
        $_SESSION['pos_tenant'] = $tenant;
    }
    if ($branch > 0) {
        $_SESSION['pos_branch'] = $branch;
    }

    $service = new BusinessDayService();
    $saved = $service->setCutoffHourForBranch($conn, $tenant, $branch, $cutoffHour);
    $currentBusinessDay = $service->currentBusinessDayForBranch($conn, $tenant, $branch);

    echo json_encode([
        'success' => true,
        'data' => [
            'business_day_cutoff_hour' => $saved,
            'current_business_day' => $currentBusinessDay,
            'tenant' => $tenant,
            'branch' => $branch,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
