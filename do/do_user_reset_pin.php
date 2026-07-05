<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/do_user_reset_pin.php');

require_once __DIR__ . '/../classes/Security/PinService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Security/UserLifecycleGuardService.php';

$id = (int) ($_POST['user_id'] ?? 0);
if ($id < 1) {
    header('Location: ../users.php');
    exit;
}

$pinService = new PinService();
$newPin = trim((string) ($_POST['pin'] ?? ''));

try {
    if ($newPin === '') {
        $newPin = (string) random_int(1000, 9999);
        while (strlen($newPin) < 4 || in_array($newPin, ['1234', '0000', '1111'], true)) {
            $newPin = (string) random_int(1000, 9999);
        }
    }
    $pinService->setPinForUser($conn, $id, $newPin);
} catch (Throwable $exception) {
    header('Location: ../edit_user.php?id=' . $id . '&pin_error=' . urlencode($exception->getMessage()));
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

header('Location: ../edit_user.php?id=' . $id . '&pin_reset=1');
exit;
