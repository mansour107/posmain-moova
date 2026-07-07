<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doadd_role.php');

require_once __DIR__ . '/../classes/Security/TeamHubService.php';
require_once __DIR__ . '/../classes/Security/TeamHubMutationService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../team.php?tab=roles&panel=new_role');
    exit;
}

$rollname = trim((string) ($_POST['rollname'] ?? ''));
if ($rollname === '') {
    header('Location: ../team.php?tab=roles&panel=new_role&error=ROLLNAME_REQUIRED');
    exit;
}

$hub = new TeamHubService($conn);
$mutations = new TeamHubMutationService($conn, $hub);

try {
    $result = $mutations->createRole($_POST);
    $roleId = (int) ($result['role_id'] ?? 0);
    header('Location: ../team.php?tab=roles' . ($roleId > 0 ? '&role=' . $roleId : ''));
} catch (Throwable) {
    header('Location: ../team.php?tab=roles&panel=new_role&error=CREATE_FAILED');
}
exit;
