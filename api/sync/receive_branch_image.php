<?php

require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../includes/sync_route.php';
require_once __DIR__ . '/../../classes/Sync/CloudBranchImageReceiveService.php';
require_once __DIR__ . '/../../classes/Sync/CloudReceiveService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'reason' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $metadataRaw = (string) ($_POST['metadata'] ?? '');
    $metadata = json_decode($metadataRaw, true);
    if (!is_array($metadata)) {
        throw new InvalidArgumentException('metadata is required');
    }

    $headers = CloudReceiveService::headersFromServer($_SERVER);
    $conn = posmain_sync_db_connect_for_payload($headers, $metadataRaw);
    $metadata['raw'] = $metadataRaw;
    $file = $_FILES['file'] ?? [];

    $service = new CloudBranchImageReceiveService();
    $result = $service->handle($conn, $headers, $metadata, is_array($file) ? $file : [], posmain_app_config());

    http_response_code((int) $result['status_code']);
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    posmain_sync_router_error($e);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'reason' => 'server_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
