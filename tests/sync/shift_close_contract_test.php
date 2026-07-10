<?php

$closeShiftSource = file_get_contents(__DIR__ . '/../../close_shift.php');
if ($closeShiftSource === false) {
    throw new RuntimeException('Unable to read close_shift.php');
}

shiftCloseContractAssert(strpos($closeShiftSource, 'ShiftSessionService') !== false, 'close_shift should delegate to ShiftSessionService');
shiftCloseContractAssert(strpos($closeShiftSource, 'closeSimpleShift') !== false, 'close_shift should call closeSimpleShift');
shiftCloseContractAssert(strpos($closeShiftSource, 'require_csrf') !== false, 'close_shift should keep CSRF guard');
shiftCloseContractAssert(strpos($closeShiftSource, 'require_pos_authenticated') !== false, 'close_shift should require POS auth');
shiftCloseContractAssert(strpos($closeShiftSource, 'SHIFT_ALREADY_CLOSED') !== false, 'close_shift should handle duplicate close');

$closeServiceSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/ShiftCloseService.php');
if ($closeServiceSource === false) {
    throw new RuntimeException('Unable to read ShiftCloseService.php');
}
shiftCloseContractAssert(strpos($closeServiceSource, "shift_token:") === false, 'ShiftCloseService must not append shift_token into info notes');
shiftCloseContractAssert(strpos($closeServiceSource, 'shift_session_token') !== false, 'ShiftCloseService should store session token in json_details');
shiftCloseContractAssert(strpos($closeServiceSource, 'truncateClosedOrderInfo') !== false, 'ShiftCloseService should bound closed_orders.info');

$previewSource = file_get_contents(__DIR__ . '/../../do/get_shift_preview.php');
if ($previewSource === false) {
    throw new RuntimeException('Unable to read get_shift_preview.php');
}

shiftCloseContractAssert(strpos($previewSource, 'currentDrawerSession') !== false, 'shift preview should scope to drawer session');
shiftCloseContractAssert(strpos($previewSource, 'pos_acting_user_id') !== false, 'shift preview should scope to acting cashier');
shiftCloseContractAssert(strpos($previewSource, 'shift_opened_at') !== false, 'shift preview should pass shift_opened_at to ShiftReport');

$barcodeSource = file_get_contents(__DIR__ . '/../../pos_barcode.php');
shiftCloseContractAssert(strpos($barcodeSource, 'openForCashier') !== false, 'pos_barcode unlock should open drawer shift');

echo "shift-close-contract-ok\n";

function shiftCloseContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
