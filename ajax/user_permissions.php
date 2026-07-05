<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../classes/Security/UserPermissionGrantService.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_or_permission('users.manage', $conn);

$userId = (int) ($_GET['user_id'] ?? 0);
if ($userId < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'code' => 'USER_ID_REQUIRED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$grantService = new UserPermissionGrantService();
$overrides = $grantService->activeOverridesForUser($conn, $userId);

echo json_encode([
    'success' => true,
    'user_id' => $userId,
    'overrides' => $overrides,
    'uses_overrides' => $grantService->userUsesOverrides($conn, $userId),
], JSON_UNESCAPED_UNICODE);
