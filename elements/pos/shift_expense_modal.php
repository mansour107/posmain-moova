<?php
if (!isset($posmainCanRecordShiftExpense)) {
    $posmainCanRecordShiftExpense = false;
}
if (!isset($posmainCanRecordShiftPayIn)) {
    $posmainCanRecordShiftPayIn = false;
}
if (!isset($posmainCanRecordShiftSafeDrop)) {
    $posmainCanRecordShiftSafeDrop = false;
}
?>
<?php if (function_exists('csrf_token')): ?>
<script>
    window.POSMAIN_SHIFT_EXPENSE_CSRF_TOKEN = <?= json_encode(csrf_token('shift_expense'), JSON_UNESCAPED_SLASHES) ?>;
    window.POSMAIN_SHIFT_PAYIN_CSRF_TOKEN = <?= json_encode(csrf_token('shift_payin'), JSON_UNESCAPED_SLASHES) ?>;
    window.POSMAIN_SHIFT_SAFE_DROP_CSRF_TOKEN = <?= json_encode(csrf_token('shift_safe_drop'), JSON_UNESCAPED_SLASHES) ?>;
    window.POSMAIN_CAN_RECORD_SHIFT_EXPENSE = <?= json_encode(!empty($posmainCanRecordShiftExpense), JSON_UNESCAPED_SLASHES) ?>;
    window.POSMAIN_CAN_RECORD_SHIFT_PAYIN = <?= json_encode(!empty($posmainCanRecordShiftPayIn), JSON_UNESCAPED_SLASHES) ?>;
    window.POSMAIN_CAN_RECORD_SHIFT_SAFE_DROP = <?= json_encode(!empty($posmainCanRecordShiftSafeDrop), JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php endif; ?>

<div class="modal fade pos-shift-expense-modal-fade" id="shiftExpenseModal" tabindex="-1"
    aria-labelledby="shiftExpenseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered pos-shift-expense-dialog">
        <div class="modal-content pos-shift-expense-content">
            <div class="modal-header pos-shift-expense-header">
                <button type="button" class="btn-close pos-shift-expense-close" data-bs-dismiss="modal"
                    aria-label="إغلاق"></button>
                <div class="pos-shift-expense-heading">
                    <h5 class="modal-title pos-shift-expense-title" id="shiftExpenseModalLabel">
                        <i class="fas fa-wallet" aria-hidden="true"></i>
                        <span>حركة نقدية للدرج</span>
                    </h5>
                    <p class="pos-shift-expense-subtitle mb-0">سجّل إيداعاً أو مصروفاً من الدرج أثناء الشيفت الحالي</p>
                </div>
            </div>
            <div class="modal-body pos-shift-expense-body">
                <ul class="nav nav-pills pos-shift-cash-tabs mb-3" id="shiftCashTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="shiftCashPayoutTab" data-bs-toggle="pill"
                            data-bs-target="#shiftCashPayoutPane" type="button" role="tab"
                            aria-controls="shiftCashPayoutPane" aria-selected="true">
                            <i class="fas fa-arrow-up me-1" aria-hidden="true"></i>
                            مصروف (خارج)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="shiftCashPayinTab" data-bs-toggle="pill"
                            data-bs-target="#shiftCashPayinPane" type="button" role="tab"
                            aria-controls="shiftCashPayinPane" aria-selected="false">
                            <i class="fas fa-arrow-down me-1" aria-hidden="true"></i>
                            إيداع (داخل)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="shiftCashSafeDropTab" data-bs-toggle="pill"
                            data-bs-target="#shiftCashSafeDropPane" type="button" role="tab"
                            aria-controls="shiftCashSafeDropPane" aria-selected="false">
                            <i class="fas fa-vault me-1" aria-hidden="true"></i>
                            تحويل للخزنة
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="shiftCashTabContent">
                    <div class="tab-pane fade show active" id="shiftCashPayoutPane" role="tabpanel"
                        aria-labelledby="shiftCashPayoutTab" tabindex="0">
                        <div id="shiftExpenseDrawerNotice" class="pos-shift-expense-notice d-none" role="status"></div>

                        <div class="pos-shift-expense-summary-card d-none" id="shiftExpenseSummaryCard">
                            <div class="pos-shift-expense-summary-label">إجمالي مصروفات الشيفت</div>
                            <div class="pos-shift-expense-summary-value" id="shiftExpenseSummaryTotal">0.00 ج.م</div>
                            <div class="pos-shift-expense-summary-meta" id="shiftExpenseSummaryMeta">0 مصروف</div>
                        </div>

                        <div class="pos-shift-expense-list-wrap">
                            <div class="pos-shift-expense-list-title">سجل المصروفات</div>
                            <div id="shiftExpenseList" class="pos-shift-expense-list">
                                <div class="pos-shift-expense-loading">
                                    <div class="spinner-border spinner-border-sm" role="status">
                                        <span class="visually-hidden">جاري التحميل...</span>
                                    </div>
                                    <span>جاري تحميل المصروفات...</span>
                                </div>
                            </div>
                        </div>

                        <div class="pos-shift-expense-form-card">
                            <div class="pos-shift-expense-form-title">مصروف جديد</div>
                            <div id="shiftExpenseFormAlert" class="pos-shift-expense-alert d-none" role="alert"></div>
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <label class="pos-shift-expense-label" for="shift_expense_amount">المبلغ (ج.م)</label>
                                    <input type="number" class="form-control pos-shift-expense-input" id="shift_expense_amount"
                                        placeholder="مثال: 25.00" step="0.01" min="0.01" inputmode="decimal">
                                </div>
                                <div class="col-md-7">
                                    <label class="pos-shift-expense-label" for="shift_expense_reason">البيان</label>
                                    <input type="text" class="form-control pos-shift-expense-input" id="shift_expense_reason"
                                        placeholder="مثال: توصيل طلب — مشتريات صغيرة" maxlength="500">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="shiftCashPayinPane" role="tabpanel"
                        aria-labelledby="shiftCashPayinTab" tabindex="0">
                        <div id="shiftPayinDrawerNotice" class="pos-shift-expense-notice d-none" role="status"></div>

                        <div class="pos-shift-expense-summary-card is-payin d-none" id="shiftPayinSummaryCard">
                            <div class="pos-shift-expense-summary-label">إجمالي إيداعات الشيفت</div>
                            <div class="pos-shift-expense-summary-value" id="shiftPayinSummaryTotal">0.00 ج.م</div>
                            <div class="pos-shift-expense-summary-meta" id="shiftPayinSummaryMeta">0 إيداع</div>
                        </div>

                        <div class="pos-shift-expense-list-wrap">
                            <div class="pos-shift-expense-list-title">سجل الإيداعات</div>
                            <div id="shiftPayinList" class="pos-shift-expense-list">
                                <div class="pos-shift-expense-loading">
                                    <div class="spinner-border spinner-border-sm" role="status">
                                        <span class="visually-hidden">جاري التحميل...</span>
                                    </div>
                                    <span>جاري تحميل الإيداعات...</span>
                                </div>
                            </div>
                        </div>

                        <div class="pos-shift-expense-form-card is-payin">
                            <div class="pos-shift-expense-form-title">إيداع جديد</div>
                            <div id="shiftPayinFormAlert" class="pos-shift-expense-alert d-none" role="alert"></div>
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <label class="pos-shift-expense-label" for="shift_payin_amount">المبلغ (ج.م)</label>
                                    <input type="number" class="form-control pos-shift-expense-input" id="shift_payin_amount"
                                        placeholder="مثال: 100.00" step="0.01" min="0.01" inputmode="decimal">
                                </div>
                                <div class="col-md-7">
                                    <label class="pos-shift-expense-label" for="shift_payin_reason">البيان</label>
                                    <input type="text" class="form-control pos-shift-expense-input" id="shift_payin_reason"
                                        placeholder="مثال: تعبئة صندوق — فكة إضافية" maxlength="500">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="shiftCashSafeDropPane" role="tabpanel"
                        aria-labelledby="shiftCashSafeDropTab" tabindex="0">
                        <div id="shiftSafeDropDrawerNotice" class="pos-shift-expense-notice d-none" role="status"></div>

                        <div class="pos-shift-expense-summary-card is-safe-drop d-none" id="shiftSafeDropSummaryCard">
                            <div class="pos-shift-expense-summary-label">إجمالي تحويلات الخزنة</div>
                            <div class="pos-shift-expense-summary-value" id="shiftSafeDropSummaryTotal">0.00 ج.م</div>
                            <div class="pos-shift-expense-summary-meta" id="shiftSafeDropSummaryMeta">0 تحويل</div>
                        </div>

                        <div class="pos-shift-expense-list-wrap">
                            <div class="pos-shift-expense-list-title">سجل التحويلات للخزنة</div>
                            <div id="shiftSafeDropList" class="pos-shift-expense-list">
                                <div class="pos-shift-expense-loading">
                                    <div class="spinner-border spinner-border-sm" role="status">
                                        <span class="visually-hidden">جاري التحميل...</span>
                                    </div>
                                    <span>جاري تحميل التحويلات...</span>
                                </div>
                            </div>
                        </div>

                        <div class="pos-shift-expense-form-card is-safe-drop">
                            <div class="pos-shift-expense-form-title">تحويل جديد للخزنة</div>
                            <div id="shiftSafeDropFormAlert" class="pos-shift-expense-alert d-none" role="alert"></div>
                            <div class="row g-3">
                                <div class="col-md-5">
                                    <label class="pos-shift-expense-label" for="shift_safe_drop_amount">المبلغ (ج.م)</label>
                                    <input type="number" class="form-control pos-shift-expense-input" id="shift_safe_drop_amount"
                                        placeholder="مثال: 500.00" step="0.01" min="0.01" inputmode="decimal">
                                </div>
                                <div class="col-md-7">
                                    <label class="pos-shift-expense-label" for="shift_safe_drop_reason">البيان</label>
                                    <input type="text" class="form-control pos-shift-expense-input" id="shift_safe_drop_reason"
                                        placeholder="مثال: إيداع نقدية في الخزنة الرئيسية" maxlength="500">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer pos-shift-expense-footer">
                <button type="button" class="btn pos-shift-expense-btn-cancel" data-bs-dismiss="modal">
                    إلغاء
                </button>
                <button type="button" class="btn pos-shift-expense-btn-save d-none" id="shiftExpenseSaveBtn">
                    <i class="fas fa-check me-1" aria-hidden="true"></i>
                    حفظ المصروف
                </button>
                <button type="button" class="btn pos-shift-expense-btn-save is-payin d-none" id="shiftPayinSaveBtn">
                    <i class="fas fa-check me-1" aria-hidden="true"></i>
                    حفظ الإيداع
                </button>
                <button type="button" class="btn pos-shift-expense-btn-save is-safe-drop d-none" id="shiftSafeDropSaveBtn">
                    <i class="fas fa-check me-1" aria-hidden="true"></i>
                    حفظ التحويل
                </button>
            </div>
        </div>
    </div>
</div>
