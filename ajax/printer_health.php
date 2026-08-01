<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/printer_health.php', $conn ?? null);
require_once __DIR__ . '/../classes/Pos/Service/PrintJobService.php';
require_once __DIR__ . '/../classes/Pos/Service/PrinterHealthService.php';
require_once __DIR__ . '/../classes/Pos/Service/PrintUserMessageService.php';
require_once __DIR__ . '/../config/app_config.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        throw new RuntimeException('METHOD_NOT_ALLOWED');
    }
    $config = posmain_app_config();
    $scope = [
        'tenant' => max(0, (int) ($_SESSION['pos_tenant'] ?? $config['branch']['pos_tenant'] ?? 0)),
        'branch' => max(0, (int) ($_SESSION['pos_branch'] ?? $config['branch']['pos_branch'] ?? 0)),
    ];
    $printerId = (int) ($_GET['printer_id'] ?? 0);
    if ($printerId < 1) {
        throw new InvalidArgumentException('PRINT_PRINTER_REQUIRED');
    }
    $printer = (new PrintJobService())->getPrinterInScope($conn, $printerId, $scope, false);
    echo json_encode(['success' => true, 'health' => (new PrinterHealthService())->check($printer)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    PrintUserMessageService::log($exception, 'printer-health');
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => PrintUserMessageService::forException($exception)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
