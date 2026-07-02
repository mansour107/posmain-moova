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

    $since = (int) ($_GET['since'] ?? 0);
    $service = new KdsTicketService();

    // On a full load (cursor reset / screen open) reconcile any orders that
    // may have missed a side-effect event, so the board is self-healing.
    if ($since <= 0) {
        try {
            $service->reconcile($conn, 25);
        } catch (Throwable $reconcileException) {
            error_log('KDS reconcile skipped: ' . $reconcileException->getMessage());
        }
    }

    $feed = $service->changesSince($conn, (int) $station['id'], $since);

    echo json_encode([
        'success' => true,
        'station' => [
            'id' => (int) $station['id'],
            'uuid' => $station['uuid'],
            'name' => $station['name'],
            'color' => $station['color'],
            'warn_after_seconds' => $station['warn_after_seconds'],
            'late_after_seconds' => $station['late_after_seconds'],
        ],
        'cursor' => $feed['cursor'],
        'full' => $feed['full'],
        'changes' => $feed['changes'],
        'server_time' => time(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
