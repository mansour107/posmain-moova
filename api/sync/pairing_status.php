<?php

require_once __DIR__ . '/../../includes/db_bootstrap.php';
require_once __DIR__ . '/../../includes/sync_route.php';
require_once __DIR__ . '/../../classes/Sync/BranchSecretProviderFactory.php';
require_once __DIR__ . '/../../classes/Sync/CloudAuthService.php';
require_once __DIR__ . '/../../classes/Sync/CloudBranchSyncEventService.php';
require_once __DIR__ . '/../../classes/Sync/PairingStatusService.php';

header('Content-Type: application/json; charset=utf-8');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'reason' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $headers = CloudBranchSyncEventService::headersFromServer($_SERVER);
    $query = $_GET;
    $conn = posmain_sync_db_connect_for_payload($headers, $query);
    $config = posmain_app_config();

    if (!in_array((string) ($config['role'] ?? 'branch'), ['cloud', 'fake_cloud'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'reason' => 'invalid_role'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $branchUuid = strtolower(trim((string) ($query['branch_uuid'] ?? '')));
    if ($branchUuid === '') {
        $branchUuid = strtolower(trim((string) ($headers['x-posmain-branch-uuid'] ?? $headers['x-branch-uuid'] ?? '')));
    }

    $signatureBody = 'GET /api/sync/pairing_status.php?' . http_build_query([
        'branch_uuid' => $branchUuid,
    ]);
    $provider = BranchSecretProviderFactory::fromConfig($conn, $config);
    $auth = (new CloudAuthService())->verifyRequest(
        $provider,
        $branchUuid,
        (string) ($headers['x-posmain-timestamp'] ?? $headers['x-timestamp'] ?? ''),
        (string) ($headers['x-posmain-nonce'] ?? $headers['x-nonce'] ?? ''),
        $signatureBody,
        (string) ($headers['x-posmain-signature'] ?? $headers['x-signature'] ?? '')
    );

    if (!$auth['ok']) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'reason' => $auth['reason']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $provider->touchLastSeen($branchUuid);
    $probe = (new PairingStatusService())->hostedProbe($conn, $config, $branchUuid);
    $conn->close();

    http_response_code(200);
    echo json_encode($probe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
