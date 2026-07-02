<?php

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/kds_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/KdsStationService.php';

if (!function_exists('kds_is_admin')) {
    function kds_is_admin(mysqli $conn): bool
    {
        $roleFlags = auth_guard_current_role_flags($conn);

        return auth_guard_is_admin_session($_SESSION, $roleFlags)
            || auth_guard_has_permission('kds.manage', $conn);
    }
}

if (!function_exists('kds_resolve_station_or_deny')) {
    /**
     * Resolves the requested station and enforces per-station access.
     * Replies with a JSON error and exits when not permitted. Returns the
     * normalized station array on success.
     */
    function kds_resolve_station_or_deny(mysqli $conn, string $stationUuid): array
    {
        $stationUuid = trim($stationUuid);
        $service = new KdsStationService();

        if ($stationUuid === '') {
            kds_json_error('KDS_STATION_REQUIRED', 422);
        }

        $station = $service->getStationByUuid($conn, $stationUuid);
        if (!$station || !$station['is_active']) {
            kds_json_error('KDS_STATION_NOT_FOUND', 404);
        }

        $userId = current_user_id();
        if (!kds_is_admin($conn) && !$service->userCanAccessStation($conn, (int) $station['id'], $userId)) {
            kds_json_error('KDS_STATION_FORBIDDEN', 403);
        }

        return $station;
    }
}

if (!function_exists('kds_require_station_id_access')) {
    /**
     * Enforces that the current user may act on the given station id.
     * Replies with a JSON error and exits when not permitted.
     */
    function kds_require_station_id_access(mysqli $conn, int $stationId): void
    {
        if ($stationId < 1) {
            kds_json_error('KDS_STATION_NOT_FOUND', 404);
        }
        if (kds_is_admin($conn)) {
            return;
        }
        $service = new KdsStationService();
        if (!$service->userCanAccessStation($conn, $stationId, current_user_id())) {
            kds_json_error('KDS_STATION_FORBIDDEN', 403);
        }
    }
}

if (!function_exists('kds_json_error')) {
    function kds_json_error(string $code, int $status): void
    {
        http_response_code($status);
        echo json_encode(['success' => false, 'code' => $code, 'message' => $code], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
