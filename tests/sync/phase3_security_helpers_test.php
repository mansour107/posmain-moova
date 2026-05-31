<?php

require_once __DIR__ . '/../../includes/auth_guard.php';
require_once __DIR__ . '/../../includes/csrf.php';

phase3SecurityResetSession();
phase3SecurityAssertSame(0, current_user_id(), 'guest user id should be zero');
phase3SecurityAssertFalse(auth_guard_is_logged_in(), 'guest should not be logged in');
phase3SecurityAssertFalse(auth_guard_is_pos_authenticated(), 'guest should not be POS authenticated');

$_SESSION['userid'] = 42;
$_SESSION['login'] = 'cashier';
$_SESSION['usrole'] = 3;
phase3SecurityAssertSame(42, current_user_id(), 'current_user_id reads legacy userid');
phase3SecurityAssertSame(3, current_user_role(), 'current_user_role reads legacy usrole');
phase3SecurityAssertTrue(auth_guard_is_logged_in(), 'legacy POS session is logged in');
phase3SecurityAssertTrue(auth_guard_is_pos_authenticated(), 'legacy POS session is POS authenticated');

$cashierFlags = [
    'add_sales' => 1,
    'show_sales' => 1,
    'sid_sales' => 1,
    'add_payment' => 1,
    'show_payment' => 1,
];
phase3SecurityAssertTrue(auth_guard_session_has_permission('pos.sell.takeaway', $cashierFlags, $_SESSION), 'cashier bridge allows takeaway sales');
phase3SecurityAssertTrue(auth_guard_session_has_permission('pos.payment.take', $cashierFlags, $_SESSION), 'cashier bridge allows payments');
phase3SecurityAssertFalse(auth_guard_session_has_permission('users.manage', $cashierFlags, $_SESSION), 'cashier bridge does not allow user management');
phase3SecurityAssertFalse(auth_guard_session_has_permission('system.tools.run', $cashierFlags, $_SESSION), 'cashier bridge does not allow tools');

$inventoryEditorFlags = [
    'edit_stock' => 1,
    'add_stock' => 1,
];
phase3SecurityAssertTrue(auth_guard_session_has_permission('inventory.edit', $inventoryEditorFlags, $_SESSION), 'inventory editor bridge allows ordinary inventory edits');
phase3SecurityAssertFalse(auth_guard_session_has_permission('inventory.approve', $inventoryEditorFlags, $_SESSION), 'ordinary inventory editor bridge does not allow sensitive inventory approvals');

$inventoryApproverFlags = [
    'delete_stock' => 1,
];
phase3SecurityAssertTrue(auth_guard_session_has_permission('inventory.approve', $inventoryApproverFlags, $_SESSION), 'senior inventory bridge allows sensitive inventory approvals');

$adminSession = [
    'userid' => 1,
    'login' => 'admin',
    'usrole' => 1,
];
phase3SecurityAssertTrue(auth_guard_session_has_permission('system.tools.run', [], $adminSession), 'legacy role id 1 is admin');
phase3SecurityAssertTrue(auth_guard_session_has_permission('roles.manage', [], $adminSession), 'admin bypass covers role management');

$waiterSession = [
    'user_logged_in' => true,
    'is_waiter' => 1,
    'waiter_id' => 9,
];
phase3SecurityAssertTrue(auth_guard_is_pos_authenticated($waiterSession), 'waiter session remains POS authenticated');

phase3SecurityResetSession();
$token = csrf_token('phase3-test');
phase3SecurityAssertTrue(strlen($token) >= 64, 'csrf token is high entropy hex');
phase3SecurityAssertSame($token, csrf_token('phase3-test'), 'csrf token is stable per namespace');
phase3SecurityAssertTrue(verify_csrf_token($token, 'phase3-test'), 'csrf token verifies directly');
phase3SecurityAssertFalse(verify_csrf_token('bad-token', 'phase3-test'), 'bad csrf token fails');

$_POST['csrf_token'] = $token;
phase3SecurityAssertTrue(verify_csrf_from_post_or_header('phase3-test'), 'csrf verifies from POST field');
unset($_POST['csrf_token']);
$_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
phase3SecurityAssertTrue(verify_csrf_from_post_or_header('phase3-test'), 'csrf verifies from X-CSRF-Token');
unset($_SERVER['HTTP_X_CSRF_TOKEN']);

$input = csrf_input('phase3-test');
$meta = csrf_meta_tag('phase3-test');
phase3SecurityAssertTrue(strpos($input, 'type="hidden"') !== false, 'csrf input is hidden');
phase3SecurityAssertTrue(strpos($input, htmlspecialchars($token, ENT_QUOTES, 'UTF-8')) !== false, 'csrf input contains token');
phase3SecurityAssertTrue(strpos($meta, 'name="csrf-token"') !== false, 'csrf meta tag uses csrf-token name');

echo "phase3-security-helpers-ok\n";

function phase3SecurityResetSession(): void
{
    $_SESSION = [];
    $_POST = [];
    unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_X_POSMAIN_CSRF_TOKEN']);
}

function phase3SecurityAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function phase3SecurityAssertFalse(bool $condition, string $message): void
{
    phase3SecurityAssertTrue(!$condition, $message);
}

function phase3SecurityAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
