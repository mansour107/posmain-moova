<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../classes/Security/PermissionService.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_or_permission('roles.manage', $conn);

$roleId = (int) ($_GET['role_id'] ?? 0);
if ($roleId < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'code' => 'ROLE_ID_REQUIRED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$service = new PermissionService($conn);
$limits = $service->roleCapabilityLimits($roleId);

echo json_encode([
    'success' => true,
    'role_id' => $roleId,
    'capabilities' => $limits,
    'is_system' => $service->isSystemRole($roleId),
], JSON_UNESCAPED_UNICODE);
