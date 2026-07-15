<?php

$endpoint = file_get_contents(__DIR__ . '/../../do/do_record_shift_payin.php');
if ($endpoint === false) {
    throw new RuntimeException('Unable to read do_record_shift_payin.php');
}

shiftPayinContractAssert(strpos($endpoint, 'ShiftSessionService') !== false, 'payin endpoint should use ShiftSessionService');
shiftPayinContractAssert(strpos($endpoint, 'recordShiftPayIn') !== false, 'payin endpoint should call recordShiftPayIn');
shiftPayinContractAssert(strpos($endpoint, "require_csrf('shift_payin')") !== false, 'payin endpoint should require shift_payin CSRF');
shiftPayinContractAssert(strpos($endpoint, 'auth_guard_is_pos_barcode_unlocked') !== false, 'payin endpoint should require POS unlock');
shiftPayinContractAssert(strpos($endpoint, 'pos.drawer.payin') !== false, 'payin endpoint should reference pos.drawer.payin permission');
shiftPayinContractAssert(strpos($endpoint, 'validateApprovedPermissionOverride') !== false, 'payin override must validate by permission_key');
shiftPayinContractAssert(strpos($endpoint, "requireApprovedIfNeeded") === false, 'payin must not use drawer_session requireApprovedIfNeeded (scope mismatch with PIN override)');
shiftPayinContractAssert(strpos($endpoint, 'ManagerApprovalRequiredException') !== false, 'payin should request manager approval when permission missing');

shiftPayinContractAssert(strpos($endpoint, 'pos_shift_handover_idempotent') !== false, 'payin endpoint should wrap with handover idempotency');
shiftPayinContractAssert(strpos($endpoint, 'pos.shift.payin') !== false, 'payin endpoint should use pos.shift.payin scope');

$manifest = file_get_contents(__DIR__ . '/../../config/rbac_route_manifest.php');
shiftPayinContractAssert(strpos($manifest, 'do/do_record_shift_payin.php') !== false, 'manifest should include payin route');
shiftPayinContractAssert(strpos($manifest, 'shift_payin') !== false, 'manifest should include shift_payin CSRF lane');

$preview = file_get_contents(__DIR__ . '/../../do/get_shift_preview.php');
shiftPayinContractAssert(strpos($preview, 'shiftPayInSummary') !== false, 'shift preview should expose payin summary');
shiftPayinContractAssert(strpos($preview, "'payins'") !== false, 'shift preview should return payins key');
shiftPayinContractAssert(strpos($preview, 'pos.drawer.payin') !== false, 'shift preview should allow payin permission');

$service = file_get_contents(__DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php');
shiftPayinContractAssert(strpos($service, 'recordShiftPayIn') !== false, 'ShiftSessionService should expose recordShiftPayIn');
shiftPayinContractAssert(strpos($service, 'drawerCashMovementSummary') !== false, 'ShiftSessionService should expose drawerCashMovementSummary');

$ledger = file_get_contents(__DIR__ . '/../../classes/Pos/Service/DrawerLedgerPostingService.php');
shiftPayinContractAssert(strpos($ledger, 'postPayIn') !== false, 'DrawerLedgerPostingService should expose postPayIn');
shiftPayinContractAssert(strpos($ledger, 'postPayOut') !== false, 'DrawerLedgerPostingService should expose postPayOut');

$modal = file_get_contents(__DIR__ . '/../../elements/pos/shift_expense_modal.php');
shiftPayinContractAssert(strpos($modal, 'shiftCashPayinPane') !== false, 'modal should include payin tab');
shiftPayinContractAssert(strpos($modal, 'shift_payin_amount') !== false, 'modal should include payin amount field');
shiftPayinContractAssert(strpos($modal, 'POSMAIN_SHIFT_PAYIN_CSRF_TOKEN') !== false, 'modal should expose payin CSRF token');

$js = file_get_contents(__DIR__ . '/../../js/pos_shift_expenses.js');
shiftPayinContractAssert(strpos($js, 'do_record_shift_payin.php') !== false, 'shift cash JS should post payins');
shiftPayinContractAssert(strpos($js, 'pos.drawer.payin') !== false, 'shift cash JS should request payin override');
shiftPayinContractAssert(strpos($js, 'idempotency_key') !== false, 'shift cash JS should send idempotency_key');
shiftPayinContractAssert(strpos($js, 'createShiftCashIdempotencyKey') !== false, 'shift cash JS should create mid-shift keys');
shiftPayinContractAssert(strpos($js, 'getShiftCashIdempotencyKey') !== false, 'shift cash JS should reuse pending keys on retry');
shiftPayinContractAssert(strpos($js, 'clearShiftCashIdempotencyKey') !== false, 'shift cash JS should clear keys after success');
shiftPayinContractAssert(strpos($js, 'clearAllShiftCashIdempotencyKeys') !== false, 'shift cash JS should reset keys when modal opens');

echo "shift-payin-contract-ok\n";

function shiftPayinContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
