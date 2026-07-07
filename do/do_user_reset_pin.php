<?php

require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_user_reset_pin.php');

require_once __DIR__ . '/../classes/Security/PinService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Security/UserLifecycleGuardService.php';

$id = (int) ($_POST['user_id'] ?? 0);
if ($id < 1) {
    header('Location: ../team.php?tab=staff');
    exit;
}

$pinService = new PinService();
$newPin = trim((string) ($_POST['pin'] ?? ''));

try {
    if ($newPin === '') {
        $newPin = $pinService->generateAvailablePin($conn, $id);
    }
    $pinService->setPinForUser($conn, $id, $newPin);
} catch (Throwable $exception) {
    header('Location: ../team.php?tab=staff&user=' . $id . '&pin_error=' . urlencode($exception->getMessage()));
    exit;
}

(new SecurityAuditLogger())->record($conn, 'user_pin_reset', [
    'target_type' => 'user',
    'target_id' => $id,
]);

$_SESSION['posmain_one_time_pin_reveal'] = [
    'user_id' => $id,
    'pin' => $newPin,
    'expires' => time() + 120,
];

header('Location: ../team.php?tab=staff&user=' . $id . '&pin_reset=1');
exit;
