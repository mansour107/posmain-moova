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
    $map = $_POST['cat_station'] ?? [];
    $count = 0;
    if (is_array($map)) {
        foreach ($map as $groupId => $stationId) {
            $service->setCategoryStation($conn, (int) $groupId, (int) $stationId);
            $count++;
        }
    }

    try {
        (new SecurityAuditLogger())->record($conn, 'kds.category_map.save', [
            'target_type' => 'kds_category_map',
            'metadata' => ['mapped' => $count],
        ]);
    } catch (Throwable $auditException) {
        error_log('KDS category map audit skipped: ' . $auditException->getMessage());
    }

    header('Location: ../kds_settings.php?ok=routing_saved#routing');
} catch (Throwable $e) {
    header('Location: ../kds_settings.php?error=' . urlencode($e->getMessage()) . '#routing');
}
exit;
