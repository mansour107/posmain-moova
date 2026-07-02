<?php

require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/kds_access.php';
require_once __DIR__ . '/../classes/Pos/Service/KdsTicketService.php';

header('Content-Type: application/json; charset=utf-8');

require_permission('kds.view', $conn);

try {
    posmain_ensure_kds_schema($conn);

    $stationUuid = (string) ($_GET['station'] ?? '');
    $station = kds_resolve_station_or_deny($conn, $stationUuid);

    $limit = (int) ($_GET['limit'] ?? 50);
    $service = new KdsTicketService();
    $tickets = $service->history($conn, (int) $station['id'], $limit);

    echo json_encode([
        'success' => true,
        'station' => [
            'id' => (int) $station['id'],
            'uuid' => $station['uuid'],
            'name' => $station['name'],
        ],
        'tickets' => $tickets,
        'server_time' => time(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
