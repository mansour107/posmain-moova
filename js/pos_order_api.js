(function (window, $) {
    'use strict';

    const API_BASE = 'api/pos/index.php';
    const submissionStates = new WeakMap();

    function submissionStateFor(form) {
        let state = submissionStates.get(form);
        if (!state) {
            state = {
                active: null,
                retryable: null
            };
            submissionStates.set(form, state);
        }
        return state;
    }

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
        $('#order_mutation_version').val('0');
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

    function decimalString(value, scale, fallback) {
        const fallbackValue = fallback !== undefined ? fallback : '0';
        let raw = value === null || value === undefined || value === ''
            ? String(fallbackValue)
            : String(value);
        raw = raw.trim();
        if (!/^\d+(?:\.\d+)?$/.test(raw)) {
            throw new Error('INVALID_DECIMAL_INPUT');
        }

        const parts = raw.split('.');
        let whole = parts[0].replace(/^0+(?=\d)/, '');
        let fraction = parts[1] || '';
        if (fraction.length > scale) {
            throw new Error('DECIMAL_SCALE_EXCEEDED');
        }
        fraction = fraction.padEnd(scale, '0');

        return scale > 0 ? whole + '.' + fraction : whole;
    }

    function addDecimalStrings(left, right, scale) {
        const normalizedLeft = decimalString(left, scale, '0');
        const normalizedRight = decimalString(right, scale, '0');
        const leftDigits = normalizedLeft.replace('.', '');
        const rightDigits = normalizedRight.replace('.', '');
        const width = Math.max(leftDigits.length, rightDigits.length);
        const a = leftDigits.padStart(width, '0');
        const b = rightDigits.padStart(width, '0');
        let carry = 0;
        let result = '';

        for (let index = width - 1; index >= 0; index -= 1) {
            const sum = Number(a[index]) + Number(b[index]) + carry;
            result = String(sum % 10) + result;
            carry = Math.floor(sum / 10);
        }
        if (carry > 0) {
            result = String(carry) + result;
        }
        result = result.padStart(scale + 1, '0');
        if (scale > 0) {
            result = result.slice(0, -scale) + '.' + result.slice(-scale);
        }

        return decimalString(result, scale, '0');
    }

    function decimalToScaledInteger(value, scale) {
        return BigInt(decimalString(value, scale, '0').replace('.', ''));
    }

    function scaledIntegerToDecimal(value, scale) {
        const negative = value < 0n;
        let digits = (negative ? -value : value).toString().padStart(scale + 1, '0');
        if (scale > 0) {
            digits = digits.slice(0, -scale) + '.' + digits.slice(-scale);
        }

        return (negative ? '-' : '') + digits;
    }

    function compareDecimalStrings(left, right, scale) {
        const leftInteger = decimalToScaledInteger(left, scale);
        const rightInteger = decimalToScaledInteger(right, scale);
        return leftInteger === rightInteger ? 0 : (leftInteger < rightInteger ? -1 : 1);
    }

    function subtractDecimalStrings(left, right, scale) {
        return scaledIntegerToDecimal(
            decimalToScaledInteger(left, scale) - decimalToScaledInteger(right, scale),
            scale
        );
    }

    function roundedIntegerRatio(numerator, denominator) {
        if (denominator <= 0n || numerator < 0n) {
            throw new Error('INVALID_DECIMAL_RATIO');
        }
        const quotient = numerator / denominator;
        const remainder = numerator % denominator;

        return remainder * 2n >= denominator ? quotient + 1n : quotient;
    }

    function prorateMoneyByQuantity(lineAmount, selectedQuantity, availableQuantity) {
        const lineInteger = decimalToScaledInteger(lineAmount, 2);
        const selectedInteger = decimalToScaledInteger(selectedQuantity, 6);
        const availableInteger = decimalToScaledInteger(availableQuantity, 6);
        if (availableInteger <= 0n || selectedInteger < 0n || selectedInteger > availableInteger) {
            throw new Error('INVALID_PRORATED_QUANTITY');
        }

        return scaledIntegerToDecimal(
            roundedIntegerRatio(lineInteger * selectedInteger, availableInteger),
            2
        );
    }

    function lineTotalFromQuantityAndUnitPrice(quantity, unitPrice) {
        const quantityInteger = decimalToScaledInteger(quantity, 6);
        const unitPriceInteger = decimalToScaledInteger(unitPrice, 6);
        if (quantityInteger < 0n || unitPriceInteger < 0n) {
            throw new Error('INVALID_LINE_AMOUNT');
        }

        return scaledIntegerToDecimal(
            roundedIntegerRatio(quantityInteger * unitPriceInteger, 10000000000n),
            2
        );
    }

    function allocateProportionalMoney(amount, selectedGross, orderGross) {
        const amountInteger = decimalToScaledInteger(amount, 2);
        const selectedInteger = decimalToScaledInteger(selectedGross, 2);
        const orderInteger = decimalToScaledInteger(orderGross, 2);
        if (orderInteger <= 0n || selectedInteger < 0n || selectedInteger > orderInteger) {
            throw new Error('INVALID_MONEY_ALLOCATION');
        }
        if (selectedInteger === orderInteger) {
            return scaledIntegerToDecimal(amountInteger, 2);
        }

        return scaledIntegerToDecimal(
            roundedIntegerRatio(amountInteger * selectedInteger, orderInteger),
            2
        );
    }

    function quantityFromIntegerRatio(numerator, denominator) {
        const numeratorRaw = String(numerator === null || numerator === undefined ? '' : numerator).trim();
        const denominatorRaw = String(denominator === null || denominator === undefined ? '' : denominator).trim();
        if (!/^\d+$/.test(numeratorRaw) || !/^\d+$/.test(denominatorRaw)) {
            throw new Error('INVALID_QUANTITY_RATIO');
        }
        const numeratorInteger = BigInt(numeratorRaw);
        const denominatorInteger = BigInt(denominatorRaw);
        if (denominatorInteger <= 0n) {
            throw new Error('INVALID_QUANTITY_RATIO');
        }

        return scaledIntegerToDecimal(
            roundedIntegerRatio(numeratorInteger * 1000000n, denominatorInteger),
            6
        );
    }

    function moneyFromPercentage(amount, percentage) {
        const amountInteger = decimalToScaledInteger(amount, 2);
        const percentageInteger = decimalToScaledInteger(percentage, 6);
        if (amountInteger < 0n || percentageInteger < 0n || percentageInteger > 100000000n) {
            throw new Error('INVALID_PERCENTAGE');
        }

        return scaledIntegerToDecimal(
            roundedIntegerRatio(amountInteger * percentageInteger, 100000000n),
            2
        );
    }

    function percentageFromMoney(amount, total) {
        const amountInteger = decimalToScaledInteger(amount, 2);
        const totalInteger = decimalToScaledInteger(total, 2);
        if (amountInteger < 0n || totalInteger < 0n || amountInteger > totalInteger) {
            throw new Error('INVALID_PERCENTAGE_AMOUNT');
        }
        if (totalInteger === 0n) {
            return '0.000000';
        }

        return scaledIntegerToDecimal(
            roundedIntegerRatio(amountInteger * 100000000n, totalInteger),
            6
        );
    }

    function isPositiveDecimal(value, scale) {
        return decimalString(value, scale, '0').replace('.', '').replace(/^0+/, '') !== '';
    }

    function paymentTenders(form) {
        const cash = decimalString(fieldValue(form, 'paid_cash', '0'), 2, '0');
        const bank = decimalString(fieldValue(form, 'paid_bank', '0'), 2, '0');
        const notes = String(fieldValue(form, 'info', '') || '');
        const tenders = [];

        // Apply non-cash first so cash is the only tender that can produce change.
        if (isPositiveDecimal(bank, 2)) {
            tenders.push({
                payment_method: 'bank',
                amount: bank,
                reference_no: notes
            });
        }
        if (isPositiveDecimal(cash, 2)) {
            tenders.push({
                payment_method: 'cash',
                amount: cash,
                reference_no: notes
            });
        }

        return {
            cash: cash,
            bank: bank,
            total: addDecimalStrings(cash, bank, 2),
            tenders: tenders
        };
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
        const preparationFields = form.querySelectorAll('[name="itmpreparation[]"], [name="itmpreparation"]');

        names.forEach(function (field, index) {
            const id = parseInt(field.value, 10);
            if (!id) {
                return;
            }
            let preparationValues = [];
            const preparationRaw = String((preparationFields[index] || {}).value || '').trim();
            if (preparationRaw !== '') {
                try {
                    const parsedPreparation = JSON.parse(preparationRaw);
                    if (Array.isArray(parsedPreparation)) {
                        preparationValues = parsedPreparation;
                    }
                } catch (ignored) {
                    preparationValues = [];
                }
            }
            items.push({
                id: id,
                qty: decimalString((qtyFields[index] || {}).value, 6, '1'),
                price: decimalString((priceFields[index] || {}).value, 6, '0'),
                discount: decimalString((discFields[index] || {}).value, 6, '0'),
                note: String((noteFields[index] || {}).value || ''),
                preparation_values: preparationValues
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
            'delivery_zone_id', 'delivery_zone_name', 'delivery_fee', 'delivery_worker_id',
            'collection_mode', 'courier_source', 'pos_customer_id', 'payment_fund_id', 'payment_bank_id',
            'paid_cash', 'paid_bank', 'paid', 'empty_table_after_payment', 'pos_split_payment_payload',
            'manager_approval_id', 'price_override_approval_id', 'mutation_version'
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
        payload.itmpreparation = [];

        collectLineItems(form).forEach(function (item) {
            payload.itmname.push(item.id);
            payload.itmqty.push(item.qty);
            payload.itmprice.push(item.price);
            payload.itmdisc.push(item.discount);
            payload.itmnote.push(item.note);
            payload.itmpreparation.push(JSON.stringify(item.preparation_values || []));
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

        if (
            (route === 'orders.table' || route === 'orders.table.free' || route === 'orders.payment')
            && window.POSMAIN_TABLE_OPEN_OVERRIDE
            && window.POSMAIN_TABLE_OPEN_OVERRIDE.approval_id
            && !payload.manager_approval_id
        ) {
            payload.manager_approval_id = window.POSMAIN_TABLE_OPEN_OVERRIDE.approval_id;
        }

        if (route === 'orders.table' || route === 'orders.edit') {
            payload.table_id = parseInt(fieldValue(form, 'selected_table_id', fieldValue(form, 'table_id', 0)), 10);
            if (!payload.order_id) {
                payload.order_id = parseInt(fieldValue(form, 'edit_id', fieldValue(form, 'selected_order_id', 0)), 10);
            }
            payload.order_date = fieldValue(form, 'pro_date', new Date().toISOString().slice(0, 10));
            payload.total = decimalString(fieldValue(form, 'headtotal', '0'), 2, '0');
            payload.discount = decimalString(fieldValue(form, 'headdisc', '0'), 2, '0');
            payload.net = decimalString(fieldValue(form, 'headnet', '0'), 2, '0');
        }

        if (route === 'orders.payment') {
            const payment = paymentTenders(form);
            payload.table_id = parseInt(fieldValue(form, 'selected_table_id', fieldValue(form, 'table_id', 0)), 10);
            payload.order_id = parseInt(fieldValue(form, 'edit_id', fieldValue(form, 'selected_order_id', 0)), 10);
            payload.paid_cash = payment.cash;
            payload.paid_bank = payment.bank;
            payload.paid = payment.total;
            payload.tenders = payment.tenders;
            payload.net = decimalString(fieldValue(form, 'headnet', '0'), 2, '0');
            payload.discount = decimalString(fieldValue(form, 'headdisc', '0'), 2, '0');
            payload.total = decimalString(fieldValue(form, 'headtotal', '0'), 2, '0');
            payload.order_date = fieldValue(form, 'pro_date', new Date().toISOString().slice(0, 10));
            payload.payment_method = payment.tenders.length === 1
                ? payment.tenders[0].payment_method
                : 'mixed';
            payload.notes = fieldValue(form, 'info', '');
            if (!payload.items || !payload.items.length) {
                payload.items = collectLineItems(form);
            }
        }

        if (route === 'orders.table.free') {
            payload.table_id = parseInt(fieldValue(form, 'selected_table_id', fieldValue(form, 'table_id', 0)), 10);
        }

        if (action === 'split_cash') {
            const payment = paymentTenders(form);
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
            payload.paid_cash = payment.cash;
            payload.paid_bank = payment.bank;
            payload.paid_amount = decimalString(
                fieldValue(form, 'pos_split_payment_total', '')
                    || payment.total,
                2,
                '0'
            );
            payload.tenders = payment.tenders;
            payload.payment_method = fieldValue(form, 'pos_split_payment_method', '')
                || (payment.tenders.length === 1 ? payment.tenders[0].payment_method : 'mixed');
            payload.order_date = fieldValue(form, 'pro_date', new Date().toISOString().slice(0, 10));
            payload.total = decimalString(fieldValue(form, 'headtotal', '0'), 2, '0');
            payload.discount = decimalString(fieldValue(form, 'headdisc', '0'), 2, '0');
            payload.net = decimalString(fieldValue(form, 'headnet', '0'), 2, '0');
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
        const mutationVersion = parseInt(state.mutation_version || body.mutation_version || 0, 10) || 0;
        if (mutationVersion > 0 && window.jQuery) {
            window.jQuery('#order_mutation_version').val(String(mutationVersion));
        }
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

    function showOrderError(message, retryFn, cancelFn) {
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
                    } else if (typeof cancelFn === 'function') {
                        cancelFn();
                    }
                });
                return;
            }
            window.Swal.fire(options);
            return;
        }
        alert(message);
        if (typeof cancelFn === 'function') {
            cancelFn();
        }
    }

    function userFacingOrderError(body) {
        body = body || {};
        const errorCode = String(body.code || body.error_code || body.error || body.message || '').trim();
        const messages = {
            PREPARATION_VALUE_REQUIRED: 'اختر عدد ملاعق السكر للصنف ثم حاول إتمام الطلب مرة أخرى.',
            PREPARATION_FIELD_NOT_ALLOWED: 'خيار التحضير المحدد لم يعد متاحاً لهذا الصنف. أعد إضافة الصنف ثم حاول مرة أخرى.',
            PREPARATION_VALUE_INVALID: 'عدد ملاعق السكر غير صحيح. أعد اختيار العدد ثم حاول مرة أخرى.',
            KITCHEN_TICKET_INCOMPLETE: 'تعذر إرسال تذكرة المطبخ كاملة. راجع الصنف وإضافاته وتعليمات التحضير ثم أعد المحاولة.',
            IDEMPOTENCY_REQUIRED: 'تعذر إرسال الطلب: مفتاح الحماية مفقود. أعد المحاولة.',
            IDEMPOTENCY_CONFLICT: 'تم إرسال نفس العملية ببيانات مختلفة. أغلق النافذة وافتح الدفع من جديد إن لزم.',
            IDEMPOTENCY_PROCESSING: 'الطلب قيد المعالجة. انتظر لحظات ثم أعد المحاولة بنفس العملية.',
            DELIVERY_ZONE_INVALID: 'منطقة التوصيل المحددة غير متاحة حالياً. افتح بيانات التوصيل واختر المنطقة مرة أخرى.',
            MUTATION_VERSION_REQUIRED: 'تعذر التحقق من نسخة الطلب. أعد تحميل الطلب قبل الحفظ.',
            STALE_ORDER_VERSION: 'تم تعديل الطلب من جهاز آخر. أعد تحميل الطلب قبل الحفظ.'
        };

        return messages[errorCode]
            || String(body.message || body.error || 'حدث خطأ أثناء معالجة الطلب');
    }

    function sleep(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, ms);
        });
    }

    function isIdempotencyProcessingResult(result) {
        const body = (result && result.body) || {};
        const code = String(body.code || body.error_code || body.error || '').trim();
        return result && (result.status === 423 || code === 'IDEMPOTENCY_PROCESSING');
    }

    function shouldReuseIdempotencyKeyOnRetry(result) {
        if (!result) {
            return true; // network/unknown failure: keep the same key
        }
        if (isIdempotencyProcessingResult(result)) {
            return true;
        }
        return false;
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

    function handleOrderResponse(result, action, options) {
        options = options || {};
        const body = result.body || {};
        const draft = window.POSOrderDraft;
        if (!result.ok || body.success === false) {
            const message = userFacingOrderError(body);
            const code = String(body.code || body.error_code || body.error || '');
            if (code === 'MANAGER_APPROVAL_REQUIRED' && window.POSMAIN && typeof window.POSMAIN.requestManagerOverride === 'function') {
                const escalationKey = body.escalation_permission_key || body.permission_key || 'pos.discount.manual_pct.limit';
                return window.POSMAIN.requestManagerOverride(escalationKey, {
                    message: message,
                }).then(function (approval) {
                    const form = options.form || document.getElementById('posForm');
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
                    // Manager approval is a new authorized attempt, but keep the same money intent key.
                    return submitFromForm(form, action, {
                        reuseIdempotencyKey: true,
                        rotateKey: false,
                        internalRetry: true
                    });
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
            return {
                success: false,
                body: body,
                status: result.status,
                reuseIdempotencyKey: shouldReuseIdempotencyKeyOnRetry(result)
            };
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
            if (draft && typeof draft.clearIdempotencyKey === 'function') {
                draft.clearIdempotencyKey();
            }
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
        if (draft && typeof draft.clearIdempotencyKey === 'function' && (isPaymentAction(action) || isDraftSaveAction(action))) {
            // Success completes this intent; next cashier action must mint a new key.
            draft.clearIdempotencyKey();
        }
        return { success: true, body: body };
    }

    function prepareDraftForSubmit(draft, action, options) {
        options = options || {};
        const rotateKey = options.rotateKey !== false;
        if (!draft) {
            return;
        }
        if (isDraftSaveAction(action)) {
            draft.markSaving();
            if (rotateKey && typeof draft.rotateIdempotencyKey === 'function') {
                draft.rotateIdempotencyKey(action);
            }
            return;
        }
        if (isPaymentAction(action) && rotateKey && typeof draft.rotateIdempotencyKey === 'function') {
            draft.rotateIdempotencyKey(action);
        }
    }

    function submitFromForm(form, action, options) {
        options = options || {};
        const draft = window.POSOrderDraft;
        if (!form) {
            return Promise.resolve({ success: false, noForm: true });
        }

        const state = submissionStateFor(form);
        if (!options.internalRetry && state.active) {
            // Only one order mutation may own a form at a time, even across different buttons.
            return state.active.promise;
        }

        if (!options.internalRetry && draft && !draft.canSave(action)) {
            return Promise.resolve({ success: false, blocked: true });
        }

        const retainedIntent = !options.internalRetry ? state.retryable : null;
        const effectiveAction = retainedIntent ? retainedIntent.action : action;
        const route = retainedIntent ? retainedIntent.route : resolveRoute(action, form);
        if (!route) {
            if (draft && isDraftSaveAction(effectiveAction)) {
                draft.markSaveFailed();
                restorePrintOrderButton();
            } else if (isPaymentAction(effectiveAction)) {
                restorePayConfirmButton();
            }
            return Promise.resolve({ success: false, noRoute: true });
        }

        let payload;
        if (retainedIntent) {
            // The server may still finish this operation. Preserve both key and payload exactly.
            payload = retainedIntent.payload;
        } else {
            // Fresh cashier intent rotates once. Retries / processing reattempts reuse the same key.
            prepareDraftForSubmit(draft, action, {
                rotateKey: options.rotateKey !== false && options.reuseIdempotencyKey !== true
            });
            payload = buildOrderPayload(form, action);
        }

        const postOnce = function () {
            return postOrderRoute(route, payload).then(function (result) {
                if (isIdempotencyProcessingResult(result)) {
                    return sleep(450).then(function () {
                        // Same payload + same key: never mint a new key for 423 processing.
                        return postOrderRoute(route, payload);
                    });
                }
                return result;
            });
        };

        const attempt = function () {
            return postOnce().then(function (result) {
                return handleOrderResponse(result, effectiveAction, {
                    form: form,
                    route: route,
                    payload: payload
                });
            });
        };

        const requestPromise = attempt().catch(function (error) {
            restoreSubmitButtons(effectiveAction);
            if (isPaymentAction(effectiveAction) && window.jQuery) {
                window.jQuery('#paymentModal').modal('show');
            }
            return new Promise(function (resolve) {
                showOrderError('حدث خطأ في الاتصال بالخادم', function () {
                    // Network retry must reuse the exact same idempotency key/payload.
                    attempt().then(resolve).catch(function () {
                        showOrderError('تعذر إتمام الطلب بعد إعادة المحاولة');
                        restoreSubmitButtons(effectiveAction);
                        if (isPaymentAction(effectiveAction) && window.jQuery) {
                            window.jQuery('#paymentModal').modal('show');
                        }
                        resolve({
                            success: false,
                            networkError: true,
                            reuseIdempotencyKey: true,
                            error: error || null
                        });
                    });
                }, function () {
                    // The outcome is unknown; a later submit must reconcile with the same intent.
                    resolve({
                        success: false,
                        networkError: true,
                        cancelled: true,
                        reuseIdempotencyKey: true,
                        error: error || null
                    });
                });
            });
        });

        const finalizedPromise = requestPromise.then(function (outcome) {
            if (outcome && outcome.success) {
                state.retryable = null;
            } else if (outcome && outcome.reuseIdempotencyKey) {
                const intent = outcome._posSubmissionIntent || {
                    action: effectiveAction,
                    route: route,
                    payload: payload
                };
                state.retryable = intent;
                if (options.internalRetry) {
                    outcome._posSubmissionIntent = intent;
                } else if (outcome._posSubmissionIntent) {
                    delete outcome._posSubmissionIntent;
                }
            } else {
                state.retryable = null;
            }
            return outcome;
        });

        if (options.internalRetry) {
            return finalizedPromise;
        }

        let trackedPromise;
        trackedPromise = finalizedPromise.then(function (outcome) {
            if (state.active && state.active.promise === trackedPromise) {
                state.active = null;
            }
            return outcome;
        }, function (error) {
            if (state.active && state.active.promise === trackedPromise) {
                state.active = null;
            }
            throw error;
        });
        state.active = {
            action: effectiveAction,
            promise: trackedPromise
        };
        return trackedPromise;
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
        decimalString: decimalString,
        addDecimalStrings: addDecimalStrings,
        compareDecimalStrings: compareDecimalStrings,
        subtractDecimalStrings: subtractDecimalStrings,
        prorateMoneyByQuantity: prorateMoneyByQuantity,
        lineTotalFromQuantityAndUnitPrice: lineTotalFromQuantityAndUnitPrice,
        allocateProportionalMoney: allocateProportionalMoney,
        quantityFromIntegerRatio: quantityFromIntegerRatio,
        moneyFromPercentage: moneyFromPercentage,
        percentageFromMoney: percentageFromMoney,
        paymentTenders: paymentTenders,
        applyOrderSuccessState: applyOrderSuccessState,
        showOrderSuccess: showOrderSuccess,
        userFacingOrderError: userFacingOrderError,
        restoreSubmitButtons: restoreSubmitButtons,
        restorePayConfirmButton: restorePayConfirmButton
    };
})(window, window.jQuery);
