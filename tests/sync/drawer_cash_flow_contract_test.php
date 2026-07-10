<?php

$mutation = file_get_contents(__DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php');
if ($mutation === false) {
    throw new RuntimeException('Unable to read PosOrderMutationService.php');
}

drawerCashFlowContractAssert(strpos($mutation, 'recordOrderCashCollected') !== false, 'mutation service should record collected cash');
drawerCashFlowContractAssert(strpos($mutation, 'recordOrderCashPaymentDelta') !== false, 'mutation service should support cash payment deltas');
drawerCashFlowContractAssert(strpos($mutation, 'insertOrderPaymentRecordIfAvailable') !== false, 'mutation service should generalize order payment rows');
drawerCashFlowContractAssert(strpos($mutation, 'insertCashRefundVoucher') !== false, 'mutation service should keep legacy voucher method banned');
drawerCashFlowContractAssert(strpos($mutation, 'LEGACY_CASH_REFUND_FORBIDDEN_USE_CREDIT_NOTE') !== false, 'cash refund vouchers must be forbidden');
drawerCashFlowContractAssert(strpos($mutation, 'FinancialRefundService') !== false, 'paid reversal must use FinancialRefundService');
drawerCashFlowContractAssert(strpos($mutation, 'recordOrderCashCollected($conn, $orderId, $payment[\'cash\']') !== false, 'takeaway create should record cash');
drawerCashFlowContractAssert(strpos($mutation, 'delivery_cash_payment') !== false, 'delivery create should record cash');
drawerCashFlowContractAssert(strpos($mutation, 'order_update_cash_payment') !== false, 'order update should record cash delta');
drawerCashFlowContractAssert(strpos($mutation, 'resolveRefundableCashForOrder') !== false, 'refund path should resolve refundable cash');
drawerCashFlowContractAssert(strpos($mutation, 'FinancialRefundService') !== false, 'refund path should post credit notes');

$drawer = file_get_contents(__DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php');
drawerCashFlowContractAssert(strpos($drawer, 'netCashRecordedForOrder') !== false, 'DrawerSessionService should expose netCashRecordedForOrder');
drawerCashFlowContractAssert(strpos($drawer, 'resolveOpenSessionForUser') !== false, 'DrawerSessionService should resolve open session for cash payments');

drawerCashFlowContractAssert(strpos($drawer, 'recordUnassignedMovement') !== false, 'DrawerSessionService should record unassigned movements');
drawerCashFlowContractAssert(strpos($drawer, 'linkMovementToVoucher') !== false, 'DrawerSessionService should link movements to vouchers by id');
drawerCashFlowContractAssert(strpos($drawer, 'sessionCashBreakdown') !== false, 'DrawerSessionService should expose session cash breakdown');

$payment = file_get_contents(__DIR__ . '/../../classes/Pos/Service/PaymentService.php');
drawerCashFlowContractAssert(strpos($payment, 'recordCashMovementWithFallback') !== false, 'PaymentService should keep cash movement helper');
drawerCashFlowContractAssert(strpos($payment, 'recordUnassignedMovement') === false, 'PaymentService must not fall back to unassigned movements');
drawerCashFlowContractAssert(strpos($payment, 'recordCashRefundMovementForPayment') !== false, 'PaymentService should record refund_cash movements');
drawerCashFlowContractAssert(strpos($payment, 'recordCollectedOrderPayments') !== false, 'PaymentService should expose collection helper');
drawerCashFlowContractAssert(strpos($payment, 'resolveOpenSessionForUser') !== false, 'PaymentService should resolve drawer session for cash payments');

$shiftReport = file_get_contents(__DIR__ . '/../../classes/ShiftReport.php');
drawerCashFlowContractAssert(strpos($shiftReport, 'shiftWindowTimestampExpression') !== false, 'ShiftReport should use coalesced shift timestamps');

$legacyInvoice = file_get_contents(__DIR__ . '/../../do/doadd_invoice.php');
drawerCashFlowContractAssert(strpos($legacyInvoice, 'recordCollectedOrderPayments') !== false, 'legacy invoice path should record drawer cash');

$settleCredit = file_get_contents(__DIR__ . '/../../do/settle_credit.php');
drawerCashFlowContractAssert(strpos($settleCredit, 'recordCollectedOrderPayments') !== false, 'credit settlement should always attempt drawer cash recording');
drawerCashFlowContractAssert(strpos($settleCredit, 'findOpenSession') === false, 'credit settlement should not gate on findOpenSession');

$zReport = file_get_contents(__DIR__ . '/../../z_report.php');
drawerCashFlowContractAssert(strpos($zReport, 'pos_acting_user_id') !== false, 'z_report should use pos_acting_user_id');

$zClose = file_get_contents(__DIR__ . '/../../do_close_shift_z.php');
drawerCashFlowContractAssert(strpos($zClose, 'pos_acting_user_id') !== false, 'do_close_shift_z should use pos_acting_user_id');

echo "drawer-cash-flow-contract-ok\n";

function drawerCashFlowContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
