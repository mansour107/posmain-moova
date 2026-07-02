<?php

require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/kds_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/KdsStationService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';

require_permission('kds.manage', $conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../kds_settings.php');
    exit;
}

require_csrf('kds_manage');
posmain_ensure_kds_schema($conn);

try {
    $service = new KdsStationService();
    $stationId = (int) ($_POST['station_id'] ?? 0);
    if ($stationId < 1) {
        throw new InvalidArgumentException('KDS_STATION_NOT_FOUND');
    }

    $posted = $_POST['user_ids'] ?? [];
    $posted = is_array($posted) ? array_map('intval', $posted) : [];

    // Reset the station's assignment set to exactly what was submitted.
    $existing = $service->userIdsForStation($conn, $stationId);
    foreach ($existing as $userId) {
        if (!in_array($userId, $posted, true)) {
            $service->unassignUser($conn, $stationId, $userId);
        }
    }
    foreach ($posted as $userId) {
        if ($userId > 0) {
            $service->assignUser($conn, $stationId, $userId);
        }
    }

    try {
        (new SecurityAuditLogger())->record($conn, 'kds.worker.assign', [
            'target_type' => 'kds_station',
            'target_id' => $stationId,
            'metadata' => ['user_count' => count($posted)],
        ]);
    } catch (Throwable $auditException) {
        error_log('KDS worker assign audit skipped: ' . $auditException->getMessage());
    }

    header('Location: ../kds_settings.php?ok=workers_saved#workers');
} catch (Throwable $e) {
    header('Location: ../kds_settings.php?error=' . urlencode($e->getMessage()) . '#workers');
}
exit;
