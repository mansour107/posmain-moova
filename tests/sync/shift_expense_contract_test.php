<?php

$endpoint = file_get_contents(__DIR__ . '/../../do/do_record_shift_expense.php');
if ($endpoint === false) {
    throw new RuntimeException('Unable to read do_record_shift_expense.php');
}

shiftExpenseContractAssert(strpos($endpoint, 'ShiftSessionService') !== false, 'expense endpoint should use ShiftSessionService');
shiftExpenseContractAssert(strpos($endpoint, 'recordShiftExpense') !== false, 'expense endpoint should call recordShiftExpense');
shiftExpenseContractAssert(strpos($endpoint, "require_csrf('shift_expense')") !== false, 'expense endpoint should require shift_expense CSRF');
shiftExpenseContractAssert(strpos($endpoint, 'auth_guard_is_pos_barcode_unlocked') !== false, 'expense endpoint should require POS unlock');
shiftExpenseContractAssert(strpos($endpoint, 'pos.cashdrawer.count') !== false, 'expense endpoint should enforce pos.cashdrawer.count');
shiftExpenseContractAssert(strpos($endpoint, 'PERMISSION_DENIED') !== false, 'expense endpoint should deny missing permission');

$preview = file_get_contents(__DIR__ . '/../../do/get_shift_preview.php');
shiftExpenseContractAssert(strpos($preview, 'shiftExpenseSummary') !== false, 'shift preview should expose expense summary');
shiftExpenseContractAssert(strpos($preview, 'pos_acting_user_id') !== false, 'shift preview should scope to acting cashier');
shiftExpenseContractAssert(strpos($preview, 'pos.cashdrawer.count') !== false, 'shift preview should allow expense summary permission');

$service = file_get_contents(__DIR__ . '/../../classes/Pos/Service/ShiftSessionService.php');
shiftExpenseContractAssert(strpos($service, 'recordShiftExpense') !== false, 'ShiftSessionService should expose recordShiftExpense');
shiftExpenseContractAssert(strpos($service, 'DrawerLedgerPostingService') !== false, 'ShiftSessionService should use DrawerLedgerPostingService');
shiftExpenseContractAssert(strpos($service, 'resolveCloseExpenses') !== false, 'close should resolve expenses from drawer');

$modal = file_get_contents(__DIR__ . '/../../elements/pos/shift_expense_modal.php');
shiftExpenseContractAssert(strpos($modal, 'shiftExpenseModal') !== false, 'shared expense modal expected');

echo "shift-expense-contract-ok\n";

function shiftExpenseContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
