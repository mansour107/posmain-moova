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

    function canRecordExpense() {
        if (window.POSMAIN_CAN_RECORD_SHIFT_EXPENSE === true) {
            return true;
        }
        if (window.POSMAIN && typeof window.POSMAIN.can === 'function') {
            return window.POSMAIN.can('pos.cashdrawer.count') === true;
        }
        return false;
    }

    function canRecordPayIn() {
        if (window.POSMAIN_CAN_RECORD_SHIFT_PAYIN === true) {
            return true;
        }
        if (window.POSMAIN && typeof window.POSMAIN.can === 'function') {
            return window.POSMAIN.can('pos.drawer.payin') === true;
        }
        return false;
    }

    function canRecordSafeDrop() {
        if (window.POSMAIN_CAN_RECORD_SHIFT_SAFE_DROP === true) {
            return true;
        }
        if (window.POSMAIN && typeof window.POSMAIN.can === 'function') {
            return window.POSMAIN.can('pos.drawer.safe_drop') === true;
        }
        return false;
    }

    function cashRecordingEnabled(summary) {
        if (!summary) {
            return false;
        }

        return summary.mid_shift_enabled === true
            || summary.drawer_active === true
            || Number(summary.total || 0) > 0;
    }

    function formatMovementList(summary, emptyLabel, emptyMutedLabel) {
        if (!cashRecordingEnabled(summary)) {
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
                    '<p>' + escapeHtml(emptyMutedLabel) + '</p>' +
                '</div>'
            );
        }

        return summary.movements.map(function (movement) {
            return (
                '<div class="pos-shift-expense-item">' +
                    '<div class="pos-shift-expense-item-main">' +
                        '<span class="pos-shift-expense-item-reason">' + escapeHtml(movement.reason || emptyLabel) + '</span>' +
                        '<strong class="pos-shift-expense-item-amount">' + escapeHtml(movement.amount) + ' ج.م</strong>' +
                    '</div>' +
                    '<small class="pos-shift-expense-item-time">' + escapeHtml(movement.created_at || '') + '</small>' +
                '</div>'
            );
        }).join('');
    }

    function updateSummaryCard(summary, cardSelector, totalSelector, metaSelector, singularLabel, pluralLabel) {
        var card = $(cardSelector);
        var enabled = cashRecordingEnabled(summary);
        var total = Number(summary && summary.total ? summary.total : 0);
        var count = Number(summary && summary.count ? summary.count : 0);

        if (!enabled) {
            card.addClass('d-none');
            return;
        }

        card.removeClass('d-none');
        $(totalSelector).text((summary.total_formatted || total.toFixed(2)) + ' ج.م');
        $(metaSelector).text(count + (count === 1 ? (' ' + singularLabel) : (' ' + pluralLabel)));
    }

    function updateDrawerNotice(summary, noticeSelector, amountSelector, reasonSelector, saveSelector, enabledByPermission) {
        var notice = $(noticeSelector);
        var enabled = cashRecordingEnabled(summary) && enabledByPermission;

        if (enabled) {
            notice.addClass('d-none').text('');
            $(amountSelector + ', ' + reasonSelector).prop('disabled', false);
            $(saveSelector).prop('disabled', false);
            return;
        }

        var message = !enabledByPermission
            ? 'ليس لديك صلاحية لهذه الحركة. يمكن طلب اعتماد المدير عند الحفظ.'
            : 'تسجيل الحركات يتطلب شيفت مفتوحاً. أغلق هذه النافذة ثم أعد فتح نقطة البيع.';

        notice
            .removeClass('d-none is-success is-danger')
            .addClass('is-warning')
            .html('<i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>' + message);
        // Keep fields + save enabled when a drawer is open so cashiers can fill the form
        // and trigger manager override on save (message above explains the permission gap).
        var canAttemptWithOverride = cashRecordingEnabled(summary);
        $(amountSelector + ', ' + reasonSelector).prop('disabled', !canAttemptWithOverride);
        $(saveSelector).prop('disabled', !canAttemptWithOverride);
    }

    function getCloseShiftExpensePayload() {
        var summary = window.posShiftExpenseLastSummary || {};
        return {
            expenses: summary.total || 0,
            exp_notes: summary.notes || '',
        };
    }

    function renderExpenseSummary(summary) {
        window.posShiftExpenseLastSummary = summary;
        $('#shiftExpenseList').html(formatMovementList(summary, 'بدون بيان', 'لا توجد مصروفات مسجلة في هذا الشيفت بعد.'));
        updateSummaryCard(summary, '#shiftExpenseSummaryCard', '#shiftExpenseSummaryTotal', '#shiftExpenseSummaryMeta', 'مصروف', 'مصروفات');
        updateDrawerNotice(summary, '#shiftExpenseDrawerNotice', '#shift_expense_amount', '#shift_expense_reason', '#shiftExpenseSaveBtn', canRecordExpense());
    }

    function renderPayInSummary(summary) {
        window.posShiftPayInLastSummary = summary;
        $('#shiftPayinList').html(formatMovementList(summary, 'بدون بيان', 'لا توجد إيداعات مسجلة في هذا الشيفت بعد.'));
        updateSummaryCard(summary, '#shiftPayinSummaryCard', '#shiftPayinSummaryTotal', '#shiftPayinSummaryMeta', 'إيداع', 'إيداعات');
        updateDrawerNotice(summary, '#shiftPayinDrawerNotice', '#shift_payin_amount', '#shift_payin_reason', '#shiftPayinSaveBtn', canRecordPayIn());
    }

    function renderSafeDropSummary(summary) {
        window.posShiftSafeDropLastSummary = summary;
        $('#shiftSafeDropList').html(formatMovementList(summary, 'بدون بيان', 'لا توجد تحويلات للخزنة مسجلة في هذا الشيفت بعد.'));
        updateSummaryCard(summary, '#shiftSafeDropSummaryCard', '#shiftSafeDropSummaryTotal', '#shiftSafeDropSummaryMeta', 'تحويل', 'تحويلات');
        updateDrawerNotice(summary, '#shiftSafeDropDrawerNotice', '#shift_safe_drop_amount', '#shift_safe_drop_reason', '#shiftSafeDropSaveBtn', canRecordSafeDrop());
    }

    function loadShiftCashSummary() {
        return $.ajax({
            url: 'do/get_shift_preview.php',
            method: 'GET',
            cache: false,
        }).then(function (response) {
            var payload = (typeof response === 'object') ? response : JSON.parse(response);
            if (!payload.success || !payload.data) {
                throw new Error(payload.error || 'تعذر تحميل حركات الدرج');
            }

            var expenseSummary = payload.data.expenses || {};
            var payInSummary = payload.data.payins || {};
            var safeDropSummary = payload.data.safe_drops || {};
            window.posShiftPreviewExpectedCash = payload.data.expected_cash || null;
            renderExpenseSummary(expenseSummary);
            renderPayInSummary(payInSummary);
            renderSafeDropSummary(safeDropSummary);
            return {
                expenses: expenseSummary,
                payins: payInSummary,
                safe_drops: safeDropSummary,
                expected_cash: payload.data.expected_cash || null,
            };
        });
    }

    function showPaneAlert(alertSelector, message, type) {
        var alert = $(alertSelector);
        if (!alert.length) {
            return;
        }
        alert
            .removeClass('d-none is-warning is-danger is-success')
            .addClass(type === 'success' ? 'is-success' : (type === 'danger' ? 'is-danger' : 'is-warning'))
            .text(message);
    }

    function requestOverrideIfNeeded(permissionKey, amount, message) {
        if (!window.POSMAIN || typeof window.POSMAIN.requestManagerOverride !== 'function') {
            return $.Deferred().reject().promise();
        }

        var options = { message: message };
        if (amount > 0) {
            options.amount = amount;
        }
        return window.POSMAIN.requestManagerOverride(permissionKey, options);
    }

    // Pending keys survive uncertain network retries for the same amount+reason.
    // Cleared on success, modal reset, or when the cashier changes the payload.
    var pendingCashKeys = {};

    function createShiftCashIdempotencyKey(scope) {
        if (typeof window.createPOSIdempotencyKey === 'function') {
            return window.createPOSIdempotencyKey(scope);
        }
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return scope + ':' + window.crypto.randomUUID();
        }
        return scope + ':' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2);
    }

    function cashPayloadFingerprint(amount, reason) {
        return String(amount) + '|' + String(reason || '');
    }

    function getShiftCashIdempotencyKey(scope, amount, reason) {
        var fingerprint = cashPayloadFingerprint(amount, reason);
        var pending = pendingCashKeys[scope];
        if (pending && pending.fingerprint === fingerprint && pending.key) {
            return pending.key;
        }
        var key = createShiftCashIdempotencyKey(scope);
        pendingCashKeys[scope] = { key: key, fingerprint: fingerprint };
        return key;
    }

    function clearShiftCashIdempotencyKey(scope) {
        delete pendingCashKeys[scope];
    }

    function clearAllShiftCashIdempotencyKeys() {
        pendingCashKeys = {};
    }

    function saveShiftExpense() {
        var amount = parseFloat($('#shift_expense_amount').val() || '0');
        var reason = ($('#shift_expense_reason').val() || '').trim();
        var cashScope = 'pos.shift.payout';

        if ($('#shiftExpenseSaveBtn').prop('disabled')) {
            return;
        }

        if (!(amount > 0)) {
            showPaneAlert('#shiftExpenseFormAlert', 'أدخل مبلغاً أكبر من صفر.', 'warning');
            $('#shift_expense_amount').trigger('focus');
            return;
        }
        if (!reason) {
            showPaneAlert('#shiftExpenseFormAlert', 'أدخل بياناً للمصروف.', 'warning');
            $('#shift_expense_reason').trigger('focus');
            return;
        }

        var idempotencyKey = getShiftCashIdempotencyKey(cashScope, amount, reason);
        $('#shiftExpenseSaveBtn').prop('disabled', true);

        function finishSuccess(response) {
            var payload = (typeof response === 'object') ? response : JSON.parse(response);
            if (!payload.success) {
                throw new Error(payload.error || 'تعذر حفظ المصروف');
            }

            clearShiftCashIdempotencyKey(cashScope);
            $('#shift_expense_amount').val('');
            $('#shift_expense_reason').val('');
            showPaneAlert('#shiftExpenseFormAlert', 'تم تسجيل المصروف بنجاح.', 'success');

            var summary = payload.data && payload.data.summary ? payload.data.summary : null;
            if (summary) {
                renderExpenseSummary(summary);
            } else {
                loadShiftCashSummary();
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
            if (code === 'MANAGER_APPROVAL_REQUIRED') {
                requestOverrideIfNeeded('pos.payout.over_limit', amount, 'مصروف يتجاوز الحد — يتطلب اعتماد مدير')
                    .done(function (approval) {
                        retryFn(approval.approval_id);
                    })
                    .fail(function () {
                        showPaneAlert('#shiftExpenseFormAlert', 'تم إلغاء اعتماد المدير', 'warning');
                        if (cashRecordingEnabled(window.posShiftExpenseLastSummary) && canRecordExpense()) {
                            $('#shiftExpenseSaveBtn').prop('disabled', false);
                        }
                    });
                return;
            }
            showPaneAlert('#shiftExpenseFormAlert', message, 'danger');
            if (cashRecordingEnabled(window.posShiftExpenseLastSummary) && canRecordExpense()) {
                $('#shiftExpenseSaveBtn').prop('disabled', false);
            }
        }

        function postExpense(approvalId) {
            var data = {
                amount: amount,
                reason: reason,
                csrf_token: window.POSMAIN_SHIFT_EXPENSE_CSRF_TOKEN || '',
                idempotency_key: idempotencyKey,
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
                    showPaneAlert('#shiftExpenseFormAlert', error.message || 'تعذر حفظ المصروف', 'danger');
                }
            }).fail(function (xhr) {
                finishFail(xhr, postExpense);
            }).always(function () {
                if (cashRecordingEnabled(window.posShiftExpenseLastSummary) && canRecordExpense()) {
                    $('#shiftExpenseSaveBtn').prop('disabled', false);
                }
            });
        }

        if (!canRecordExpense()) {
            showPaneAlert('#shiftExpenseFormAlert', 'ليس لديك صلاحية تسجيل المصروفات.', 'danger');
            $('#shiftExpenseSaveBtn').prop('disabled', false);
            return;
        }

        if (window.POSMAIN && typeof window.POSMAIN.amountExceedsLimit === 'function'
            && window.POSMAIN.amountExceedsLimit('pos.payout.over_limit', amount)) {
            requestOverrideIfNeeded('pos.payout.over_limit', amount, 'مصروف يتجاوز الحد — يتطلب اعتماد مدير')
                .done(function (approval) {
                    postExpense(approval.approval_id);
                })
                .fail(function () {
                    showPaneAlert('#shiftExpenseFormAlert', 'تم إلغاء اعتماد المدير', 'warning');
                    if (cashRecordingEnabled(window.posShiftExpenseLastSummary) && canRecordExpense()) {
                        $('#shiftExpenseSaveBtn').prop('disabled', false);
                    }
                });
            return;
        }

        postExpense(null);
    }

    function saveShiftPayIn() {
        var amount = parseFloat($('#shift_payin_amount').val() || '0');
        var reason = ($('#shift_payin_reason').val() || '').trim();
        var cashScope = 'pos.shift.payin';

        if ($('#shiftPayinSaveBtn').prop('disabled')) {
            return;
        }

        if (!(amount > 0)) {
            showPaneAlert('#shiftPayinFormAlert', 'أدخل مبلغاً أكبر من صفر.', 'warning');
            $('#shift_payin_amount').trigger('focus');
            return;
        }
        if (!reason) {
            showPaneAlert('#shiftPayinFormAlert', 'أدخل بياناً للإيداع.', 'warning');
            $('#shift_payin_reason').trigger('focus');
            return;
        }

        var idempotencyKey = getShiftCashIdempotencyKey(cashScope, amount, reason);
        $('#shiftPayinSaveBtn').prop('disabled', true);

        function finishSuccess(response) {
            var payload = (typeof response === 'object') ? response : JSON.parse(response);
            if (!payload.success) {
                throw new Error(payload.error || 'تعذر حفظ الإيداع');
            }

            clearShiftCashIdempotencyKey(cashScope);
            $('#shift_payin_amount').val('');
            $('#shift_payin_reason').val('');
            showPaneAlert('#shiftPayinFormAlert', 'تم تسجيل الإيداع بنجاح.', 'success');

            var summary = payload.data && payload.data.summary ? payload.data.summary : null;
            if (summary) {
                renderPayInSummary(summary);
                loadShiftCashSummary();
            } else {
                loadShiftCashSummary();
            }
        }

        function finishFail(xhr, retryFn) {
            var message = 'تعذر حفظ الإيداع';
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
            if (code === 'MANAGER_APPROVAL_REQUIRED' || code === 'PERMISSION_DENIED') {
                requestOverrideIfNeeded('pos.drawer.payin', amount, 'إيداع نقدي — يتطلب اعتماد مدير')
                    .done(function (approval) {
                        retryFn(approval.approval_id);
                    })
                    .fail(function () {
                        showPaneAlert('#shiftPayinFormAlert', 'تم إلغاء اعتماد المدير', 'warning');
                        if (cashRecordingEnabled(window.posShiftPayInLastSummary)) {
                            $('#shiftPayinSaveBtn').prop('disabled', false);
                        }
                    });
                return;
            }
            showPaneAlert('#shiftPayinFormAlert', message, 'danger');
            if (cashRecordingEnabled(window.posShiftPayInLastSummary)) {
                $('#shiftPayinSaveBtn').prop('disabled', false);
            }
        }

        function postPayIn(approvalId) {
            var data = {
                amount: amount,
                reason: reason,
                csrf_token: window.POSMAIN_SHIFT_PAYIN_CSRF_TOKEN || '',
                idempotency_key: idempotencyKey,
            };
            if (approvalId) {
                data.manager_approval_id = approvalId;
            }
            $.ajax({
                url: 'do/do_record_shift_payin.php',
                method: 'POST',
                data: data,
            }).done(function (response) {
                try {
                    finishSuccess(response);
                } catch (error) {
                    showPaneAlert('#shiftPayinFormAlert', error.message || 'تعذر حفظ الإيداع', 'danger');
                }
            }).fail(function (xhr) {
                finishFail(xhr, postPayIn);
            }).always(function () {
                if (cashRecordingEnabled(window.posShiftPayInLastSummary)) {
                    $('#shiftPayinSaveBtn').prop('disabled', false);
                }
            });
        }

        if (!canRecordPayIn()) {
            requestOverrideIfNeeded('pos.drawer.payin', amount, 'إيداع نقدي — يتطلب اعتماد مدير')
                .done(function (approval) {
                    postPayIn(approval.approval_id);
                })
                .fail(function () {
                    showPaneAlert('#shiftPayinFormAlert', 'تم إلغاء اعتماد المدير', 'warning');
                    if (cashRecordingEnabled(window.posShiftPayInLastSummary)) {
                        $('#shiftPayinSaveBtn').prop('disabled', false);
                    }
                });
            return;
        }

        postPayIn(null);
    }

    function saveShiftSafeDrop() {
        var amount = parseFloat($('#shift_safe_drop_amount').val() || '0');
        var reason = ($('#shift_safe_drop_reason').val() || '').trim();
        var cashScope = 'pos.shift.safe_drop';

        if ($('#shiftSafeDropSaveBtn').prop('disabled')) {
            return;
        }

        if (!(amount > 0)) {
            showPaneAlert('#shiftSafeDropFormAlert', 'أدخل مبلغاً أكبر من صفر.', 'warning');
            $('#shift_safe_drop_amount').trigger('focus');
            return;
        }
        if (!reason) {
            showPaneAlert('#shiftSafeDropFormAlert', 'أدخل بياناً للتحويل.', 'warning');
            $('#shift_safe_drop_reason').trigger('focus');
            return;
        }

        var idempotencyKey = getShiftCashIdempotencyKey(cashScope, amount, reason);
        $('#shiftSafeDropSaveBtn').prop('disabled', true);

        function finishSuccess(response) {
            var payload = (typeof response === 'object') ? response : JSON.parse(response);
            if (!payload.success) {
                throw new Error(payload.error || 'تعذر حفظ التحويل');
            }

            clearShiftCashIdempotencyKey(cashScope);
            $('#shift_safe_drop_amount').val('');
            $('#shift_safe_drop_reason').val('');
            showPaneAlert('#shiftSafeDropFormAlert', 'تم تسجيل التحويل للخزنة بنجاح.', 'success');

            var summary = payload.data && payload.data.summary ? payload.data.summary : null;
            if (summary) {
                renderSafeDropSummary(summary);
                loadShiftCashSummary();
            } else {
                loadShiftCashSummary();
            }
        }

        function finishFail(xhr, retryFn) {
            var message = 'تعذر حفظ التحويل';
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
            if (code === 'MANAGER_APPROVAL_REQUIRED' || code === 'PERMISSION_DENIED') {
                requestOverrideIfNeeded('pos.drawer.safe_drop', amount, 'تحويل للخزنة — يتطلب اعتماد مدير')
                    .done(function (approval) {
                        retryFn(approval.approval_id);
                    })
                    .fail(function () {
                        showPaneAlert('#shiftSafeDropFormAlert', 'تم إلغاء اعتماد المدير', 'warning');
                        if (cashRecordingEnabled(window.posShiftSafeDropLastSummary)) {
                            $('#shiftSafeDropSaveBtn').prop('disabled', false);
                        }
                    });
                return;
            }
            showPaneAlert('#shiftSafeDropFormAlert', message, 'danger');
            if (cashRecordingEnabled(window.posShiftSafeDropLastSummary)) {
                $('#shiftSafeDropSaveBtn').prop('disabled', false);
            }
        }

        function postSafeDrop(approvalId) {
            var data = {
                amount: amount,
                reason: reason,
                csrf_token: window.POSMAIN_SHIFT_SAFE_DROP_CSRF_TOKEN || '',
                idempotency_key: idempotencyKey,
            };
            if (approvalId) {
                data.manager_approval_id = approvalId;
            }
            $.ajax({
                url: 'do/do_record_shift_safe_drop.php',
                method: 'POST',
                data: data,
            }).done(function (response) {
                try {
                    finishSuccess(response);
                } catch (error) {
                    showPaneAlert('#shiftSafeDropFormAlert', error.message || 'تعذر حفظ التحويل', 'danger');
                }
            }).fail(function (xhr) {
                finishFail(xhr, postSafeDrop);
            }).always(function () {
                if (cashRecordingEnabled(window.posShiftSafeDropLastSummary)) {
                    $('#shiftSafeDropSaveBtn').prop('disabled', false);
                }
            });
        }

        if (!canRecordSafeDrop()) {
            requestOverrideIfNeeded('pos.drawer.safe_drop', amount, 'تحويل للخزنة — يتطلب اعتماد مدير')
                .done(function (approval) {
                    postSafeDrop(approval.approval_id);
                })
                .fail(function () {
                    showPaneAlert('#shiftSafeDropFormAlert', 'تم إلغاء اعتماد المدير', 'warning');
                    if (cashRecordingEnabled(window.posShiftSafeDropLastSummary)) {
                        $('#shiftSafeDropSaveBtn').prop('disabled', false);
                    }
                });
            return;
        }

        postSafeDrop(null);
    }

    function syncTabVisibility() {
        var payoutTab = $('#shiftCashPayoutTab').closest('.nav-item');
        var payinTab = $('#shiftCashPayinTab').closest('.nav-item');

        if (!canRecordExpense()) {
            payoutTab.addClass('d-none');
            $('#shiftCashPayoutPane').removeClass('show active');
            $('#shiftCashPayoutTab').removeClass('active').attr('aria-selected', 'false');
        } else {
            payoutTab.removeClass('d-none');
        }

        if (!window.POSMAIN_CAN_RECORD_SHIFT_PAYIN && !canRecordPayIn()) {
            // pay-in tab stays visible so cashier can request manager override
        }
        payinTab.removeClass('d-none');
    }

    function syncFooterButtons() {
        var activePayin = $('#shiftCashPayinPane').hasClass('active');
        var activeSafeDrop = $('#shiftCashSafeDropPane').hasClass('active');
        $('#shiftExpenseSaveBtn').toggleClass('d-none', activePayin || activeSafeDrop || !canRecordExpense());
        $('#shiftPayinSaveBtn').toggleClass('d-none', !activePayin);
        $('#shiftSafeDropSaveBtn').toggleClass('d-none', !activeSafeDrop);
    }

    window.posShiftExpenseLoadSummary = loadShiftCashSummary;
    window.posShiftExpenseClosePayload = getCloseShiftExpensePayload;

    $(function () {
        syncTabVisibility();

        $('#shiftCashTabs button[data-bs-toggle="pill"]').on('shown.bs.tab', function () {
            syncFooterButtons();
        });

        $('#shiftExpenseModal').on('show.bs.modal', function () {
            syncTabVisibility();
            clearAllShiftCashIdempotencyKeys();
            $('#shiftExpenseFormAlert, #shiftPayinFormAlert, #shiftSafeDropFormAlert').addClass('d-none').text('');
            $('#shift_expense_amount, #shift_payin_amount, #shift_safe_drop_amount').val('');
            $('#shift_expense_reason, #shift_payin_reason, #shift_safe_drop_reason').val('');
            loadShiftCashSummary()
                .catch(function (error) {
                    $('#shiftExpenseList, #shiftPayinList, #shiftSafeDropList').html(
                        '<div class="pos-shift-expense-empty is-danger">' +
                            '<i class="fas fa-times-circle" aria-hidden="true"></i>' +
                            '<p>' + escapeHtml(error.message || 'تعذر تحميل حركات الدرج') + '</p>' +
                        '</div>'
                    );
                    updateDrawerNotice(null, '#shiftExpenseDrawerNotice', '#shift_expense_amount', '#shift_expense_reason', '#shiftExpenseSaveBtn', canRecordExpense());
                    updateDrawerNotice(null, '#shiftPayinDrawerNotice', '#shift_payin_amount', '#shift_payin_reason', '#shiftPayinSaveBtn', canRecordPayIn());
                    updateDrawerNotice(null, '#shiftSafeDropDrawerNotice', '#shift_safe_drop_amount', '#shift_safe_drop_reason', '#shiftSafeDropSaveBtn', canRecordSafeDrop());
                })
                .always(function () {
                    if (!canRecordExpense()) {
                        var payinTab = document.querySelector('#shiftCashPayinTab');
                        if (payinTab && typeof bootstrap !== 'undefined') {
                            bootstrap.Tab.getOrCreateInstance(payinTab).show();
                        }
                    }
                    syncFooterButtons();
                });
        });

        $('#shiftExpenseSaveBtn').on('click', saveShiftExpense);
        $('#shiftPayinSaveBtn').on('click', saveShiftPayIn);
        $('#shiftSafeDropSaveBtn').on('click', saveShiftSafeDrop);
        $('#shift_expense_reason').on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                saveShiftExpense();
            }
        });
        $('#shift_payin_reason').on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                saveShiftPayIn();
            }
        });
        $('#shift_safe_drop_reason').on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                saveShiftSafeDrop();
            }
        });

        loadShiftCashSummary().catch(function () {
            // optional initial badge load
        });
        syncFooterButtons();
    });
})(window, window.jQuery);
