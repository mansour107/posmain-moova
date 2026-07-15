<?php

require_once __DIR__ . '/../../includes/auth_guard.php';
require_once __DIR__ . '/../../includes/pos_shift_guard.php';

posShiftSessionGateResetSession();

$loggedInOnly = [
    'userid' => 42,
    'login' => 'cashier',
    'usrole' => 3,
];
posShiftSessionGateAssertTrue(auth_guard_is_pos_authenticated($loggedInOnly), 'legacy helper still treats ERP login as POS authenticated');
posShiftSessionGateAssertFalse(auth_guard_is_pos_write_authorized($loggedInOnly), 'writes require barcode unlock or waiter session');

$unlocked = $loggedInOnly;
$unlocked['pos_authenticated'] = true;
$unlocked['pos_user_id'] = 42;
posShiftSessionGateAssertTrue(auth_guard_is_pos_barcode_unlocked($unlocked), 'barcode unlock authorizes writes');
posShiftSessionGateAssertTrue(auth_guard_is_pos_write_authorized($unlocked), 'write guard accepts barcode unlock');

$closedUnlock = $unlocked;
$closedUnlock['pos_shift_closed_for_session'] = true;
posShiftSessionGateAssertFalse(auth_guard_is_pos_barcode_unlocked($closedUnlock), 'closed shift flag blocks barcode unlock');

$waiterSession = [
    'user_logged_in' => true,
    'is_waiter' => 1,
    'waiter_id' => 9,
];
posShiftSessionGateAssertTrue(auth_guard_is_pos_write_authorized($waiterSession), 'waiter legacy session still authorizes writes');

$closedWaiter = $waiterSession;
$closedWaiter['pos_shift_closed_for_session'] = true;
posShiftSessionGateAssertFalse(auth_guard_is_pos_write_authorized($closedWaiter), 'closed shift flag blocks waiter writes (no waiter bypass)');

// Shift guard: the per-session close flag blocks writes regardless of connection.
posShiftSessionGateAssertTrue(posmain_pos_shift_write_blocked($closedWaiter, null), 'shift guard blocks a session flagged closed');
posShiftSessionGateAssertFalse(posmain_pos_shift_write_blocked($waiterSession, null), 'shift guard allows a normal waiter session when no shift subsystem is available');
posShiftSessionGateAssertFalse(posmain_pos_shift_write_blocked($unlocked, null), 'shift guard allows an unlocked cashier session without drawer sessions');

$_SESSION = $unlocked;
posmain_begin_pos_shift_session(42);
posShiftSessionGateAssertSame(42, (int) $_SESSION['pos_user_id'], 'begin shift session stores user id');
posShiftSessionGateAssertNotEmpty($_SESSION['pos_shift_session_token'] ?? '', 'begin shift session stores token');
posShiftSessionGateAssertTrue(empty($_SESSION['pos_shift_closed_for_session']), 'begin shift session clears closed flag');

posmain_clear_pos_shift_session(true);
posShiftSessionGateAssertTrue(!empty($_SESSION['pos_shift_closed_for_session']), 'clear with markClosed sets closed flag');
posShiftSessionGateAssertTrue(empty($_SESSION['pos_authenticated']), 'clear removes barcode auth');

$_SESSION['pos_authenticated'] = true;
$_SESSION['pos_user_id'] = 42;
posmain_clear_pos_shift_session(false);
posShiftSessionGateAssertTrue(empty($_SESSION['pos_shift_closed_for_session']), 'clear without markClosed drops closed flag');

echo "pos-shift-session-gate-ok\n";

function posShiftSessionGateResetSession(): void
{
    $_SESSION = [];
}

function posShiftSessionGateAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function posShiftSessionGateAssertFalse(bool $condition, string $message): void
{
    posShiftSessionGateAssertTrue(!$condition, $message);
}

function posShiftSessionGateAssertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function posShiftSessionGateAssertNotEmpty($value, string $message): void
{
    if ($value === '' || $value === null) {
        throw new RuntimeException($message);
    }
}
