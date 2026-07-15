<?php

/**
 * After a shift close, successful POS unlock must clear the closed-session lockout
 * so the unlock PIN does not silently refresh the lock screen.
 */

$pinLogin = (string) file_get_contents(__DIR__ . '/../../ajax/pos_pin_login.php');
$authGuard = (string) file_get_contents(__DIR__ . '/../../includes/auth_guard.php');
$mainAuth = (string) file_get_contents(__DIR__ . '/../../classes/Security/MainAuthenticationService.php');
$entry = (string) file_get_contents(__DIR__ . '/../../classes/Pos/Service/ShiftEntryService.php');
$loginScreen = (string) file_get_contents(__DIR__ . '/../../includes/pos_login_screen.php');

function posUnlockClosedFlagAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

posUnlockClosedFlagAssert($pinLogin !== '' && $authGuard !== '', 'sources readable');

posUnlockClosedFlagAssert(
    strpos($pinLogin, "unset(\$_SESSION['pos_shift_closed_for_session'])") !== false,
    'pos_pin_login must clear pos_shift_closed_for_session after successful unlock'
);

posUnlockClosedFlagAssert(
    strpos($authGuard, "unset(\$_SESSION['pos_shift_closed_for_session'])") !== false
        && strpos($authGuard, 'function posmain_clear_pos_shift_session') !== false,
    'clear_pos_shift_session must be able to unset the closed flag'
);

posUnlockClosedFlagAssert(
    strpos($mainAuth, "'pos_shift_closed_for_session'") !== false,
    'lock/logout identity clear must drop the closed flag'
);

posUnlockClosedFlagAssert(
    strpos($entry, "unset(\$_SESSION['posmain_shift_blocking'], \$_SESSION['pos_shift_closed_for_session'])") !== false
        || (
            strpos($entry, "STATE_OPEN_COUNT_PENDING") !== false
            && strpos($entry, "pos_shift_closed_for_session") !== false
        ),
    'open_count_pending entry must clear closed lockout'
);

posUnlockClosedFlagAssert(
    strpos($loginScreen, 'ppm-page--pos-unlock') !== false,
    'POS unlock screen must use distinct visual theme class'
);

require_once __DIR__ . '/../../includes/auth_guard.php';

$_SESSION = [
    'userid' => 7,
    'login' => 'manager',
    'pos_authenticated' => true,
    'pos_user_id' => 7,
    'pos_shift_closed_for_session' => true,
];
posUnlockClosedFlagAssert(
    !auth_guard_is_pos_barcode_unlocked($_SESSION),
    'closed flag still blocks unlock while set'
);

posmain_clear_pos_shift_session(false);
$_SESSION['pos_authenticated'] = true;
$_SESSION['pos_user_id'] = 7;
posUnlockClosedFlagAssert(
    empty($_SESSION['pos_shift_closed_for_session']),
    'clear(false) removes closed flag'
);
posUnlockClosedFlagAssert(
    auth_guard_is_pos_barcode_unlocked($_SESSION),
    'unlock succeeds after closed flag is cleared'
);

echo "pos_unlock_clears_closed_flag_contract_test: OK\n";
