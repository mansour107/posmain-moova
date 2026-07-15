<?php

/**
 * Contract: successful takeover must auto-open the manager shift from the
 * close-count cash (selling_ready) — no second open-count of the same drawer.
 */

$endpoint = (string) file_get_contents(__DIR__ . '/../../do/do_takeover_drawer_session.php');
$barcode = (string) file_get_contents(__DIR__ . '/../../pos_barcode.php');
$supermarket = (string) file_get_contents(__DIR__ . '/../../pos_supermarket.php');
$recovery = (string) file_get_contents(__DIR__ . '/../../elements/pos/shift_recovery_overlay.php');
$wizard = (string) file_get_contents(__DIR__ . '/../../js/pos_shift_count_wizard.js');
$countService = (string) file_get_contents(__DIR__ . '/../../classes/Pos/Service/ShiftCountService.php');

function takeoverEntryAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

takeoverEntryAssert($endpoint !== '', 'takeover endpoint readable');
takeoverEntryAssert(
    strpos($endpoint, "unset(\$_SESSION['posmain_shift_blocking'])") !== false,
    'takeover must unset posmain_shift_blocking'
);
takeoverEntryAssert(
    strpos($endpoint, 'openFromTakeoverCountedCash') !== false,
    'takeover must auto-open from close-count cash'
);
takeoverEntryAssert(
    strpos($endpoint, "posmain_shift_entry_state'] = 'selling_ready'") !== false,
    'takeover must set selling_ready entry state'
);
takeoverEntryAssert(
    strpos($endpoint, "'redirect' => 'pos_barcode.php'") !== false
        && strpos($endpoint, 'shift=open_count') === false,
    'takeover response must redirect to sellable POS, not open_count'
);

takeoverEntryAssert(
    strpos($countService, 'function openFromTakeoverCountedCash') !== false,
    'ShiftCountService must expose openFromTakeoverCountedCash'
);

takeoverEntryAssert(
    strpos($barcode, 'pos_pending_takeover') !== false
        && strpos($barcode, 'open_count_pending') !== false,
    'pos_barcode must heal stale branch_blocked when pending takeover exists'
);
takeoverEntryAssert(
    strpos($supermarket, 'pos_pending_takeover') !== false,
    'pos_supermarket must heal stale branch_blocked when pending takeover exists'
);

takeoverEntryAssert(
    strpos($recovery, 'var redirect = (res.j.data && res.j.data.redirect)') !== false
        || strpos($recovery, 'window.location.href = redirect') !== false,
    'recovery overlay must follow takeover redirect (sellable POS)'
);
takeoverEntryAssert(
    strpos($wizard, "window.location.href = 'pos_barcode.php'") !== false
        && strpos($wizard, 'shift=open_count') === false,
    'wizard takeover success must land on sellable POS'
);

// Simulate the barcode heal path used after takeover reload (legacy pending).
$_SESSION = [
    'pos_pending_takeover' => [
        'preceding_session_id' => 99,
        'incoming_user_id' => 2,
    ],
    'posmain_shift_entry_state' => 'branch_blocked',
    'posmain_shift_entry_message' => 'stale',
    'posmain_shift_blocking' => ['id' => 99],
];

$posmainShiftEntryState = (string) ($_SESSION['posmain_shift_entry_state'] ?? '');
if (!empty($_SESSION['pos_pending_takeover'])
    && in_array($posmainShiftEntryState, ['branch_blocked', ''], true)
) {
    $_SESSION['posmain_shift_entry_state'] = 'open_count_pending';
    $_SESSION['posmain_shift_entry_message'] = '';
    unset($_SESSION['posmain_shift_blocking']);
    $_SESSION['pos_unlocked_pending_open'] = true;
    $posmainShiftEntryState = 'open_count_pending';
}

$posmainShiftBlocked = in_array($posmainShiftEntryState, [
    'branch_blocked',
    'register_transfer_required',
    'stale_shift',
    'permission_denied',
    'entry_error',
], true);

takeoverEntryAssert($posmainShiftEntryState === 'open_count_pending', 'healed state must be open_count_pending');
takeoverEntryAssert(!$posmainShiftBlocked, 'healed state must not keep recovery overlay');
takeoverEntryAssert(!isset($_SESSION['posmain_shift_blocking']), 'blocking payload must be cleared');
takeoverEntryAssert(!empty($_SESSION['pos_unlocked_pending_open']), 'pending open flag must be set');

echo "takeover_entry_state_contract_test: OK\n";
