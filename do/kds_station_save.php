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
    $stationId = $service->saveStation($conn, [
        'id' => (int) ($_POST['id'] ?? 0),
        'name' => (string) ($_POST['name'] ?? ''),
        'color' => (string) ($_POST['color'] ?? '#e8a020'),
        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'is_default' => isset($_POST['is_default']) ? 1 : 0,
        'auto_complete_on_paid' => isset($_POST['auto_complete_on_paid']) ? 1 : 0,
        'warn_after_seconds' => (int) ($_POST['warn_after_seconds'] ?? 360),
        'late_after_seconds' => (int) ($_POST['late_after_seconds'] ?? 720),
    ]);

    try {
        (new SecurityAuditLogger())->record($conn, 'kds.station.save', [
            'target_type' => 'kds_station',
            'target_id' => $stationId,
            'metadata' => ['name' => (string) ($_POST['name'] ?? '')],
        ]);
    } catch (Throwable $auditException) {
        error_log('KDS station audit skipped: ' . $auditException->getMessage());
    }

    header('Location: ../kds_settings.php?ok=station_saved');
} catch (Throwable $e) {
    header('Location: ../kds_settings.php?error=' . urlencode($e->getMessage()));
}
exit;
