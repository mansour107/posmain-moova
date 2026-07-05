<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

$permissions = auth_guard_effective_permissions($conn, true);

echo json_encode([
    'success' => true,
    'permissions' => $permissions,
    'user_id' => current_user_id(),
    'role_id' => current_user_role(),
], JSON_UNESCAPED_UNICODE);
