<?php

if (!function_exists('posmain_layout_capabilities')) {
    function posmain_layout_capabilities(?mysqli $conn = null): array
    {
        if (!function_exists('auth_guard_effective_permissions')) {
            require_once __DIR__ . '/auth_guard.php';
        }

        if (!auth_guard_is_logged_in()) {
            return [];
        }

        return auth_guard_effective_permissions($conn, true);
    }
}

if (!function_exists('posmain_acting_user_permissions')) {
    function posmain_acting_user_permissions(mysqli $conn, int $actingUserId): array
    {
        if ($actingUserId < 1) {
            return [];
        }

        $stmt = $conn->prepare('SELECT userrole FROM users WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1');
        $stmt->bind_param('i', $actingUserId);
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$userRow) {
            return [];
        }

        $roleId = (int) ($userRow['userrole'] ?? 0);
        $roleFlags = [];
        if ($roleId > 0) {
            $roleStmt = $conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? LIMIT 1');
            $roleStmt->bind_param('i', $roleId);
            $roleStmt->execute();
            $roleFlags = $roleStmt->get_result()->fetch_assoc() ?: [];
            $roleStmt->close();
        }

        $session = ['userid' => $actingUserId, 'login' => true, 'usrole' => $roleId];
        $permissions = [];
        foreach (array_keys(auth_guard_permission_map()) as $permission) {
            $permissions[$permission] = auth_guard_session_has_permission($permission, $roleFlags, $session, $conn);
        }

        return $permissions;
    }
}

if (!function_exists('posmain_acting_user_limits')) {
    function posmain_acting_user_limits(mysqli $conn, int $actingUserId): array
    {
        if ($actingUserId < 1) {
            return [];
        }

        if (!class_exists('PermissionService', false)) {
            require_once __DIR__ . '/../classes/Security/PermissionService.php';
        }
        if (!class_exists('RolePermissionSyncService', false)) {
            require_once __DIR__ . '/../classes/Security/RolePermissionSyncService.php';
        }

        $svc = new PermissionService($conn);
        $limits = [];
        foreach (RolePermissionSyncService::limitablePermissions() as $permissionKey) {
            try {
                $limits[$permissionKey] = $svc->limit($actingUserId, $permissionKey);
            } catch (InvalidArgumentException $ignored) {
                continue;
            }
        }

        return $limits;
    }
}

if (!function_exists('posmain_render_layout_capabilities_script')) {
    function posmain_render_layout_capabilities_script(?mysqli $conn = null): string
    {
        $capabilities = posmain_layout_capabilities($conn);
        $json = json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<script>window.POSMAIN_CAPABILITIES = ' . $json . ';</script>';
    }
}

if (!function_exists('posmain_render_acting_pos_context_script')) {
    function posmain_render_acting_pos_context_script(mysqli $conn, int $actingUserId): string
    {
        $capabilities = posmain_acting_user_permissions($conn, $actingUserId);
        $limits = posmain_acting_user_limits($conn, $actingUserId);
        $capsJson = json_encode($capabilities, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $limitsJson = json_encode($limits, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return '<script>window.POSMAIN_CAPABILITIES = ' . $capsJson . ';window.POSMAIN_LIMITS = ' . $limitsJson . ';</script>';
    }
}
