<?php

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/moova_integration.php');
$save = file_get_contents($root . '/ajax/moova_save_integration.php');
$service = file_get_contents($root . '/classes/Moova/MoovaPosMenuReconcileService.php');
$disconnect = file_get_contents($root . '/ajax/moova_disconnect_integration.php');
$confirm = file_get_contents($root . '/ajax/moova_confirm_order.php');
$change = file_get_contents($root . '/ajax/moova_change_order.php');

moovaAdminAssertContains("require_once('includes/csrf.php')", $page, 'Moova page should load central CSRF helper');
moovaAdminAssertContains("require_once('includes/auth_guard.php')", $page, 'Moova page should load central auth guard helper');
moovaAdminAssertContains("SecurityAuditLogger", $page, 'Moova page should use audit logger for token views');
moovaAdminAssertContains("csrf_token('moova_integration')", $page, 'Moova page should use central CSRF namespace');
moovaAdminAssertContains("auth_guard_has_permission('moova.manage'", $page, 'Moova page should honor named Moova permission bridge');
moovaAdminAssertContains("moova_device_token_viewed", $page, 'Moova page should audit full device token views');

foreach (['save' => $save, 'disconnect' => $disconnect] as $name => $source) {
    moovaAdminAssertContains("../includes/auth_guard.php", $source, "{$name} endpoint should load auth guard");
    moovaAdminAssertContains("../includes/csrf.php", $source, "{$name} endpoint should load CSRF helper");
    moovaAdminAssertContains("SecurityAuditLogger", $source, "{$name} endpoint should use audit logger");
    moovaAdminAssertContains("current_user_id()", $source, "{$name} endpoint should use central current_user_id");
    moovaAdminAssertContains("verify_csrf_token($", $source, "{$name} endpoint should verify central CSRF token");
    moovaAdminAssertContains("'moova_integration'", $source, "{$name} endpoint should use moova_integration CSRF namespace");
    moovaAdminAssertContains("auth_guard_has_permission('moova.manage'", $source, "{$name} endpoint should check named Moova permission bridge");
    moovaAdminAssertContains("permission_denied", $source, "{$name} endpoint should audit denied requests");
}

moovaAdminAssertContains("moova_integration_saved", $save, 'save endpoint should audit successful save');
moovaAdminAssertContains("moova_integration_trigger_menu_sync_after_save", $save, 'save endpoint should trigger automatic menu sync after token attach');
moovaAdminAssertContains("posmain_integration_save", $service, 'save-triggered reconcile should identify the POS integration save source');
moovaAdminAssertContains("X-Pos-Widget-Origin", $service, 'save-triggered reconcile should expose the POS public origin to Moova');
moovaAdminAssertContains("MoovaPosMenuReconcileService", $save, 'save endpoint should reconcile Moova menu through dedicated service');
moovaAdminAssertContains("moova_menu_sync_payload.php", $service, 'save endpoint reconcile service should target full POS menu payload');
moovaAdminAssertContains("menu-endpoints/register", $service, 'save endpoint should register POS menu endpoints with Moova');
moovaAdminAssertContains("menu-sync/reconcile", $service, 'save endpoint should fall back to explicit menu reconcile route');
moovaAdminAssertContains("menuSyncMode", $service, 'save endpoint should request full POS-authoritative menu sync');
moovaAdminAssertContains("moova_integration_disconnected", $disconnect, 'disconnect endpoint should audit successful disconnect');

moovaAdminAssertNotContains("verify_csrf_from_post_or_header", $confirm, 'machine Moova confirm should not use browser CSRF');
moovaAdminAssertNotContains("verify_csrf_from_post_or_header", $change, 'machine Moova change should not use browser CSRF');

echo "moova-admin-security-ok\n";

function moovaAdminAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function moovaAdminAssertNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message);
    }
}
