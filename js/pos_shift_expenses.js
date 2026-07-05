(function (window, $) {
    'use strict';

    if (!$) {
        return;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function expenseRecordingEnabled(summary) {
        if (!summary) {
            return false;
        }

        return summary.mid_shift_enabled === true
            || summary.drawer_active === true
            || Number(summary.total || 0) > 0;
    }

    function formatExpenseList(summary) {
        if (!expenseRecordingEnabled(summary)) {
            return (
                '<div class="pos-shift-expense-empty">' +
                    '<i class="fas fa-info-circle" aria-hidden="true"></i>' +
                    '<p>لا توجد جلسة درج مفتوحة. أعد فتح نقطة البيع ثم جرّب مرة أخرى.</p>' +
                '</div>'
            );
        }

        if (!summary.movements || summary.movements.length === 0) {
            return (
                '<div class="pos-shift-expense-empty is-muted">' +
                    '<i class="fas fa-receipt" aria-hidden="true"></i>' +
                    '<p>لا توجد مصروفات مسجلة في هذا الشيفت بعد.</p>' +
                '</div>'
            );
        }

        return summary.movements.map(function (movement) {
            return (
                '<div class="pos-shift-expense-item">' +
                    '<div class="pos-shift-expense-item-main">' +
                        '<span class="pos-shift-expense-item-reason">' + escapeHtml(movement.reason || 'بدون بيان') + '</span>' +
                        '<strong class="pos-shift-expense-item-amount">' + escapeHtml(movement.amount) + ' ج.م</strong>' +
                    '</div>' +
                    '<small class="pos-shift-expense-item-time">' + escapeHtml(movement.created_at || '') + '</small>' +
                '</div>'
            );
        }).join('');
    }

    function updateSummaryCard(summary) {
        var card = $('#shiftExpenseSummaryCard');
        var enabled = expenseRecordingEnabled(summary);
        var total = Number(summary && summary.total ? summary.total : 0);
        var count = Number(summary && summary.count ? summary.count : 0);

        if (!enabled) {
            card.addClass('d-none');
            return;
        }

        card.removeClass('d-none');
        $('#shiftExpenseSummaryTotal').text((summary.total_formatted || total.toFixed(2)) + ' ج.م');
        $('#shiftExpenseSummaryMeta').text(count + (count === 1 ? ' مصروف' : ' مصروفات'));
    }

    function updateDrawerNotice(summary) {
        var notice = $('#shiftExpenseDrawerNotice');
        var enabled = expenseRecordingEnabled(summary);

        if (enabled) {
            notice.addClass('d-none').text('');
            $('#shift_expense_amount, #shift_expense_reason').prop('disabled', false);
            $('#shiftExpenseSaveBtn').prop('disabled', false);
            return;
        }

        notice
            .removeClass('d-none is-success is-danger')
            .addClass('is-warning')
            .html('<i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>' +
                'تسجيل المصروفات يتطلب شيفت مفتوحاً. أغلق هذه النافذة ثم أعد فتح نقطة البيع.');
        $('#shift_expense_amount, #shift_expense_reason').prop('disabled', true);
        $('#shiftExpenseSaveBtn').prop('disabled', true);
    }

    function updateExpenseBadge(summary) {
        var badges = $('.js-shift-expense-badge');
        if (!badges.length) {
            return;
        }

        if (!expenseRecordingEnabled(summary) || !(parseFloat(summary.total) > 0)) {
            badges.addClass('d-none').text('');
            return;
        }

        badges.removeClass('d-none').text(summary.total_formatted + ' ج.م');
    }

    function applyCloseShiftExpenseFields(summary) {
        if (!summary) {
            return;
        }

        var expensesInput = $('#shift_expenses');
        var notesInput = $('#shift_exp_notes');
        if (!expensesInput.length) {
            return;
        }

        expensesInput.val(summary.total_formatted || summary.total || '0.00');
        if (expenseRecordingEnabled(summary)) {
            expensesInput.prop('readonly', true).attr('title', 'يُحسب تلقائياً من مصروفات الشيفت');
            if (notesInput.length && summary.notes) {
                notesInput.val(summary.notes);
            }
        } else {
            expensesInput.prop('readonly', false).removeAttr('title');
        }
    }

    function renderExpenseSummary(summary) {
        window.posShiftExpenseLastSummary = summary;
        $('#shiftExpenseList').html(formatExpenseList(summary));
        updateSummaryCard(summary);
        updateDrawerNotice(summary);
        updateExpenseBadge(summary);
        applyCloseShiftExpenseFields(summary);
    }

    function loadShiftExpenseSummary() {
        return $.ajax({
            url: 'do/get_shift_preview.php',
            method: 'GET',
            cache: false,
        }).then(function (response) {
            var payload = (typeof response === 'object') ? response : JSON.parse(response);
            if (!payload.success || !payload.data) {
                throw new Error(payload.error || 'تعذر تحميل المصروفات');
            }

            var summary = payload.data.expenses || {};
            renderExpenseSummary(summary);
            return summary;
        });
    }

    function showExpenseAlert(message, type) {
        var alert = $('#shiftExpenseFormAlert');
        if (!alert.length) {
            return;
        }
        alert
            .removeClass('d-none is-warning is-danger is-success')
            .addClass(type === 'success' ? 'is-success' : (type === 'danger' ? 'is-danger' : 'is-warning'))
            .text(message);
    }

    function saveShiftExpense() {
        var amount = parseFloat($('#shift_expense_amount').val() || '0');
        var reason = ($('#shift_expense_reason').val() || '').trim();

        if ($('#shiftExpenseSaveBtn').prop('disabled')) {
            return;
        }

        if (!(amount > 0)) {
            showExpenseAlert('أدخل مبلغاً أكبر من صفر.', 'warning');
            $('#shift_expense_amount').trigger('focus');
            return;
        }
        if (!reason) {
            showExpenseAlert('أدخل بياناً للمصروف.', 'warning');
            $('#shift_expense_reason').trigger('focus');
            return;
        }

        $('#shiftExpenseSaveBtn').prop('disabled', true);

        function finishSuccess(response) {
            var payload = (typeof response === 'object') ? response : JSON.parse(response);
            if (!payload.success) {
                throw new Error(payload.error || 'تعذر حفظ المصروف');
            }

            $('#shift_expense_amount').val('');
            $('#shift_expense_reason').val('');
            showExpenseAlert('تم تسجيل المصروف بنجاح.', 'success');

            var summary = payload.data && payload.data.summary ? payload.data.summary : null;
            if (summary) {
                renderExpenseSummary(summary);
            } else {
                loadShiftExpenseSummary();
            }
        }

        function finishFail(xhr, retryFn) {
            var message = 'تعذر حفظ المصروف';
            var code = '';
            try {
                var body = xhr.responseJSON || JSON.parse(xhr.responseText || '{}');
                if (body.error) {
                    message = body.error;
                }
                code = body.code || body.error || '';
            } catch (error) {
                // ignore parse errors
            }
            if (code === 'MANAGER_APPROVAL_REQUIRED' && window.POSMAIN && typeof window.POSMAIN.requestManagerOverride === 'function') {
                window.POSMAIN.requestManagerOverride('pos.payout.over_limit', {
                    message: 'مصروف يتجاوز الحد — يتطلب اعتماد مدير',
                }).done(function (approval) {
                    retryFn(approval.approval_id);
                }).fail(function () {
                    showExpenseAlert('تم إلغاء اعتماد المدير', 'warning');
                    if (expenseRecordingEnabled(window.posShiftExpenseLastSummary)) {
                        $('#shiftExpenseSaveBtn').prop('disabled', false);
                    }
                });
                return;
            }
            showExpenseAlert(message, 'danger');
            if (expenseRecordingEnabled(window.posShiftExpenseLastSummary)) {
                $('#shiftExpenseSaveBtn').prop('disabled', false);
            }
        }

        function postExpense(approvalId) {
            var data = {
                amount: amount,
                reason: reason,
                csrf_token: window.POSMAIN_SHIFT_EXPENSE_CSRF_TOKEN || '',
            };
            if (approvalId) {
                data.manager_approval_id = approvalId;
            }
            $.ajax({
                url: 'do/do_record_shift_expense.php',
                method: 'POST',
                data: data,
            }).done(function (response) {
                try {
                    finishSuccess(response);
                } catch (error) {
                    showExpenseAlert(error.message || 'تعذر حفظ المصروف', 'danger');
                }
            }).fail(function (xhr) {
                finishFail(xhr, postExpense);
            }).always(function () {
                if (expenseRecordingEnabled(window.posShiftExpenseLastSummary)) {
                    $('#shiftExpenseSaveBtn').prop('disabled', false);
                }
            });
        }

        if (window.POSMAIN && typeof window.POSMAIN.amountExceedsLimit === 'function'
            && window.POSMAIN.amountExceedsLimit('pos.payout.over_limit', amount)
            && typeof window.POSMAIN.requestManagerOverride === 'function') {
            window.POSMAIN.requestManagerOverride('pos.payout.over_limit', {
                message: 'مصروف يتجاوز الحد — يتطلب اعتماد مدير',
            }).done(function (approval) {
                postExpense(approval.approval_id);
            }).fail(function () {
                showExpenseAlert('تم إلغاء اعتماد المدير', 'warning');
                if (expenseRecordingEnabled(window.posShiftExpenseLastSummary)) {
                    $('#shiftExpenseSaveBtn').prop('disabled', false);
                }
            });
            return;
        }

        postExpense(null);
    }

    window.posShiftExpenseLoadSummary = loadShiftExpenseSummary;
    window.posShiftExpenseApplyCloseFields = applyCloseShiftExpenseFields;

    $(function () {
        $('#shiftExpenseModal').on('show.bs.modal', function () {
            $('#shiftExpenseFormAlert').addClass('d-none').text('');
            $('#shift_expense_amount').val('');
            $('#shift_expense_reason').val('');
            loadShiftExpenseSummary()
                .then(function (summary) {
                    window.posShiftExpenseLastSummary = summary;
                })
                .catch(function (error) {
                    $('#shiftExpenseList').html(
                        '<div class="pos-shift-expense-empty is-danger">' +
                            '<i class="fas fa-times-circle" aria-hidden="true"></i>' +
                            '<p>' + escapeHtml(error.message || 'تعذر تحميل المصروفات') + '</p>' +
                        '</div>'
                    );
                    updateDrawerNotice(null);
                });
        });

        $('#shiftExpenseSaveBtn').on('click', saveShiftExpense);
        $('#shift_expense_reason').on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                saveShiftExpense();
            }
        });

        loadShiftExpenseSummary()
            .then(function (summary) {
                window.posShiftExpenseLastSummary = summary;
            })
            .catch(function () {
                // optional initial badge load
            });
    });
})(window, window.jQuery);
