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
drawerCashFlowContractAssert(strpos($mutation, 'takeaway_cash_payment') !== false, 'takeaway create should record cash');
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
drawerCashFlowContractAssert(strpos($payment, 'requireActiveOverrideForWrite') !== false, 'PaymentService should honor an active approved drawer override for cash writes');

$controller = file_get_contents(__DIR__ . '/../../classes/Pos/Http/PosOrderController.php');
drawerCashFlowContractAssert(strpos($controller, 'browserMutationContext') !== false, 'POS controller should carry authenticated drawer context into cashier mutations');
drawerCashFlowContractAssert(strpos($controller, "'drawer_session_id' => max(0, (int) (\$_SESSION['pos_drawer_session_id'] ?? 0))") !== false, 'POS controller should retain the drawer binding after the AJAX session lock is released');

$authGuard = file_get_contents(__DIR__ . '/../../includes/auth_guard.php');
drawerCashFlowContractAssert(strpos($authGuard, 'auditPosAuthorization') !== false, 'POS auth guard should record authorization rather than claiming operation success');
drawerCashFlowContractAssert(strpos($authGuard, '$overrideAuthorizationAudited') !== false, 'POS auth guard should deduplicate override authorization within one request');
drawerCashFlowContractAssert(strpos($authGuard, 'auditPosWrite') === false, 'POS auth guard must not record a pre-mutation write outcome');

$cashFlow = file_get_contents(__DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php');
drawerCashFlowContractAssert(strpos($cashFlow, 'drawer_override_authorization') !== false, 'cash-flow audit view should include override authorization events');
drawerCashFlowContractAssert(strpos($cashFlow, 'authorization_granted') !== false, 'cash-flow audit view should label authorization separately from operation outcome');

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
