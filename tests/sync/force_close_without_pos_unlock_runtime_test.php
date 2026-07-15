<?php

/**
 * Runtime: ERP force-close must not require POS unlock.
 */

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../../includes/auth_guard.php';
require_once __DIR__ . '/../../includes/rbac_route_guard.php';
require_once __DIR__ . '/../../includes/db_bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$_SESSION['userid'] = 1;
$_SESSION['login'] = 'admin';
$_SESSION['usrole'] = 1;
$_SESSION['usty'] = 1;
$_SESSION['posmain_auth_method'] = 'main_pin';
$_SESSION['posmain_auth_version'] = 1;
unset($_SESSION['pos_authenticated'], $_SESSION['pos_user_id'], $_SESSION['pos_acting_user_id']);

$conn = posmain_db_connect();
$manifest = posmain_rbac_route_manifest();
$entry = $manifest['do/do_force_close_drawer.php'] ?? [];

if (($entry['lane'] ?? '') !== 'erp') {
    throw new RuntimeException('expected erp lane');
}
if (!auth_guard_is_logged_in()) {
    throw new RuntimeException('expected logged in');
}
if (auth_guard_is_pos_write_authorized()) {
    throw new RuntimeException('fixture must NOT be POS-unlocked');
}
if (!rbac_guard_route_permissions_satisfied($entry, 'erp', $conn)) {
    throw new RuntimeException('admin must satisfy force_close permission on ERP lane');
}

echo "force-close-without-pos-unlock-runtime-ok\n";
