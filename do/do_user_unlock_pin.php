<?php

require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_user_unlock_pin.php');

require_once __DIR__ . '/../classes/Security/PinService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';

$id = (int) ($_POST['user_id'] ?? $_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: ../team.php?tab=staff');
    exit;
}

(new PinService())->clearUserFailures($conn, $id);

(new SecurityAuditLogger())->record($conn, 'user_pin_unlocked', [
    'target_type' => 'user',
    'target_id' => $id,
]);

header('Location: ../team.php?tab=staff&pin_unlocked=1');
exit;
