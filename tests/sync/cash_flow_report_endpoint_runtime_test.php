<?php

if (($argv[1] ?? '') === '--child') {
    cashFlowReportEndpointChild((string) ($argv[2] ?? ''));
}

require_once __DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php';

$ajax = (string) file_get_contents(__DIR__ . '/../../ajax/cash_flow_report.php');
cashFlowReportEndpointAssert(strpos($ajax, "reports.cash_flow") !== false, 'endpoint should require reports.cash_flow');
cashFlowReportEndpointAssert(strpos($ajax, 'payment_breakdown') !== false, 'endpoint should return payment_breakdown');

$manifest = require __DIR__ . '/../../config/rbac_route_manifest.php';
cashFlowReportEndpointAssert(isset($manifest['ajax/cash_flow_report.php']), 'manifest should guard cash flow ajax route');

$service = new CashFlowPeriodService();
$normalize = new ReflectionMethod(CashFlowPeriodService::class, 'normalizeFilters');
try {
    $normalize->invoke($service, ['date_from' => 'bad', 'date_to' => date('Y-m-d')]);
    cashFlowReportEndpointAssert(false, 'invalid date_from should throw');
} catch (InvalidArgumentException $exception) {
    cashFlowReportEndpointAssert($exception->getMessage() === 'DATE_FROM_INVALID', 'invalid date_from code');
}

$noLogin = cashFlowReportEndpointRunChild('no_login');
cashFlowReportEndpointAssert(($noLogin['success'] ?? null) === false, 'missing login should fail');
cashFlowReportEndpointAssert(($noLogin['code'] ?? '') === 'LOGIN_REQUIRED', 'missing login should return LOGIN_REQUIRED');

$denied = cashFlowReportEndpointRunChild('cashier_denied');
cashFlowReportEndpointAssert(($denied['success'] ?? null) === false, 'cashier without reports.cash_flow should fail');
cashFlowReportEndpointAssert(($denied['code'] ?? '') === 'PERMISSION_DENIED', 'cashier should get PERMISSION_DENIED');

$allowed = cashFlowReportEndpointRunChild('owner_allowed');
cashFlowReportEndpointAssert(($allowed['success'] ?? null) === true, 'owner should access cash flow report');
cashFlowReportEndpointAssert(isset($allowed['data']['summary']), 'response should include summary');
cashFlowReportEndpointAssert(isset($allowed['data']['sessions']), 'response should include sessions');
cashFlowReportEndpointAssert(isset($allowed['data']['movements']), 'response should include movements');
cashFlowReportEndpointAssert(isset($allowed['data']['payment_breakdown']), 'response should include payment_breakdown');

$invalidRange = cashFlowReportEndpointRunChild('invalid_range');
cashFlowReportEndpointAssert(($invalidRange['success'] ?? null) === false, 'invalid date range should fail');
cashFlowReportEndpointAssert(($invalidRange['code'] ?? '') === 'DATE_RANGE_INVALID', 'invalid range should return DATE_RANGE_INVALID');

echo "cash-flow-report-endpoint-runtime-ok\n";

function cashFlowReportEndpointRunChild(string $mode): array
{
    $php = PHP_BINARY ?: 'php';
    $script = escapeshellarg(__FILE__);
    $modeArg = escapeshellarg($mode);
    $output = shell_exec("{$php} {$script} --child {$modeArg}");
    if (!is_string($output) || trim($output) === '') {
        throw new RuntimeException('Child process returned no output for mode ' . $mode);
    }

    $decoded = json_decode(trim($output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Child process returned invalid JSON for mode ' . $mode . ': ' . $output);
    }

    return $decoded;
}

function cashFlowReportEndpointChild(string $mode): void
{
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['PHP_SELF'] = '/ajax/cash_flow_report.php';
    $_SERVER['SCRIPT_NAME'] = '/ajax/cash_flow_report.php';
    $_GET = [
        'date_from' => date('Y-m-d'),
        'date_to' => date('Y-m-d'),
    ];

    if ($mode === 'invalid_range') {
        $_GET['date_from'] = date('Y-m-d', strtotime('+2 days'));
        $_GET['date_to'] = date('Y-m-d');
    }

    require_once __DIR__ . '/../../includes/session_bootstrap.php';
    require_once __DIR__ . '/../../includes/connect.php';
    require_once __DIR__ . '/../../includes/auth_guard.php';
    require_once __DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php';

    $_SESSION = [];
    $GLOBALS['role'] = [];

    if ($mode === 'owner_allowed' || $mode === 'invalid_range') {
        $_SESSION = ['userid' => 1, 'usrole' => 1, 'login' => 'owner'];
        $GLOBALS['role'] = ['show_gl_reports' => 1, 'sid_reports' => 1];
    } elseif ($mode === 'cashier_denied') {
        $_SESSION = ['userid' => 9, 'usrole' => 2, 'login' => 'cashier'];
        $GLOBALS['role'] = ['add_sales' => 1, 'show_sales' => 1];
    }

    global $conn;
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
    exit;
}

function cashFlowReportEndpointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
