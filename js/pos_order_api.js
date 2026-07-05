(function (window, $) {
    'use strict';

    const API_BASE = 'api/pos/index.php';

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="posmain-csrf-token"]');
        if (meta && meta.getAttribute('content')) {
            return meta.getAttribute('content');
        }

        const input = document.querySelector('input[name="csrf_token"]');
        return input ? input.value : '';
    }

    function createIdempotencyKey(scope) {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return scope + ':' + window.crypto.randomUUID();
        }

        return scope + ':' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2);
    }

    function readCheckedAge(form) {
        const checked = form.querySelector('input[name="age"]:checked');
        if (checked) {
            return parseInt(checked.value, 10) || 1;
        }
        const globalChecked = document.querySelector('input[name="age"]:checked');
        return globalChecked ? (parseInt(globalChecked.value, 10) || 1) : 1;
    }

    function hasDeliveryCustomerFields(form) {
        const name = (form.querySelector('[name="delivery_customer_name"]') || {}).value || '';
        const phone = (form.querySelector('[name="delivery_customer_phone"]') || {}).value || '';
        const address = (form.querySelector('[name="delivery_customer_address"]') || {}).value || '';
        return String(name).trim() !== '' && String(phone).trim() !== '' && String(address).trim() !== '';
    }

    function clearCashierEditState() {
        $('#edit_order_id').val('');
        $('#selected_order_id').val('');
        const form = document.getElementById('posForm');
        if (!form) {
            return;
        }
        const editInput = form.querySelector('input[name="edit_id"]');
        if (editInput) {
            editInput.remove();
        }
        const keyInput = form.querySelector('input[name="idempotency_key"]');
        if (keyInput) {
            keyInput.value = '';
            delete keyInput.dataset.action;
            delete keyInput.dataset.age;
            delete keyInput.dataset.orderId;
            delete keyInput.dataset.revision;
        }
    }

    function readEditId(form) {
        const age = readCheckedAge(form);
        if (age === 2) {
            const tableOrderField = form.querySelector('[name="selected_order_id"]');
            return tableOrderField ? (parseInt(tableOrderField.value, 10) || 0) : 0;
        }

        const fromForm = form.querySelector('[name="edit_id"]');
        const formVal = fromForm ? (parseInt(fromForm.value, 10) || 0) : 0;
        if (formVal > 0) {
            return formVal;
        }

        return parseInt($('#edit_order_id').val() || '0', 10) || 0;
    }

    function resolveRoute(action, form) {
        if (action === 'free_table') {
            return 'orders.table.free';
        }

        if (action === 'split_cash') {
            return 'orders.split-payment';
        }

        const editId = readEditId(form);
        const age = readCheckedAge(form);
        const isTable = age === 2;
        const isDelivery = age === 3 || hasDeliveryCustomerFields(form);

        if (editId > 0) {
            if (isTable && (action === 'save' || action === 'print_receipt' || action === 'cash')) {
                return action === 'cash' ? 'orders.payment' : 'orders.table';
            }
            return 'orders.edit';
        }

        if (isTable && (action === 'save' || action === 'print_receipt')) {
            return 'orders.table';
        }

        if (isTable && action === 'cash') {
            return 'orders.payment';
        }

        if (isDelivery) {
            return 'orders.delivery';
        }

        if (action === 'save' || action === 'print_receipt' || action === 'cash') {
            return 'orders.takeaway';
        }

        return null;
    }

    function collectLineItems(form) {
        const items = [];
        const names = form.querySelectorAll('[name="itmname[]"], [name="itmname"]');
        if (!names.length) {
            return items;
        }

        const qtyFields = form.querySelectorAll('[name="itmqty[]"], [name="itmqty"]');
        const priceFields = form.querySelectorAll('[name="itmprice[]"], [name="itmprice"]');
        const discFields = form.querySelectorAll('[name="itmdisc[]"], [name="itmdisc"]');
        const noteFields = form.querySelectorAll('[name="itmnote[]"], [name="itmnote"]');

        names.forEach(function (field, index) {
            const id = parseInt(field.value, 10);
            if (!id) {
                return;
            }
            items.push({
                id: id,
                qty: parseFloat((qtyFields[index] || {}).value || 1),
                price: parseFloat((priceFields[index] || {}).value || 0),
                discount: parseFloat((discFields[index] || {}).value || 0),
                note: String((noteFields[index] || {}).value || '')
            });
        });

        return items;
    }

    function fieldValue(form, name, fallback) {
        const field = form.querySelector('[name="' + name + '"]');
        if (!field) {
            return fallback !== undefined ? fallback : '';
        }
        return field.value;
    }

    function buildOrderPayload(form, action) {
        const payload = {};
        const names = [
            'pro_tybe', 'store_id', 'pro_serial', 'pro_date', 'accural_date', 'acc2_id', 'emp_id',
            'headtotal', 'headdisc', 'headplus', 'headnet', 'fund_id', 'info', 'age', 'table_id',
            'selected_table_id', 'selected_order_id', 'edit_id', 'jal_name', 'jal_notes', 'jal_amount',
            'delivery_customer_name', 'delivery_customer_phone', 'delivery_customer_address',
            'delivery_zone_name', 'delivery_fee', 'pos_customer_id', 'payment_fund_id', 'payment_bank_id',
            'paid_cash', 'paid_bank', 'paid', 'empty_table_after_payment', 'pos_split_payment_payload',
            'manager_approval_id', 'price_override_approval_id'
        ];

        names.forEach(function (name) {
            const value = fieldValue(form, name, null);
            if (value !== null && value !== '') {
                payload[name] = value;
            }
        });

        payload.age = String(readCheckedAge(form));

        const editId = readEditId(form);
        if (editId > 0) {
            payload.edit_id = editId;
            payload.order_id = editId;
        }

        payload.submit = action;
        payload.submit_action = action;
        payload.itmname = [];
        payload.itmqty = [];
        payload.itmprice = [];
        payload.itmdisc = [];
        payload.itmnote = [];

        collectLineItems(form).forEach(function (item) {
            payload.itmname.push(item.id);
            payload.itmqty.push(item.qty);
            payload.itmprice.push(item.price);
            payload.itmdisc.push(item.discount);
            payload.itmnote.push(item.note);
        });

        payload.items = collectLineItems(form);

        const scope = action === 'save'
            ? 'pos.order.save'
            : (action === 'print_receipt'
                ? 'pos.order.print'
                : (action === 'free_table' ? 'pos.table.free' : 'pos.order.pay'));
        payload.idempotency_key = (window.POSOrderDraft && typeof window.POSOrderDraft.ensureFormIdempotencyKey === 'function')
            ? window.POSOrderDraft.ensureFormIdempotencyKey(form, action)
            : (fieldValue(form, 'idempotency_key', '') || createIdempotencyKey(scope));

        const route = resolveRoute(action, form);

        if (route === 'orders.table' || route === 'orders.edit') {
            payload.table_id = parseInt(fieldValue(form, 'selected_table_id', fieldValue(form, 'table_id', 0)), 10);
            if (!payload.order_id) {
                payload.order_id = parseInt(fieldValue(form, 'edit_id', fieldValue(form, 'selected_order_id', 0)), 10);
            }
            payload.order_date = fieldValue(form, 'pro_date', new Date().toISOString().slice(0, 10));
            payload.total = parseFloat(fieldValue(form, 'headtotal', 0));
            payload.discount = parseFloat(fieldValue(form, 'headdisc', 0));
            payload.net = parseFloat(fieldValue(form, 'headnet', 0));
        }

        if (route === 'orders.payment') {
            payload.table_id = parseInt(fieldValue(form, 'selected_table_id', fieldValue(form, 'table_id', 0)), 10);
            payload.order_id = parseInt(fieldValue(form, 'edit_id', fieldValue(form, 'selected_order_id', 0)), 10);
            payload.paid = parseFloat(fieldValue(form, 'paid', 0))
                || (parseFloat(fieldValue(form, 'paid_cash', 0)) + parseFloat(fieldValue(form, 'paid_bank', 0)));
            payload.net = parseFloat(fieldValue(form, 'headnet', 0));
            payload.discount = parseFloat(fieldValue(form, 'headdisc', 0));
            payload.payment_method = parseFloat(fieldValue(form, 'paid_bank', 0)) > 0 ? 'bank' : 'cash';
            payload.notes = fieldValue(form, 'info', '');
        }

        if (route === 'orders.table.free') {
            payload.table_id = parseInt(fieldValue(form, 'selected_table_id', fieldValue(form, 'table_id', 0)), 10);
        }

        if (action === 'split_cash') {
            let splitPayload = payload.pos_split_payment_payload || '';
            if (!splitPayload && typeof window.POSMainGetSplitPaymentPayload === 'function') {
                splitPayload = window.POSMainGetSplitPaymentPayload();
            }

            let splitItems = [];
            if (splitPayload) {
                try {
                    const parsed = typeof splitPayload === 'string' ? JSON.parse(splitPayload) : splitPayload;
                    if (Array.isArray(parsed)) {
                        splitItems = parsed;
                    } else if (parsed && Array.isArray(parsed.rows)) {
                        splitItems = parsed.rows;
                    } else if (parsed && Array.isArray(parsed.items)) {
                        splitItems = parsed.items;
                    }
                } catch (error) {
                    console.warn('split payload parse failed', error);
                }
            }

            payload.split_items = splitItems;
            payload.order_id = parseInt(
                fieldValue(form, 'selected_order_id', fieldValue(form, 'edit_id', fieldValue(form, 'order_id', 0))),
                10
            );
            payload.table_id = parseInt(
                fieldValue(form, 'selected_table_id', fieldValue(form, 'table_id', 0)),
                10
            );
            payload.paid_amount = parseFloat(
                fieldValue(form, 'pos_split_payment_total', 0)
                    || fieldValue(form, 'paid', 0)
                    || (parseFloat(fieldValue(form, 'paid_cash', 0)) + parseFloat(fieldValue(form, 'paid_bank', 0)))
            );
            payload.payment_method = fieldValue(form, 'pos_split_payment_method', '')
                || (parseFloat(fieldValue(form, 'paid_bank', 0)) > 0 ? 'bank' : 'cash');
            payload.order_date = fieldValue(form, 'pro_date', new Date().toISOString().slice(0, 10));
            payload.total = parseFloat(fieldValue(form, 'headtotal', 0));
            payload.discount = parseFloat(fieldValue(form, 'headdisc', 0));
            payload.net = parseFloat(fieldValue(form, 'headnet', 0));
        }

        return payload;
    }

    function postOrderRoute(route, payload) {
        const url = API_BASE + '?route=' + encodeURIComponent(route);
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        const token = getCsrfToken();
        if (token) {
            headers['X-CSRF-Token'] = token;
            headers['X-POSMAIN-CSRF-TOKEN'] = token;
        }

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify(payload || {})
        }).then(function (response) {
            return response.json().then(function (body) {
                return {
                    ok: response.ok,
                    status: response.status,
                    body: body
                };
            });
        });
    }

    function applyOrderSuccessState(body, action) {
        const state = body.updated_state || {};
        const orderId = state.order_id || body.order_id || 0;
        if (orderId > 0) {
            const form = document.getElementById('posForm');
            const age = form ? readCheckedAge(form) : 1;
            if (age === 2) {
                if (window.jQuery) {
                    window.jQuery('#selected_order_id').val(String(orderId));
                }
            } else {
                if (window.jQuery) {
                    window.jQuery('#edit_order_id').val(orderId);
                    window.jQuery('#selected_order_id').val(orderId);
                }
                if (form) {
                    let editInput = form.querySelector('input[name="edit_id"]');
                    if (!editInput) {
                        editInput = document.createElement('input');
                        editInput.type = 'hidden';
                        editInput.name = 'edit_id';
                        form.appendChild(editInput);
                    }
                    editInput.value = String(orderId);
                }
            }
            if (window.history && typeof window.history.replaceState === 'function' && form) {
                const params = new URLSearchParams(window.location.search);
                params.set('edit', String(orderId));
                const tableId = state.table_id || body.table_id || fieldValue(form, 'table_id', '');
                if (tableId) {
                    params.set('table', String(tableId));
                } else if (age !== 2) {
                    params.delete('table');
                }
                window.history.replaceState({}, '', window.location.pathname + '?' + params.toString());
            }
        }

        if (action === 'cash' || action === 'split_cash') {
            if (typeof window.POSMainResetCartAfterPayment === 'function') {
                window.POSMainResetCartAfterPayment(body);
            }
        }

        if (action === 'free_table' && typeof window.POSMainRefreshTableState === 'function') {
            window.POSMainRefreshTableState(body);
        }
    }

    function showOrderSuccess(message) {
        if (typeof window.POSShowOrderSuccess === 'function') {
            window.POSShowOrderSuccess(message);
            return;
        }
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({ icon: 'success', title: 'تم بنجاح', text: message, timer: 1500, showConfirmButton: false });
        }
    }

    function showOrderError(message, retryFn) {
        if (window.Swal && typeof window.Swal.fire === 'function') {
            const options = {
                icon: 'error',
                title: 'خطأ',
                text: message
            };
            if (typeof retryFn === 'function') {
                options.showCancelButton = true;
                options.confirmButtonText = 'إعادة المحاولة';
                options.cancelButtonText = 'إغلاق';
                window.Swal.fire(options).then(function (result) {
                    if (result.isConfirmed) {
                        retryFn();
                    }
                });
                return;
            }
            window.Swal.fire(options);
            return;
        }
        alert(message);
    }

    function restorePrintOrderButton() {
        const printOrderBtn = $('.pos-print-order-btn');
        if (printOrderBtn.length > 0) {
            printOrderBtn.prop('disabled', false).html('<i class="fas fa-print"></i> طباعة الطلب');
        }
    }

    function restorePayConfirmButton() {
        const printBtn = $('.pos-pay-confirm-btn');
        if (printBtn.length > 0) {
            printBtn.prop('disabled', false).html('دفع وطباعة');
        }
        const splitBtn = $('.pos-split-pay-confirm-btn');
        if (splitBtn.length > 0) {
            splitBtn.prop('disabled', false).text('دفع المحدد');
        }
    }

    function isDraftSaveAction(action) {
        return action === 'save' || action === 'print_receipt';
    }

    function isPaymentAction(action) {
        return action === 'cash' || action === 'split_cash';
    }

    function resetOrderScreenAfterCommit(body, action) {
        if (typeof window.POSMainResetOrderScreen === 'function') {
            const state = (body && body.updated_state) ? body.updated_state : {};
            window.POSMainResetOrderScreen({
                orderId: parseInt(state.order_id || (body && body.order_id) || 0, 10) || 0,
                action: action
            });
            return;
        }

        const draft = window.POSOrderDraft;
        if (draft && isDraftSaveAction(action)) {
            draft.markSaved(body || {});
        }
    }

    function restoreSubmitButtons(action) {
        const draft = window.POSOrderDraft;
        if (draft && isDraftSaveAction(action)) {
            draft.markSaveFailed();
            restorePrintOrderButton();
            return;
        }

        restorePrintOrderButton();
        restorePayConfirmButton();
    }

    function handleOrderResponse(result, action) {
        const body = result.body || {};
        const draft = window.POSOrderDraft;
        if (!result.ok || body.success === false) {
            const message = body.message || body.error || 'حدث خطأ أثناء معالجة الطلب';
            const code = body.code || '';
            if (code === 'MANAGER_APPROVAL_REQUIRED' && window.POSMAIN && typeof window.POSMAIN.requestManagerOverride === 'function') {
                const escalationKey = body.escalation_permission_key || body.permission_key || 'pos.discount.manual_pct.limit';
                return window.POSMAIN.requestManagerOverride(escalationKey, {
                    message: message,
                }).then(function (approval) {
                    const form = document.getElementById('posForm');
                    if (form && approval && approval.approval_id) {
                        let input = form.querySelector('input[name="manager_approval_id"]');
                        if (!input) {
                            input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'manager_approval_id';
                            form.appendChild(input);
                        }
                        input.value = String(approval.approval_id);
                    }
                    return submitFromForm(form, action);
                }).catch(function () {
                    showOrderError('تم إلغاء اعتماد المدير');
                    restoreSubmitButtons(action);
                    if (isPaymentAction(action) && window.jQuery) {
                        window.jQuery('#paymentModal').modal('show');
                    }
                    return { success: false, body: body };
                });
            }
            showOrderError(message);
            restoreSubmitButtons(action);
            if (isPaymentAction(action) && window.jQuery) {
                window.jQuery('#paymentModal').modal('show');
            }
            return { success: false, body: body };
        }

        if (body.offline_queued) {
            applyOrderSuccessState(body, action);
            showOrderSuccess(body.message || 'تم حفظ الطلب محلياً');
            if (isDraftSaveAction(action)) {
                restorePrintOrderButton();
                resetOrderScreenAfterCommit(body, action);
            } else {
                restoreSubmitButtons(action);
            }
            return { success: true, body: body, offlineQueued: true };
        }

        if (body.idempotency_replayed && body.print_url && (action === 'cash' || action === 'split_cash' || action === 'print_receipt')) {
            delete body.print_url;
        }

        if (body.print_url && (action === 'cash' || action === 'split_cash' || action === 'print_receipt')) {
            applyOrderSuccessState(body, action);
            window.location.href = body.print_url;
            return { success: true, body: body };
        }

        applyOrderSuccessState(body, action);
        if (isDraftSaveAction(action)) {
            showOrderSuccess(body.message || 'تم حفظ الطلب بنجاح');
            restorePrintOrderButton();
            resetOrderScreenAfterCommit(body, action);
        } else {
            showOrderSuccess(body.message || 'تم حفظ الطلب بنجاح');
            restoreSubmitButtons(action);
            if (isPaymentAction(action)) {
                if (window.jQuery) {
                    window.jQuery('#paymentModal').modal('hide');
                }
                resetOrderScreenAfterCommit(body, action);
            }
        }
        return { success: true, body: body };
    }

    function prepareDraftForSubmit(draft, action) {
        if (!draft) {
            return;
        }
        if (isDraftSaveAction(action)) {
            draft.markSaving();
            draft.rotateIdempotencyKey(action);
            return;
        }
        if (isPaymentAction(action)) {
            draft.rotateIdempotencyKey(action);
        }
    }

    function submitFromForm(form, action) {
        const draft = window.POSOrderDraft;
        if (draft && !draft.canSave(action)) {
            return Promise.resolve({ success: false, blocked: true });
        }

        const route = resolveRoute(action, form);
        if (!route) {
            if (draft && isDraftSaveAction(action)) {
                draft.markSaveFailed();
                restorePrintOrderButton();
            } else if (isPaymentAction(action)) {
                restorePayConfirmButton();
            }
            return Promise.resolve({ success: false, noRoute: true });
        }

        prepareDraftForSubmit(draft, action);

        const payload = buildOrderPayload(form, action);
        const attempt = function () {
            return postOrderRoute(route, payload).then(function (result) {
                return handleOrderResponse(result, action);
            });
        };

        return attempt().catch(function () {
            restoreSubmitButtons(action);
            if (isPaymentAction(action) && window.jQuery) {
                window.jQuery('#paymentModal').modal('show');
            }
            return new Promise(function (resolve) {
                showOrderError('حدث خطأ في الاتصال بالخادم', function () {
                    prepareDraftForSubmit(draft, action);
                    attempt().then(resolve).catch(function () {
                        showOrderError('تعذر إتمام الطلب بعد إعادة المحاولة');
                        restoreSubmitButtons(action);
                        if (isPaymentAction(action) && window.jQuery) {
                            window.jQuery('#paymentModal').modal('show');
                        }
                        resolve({ success: false, networkError: true });
                    });
                });
            });
        });
    }

    window.POSOrderApi = {
        API_BASE: API_BASE,
        getCsrfToken: getCsrfToken,
        resolveRoute: resolveRoute,
        readCheckedAge: readCheckedAge,
        readEditId: readEditId,
        clearCashierEditState: clearCashierEditState,
        buildOrderPayload: buildOrderPayload,
        postOrderRoute: postOrderRoute,
        handleOrderResponse: handleOrderResponse,
        submitFromForm: submitFromForm,
        createIdempotencyKey: createIdempotencyKey,
        applyOrderSuccessState: applyOrderSuccessState,
        showOrderSuccess: showOrderSuccess,
        restoreSubmitButtons: restoreSubmitButtons,
        restorePayConfirmButton: restorePayConfirmButton
    };
})(window, window.jQuery);
