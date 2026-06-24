<?php

require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../includes/sync_route.php';
require_once __DIR__ . '/../../classes/Sync/CloudBranchImageExportService.php';
require_once __DIR__ . '/../../classes/Sync/CloudReceiveService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'reason' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $branchUuid = strtolower(trim((string) ($_GET['branch_uuid'] ?? '')));
    $fileName = (string) ($_GET['file_name'] ?? '');
    $signatureBody = CloudBranchImageExportService::exportSignatureBody($branchUuid, $fileName);
    $headers = CloudReceiveService::headersFromServer($_SERVER);
    $conn = posmain_sync_db_connect_for_payload($headers, $signatureBody);

    $service = new CloudBranchImageExportService();
    $result = $service->handle($conn, $headers, $branchUuid, $fileName, posmain_app_config());

    if (!empty($result['stream']) && !empty($result['file_path']) && is_file((string) $result['file_path'])) {
        http_response_code(200);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: inline; filename="' . basename((string) $result['file_name']) . '"');
        header('Content-Length: ' . (int) ($result['file_size'] ?? 0));
        if (!empty($result['file_sha256'])) {
            header('X-POSMAIN-File-SHA256: ' . (string) $result['file_sha256']);
        }
        readfile((string) $result['file_path']);
        exit;
    }

    http_response_code((int) ($result['status_code'] ?? 500));
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result['body'] ?? ['ok' => false, 'reason' => 'export_failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    posmain_sync_router_error($e);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'reason' => 'server_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
