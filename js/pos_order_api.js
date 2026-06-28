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
        return checked ? parseInt(checked.value, 10) : 1;
    }

    function hasDeliveryCustomerFields(form) {
        const name = (form.querySelector('[name="delivery_customer_name"]') || {}).value || '';
        const phone = (form.querySelector('[name="delivery_customer_phone"]') || {}).value || '';
        const address = (form.querySelector('[name="delivery_customer_address"]') || {}).value || '';
        return String(name).trim() !== '' && String(phone).trim() !== '' && String(address).trim() !== '';
    }

    function readEditId(form) {
        return parseInt(
            (form.querySelector('[name="edit_id"]') || {}).value
            || ($('#edit_order_id').val() || $('#selected_order_id').val() || '0'),
            10
        ) || 0;
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
            'paid_cash', 'paid_bank', 'paid', 'empty_table_after_payment', 'pos_split_payment_payload'
        ];

        names.forEach(function (name) {
            const value = fieldValue(form, name, null);
            if (value !== null && value !== '') {
                payload[name] = value;
            }
        });

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
        payload.idempotency_key = fieldValue(form, 'idempotency_key', '') || createIdempotencyKey(scope);

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
            if (splitPayload) {
                try {
                    const parsed = typeof splitPayload === 'string' ? JSON.parse(splitPayload) : splitPayload;
                    payload.order_id = parseInt(parsed.order_id || fieldValue(form, 'selected_order_id', 0), 10);
                    payload.table_id = parseInt(parsed.table_id || fieldValue(form, 'table_id', 0), 10);
                    payload.items = parsed.items || [];
                    payload.paid_amount = parseFloat(parsed.paid_amount || payload.paid || 0);
                    payload.payment_method = parsed.payment_method || (parseFloat(payload.paid_bank || 0) > 0 ? 'bank' : 'cash');
                } catch (error) {
                    console.warn('split payload parse failed', error);
                }
            }
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
            $('#edit_order_id').val(orderId);
            $('#selected_order_id').val(orderId);
            const form = document.getElementById('posForm');
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
            if (window.history && typeof window.history.replaceState === 'function') {
                const params = new URLSearchParams(window.location.search);
                params.set('edit', String(orderId));
                const tableId = state.table_id || body.table_id || fieldValue(form || document.createElement('form'), 'table_id', '');
                if (tableId) {
                    params.set('table', String(tableId));
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

    function restoreSubmitButtons() {
        const saveBtn = $('.pos-save-order-btn');
        const printOrderBtn = $('.pos-print-order-btn');
        const printBtn = $('.pos-pay-confirm-btn');
        if (saveBtn.length > 0) saveBtn.prop('disabled', false).html('<i class="fas fa-save"></i> حفظ');
        if (printOrderBtn.length > 0) printOrderBtn.prop('disabled', false).html('<i class="fas fa-print"></i> طباعة الطلب');
        if (printBtn.length > 0) printBtn.prop('disabled', false).html('<i class="fas fa-check"></i> تأكيد الدفع');
    }

    function handleOrderResponse(result, action) {
        const body = result.body || {};
        if (!result.ok || body.success === false) {
            const message = body.message || 'حدث خطأ أثناء معالجة الطلب';
            showOrderError(message);
            restoreSubmitButtons();
            return { success: false, body: body };
        }

        if (body.offline_queued) {
            applyOrderSuccessState(body, action);
            showOrderSuccess(body.message || 'تم حفظ الطلب محلياً');
            restoreSubmitButtons();
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
        showOrderSuccess(body.message || 'تم حفظ الطلب بنجاح');
        restoreSubmitButtons();
        return { success: true, body: body };
    }

    function submitFromForm(form, action) {
        const route = resolveRoute(action, form);
        if (!route) {
            return Promise.resolve({ success: false, noRoute: true });
        }

        const payload = buildOrderPayload(form, action);
        const attempt = function () {
            return postOrderRoute(route, payload).then(function (result) {
                return handleOrderResponse(result, action);
            });
        };

        return attempt().catch(function () {
            restoreSubmitButtons();
            return new Promise(function (resolve) {
                showOrderError('حدث خطأ في الاتصال بالخادم', function () {
                    attempt().then(resolve).catch(function () {
                        showOrderError('تعذر إتمام الطلب بعد إعادة المحاولة');
                        restoreSubmitButtons();
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
        buildOrderPayload: buildOrderPayload,
        postOrderRoute: postOrderRoute,
        handleOrderResponse: handleOrderResponse,
        submitFromForm: submitFromForm,
        createIdempotencyKey: createIdempotencyKey,
        applyOrderSuccessState: applyOrderSuccessState,
        showOrderSuccess: showOrderSuccess,
        restoreSubmitButtons: restoreSubmitButtons
    };
})(window, window.jQuery);
