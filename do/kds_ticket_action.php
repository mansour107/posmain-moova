<?php

require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/kds_access.php';
require_once __DIR__ . '/../classes/Pos/Service/KdsTicketService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';

header('Content-Type: application/json; charset=utf-8');

require_permission('kds.complete', $conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_csrf('kds');
    posmain_ensure_kds_schema($conn);

    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    $allowed = ['start', 'complete', 'recall'];
    if ($ticketId < 1) {
        throw new InvalidArgumentException('KDS_TICKET_ID_REQUIRED');
    }
    if (!in_array($action, $allowed, true)) {
        throw new InvalidArgumentException('KDS_TICKET_ACTION_INVALID');
    }

    $service = new KdsTicketService();
    $stationId = $service->stationIdForTicket($conn, $ticketId);
    if ($stationId < 1) {
        throw new InvalidArgumentException('KDS_TICKET_NOT_FOUND');
    }
    kds_require_station_id_access($conn, $stationId);

    $userId = current_user_id();
    switch ($action) {
        case 'start':
            $applied = $service->startTicket($conn, $ticketId, $userId);
            break;
        case 'recall':
            $applied = $service->recallTicket($conn, $ticketId, $userId);
            break;
        case 'complete':
        default:
            $applied = $service->completeTicket($conn, $ticketId, $userId);
            break;
    }

    try {
        (new SecurityAuditLogger())->record($conn, 'kds.ticket.' . $action, [
            'target_type' => 'kds_ticket',
            'target_id' => $ticketId,
            'metadata' => [
                'station_id' => $stationId,
                'applied' => $applied,
            ],
        ]);
    } catch (Throwable $auditException) {
        error_log('KDS ticket audit skipped: ' . $auditException->getMessage());
    }

    echo json_encode([
        'success' => true,
        'applied' => $applied,
        'ticket_id' => $ticketId,
        'action' => $action,
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
