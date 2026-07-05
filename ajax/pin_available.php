<?php

require_once __DIR__ . '/../includes/write_bootstrap.php';
require_once __DIR__ . '/../classes/Security/PinService.php';
require_once __DIR__ . '/../classes/Security/PermissionService.php';

header('Content-Type: application/json; charset=utf-8');

$pinCheck = trim((string) ($_GET['pin'] ?? $_POST['pin'] ?? ''));

if ($pinCheck !== '') {
    require_permission('users.manage', $conn);

    $pinService = new PinService();
    $excludeUserId = (int) ($_GET['exclude_user_id'] ?? $_POST['exclude_user_id'] ?? 0);
    $available = false;

    try {
        posmain_pin_secret();
        $pinService->validatePinFormat($pinCheck);
        $existing = $pinService->findUserByPin($conn, $pinCheck);
        $available = !$existing || ($excludeUserId > 0 && (int) ($existing['id'] ?? 0) === $excludeUserId);
    } catch (Throwable $exception) {
        $available = false;
    }

    echo json_encode(['available' => $available], JSON_UNESCAPED_UNICODE);
    exit;
}

require_login();

$pinService = new PinService();
$anyPin = $pinService->anyActiveUserHasPin($conn);
$autolock = 90;

try {
    $autolock = (new PermissionService($conn))->posAutolockSeconds();
} catch (Throwable $ignored) {
}

$secretConfigured = true;
try {
    posmain_pin_secret();
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'PIN_SECRET_MISSING') {
        $secretConfigured = false;
    }
}

echo json_encode([
    'success' => true,
    'pin_mode' => $anyPin && $secretConfigured,
    'any_user_has_pin' => $anyPin,
    'pin_secret_configured' => $secretConfigured,
    'pos_autolock_seconds' => $autolock,
    'legacy_password_fallback' => !$anyPin,
], JSON_UNESCAPED_UNICODE);
