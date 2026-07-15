<?php

/**
 * Contract: mid-shift cash overrides from ajax/pos_override_auth.php default to
 * target_type=pos_action. Endpoints must validate by permission_key, not by
 * drawer_session action/target scope (that mismatch surfaces as
 * MANAGER_APPROVAL_SCOPE_MISMATCH after a successful manager PIN).
 */

function shiftCashOverrideScopeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$payin = file_get_contents(__DIR__ . '/../../do/do_record_shift_payin.php');
$safeDrop = file_get_contents(__DIR__ . '/../../do/do_record_shift_safe_drop.php');
$noSale = file_get_contents(__DIR__ . '/../../ajax/pos_drawer_no_sale.php');
$shiftService = file_get_contents(__DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php');
$overrideJs = file_get_contents(__DIR__ . '/../../js/pos_barcode.js');
$expensesJs = file_get_contents(__DIR__ . '/../../js/pos_shift_expenses.js');

shiftCashOverrideScopeAssert($payin !== false && $safeDrop !== false && $noSale !== false, 'source files readable');
shiftCashOverrideScopeAssert($shiftService !== false && $overrideJs !== false && $expensesJs !== false, 'service/js readable');

shiftCashOverrideScopeAssert(
    strpos($overrideJs, "target_type: options.target_type || 'pos_action'") !== false,
    'PIN override UI defaults to pos_action target_type'
);

foreach ([
    'do_record_shift_payin.php' => $payin,
    'do_record_shift_safe_drop.php' => $safeDrop,
    'pos_drawer_no_sale.php' => $noSale,
] as $label => $source) {
    shiftCashOverrideScopeAssert(
        strpos($source, 'validateApprovedPermissionOverride') !== false,
        "{$label} should validate permission-key overrides"
    );
    shiftCashOverrideScopeAssert(
        strpos($source, "requireApprovedIfNeeded") === false,
        "{$label} must not assert drawer_session scope against PIN overrides"
    );
}

shiftCashOverrideScopeAssert(
    strpos($shiftService, 'function requirePayoutApprovalIfNeeded') !== false,
    'ShiftSessionService should keep payout limit gate'
);
shiftCashOverrideScopeAssert(
    preg_match(
        '/function requirePayoutApprovalIfNeeded.*?validateApprovedPermissionOverride/s',
        $shiftService
    ) === 1,
    'payout over-limit gate should validate permission-key overrides'
);
shiftCashOverrideScopeAssert(
    preg_match(
        '/function requirePayoutApprovalIfNeeded.*?requireApprovedIfNeeded/s',
        $shiftService
    ) !== 1,
    'payout over-limit gate must not use drawer_session requireApprovedIfNeeded'
);

shiftCashOverrideScopeAssert(
    strpos($expensesJs, "requestOverrideIfNeeded('pos.drawer.payin'") !== false,
    'expense modal should request payin override'
);
shiftCashOverrideScopeAssert(
    strpos($expensesJs, "requestOverrideIfNeeded('pos.drawer.safe_drop'") !== false,
    'expense modal should request safe-drop override'
);
shiftCashOverrideScopeAssert(
    strpos($expensesJs, "requestOverrideIfNeeded('pos.payout.over_limit'") !== false,
    'expense modal should request payout over-limit override'
);

echo "shift-cash-override-scope-contract-ok\n";
