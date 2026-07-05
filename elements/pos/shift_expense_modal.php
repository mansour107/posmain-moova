<?php if (function_exists('csrf_token')): ?>
<script>
    window.POSMAIN_SHIFT_EXPENSE_CSRF_TOKEN = <?= json_encode(csrf_token('shift_expense'), JSON_UNESCAPED_SLASHES) ?>;
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
                        <span>تسجيل مصروف</span>
                    </h5>
                    <p class="pos-shift-expense-subtitle mb-0">سجّل مصروفاً من الدرج أثناء الشيفت الحالي</p>
                </div>
            </div>
            <div class="modal-body pos-shift-expense-body">
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
            <div class="modal-footer pos-shift-expense-footer">
                <button type="button" class="btn pos-shift-expense-btn-cancel" data-bs-dismiss="modal">
                    إلغاء
                </button>
                <button type="button" class="btn pos-shift-expense-btn-save" id="shiftExpenseSaveBtn">
                    <i class="fas fa-check me-1" aria-hidden="true"></i>
                    حفظ المصروف
                </button>
            </div>
        </div>
    </div>
</div>
