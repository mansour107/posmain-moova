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
    $stationId = (int) ($_POST['station_id'] ?? 0);
    $service = new KdsStationService();
    $service->deleteStation($conn, $stationId);

    try {
        (new SecurityAuditLogger())->record($conn, 'kds.station.delete', [
            'target_type' => 'kds_station',
            'target_id' => $stationId,
        ]);
    } catch (Throwable $auditException) {
        error_log('KDS station delete audit skipped: ' . $auditException->getMessage());
    }

    header('Location: ../kds_settings.php?ok=station_deleted');
} catch (Throwable $e) {
    header('Location: ../kds_settings.php?error=' . urlencode($e->getMessage()));
}
exit;
