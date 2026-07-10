<?php

$root = dirname(__DIR__, 2);

$checks = [
    'config/app_config.php' => ['posmain_resolve_main_auth_mode', 'posmain_is_pin_main_auth'],
    'classes/Security/MainAuthenticationService.php' => ['authenticateWithPin', 'lockToLoginScreen', 'sessionAuthVersionValid'],
    'classes/Security/LocalSecurityBootstrapService.php' => ['BOOTSTRAP', '0000', 'completed'],
    'classes/Security/PostLoginRouteService.php' => ['WORKSPACE_DASHBOARD', 'WORKSPACE_POS', 'WORKSPACE_KDS', 'WORKSPACE_CHOOSER'],
    'classes/Security/PinService.php' => ['PIN_LENGTH = 4', 'PIN_BLACKLISTED', 'PIN_REVEAL_DISABLED', 'auth_version'],
    'classes/Pos/Service/PosRegisterService.php' => ['COOKIE_NAME', 'requirePairedRegister', 'pairing_token_hash'],
    'classes/Pos/Service/ShiftEntryService.php' => [
        'STATE_SELLING_READY',
        'STATE_OPEN_COUNT_PENDING',
        'STATE_REGISTER_TRANSFER_REQUIRED',
        'STATE_STALE_SHIFT',
    ],
    'classes/Pos/Service/DrawerSessionService.php' => ['open_register_lock', 'open_user_lock', 'transferOpenSessionRegister'],
    'ajax/main_pin_login.php' => ['main_pin', 'MainAuthenticationService'],
    'includes/pos_main_pin_entry.php' => ['posmain_apply_main_pin_pos_entry', 'ShiftEntryService'],
    'includes/pin_pad_fragment.php' => ['ppm-dot', 'data-digits'],
    'js/pin_pad.js' => ['PIN_RATE_LIMITED', 'OFFLINE', 'PosmainPinPad'],
    'scripts/recover_owner_pin.php' => ['CLI only', 'must_change', 'owner_pin_recovered_cli'],
    'register_pair.php' => ['ربط هذا الجهاز بصندوق', 'register_pair'],
    'elements/pos/shift_recovery_overlay.php' => ['register_transfer_required', 'do_transfer_drawer_register'],
];

foreach ($checks as $relative => $needles) {
    $path = $root . '/' . $relative;
    localPinAssert(is_file($path), 'missing file: ' . $relative);
    $source = (string) file_get_contents($path);
    foreach ($needles as $needle) {
        localPinAssert(strpos($source, $needle) !== false, $relative . ' must contain ' . $needle);
    }
}

$pageManifest = require $root . '/config/rbac_page_manifest.php';
foreach ([
    'pos_barcode.php',
    'pos_supermarket.php',
    'pos_clothes.php',
    'pos_tables.php',
    'kds.php',
    'kds_station.php',
    'register_pair.php',
    'change_pin.php',
    'workspace.php',
    'no_access.php',
] as $page) {
    localPinAssert(isset($pageManifest[$page]), 'page manifest missing ' . $page);
}
localPinAssert(
    ($pageManifest['kds_station.php']['permission'] ?? '') === 'kds.view',
    'kds_station.php must allow kitchen view access'
);

$routeManifest = require $root . '/config/rbac_route_manifest.php';
localPinAssert(!empty($routeManifest['ajax/main_pin_login.php']['public']), 'main_pin_login must be public');
localPinAssert(
    ($routeManifest['do/kds_ticket_action.php']['csrf'] ?? '') === 'kds',
    'kds_ticket_action csrf must match runtime'
);
localPinAssert(
    !empty($routeManifest['do/doadd_invoice_waiter.php']['quarantined']),
    'legacy waiter invoice must be quarantined'
);
$waiterSource = (string) file_get_contents($root . '/do/doadd_invoice_waiter.php');
localPinAssert(
    strpos($waiterSource, 'http_response_code(410)') !== false
        && strpos($waiterSource, 'LEGACY_WAITER_AUTH_DISABLED') !== false,
    'legacy waiter invoice must return HTTP 410 / LEGACY_WAITER_AUTH_DISABLED'
);

$docker = (string) file_get_contents($root . '/docker-compose.posmain-test.yml');
localPinAssert(
    strpos($docker, 'POSMAIN_MAIN_AUTH_MODE') !== false && preg_match('/POSMAIN_MAIN_AUTH_MODE[=:].*pin/', $docker) === 1,
    'local docker must set PIN main auth'
);

echo "local-pin-auth-contract-ok\n";

function localPinAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
