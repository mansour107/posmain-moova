/**
 * POS Barcode System - JavaScript
 * نظام نقاط البيع بالباركود
 */

// Prevent mouse-wheel from accidentally changing focused number fields (amounts, qty, etc.).
document.addEventListener('wheel', function (event) {
    var target = event.target;
    if (!target || target.type !== 'number') {
        return;
    }
    event.preventDefault();
}, { passive: false, capture: true });

function posmainCanRecipeStockOverride() {
    if (window.POSMAIN && typeof window.POSMAIN.can === 'function') {
        return window.POSMAIN.can('pos.recipe_stock_override') === true;
    }
    return window.POSMAIN_CAN_RECIPE_STOCK_OVERRIDE === true;
}

window.POSMAIN = window.POSMAIN || {};

function posmainSwalPremiumOptions(extra) {
    const base = {
        buttonsStyling: false,
        customClass: {
            popup: 'pos-swal-premium',
            title: 'pos-swal-premium__title',
            htmlContainer: 'pos-swal-premium__text',
            actions: 'pos-swal-premium__actions',
            confirmButton: 'pos-swal-premium__confirm',
            cancelButton: 'pos-swal-premium__cancel',
        },
    };
    return Object.assign({}, base, extra || {});
}

function posmainDrawerUserMessage(code, fallback) {
    const map = {
        DRAWER_SESSION_REQUIRED: 'لا توجد وردية مفتوحة. افتح شيفت الكاشير أولاً ثم أعد المحاولة.',
        CASH_DRAWER_NOT_CONFIGURED: 'لم يتم إعداد درج النقدية. اربط طابعة الإيصالات عبر الشبكة أولاً.',
        CASH_DRAWER_OFFLINE: 'تعذر الاتصال بالطابعة أو درج النقدية. تحقق من التوصيل والشبكة.',
        CASH_DRAWER_OPEN_FAILED: 'أُرسل أمر فتح الدرج لكن الجهاز لم يستجب.',
        CASH_DRAWER_STATUS_UNAVAILABLE: 'تعذر قراءة حالة الدرج من الجهاز.',
        MANAGER_APPROVAL_REQUIRED: 'فتح الدرج بدون بيع يتطلب اعتماد مدير.',
    };
    return map[code] || fallback || 'تعذر فتح الدرج. حاول مرة أخرى.';
}

function posmainShowDrawerResult(response) {
    if (!window.Swal || typeof window.Swal.fire !== 'function') {
        return;
    }

    const hardware = response && response.hardware ? response.hardware : null;
    const status = hardware && hardware.status ? hardware.status : '';
    const statusLabel = status === 'open'
        ? 'مفتوح'
        : (status === 'closed' ? 'مغلق' : 'غير معروف');

    let detail = 'تم تسجيل فتح الدرج بدون بيع.';
    if (hardware && hardware.driver === 'network') {
        detail = 'تم إرسال أمر فتح الدرج إلى الطابعة. الحالة: ' + statusLabel + '.';
    }

    Swal.fire(posmainSwalPremiumOptions({
        icon: 'success',
        title: 'تم فتح الدرج',
        text: detail,
        timer: 1800,
        showConfirmButton: false,
    }));
}

function posmainShowDrawerError(code, fallbackMessage) {
    if (!window.Swal || typeof window.Swal.fire !== 'function') {
        alert(posmainDrawerUserMessage(code, fallbackMessage));
        return;
    }

    Swal.fire(posmainSwalPremiumOptions({
        icon: 'error',
        title: 'تعذر فتح الدرج',
        text: posmainDrawerUserMessage(code, fallbackMessage),
        confirmButtonText: 'حسناً',
    }));
}

function posmainPollDrawerStatus(maxAttempts) {
    const attempts = Math.max(1, maxAttempts || 4);
    let remaining = attempts;

    const poll = function () {
        return $.getJSON('ajax/pos_drawer_status.php').then(function (payload) {
            const status = payload && payload.hardware ? payload.hardware.status : '';
            if (status === 'open' || status === 'closed' || remaining <= 1) {
                return payload;
            }
            remaining -= 1;
            return $.Deferred(function (defer) {
                window.setTimeout(function () {
                    poll().then(defer.resolve, defer.reject);
                }, 700);
            }).promise();
        });
    };

    return poll();
}

function posmainOverrideCsrfToken() {
    if (window.POSMAIN_POS_OVERRIDE_CSRF_TOKEN) {
        return String(window.POSMAIN_POS_OVERRIDE_CSRF_TOKEN);
    }
    const meta = document.querySelector('meta[name="pos-override-csrf-token"]');
    return meta ? (meta.getAttribute('content') || '') : '';
}

function posmainActingUserId() {
    const el = document.getElementById('posActingUserId');
    return el ? parseInt(el.getAttribute('data-acting-user-id') || '0', 10) : 0;
}

window.POSMAIN.renderIdentityBadge = function (identity) {
    const badge = document.getElementById('posIdentityBadge');
    if (!badge || !identity) {
        return;
    }

    const nameEl = document.getElementById('posIdentityCashierName');
    const metaEl = document.getElementById('posIdentityMeta');
    const cashierName = String(identity.cashier_name || 'الموظف');
    if (nameEl) {
        nameEl.textContent = cashierName;
    }

    badge.setAttribute('data-cashier-id', String(identity.cashier_user_id || 0));
    badge.classList.toggle('is-takeover', !!identity.is_takeover);

    if (!metaEl) {
        return;
    }

    if (identity.is_takeover && identity.preceding_cashier_name) {
        let meta = 'استلم من ' + identity.preceding_cashier_name;
        if (identity.authorized_by_name) {
            meta += ' · اعتمد ' + identity.authorized_by_name;
        }
        metaEl.textContent = meta;
        metaEl.classList.remove('is-empty');
        badge.setAttribute('title', cashierName + ' — ' + meta);
    } else {
        metaEl.textContent = '';
        metaEl.classList.add('is-empty');
        badge.setAttribute('title', cashierName);
    }

    window.POSMAIN_IDENTITY = identity;
};

window.POSMAIN.refreshIdentityBadge = function () {
    return $.ajax({
        url: 'pos_session_status.php',
        method: 'GET',
        dataType: 'json',
        cache: false,
    }).done(function (status) {
        if (status && status.identity) {
            window.POSMAIN.renderIdentityBadge(status.identity);
        }
    });
};

function posmainParkedCartStorageKey(userId) {
    return 'pos_parked_cart_' + String(userId || 0);
}

function posmainSerializeCartState() {
    if (!window.POSOrderApi) {
        throw new Error('POS_MONEY_API_REQUIRED');
    }
    const rows = [];
    $('#itemData .item-card-order').each(function () {
        const $row = $(this);
        rows.push({
            barcode: String($row.attr('data-itemid') || $row.data('itemid') || ''),
            name: String($row.find('.pos-cart-name').text() || '').trim(),
            qty: window.POSOrderApi.decimalString($row.find('.quantityInput').val(), 6, '0'),
            price: window.POSOrderApi.decimalString($row.find('.priceInput').val(), 6, '0'),
            note: String($row.find('.lineNoteInput').val() || '').trim(),
        });
    });
    return { rows: rows, saved_at: Date.now() };
}

window.POSMAIN.parkCartForActingUser = function (actingUserId) {
    const userId = actingUserId || posmainActingUserId();
    if (userId < 1) {
        return;
    }
    const state = posmainSerializeCartState();
    if (!state.rows.length) {
        return;
    }
    try {
        localStorage.setItem(posmainParkedCartStorageKey(userId), JSON.stringify(state));
    } catch (e) {}
};

window.POSMAIN.restoreParkedCartForActingUser = function (actingUserId) {
    const userId = actingUserId || posmainActingUserId();
    if (userId < 1) {
        return false;
    }
    let raw = null;
    try {
        raw = localStorage.getItem(posmainParkedCartStorageKey(userId));
    } catch (e) {
        return false;
    }
    if (!raw) {
        return false;
    }
    let state = null;
    try {
        state = JSON.parse(raw);
    } catch (e) {
        return false;
    }
    if (!state || !Array.isArray(state.rows) || !state.rows.length) {
        return false;
    }
    if ($('#itemData .item-card-order').length > 0) {
        return false;
    }
    state.rows.forEach(function (row) {
        if (!row.barcode) {
            return;
        }
        if (typeof addItemToCart === 'function') {
            addItemToCart(row.barcode, row.qty || '1.000000', row.price || null);
        }
    });
    try {
        localStorage.removeItem(posmainParkedCartStorageKey(userId));
    } catch (e) {}
    if (typeof updateItemCount === 'function') {
        updateItemCount();
    }
    if (typeof updateTotal === 'function') {
        updateTotal();
    }
    return true;
};

/**
 * Shared PIN modal — exact first-login PosmainPinPad (4 digits, auto-submit).
 * Default: resolves with the PIN string after 4 digits (or Enter).
 * Pass options.onSubmit(pin) to validate inside the pad (return {ok:false,code} or {ok:true,close:true,...}).
 */
window.POSMAIN.showPinPadModal = function (options) {
    options = options || {};
    const deferred = $.Deferred();

    if (!window.PosmainPinPad || typeof window.PosmainPinPad.openModal !== 'function') {
        deferred.reject({ code: 'PIN_PAD_UNAVAILABLE' });
        return deferred.promise();
    }

    window.PosmainPinPad.openModal({
        title: options.title || 'تأكيد الهوية',
        subtitle: options.message || options.subtitle || 'أدخل رمزك المكوّن من 4 أرقام',
        roleHint: options.roleHint || '',
        initialError: options.initialError || '',
        autoSubmit: options.autoSubmit !== false,
        onCancel: function () {
            deferred.reject({ code: 'OVERRIDE_CANCELLED' });
        },
        onSubmit: function (pin) {
            if (typeof options.onSubmit === 'function') {
                return Promise.resolve(options.onSubmit(pin)).then(function (res) {
                    if (res && res.ok !== false && res.close) {
                        deferred.resolve(res.pin != null ? res.pin : res);
                    }
                    return res;
                });
            }
            deferred.resolve(pin);
            return { ok: true, close: true, pin: pin };
        }
    });

    return deferred.promise();
};

window.POSMAIN.overrideErrorMessage = function (err) {
    let code = '';
    if (err && typeof err === 'object') {
        code = String(err.code || err.message || '').trim();
    } else if (err) {
        code = String(err).trim();
    }
    if (window.PosmainPinPad && typeof window.PosmainPinPad.mapError === 'function') {
        const mapped = window.PosmainPinPad.mapError(code);
        if (mapped && mapped !== 'تعذر إتمام العملية') {
            return mapped;
        }
    }
    switch (code) {
        case 'MANAGER_PIN_MISMATCH':
            return 'يجب إدخال رمزك أنت لتأكيد العملية.';
        case 'MANAGER_PIN_INVALID':
        case 'PIN_INVALID':
        case 'PIN_BLACKLISTED':
        case 'PIN_FORMAT_INVALID':
            return 'رمز PIN غير صحيح. حاول مرة أخرى.';
        case 'MANAGER_PERMISSION_DENIED':
            return 'هذا المستخدم غير مصرح له بهذا الإجراء.';
        case 'MANAGER_PIN_LOCKED':
            return 'حساب PIN هذا مقفل مؤقتاً.';
        case 'PIN_TERMINAL_FROZEN':
            return 'تم تجميد المحطة مؤقتاً بسبب محاولات خاطئة.';
        case 'APPROVER_LIMIT_EXCEEDED':
            return 'المدير لا يملك صلاحية كافية لهذا الإجراء.';
        case 'OVERRIDE_INPUT_REQUIRED':
            return 'أدخل رمز PIN صالحاً.';
        case 'CSRF_INVALID':
            return 'انتهت صلاحية الجلسة. حدّث الصفحة وحاول مرة أخرى.';
        default:
            return 'تعذر اعتماد المدير. حاول مرة أخرى.';
    }
};

function posmainParseOverrideAjaxError(xhr) {
    if (xhr && xhr.responseJSON && typeof xhr.responseJSON === 'object') {
        const payload = xhr.responseJSON;
        if (payload.code === 'DATABASE_ERROR' && payload.message === 'PIN_BLACKLISTED') {
            return { code: 'MANAGER_PIN_INVALID' };
        }
        return payload;
    }
    if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim() !== '') {
        try {
            const parsed = JSON.parse(xhr.responseText);
            if (parsed && typeof parsed === 'object') {
                return parsed;
            }
        } catch (ignored) {
        }
    }
    return { code: 'OVERRIDE_FAILED' };
}

window.POSMAIN.requestManagerOverride = function (permissionKey, options) {
    options = options || {};
    const deferred = $.Deferred();
    const csrf = posmainOverrideCsrfToken();
    if (!permissionKey) {
        deferred.reject({ code: 'PERMISSION_KEY_REQUIRED' });
        return deferred.promise();
    }

    function submitOverridePin(pin) {
        const postData = {
            manager_pin: String(pin),
            permission_key: permissionKey,
            action_type: options.action_type || permissionKey,
            target_type: options.target_type || 'pos_action',
            target_id: options.target_id || '',
            reason: options.reason || '',
            csrf_token: csrf,
        };
        if (options.amount !== undefined && options.amount !== null && options.amount !== '') {
            postData.amount = options.amount;
        }
        if (options.limit_permission_key) {
            postData.limit_permission_key = options.limit_permission_key;
        }
        if (options.require_same_user) {
            postData.require_same_user = '1';
        }
        return $.ajax({
            url: 'ajax/pos_override_auth.php',
            method: 'POST',
            dataType: 'json',
            data: postData,
            headers: { 'X-CSRF-Token': csrf },
        });
    }

    if (!window.PosmainPinPad || typeof window.PosmainPinPad.openModal !== 'function') {
        const pin = window.prompt(options.message || 'رمز PIN للمدير');
        if (!pin) {
            deferred.reject({ code: 'OVERRIDE_CANCELLED' });
            return deferred.promise();
        }
        submitOverridePin(pin).done(function (response) {
            if (response && response.success && response.approval_id) {
                deferred.resolve(response);
            } else {
                deferred.reject(response || { code: 'OVERRIDE_FAILED' });
            }
        }).fail(function (xhr) {
            deferred.reject((xhr.responseJSON) || { code: 'OVERRIDE_FAILED' });
        });
        return deferred.promise();
    }

    window.PosmainPinPad.openModal({
        title: options.title || 'تأكيد الهوية',
        subtitle: options.message || 'أدخل رمزك المكوّن من 4 أرقام',
        roleHint: (typeof window.POSMAIN.formatApproverRoleHint === 'function')
            ? window.POSMAIN.formatApproverRoleHint(permissionKey, options)
            : (options.roleHint || ''),
        initialError: options.initialError || '',
        autoSubmit: true,
        onCancel: function () {
            deferred.reject({ code: 'OVERRIDE_CANCELLED' });
        },
        onSubmit: function (pin) {
            return new Promise(function (resolve) {
                submitOverridePin(pin).done(function (response) {
                    if (response && response.success && response.approval_id) {
                        deferred.resolve(response);
                        resolve({ ok: true, close: true, approval_id: response.approval_id });
                        return;
                    }
                    resolve({
                        ok: false,
                        code: (response && (response.code || response.error)) || 'MANAGER_PIN_INVALID',
                        retry_after: response && (response.retry_after || response.cooldown_seconds),
                    });
                }).fail(function (xhr) {
                    const payload = posmainParseOverrideAjaxError(xhr);
                    resolve({
                        ok: false,
                        code: (payload && (payload.code || payload.error)) || 'OVERRIDE_FAILED',
                        retry_after: payload && (payload.retry_after || payload.cooldown_seconds),
                    });
                });
            });
        }
    });

    return deferred.promise();
};

window.POSMAIN.applyLockedAction = function ($btn, permissionKey, onAllowed) {
    if (!$btn || !$btn.length) {
        return;
    }
    const allowed = window.POSMAIN && typeof window.POSMAIN.can === 'function'
        ? window.POSMAIN.can(permissionKey) === true
        : false;
    if (allowed) {
        $btn.removeClass('pos-action-locked').prop('disabled', false);
        $btn.off('click.posOverride').on('click.posOverride', function (e) {
            if (typeof onAllowed === 'function') {
                onAllowed(e);
            }
        });
        return;
    }
    $btn.addClass('pos-action-locked').prop('disabled', false);
    $btn.off('click.posOverride').on('click.posOverride', function (e) {
        e.preventDefault();
        window.POSMAIN.requestManagerOverride(permissionKey, {
            message: $btn.attr('title') || 'يتطلب اعتماد مدير',
        }).done(function (approval) {
            if (typeof onAllowed === 'function') {
                onAllowed(e, approval);
            }
        });
    });
};

window.POSMAIN.ensureEscalationForAmount = function (limitPermissionKey, escalationPermissionKey, amount, options) {
    const deferred = $.Deferred();
    options = options || {};
    if (window.POSMAIN.can(escalationPermissionKey) === true) {
        deferred.resolve(null);
        return deferred.promise();
    }
    if (typeof window.POSMAIN.checkAmountWithinLimit === 'function'
        && window.POSMAIN.checkAmountWithinLimit(limitPermissionKey, amount)) {
        deferred.resolve(null);
        return deferred.promise();
    }
    window.POSMAIN.requestManagerOverride(escalationPermissionKey, Object.assign({}, options, {
        amount: amount,
        limit_permission_key: limitPermissionKey,
    })).done(function (approval) {
        deferred.resolve(approval);
    }).fail(function (err) {
        deferred.reject(err);
    });
    return deferred.promise();
};

window.POSMAIN.ensurePermissionOrOverride = function (permissionKey, options) {
    const deferred = $.Deferred();
    if (window.POSMAIN.can(permissionKey) === true) {
        deferred.resolve(null);
        return deferred.promise();
    }
    window.POSMAIN.requestManagerOverride(permissionKey, options || {}).done(function (approval) {
        deferred.resolve(approval);
    }).fail(function (err) {
        deferred.reject(err);
    });
    return deferred.promise();
};

function posmainSetHiddenFormApproval(form, approvalId, fieldName) {
    if (!form || !approvalId) {
        return;
    }
    fieldName = fieldName || 'manager_approval_id';
    let input = form.querySelector('input[name="' + fieldName + '"]');
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = fieldName;
        form.appendChild(input);
    }
    input.value = String(approvalId);
}

function posmainCurrentOrderId() {
    return parseInt($('#edit_order_id').val() || $('#selected_order_id').val() || '0', 10) || 0;
}

function posmainCanVoidPersistedItem() {
    if (typeof window.POSMAIN_ACTING_CAN_VOID_PERSISTED === 'boolean') {
        return window.POSMAIN_ACTING_CAN_VOID_PERSISTED === true;
    }
    return window.POSMAIN && typeof window.POSMAIN.can === 'function'
        && window.POSMAIN.can('pos.void.item_after_send') === true;
}

function posmainRefreshPersistedLineLocks() {
    const needsLock = !posmainCanVoidPersistedItem();
    $('#itemData .item-card-order').each(function () {
        const $card = $(this);
        const $btn = $card.find('.delRow');
        if ($card.attr('data-persisted-line') === '1' && needsLock) {
            $btn.addClass('pos-action-locked');
        } else {
            $btn.removeClass('pos-action-locked');
        }
    });
}

function posmainRequestItemVoidOverride(message, targetOrderId) {
    const deferred = $.Deferred();
    if (!window.POSMAIN || typeof window.POSMAIN.requestManagerOverride !== 'function') {
        deferred.reject({ code: 'OVERRIDE_UNAVAILABLE' });
        return deferred.promise();
    }
    window.POSMAIN.requestManagerOverride('pos.void.item_after_send', {
        message: message || 'إزالة صنف محفوظ يتطلب اعتماد مدير',
        target_type: 'pos_order',
        target_id: targetOrderId || posmainCurrentOrderId() || '',
    }).done(function (approval) {
        const form = document.getElementById('posForm');
        if (form && approval && approval.approval_id) {
            posmainSetHiddenFormApproval(form, approval.approval_id, 'manager_approval_id');
        }
        deferred.resolve(approval);
    }).fail(function (err) {
        deferred.reject(err);
    });
    return deferred.promise();
}

function posmainPersistedLineNeedsVoidApproval($card, nextQty) {
    if (!$card || $card.attr('data-persisted-line') !== '1') {
        return false;
    }
    if (posmainCanVoidPersistedItem()) {
        return false;
    }
    if (nextQty === undefined || nextQty === null) {
        return true;
    }
    if (!window.POSOrderApi) {
        throw new Error('POS_MONEY_API_REQUIRED');
    }
    const persistedQty = window.POSOrderApi.decimalString($card.attr('data-persisted-qty'), 6, '0');
    const proposedQty = window.POSOrderApi.decimalString(nextQty, 6, '0');
    return window.POSOrderApi.compareDecimalStrings(proposedQty, persistedQty, 6) < 0;
}

function posmainCurrentDiscountPct() {
    const money = window.POSOrderApi;
    if (!money) {
        throw new Error('POS_MONEY_API_REQUIRED');
    }
    const total = money.decimalString($('#total').val(), 2, '0');
    const discount = money.decimalString($('#discount').val(), 2, '0');
    return money.percentageFromMoney(discount, total);
}

function posmainCartHasPriceOverride() {
    const money = window.POSOrderApi;
    if (!money) {
        throw new Error('POS_MONEY_API_REQUIRED');
    }
    let overridden = false;
    $('#itemData .item-card-order').each(function () {
        const $card = $(this);
        const price = money.decimalString($card.find('.priceInput').val(), 6, '0');
        const catalog = money.decimalString($card.attr('data-catalog-price'), 6, '0');
        if (money.compareDecimalStrings(catalog, '0.000000', 6) > 0
            && money.compareDecimalStrings(price, catalog, 6) !== 0) {
            overridden = true;
        }
    });
    return overridden;
}

function posmainCollectPreSubmitEscalations(form, action) {
    const isPayment = action === 'cash' || action === 'split_cash';
    const isSave = action === 'save' || action === 'print_receipt' || isPayment;

    if (!isSave) {
        return $.Deferred().resolve({}).promise();
    }

    const tasks = [];
    let discountApprovalId = null;
    let priceApprovalId = null;
    let creditApprovalId = null;

    const money = window.POSOrderApi;
    if (!money) {
        return $.Deferred().reject({ code: 'POS_MONEY_API_REQUIRED' }).promise();
    }
    const discountPct = posmainCurrentDiscountPct();
    if (money.compareDecimalStrings(discountPct, '0.000000', 6) > 0) {
        tasks.push(window.POSMAIN.ensureEscalationForAmount(
            'pos.discount.apply',
            'pos.discount.manual_pct.limit',
            discountPct,
            { message: 'خصم يتجاوز الحد المسموح — يتطلب اعتماد مدير' }
        ).then(function (approval) {
            if (approval && approval.approval_id) {
                discountApprovalId = approval.approval_id;
            }
        }));
    }
    if (posmainCartHasPriceOverride()) {
        tasks.push(window.POSMAIN.ensurePermissionOrOverride('pos.price.override', {
            message: 'تعديل السعر يتطلب اعتماد مدير',
        }).then(function (approval) {
            if (approval && approval.approval_id) {
                priceApprovalId = approval.approval_id;
            }
        }));
    }
    const jalAmount = money.decimalString($('[name="jal_amount"]').val(), 2, '0');
    if (money.compareDecimalStrings(jalAmount, '0.00', 2) > 0) {
        tasks.push(window.POSMAIN.ensurePermissionOrOverride('pos.credit.sale', {
            message: 'بيع آجل يتطلب اعتماد مدير',
        }).then(function (approval) {
            if (approval && approval.approval_id) {
                creditApprovalId = approval.approval_id;
            }
        }));
    }

    if (!tasks.length) {
        return $.Deferred().resolve({}).promise();
    }

    return $.when.apply($, tasks).then(function () {
        if (discountApprovalId) {
            posmainSetHiddenFormApproval(form, discountApprovalId, 'manager_approval_id');
        }
        if (priceApprovalId) {
            posmainSetHiddenFormApproval(form, priceApprovalId, 'price_override_approval_id');
        }
        if (creditApprovalId && !discountApprovalId) {
            posmainSetHiddenFormApproval(form, creditApprovalId, 'manager_approval_id');
        }
        return {
            discountApprovalId: discountApprovalId,
            priceApprovalId: priceApprovalId,
            creditApprovalId: creditApprovalId,
        };
    });
}

$(document).ready(function() {
    // ========================================
    // Initialize on page load - Update totals if items exist (edit mode)
    // ========================================
    $(document).on('click', '#posDrawerNoSaleBtn', function () {
        const noSaleRequestKey = (
            typeof window.createPOSIdempotencyKey === 'function'
                ? window.createPOSIdempotencyKey('pos.drawer.no_sale')
                : 'pos.drawer.no_sale:' + (
                    window.crypto && typeof window.crypto.randomUUID === 'function'
                        ? window.crypto.randomUUID()
                        : Date.now().toString(36) + ':' + Math.random().toString(36).slice(2)
                )
        );
        const runNoSale = function (approvalId) {
            const data = {
                reason: 'فتح درج بدون بيع',
                csrf_token: posmainOverrideCsrfToken(),
                idempotency_key: noSaleRequestKey,
            };
            if (approvalId) {
                data.manager_approval_id = approvalId;
            }
            $.ajax({
                url: 'ajax/pos_drawer_no_sale.php',
                method: 'POST',
                dataType: 'json',
                data: data,
                headers: { 'X-CSRF-Token': posmainOverrideCsrfToken() },
            }).done(function (response) {
                if (response && response.success) {
                    const afterStatus = function () {
                        posmainShowDrawerResult(response);
                    };
                    if (response.hardware && response.hardware.sensor_supported) {
                        posmainPollDrawerStatus(5).always(afterStatus);
                    } else {
                        afterStatus();
                    }
                } else if ((response.code || '') === 'MANAGER_APPROVAL_REQUIRED') {
                    window.POSMAIN.requestManagerOverride('pos.drawer.no_sale', {
                        message: 'فتح الدرج بدون بيع يتطلب اعتماد مدير',
                    }).done(function (approval) {
                        runNoSale(approval.approval_id);
                    });
                } else {
                    posmainShowDrawerError(response && response.code, response && response.message);
                }
            }).fail(function (xhr) {
                const payload = xhr.responseJSON || {};
                if ((payload.code || '') === 'MANAGER_APPROVAL_REQUIRED') {
                    window.POSMAIN.requestManagerOverride('pos.drawer.no_sale', {
                        message: 'فتح الدرج بدون بيع يتطلب اعتماد مدير',
                    }).done(function (approval) {
                        runNoSale(approval.approval_id);
                    });
                    return;
                }
                posmainShowDrawerError(payload.code, payload.message);
            });
        };
        if (!window.POSMAIN || typeof window.POSMAIN.requestManagerOverride !== 'function') {
            posmainShowDrawerError('', 'تعذر تنفيذ فتح الدرج. أعد تحميل الصفحة ثم حاول مرة أخرى.');
            return;
        }
        if (window.POSMAIN.can('pos.drawer.no_sale') === true) {
            runNoSale(null);
            return;
        }
        window.POSMAIN.requestManagerOverride('pos.drawer.no_sale', {
            message: 'فتح الدرج بدون بيع يتطلب اعتماد مدير',
        }).done(function (approval) {
            runNoSale(approval.approval_id);
        });
    });

    if (window.POSMAIN && typeof window.POSMAIN.restoreParkedCartForActingUser === 'function') {
        window.POSMAIN.restoreParkedCartForActingUser(posmainActingUserId());
    }
    if ($('#itemData .item-card-order').length > 0) {
        updateItemCount();
        updateTotal();
        posmainRefreshPersistedLineLocks();
    }
    const $filterInput = $('#posUnifiedSearch').length ? $('#posUnifiedSearch') : $('#itemFilterInput');
    const $itemsGrid = $('#itemsGrid');
    const $itemsGridLoader = $('#itemsGridLoader');
    const $currentControls = $('.pos-current-order-controls');
    const itemVariantCache = new Map();
    if ($currentControls.length) {
        $currentControls.find('.pos-table-mount').append($('.pos-table-field'));
    }

    const $paymentFundSelect = $('#payment_fund_id');
    const $paymentBankSelect = $('#payment_bank_id');
    let bankOptionsLoadStarted = false;

    function mergeSelectOptions($select, options, selectedValue, placeholderHtml) {
        if (!$select.length) {
            return;
        }

        const normalizedSelectedValue = String(selectedValue || $select.val() || '');
        const existingOptions = new Map();
        $select.find('option').each(function() {
            const value = String($(this).val() || '');
            if (value) {
                existingOptions.set(value, String($(this).text()).trim());
            }
        });

        (options || []).forEach(function(option) {
            const value = String(option && option.id ? option.id : '');
            const label = String(option && option.name ? option.name : '').trim();
            if (value && label) {
                existingOptions.set(value, label);
            }
        });

        const html = [];
        if (placeholderHtml) {
            html.push(placeholderHtml);
        }

        existingOptions.forEach(function(label, value) {
            html.push($('<option>', { value: value, text: label }).prop('outerHTML'));
        });

        $select.html(html.join(''));
        if (normalizedSelectedValue && existingOptions.has(normalizedSelectedValue)) {
            $select.val(normalizedSelectedValue);
        }
    }

    function syncPaymentFundOptions() {
        const $mainFundSelect = $('#pos_setup_fund_id');
        const $mainFundValue = $('input[name="fund_id"]');
        if (!$paymentFundSelect.length) {
            return;
        }

        if ($mainFundSelect.length) {
            $paymentFundSelect.html($mainFundSelect.html());
        }
        const selectedFund = String(($mainFundSelect.val() || $mainFundValue.val() || ''));
        if (selectedFund) {
            $paymentFundSelect.val(selectedFund);
        }
    }

    function loadBankOptions() {
        if (!$paymentBankSelect.length || bankOptionsLoadStarted || $paymentBankSelect.attr('data-options-loaded') === '1') {
            return;
        }

        bankOptionsLoadStarted = true;
        const selectedValue = String($paymentBankSelect.val() || '');
        $.ajax({
            url: 'ajax/get_pos_options.php',
            method: 'GET',
            dataType: 'json',
            cache: false,
            data: { type: 'banks' },
            success: function(response) {
                if (!response || response.success !== true) {
                    return;
                }

                mergeSelectOptions($paymentBankSelect, response.options || [], selectedValue, '<option value=""></option>');
                $paymentBankSelect.attr('data-options-loaded', '1');
                if (!$paymentBankSelect.val() && response.options && response.options.length) {
                    $paymentBankSelect.val(String(response.options[0].id));
                }
            },
            complete: function() {
                bankOptionsLoadStarted = false;
            }
        });
    }

    window.POSMainSyncPaymentOptions = function() {
        syncPaymentFundOptions();
        loadBankOptions();
    };

    function getPosPaymentMethod() {
        return String($('input[name="pos_payment_method"]:checked').val() || 'cash');
    }

    function updatePosPaymentAmountLayout() {
        const mode = getPosPaymentMethod();
        const $amounts = $('.pos-payment-amounts');
        $amounts.removeClass('pos-payment-mode-cash pos-payment-mode-bank pos-payment-mode-mixed');
        $amounts.addClass('pos-payment-mode-' + mode);
    }

    function applyPosPaymentMethodAmounts(netAmount) {
        if ($('#pos_split_payment_enabled').prop('checked')) {
            refreshSplitPaymentLineAmounts();
            return;
        }

        const money = window.POSOrderApi;
        if (!money) {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        const net = money.decimalString(
            netAmount === undefined || netAmount === null || netAmount === ''
                ? paymentAmountDue()
                : netAmount,
            2,
            '0'
        );
        const mode = getPosPaymentMethod();
        if (mode === 'cash') {
            $('#modal_paid_cash').val(net);
            $('#modal_paid_bank').val('0.00');
        } else if (mode === 'bank') {
            $('#modal_paid_cash').val('0.00');
            $('#modal_paid_bank').val(net);
        } else {
            const paidCash = money.decimalString($('#modal_paid_cash').val(), 2, '0');
            const paidBank = money.decimalString($('#modal_paid_bank').val(), 2, '0');
            if (money.compareDecimalStrings(money.addDecimalStrings(paidCash, paidBank, 2), '0.00', 2) === 0) {
                $('#modal_paid_cash').val(net);
                $('#modal_paid_bank').val('0.00');
            }
        }
        calculateChange();
    }

    function resetPosPaymentMethodToCash() {
        $('input[name="pos_payment_method"][value="cash"]').prop('checked', true);
        updatePosPaymentAmountLayout();
    }

    function ensureDefaultPaymentBankSelection() {
        if (!$paymentBankSelect.length) {
            return;
        }

        if ($paymentBankSelect.val()) {
            return;
        }

        const $firstOption = $paymentBankSelect.find('option[value]').filter(function() {
            return String($(this).val() || '') !== '';
        }).first();
        if ($firstOption.length) {
            $paymentBankSelect.val(String($firstOption.val()));
        }
    }

    window.POSMainEnsurePaymentAccountDefaults = function() {
        syncPaymentFundOptions();
        if (!$paymentFundSelect.val()) {
            const fallbackFund = String($('#pos_setup_fund_id').val() || $('input[name="fund_id"]').val() || '');
            if (fallbackFund) {
                $paymentFundSelect.val(fallbackFund);
            }
        }
        ensureDefaultPaymentBankSelection();
    };

    function activeCategoryFilter() {
        const $activeCategory = $('.category-btn.active').first();
        return {
            categoryId: $activeCategory.data('category'),
            keywords: String($activeCategory.data('keywords') || '')
                .split(',')
                .map(keyword => keyword.trim().toLowerCase())
                .filter(Boolean)
        };
    }

    function itemMatchesActiveFilter($item, searchText, categoryFilter) {
        const $card = $item.find('.item-card');
        const itemName = String($card.data('item-name') || '').toLowerCase();
        const itemBarcode = String($card.data('item-barcode') || '').toLowerCase();
        const categoryId = categoryFilter.categoryId;

        if (searchText && !itemName.includes(searchText) && !itemBarcode.includes(searchText)) {
            return false;
        }

        if (!categoryId || categoryId === 'all') {
            return true;
        }

        if (categoryFilter.keywords.length > 0) {
            return categoryFilter.keywords.some(keyword => itemName.includes(keyword));
        }

        return String($item.data('category')) === String(categoryId);
    }

    function normalizeItemId(itemId) {
        const normalized = String(itemId || '').trim();
        return normalized === '0' ? '' : normalized;
    }

    function itemHasVariantsValue(value) {
        return value === true || value === 1 || String(value || '') === '1' || String(value || '').toLowerCase() === 'true';
    }

    function cacheItemVariants(itemId, variants) {
        const key = normalizeItemId(itemId);
        if (!key || !Array.isArray(variants)) {
            return false;
        }

        itemVariantCache.set(key, variants);
        return true;
    }

    function cachedItemVariants(itemId) {
        const key = normalizeItemId(itemId);
        return key && itemVariantCache.has(key) ? itemVariantCache.get(key) : null;
    }

    function registerVariantCacheFromItem(item) {
        if (item && itemHasVariantsValue(item.has_variants) && Array.isArray(item.variants)) {
            cacheItemVariants(item.id, item.variants);
        }
    }

    function registerVariantCacheFromCard($card) {
        const itemId = $card.data('item-id');
        const rawVariants = $card.attr('data-variants');
        if (!rawVariants) {
            return;
        }

        try {
            const variants = JSON.parse(rawVariants);
            cacheItemVariants(itemId, variants);
        } catch (error) {
            console.warn('Unable to parse preloaded item variants:', error);
        }
    }

    function itemAvailabilityContext($card) {
        const isAvailable = String($card.attr('data-is-available') || '1') !== '0';
        const canAdd = String($card.attr('data-availability-can-add') || (isAvailable ? '1' : '0')) !== '0';
        return {
            isAvailable: isAvailable,
            canAdd: canAdd,
            status: String($card.attr('data-availability-status') || (isAvailable ? 'available' : 'manual_unavailable')),
            reason: String($card.attr('data-unavailable-reason') || '').trim(),
            requiresManagerOverride: String($card.attr('data-requires-manager-override') || '0') === '1',
            overrideAllowed: String($card.attr('data-override-allowed') || '0') === '1',
            overridePermission: String($card.attr('data-override-permission') || '').trim(),
            recipeEnabled: String($card.attr('data-recipe-enabled') || '0') === '1',
            recipeQty: String($card.attr('data-recipe-effective-available-qty') || '').trim(),
            warnOnly: String($card.attr('data-availability-warn-only') || '0') === '1'
        };
    }

    function itemAvailabilityContextFromPayload(item) {
        item = item || {};
        const isAvailable = String(item.is_available !== undefined ? item.is_available : '1') !== '0';
        const canAdd = String(item.availability_can_add !== undefined ? item.availability_can_add : (isAvailable ? '1' : '0')) !== '0';
        return {
            isAvailable: isAvailable,
            canAdd: canAdd,
            status: String(item.availability_status || (isAvailable ? 'available' : 'manual_unavailable')),
            reason: String(item.unavailable_reason || item.recipe_unavailable_reason || '').trim(),
            requiresManagerOverride: String(item.availability_requires_manager_override || '0') === '1',
            overrideAllowed: String(item.availability_override_allowed || '0') === '1',
            overridePermission: String(item.availability_override_permission || '').trim(),
            recipeEnabled: String(item.recipe_enabled || '0') === '1',
            recipeQty: String(item.recipe_effective_available_qty || '').trim(),
            warnOnly: String(item.availability_warn_only || '0') === '1'
        };
    }

    function itemUnavailableMessage(context, itemName) {
        if (context.reason) {
            return context.reason;
        }

        if (context.status === 'recipe_unavailable') {
            return 'هذا الصنف غير متاح حالياً بسبب مخزون المكونات.';
        }

        return 'هذا الصنف غير متاح حالياً.';
    }

    function dismissAvailabilityWarnToast() {
        const existing = document.getElementById('posAvailabilityWarnToast');
        if (!existing) {
            return;
        }
        if (existing._posToastTimer) {
            window.clearTimeout(existing._posToastTimer);
        }
        existing.classList.remove('is-visible');
        window.setTimeout(function() {
            if (existing.parentNode) {
                existing.parentNode.removeChild(existing);
            }
        }, 180);
    }

    function showAvailabilityWarnToast(context, itemName) {
        const lowStock = context.status === 'recipe_low';
        const message = lowStock
            ? 'هذا الصنف على وشك النفاد (متبقي ' + (context.recipeQty || '0') + ').'
            : (context.reason || 'مخزون المكونات غير كافٍ — سيُسمح بالبيع مع تحذير.');
        const title = lowStock ? 'تنبيه: نفاد قريب' : 'تنبيه: مخزون غير كافٍ';
        const safeName = String(itemName || '').trim();

        dismissAvailabilityWarnToast();

        const toast = document.createElement('div');
        toast.id = 'posAvailabilityWarnToast';
        toast.className = 'pos-availability-toast';
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.innerHTML =
            '<div class="pos-availability-toast__icon" aria-hidden="true">' +
                '<i class="fas fa-exclamation-triangle"></i>' +
            '</div>' +
            '<div class="pos-availability-toast__body">' +
                '<div class="pos-availability-toast__title"></div>' +
                (safeName ? '<div class="pos-availability-toast__item"></div>' : '') +
                '<div class="pos-availability-toast__message"></div>' +
            '</div>' +
            '<button type="button" class="pos-availability-toast__close" aria-label="إغلاق">' +
                '<i class="fas fa-times" aria-hidden="true"></i>' +
            '</button>';

        toast.querySelector('.pos-availability-toast__title').textContent = title;
        if (safeName) {
            toast.querySelector('.pos-availability-toast__item').textContent = safeName;
        }
        toast.querySelector('.pos-availability-toast__message').textContent = message;
        toast.querySelector('.pos-availability-toast__close').addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            dismissAvailabilityWarnToast();
        });

        document.body.appendChild(toast);
        window.requestAnimationFrame(function() {
            toast.classList.add('is-visible');
        });
        toast._posToastTimer = window.setTimeout(dismissAvailabilityWarnToast, 4500);

        try {
            if (window.console && console.warn) {
                console.warn('[posmain][availability]', title, safeName, message);
            }
        } catch (e) { /* ignore */ }
    }

    function showUnavailableItemMessage(context, itemName) {
        const message = itemUnavailableMessage(context, itemName);
        if (window.Swal && typeof Swal.fire === 'function') {
            Swal.fire({
                icon: 'warning',
                title: 'الصنف غير متاح',
                text: message
            });
            return;
        }

        alert(message);
    }

    function requestRecipeStockOverride(context, itemName, itemId) {
        if (!context.requiresManagerOverride) {
            return $.Deferred().resolve(null).promise();
        }

        if (!context.overrideAllowed || !posmainCanRecipeStockOverride()) {
            showUnavailableItemMessage(context, itemName);
            return $.Deferred().reject().promise();
        }

        const message = itemUnavailableMessage(context, itemName);
        const prompt = window.Swal && typeof Swal.fire === 'function'
            ? Swal.fire({
                icon: 'warning',
                title: 'اعتماد مدير',
                text: message,
                input: 'text',
                inputLabel: 'سبب السماح بالبيع',
                inputPlaceholder: 'اكتب السبب',
                showCancelButton: true,
                confirmButtonText: 'اعتماد وإضافة',
                cancelButtonText: 'إلغاء',
                preConfirm: function(value) {
                    return String(value || '').trim();
                }
            })
            : $.Deferred().resolve({
                isConfirmed: window.confirm(message + '\n\nاعتماد وإضافة؟'),
                value: 'recipe stock override'
            }).promise();

        return $.when(prompt).then(function(result) {
            if (!result || !result.isConfirmed) {
                return $.Deferred().reject().promise();
            }

            return window.POSMAIN.requestManagerOverride('pos.recipe_stock_override', {
                message: message,
                target_type: 'item',
                target_id: itemId,
                reason: String(result.value || 'recipe stock override').trim(),
            }).then(function(response) {
                return response.approval_id;
            });
        });
    }

    function applyActiveItemFilter() {
        const searchText = String($filterInput.val() || '').toLowerCase().trim();
        const categoryFilter = activeCategoryFilter();

        $('.item-wrapper').each(function() {
            const $item = $(this);
            $item.toggleClass('hidden', !itemMatchesActiveFilter($item, searchText, categoryFilter));
        });
    }

    function appendLazyItems(items) {
        const existingIds = new Set();
        $('.item-card').each(function() {
            existingIds.add(String($(this).data('item-id') || ''));
        });

        const html = [];
        (items || []).forEach(function(item) {
            const itemId = String(item && item.id ? item.id : '');
            if (!itemId || existingIds.has(itemId) || !item.html) {
                return;
            }
            registerVariantCacheFromItem(item);
            existingIds.add(itemId);
            html.push(item.html);
        });

        if (html.length > 0) {
            $itemsGrid.append(html.join(''));
            applyActiveItemFilter();
        }
    }

    function loadRemainingItems() {
        if (!$itemsGrid.length || $itemsGrid.data('lazy-loading-started')) {
            return;
        }

        const pageSize = parseInt($itemsGrid.data('page-size'), 10) || 48;
        let nextPage = (parseInt($itemsGrid.data('initial-page'), 10) || 1) + 1;
        $itemsGrid.data('lazy-loading-started', true);

        function loadNextPage() {
            $itemsGridLoader.removeClass('d-none');
            $.ajax({
                url: 'ajax/load_items_lazy.php',
                method: 'GET',
                dataType: 'json',
                data: {
                    page: nextPage,
                    limit: pageSize
                },
                success: function(response) {
                    if (!response || response.success !== true) {
                        $itemsGridLoader.addClass('d-none');
                        return;
                    }

                    appendLazyItems(response.items || []);
                    if (response.has_more) {
                        nextPage += 1;
                        setTimeout(loadNextPage, 50);
                    } else {
                        $itemsGridLoader.addClass('d-none');
                    }
                },
                error: function() {
                    $itemsGridLoader.addClass('d-none');
                }
            });
        }

        setTimeout(loadNextPage, 250);
    }

    // ========================================
    // Category Filter
    // ========================================
    $('.category-btn').on('click', function() {
        const $this = $(this);
        const categoryId = $this.data('category');
        const keywords = String($this.data('keywords') || '')
            .split(',')
            .map(keyword => keyword.trim().toLowerCase())
            .filter(Boolean);

        // تحديث الأزرار
        $('.category-btn').removeClass('active btn-primary').addClass('btn-outline-primary');
        $this.removeClass('btn-outline-primary').addClass('btn-primary active');

        // مسح البحث
        $filterInput.val('');

        applyActiveItemFilter();
    });

    // ========================================
    // Barcode & Search Input
    // ========================================
    $('#barcodeInput').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            let barcode = $(this).val().trim();
            if (barcode) {
                searchItemByBarcode(barcode);
                $(this).val('');
            }
        }
    });

    $('#searchInput').on('keypress', function(e) {
        if (e.which === 13) {
            let search = $(this).val().trim();
            if (search) {
                // Try barcode search first
                searchItemByBarcode(search);

                // Also trigger the filter search
                if (search.length >= 2) {
                    $filterInput.val(search).trigger('input');
                }

                $(this).val('');
            }
        }
    });

    // البحث البسيط مع Debouncing للأداء
    let searchTimeout;
    $filterInput.on('input', function() {
        clearTimeout(searchTimeout);
        const searchText = $(this).val().toLowerCase().trim();

        // لو فاضي، اعرض الأصناف حسب التصنيف الحالي فوراً
        if (searchText === '') {
            applyActiveItemFilter();
            return;
        }

        // انتظر 200ms قبل البحث (debouncing)
        searchTimeout = setTimeout(function() {
            applyActiveItemFilter();
        }, 200);
    });

    $('#clearFilter').click(function() {
        $filterInput.val('');
        applyActiveItemFilter();
    });

    $('#focusUnifiedSearch').on('click', function() {
        $filterInput.focus().select();
    });

    $filterInput.on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            let search = $(this).val().trim();
            if (search) {
                searchItemByBarcode(search);
            }
        }
    });

    function canUseTableOrders() {
        return !window.POSMAIN
            || typeof window.POSMAIN.can !== 'function'
            || window.POSMAIN.can('pos.table.open');
    }

    function posmainTableOpenOverrideParams() {
        const override = window.POSMAIN_TABLE_OPEN_OVERRIDE;
        if (override && override.approval_id) {
            return { manager_approval_id: override.approval_id };
        }
        return {};
    }

    function posModeLabel(modeValue) {
        const mode = String(modeValue || '');
        if (mode === '2') {
            return 'طاولة';
        }
        if (mode === '3') {
            return 'دليفري';
        }
        return 'تيك اواي';
    }

    function getPosCartItemCount() {
        return $('#itemData .item-card-order').length;
    }

    function hasActiveSavedOrderContext() {
        const editId = parseInt($('#edit_order_id').val() || '0', 10) || 0;
        const selectedId = parseInt($('#selected_order_id').val() || '0', 10) || 0;
        return editId > 0 || selectedId > 0;
    }

    function ageValueFromTargetId(targetId) {
        if (targetId === 'age2') {
            return '2';
        }
        if (targetId === 'age3') {
            return '3';
        }
        return '1';
    }

    function flashPosCartTransfer() {
        const panel = document.getElementById('itemData');
        if (!panel) {
            return;
        }
        panel.classList.remove('pos-cart-transfer-flash');
        // Force reflow so the animation can replay on rapid switches.
        void panel.offsetWidth;
        panel.classList.add('pos-cart-transfer-flash');
        window.setTimeout(function() {
            panel.classList.remove('pos-cart-transfer-flash');
        }, 700);
    }

    function dismissModeTransferToast() {
        const existing = document.querySelector('.pos-mode-transfer-toast');
        if (!existing) {
            return;
        }
        existing.classList.remove('is-visible');
        window.setTimeout(function() {
            if (existing.parentNode) {
                existing.parentNode.removeChild(existing);
            }
        }, 220);
    }

    function showModeTransferToast(itemCount, targetMode) {
        dismissModeTransferToast();
        const count = Math.max(0, parseInt(itemCount, 10) || 0);
        if (count <= 0) {
            return;
        }

        const toast = document.createElement('div');
        toast.className = 'pos-mode-transfer-toast';
        toast.setAttribute('role', 'status');
        toast.innerHTML =
            '<div class="pos-mode-transfer-toast__icon" aria-hidden="true"><i class="fas fa-exchange-alt"></i></div>' +
            '<div class="pos-mode-transfer-toast__body">' +
                '<div class="pos-mode-transfer-toast__title">تم نقل الأصناف</div>' +
                '<div class="pos-mode-transfer-toast__message"></div>' +
            '</div>' +
            '<button type="button" class="pos-mode-transfer-toast__close" aria-label="إغلاق">' +
                '<i class="fas fa-times"></i>' +
            '</button>';

        const message = 'نُقلت ' + count + ' ' + (count === 1 ? 'صنف' : 'أصناف') +
            ' إلى ' + posModeLabel(targetMode);
        toast.querySelector('.pos-mode-transfer-toast__message').textContent = message;
        toast.querySelector('.pos-mode-transfer-toast__close').addEventListener('click', function(event) {
            event.preventDefault();
            dismissModeTransferToast();
        });

        document.body.appendChild(toast);
        window.requestAnimationFrame(function() {
            toast.classList.add('is-visible');
        });
        window.setTimeout(dismissModeTransferToast, 2800);
        flashPosCartTransfer();
    }

    function confirmModeSwitchWithSavedOrder(targetMode, itemCount) {
        const targetLabel = posModeLabel(targetMode);
        const count = Math.max(1, parseInt(itemCount, 10) || 1);
        const title = 'نقل الأصناف؟';
        const text = 'لديك ' + count + ' ' + (count === 1 ? 'صنف' : 'أصناف') +
            ' على طلب محفوظ. نقلها إلى «' + targetLabel +
            '» يبدأ طلباً جديداً هناك، والطلب الأصلي يبقى كما هو.';

        if (window.Swal && typeof window.Swal.fire === 'function') {
            // POS ships SweetAlert2 v11 (didOpen + showDenyButton). Keep a v8 fallback.
            const supportsDeny = typeof Swal.isValidParameter !== 'function'
                || Swal.isValidParameter('showDenyButton');
            const openHook = (typeof Swal.isValidParameter === 'function' && Swal.isValidParameter('didOpen'))
                ? 'didOpen'
                : 'onOpen';

            const options = posmainSwalPremiumOptions({
                icon: 'question',
                type: 'question',
                title: title,
                text: text,
                showCancelButton: true,
                confirmButtonText: 'نقل الأصناف',
                cancelButtonText: 'إلغاء',
                reverseButtons: true,
                focusConfirm: true,
            });

            if (supportsDeny) {
                options.showDenyButton = true;
                options.denyButtonText = 'بدء طلب جديد بدون نقل';
                options.customClass = Object.assign({}, options.customClass || {}, {
                    denyButton: 'pos-swal-premium__deny',
                });
            } else {
                options.html = '<p class="pos-mode-transfer-swal__text">' + text + '</p>' +
                    '<button type="button" class="pos-mode-transfer-swal__discard" data-pos-mode-discard="1">بدء طلب جديد بدون نقل</button>';
                delete options.text;
                let discarded = false;
                options[openHook] = function(popup) {
                    const root = popup && popup.querySelector ? popup : document;
                    const discardBtn = root.querySelector('[data-pos-mode-discard]');
                    if (!discardBtn) {
                        return;
                    }
                    discardBtn.addEventListener('click', function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        discarded = true;
                        if (typeof Swal.close === 'function') {
                            Swal.close({ value: 'discard' });
                        }
                    });
                };
                options.__posDiscardFlag = function() { return discarded; };
            }

            return Swal.fire(options).then(function(result) {
                if (options.__posDiscardFlag && options.__posDiscardFlag()) {
                    return 'discard';
                }
                if (result && (result.isDenied || result.value === 'discard')) {
                    return 'discard';
                }
                if (result && (result.isConfirmed || result.value === true || result.value === 'transfer')) {
                    return 'transfer';
                }
                return 'cancel';
            });
        }

        if (window.confirm(text + '\n\nموافق = نقل الأصناف')) {
            return Promise.resolve('transfer');
        }
        return Promise.resolve('cancel');
    }

    function openTablesModalForModeSwitch() {
        const tablesModal = document.getElementById('tablesModal');
        if (tablesModal) {
            bootstrap.Modal.getOrCreateInstance(tablesModal).show();
        }
    }

    function activatePosOrderMode(targetId, $tab) {
        const $target = $('#' + targetId);
        if (!$target.length) {
            return;
        }
        $target.prop('checked', true).trigger('change');
        if (targetId === 'age2') {
            openTablesModalForModeSwitch();
        }
        if ($tab && $tab.length) {
            $('.pos-mode-tab').toggleClass('active', false);
            $tab.toggleClass('active', true);
        }
    }

    function posmainActivateTableOrderMode($tab) {
        activatePosOrderMode('age2', $tab);
    }

    function requestPosOrderModeSwitch(targetId, $tab) {
        const targetVal = ageValueFromTargetId(targetId);
        const currentVal = String($('input[name="age"]:checked').val() || '1');
        const itemCount = getPosCartItemCount();

        const runSwitch = function(keepCart) {
            window.__posModeSwitchKeepCart = !!keepCart;
            activatePosOrderMode(targetId, $tab);
        };

        if (targetVal === currentVal) {
            if (targetId === 'age2') {
                openTablesModalForModeSwitch();
            }
            return;
        }

        if (itemCount <= 0) {
            runSwitch(false);
            return;
        }

        if (hasActiveSavedOrderContext()) {
            confirmModeSwitchWithSavedOrder(targetVal, itemCount).then(function(action) {
                if (action === 'cancel') {
                    return;
                }
                runSwitch(action === 'transfer');
            });
            return;
        }

        // Unsaved draft: transfer items automatically for a smooth cashier flow.
        runSwitch(true);
    }

    function syncTableModeAvailability() {
        const canTable = canUseTableOrders();
        const $tableTab = $('.pos-mode-tab[data-age-target="age2"]');
        $tableTab.show();
        if (canTable) {
            $tableTab.removeClass('pos-action-locked');
        } else {
            $tableTab.addClass('pos-action-locked');
        }

        if (!canTable && String($('input[name="age"]:checked').val() || '') === '2') {
            window.__posModeSwitchKeepCart = getPosCartItemCount() > 0;
            $('#age1').prop('checked', true).trigger('change');
        }
    }

    function syncTableControlVisibility() {
        const isTableMode = String($('input[name="age"]:checked').val() || '') === '2';
        $('.pos-table-visible-control').toggle(isTableMode);
        $('.pos-table-mount').toggle(isTableMode);
        $('.pos-current-order-controls').toggleClass('pos-table-mode', isTableMode);
    }

    function syncModeTabs() {
        syncTableModeAvailability();
        const activeId = $('input[name="age"]:checked').attr('id');
        $('.pos-mode-tab').toggleClass('active', false);
        $(`.pos-mode-tab[data-age-target="${activeId}"]`).toggleClass('active', true);
        syncTableControlVisibility();
    }

    $('.pos-mode-tab').on('click', function() {
        const targetId = $(this).data('age-target');
        const $tab = $(this);
        if (targetId === 'age2' && !canUseTableOrders()) {
            if (!window.POSMAIN || typeof window.POSMAIN.ensurePermissionOrOverride !== 'function') {
                showPOSNotice('لا تملك صلاحية فتح الطاولات', 'warning');
                return;
            }
            window.POSMAIN.ensurePermissionOrOverride('pos.table.open', {
                message: 'فتح الطاولات يتطلب اعتماد مدير',
            }).done(function(approval) {
                if (approval && approval.approval_id) {
                    window.POSMAIN_TABLE_OPEN_OVERRIDE = approval;
                }
                requestPosOrderModeSwitch('age2', $tab);
            });
            return;
        }
        requestPosOrderModeSwitch(targetId, $tab);
    });
    syncModeTabs();
    syncPaymentFundOptions();
    // Defer the lazy product-grid loader while the order-saved success modal
    // is on screen, so nothing repaints behind its translucent backdrop.
    (function posMaybeLoadRemainingItems() {
        if (window.POS_SUCCESS_HOLD) {
            window.setTimeout(posMaybeLoadRemainingItems, 100);
            return;
        }
        loadRemainingItems();
    })();

    // ========================================
    // Item Filtering Functions
    // ========================================




    // ========================================
    // Item Search & Add Functions
    // ========================================
    function searchItemByBarcode(barcode) {
        let qty = '1.000000';
        let searchCode = barcode;

        // Check if it's a scale barcode using config
        if (posConfig && posConfig.scale_barcode && posConfig.scale_barcode.enabled) {
            const cfg = posConfig.scale_barcode;

            if (barcode.length === cfg.barcode_length &&
                barcode.substring(0, cfg.prefix.length) === cfg.prefix) {

                searchCode = barcode.substring(cfg.item_code_start,
                                               cfg.item_code_start + cfg.item_code_length);

                const weightStr = barcode.substring(cfg.weight_start,
                                                    cfg.weight_start + cfg.weight_length);
                if (!window.POSOrderApi) {
                    throw new Error('POS_MONEY_API_REQUIRED');
                }
                qty = window.POSOrderApi.quantityFromIntegerRatio(weightStr, cfg.weight_divisor);
                searchCode = parseInt(searchCode).toString();

                console.log('🔢 Scale Barcode Detected:', {
                    original: barcode,
                    itemCode: searchCode,
                    weight: qty
                });
            }
        }

        $.ajax({
            url: 'ajax/search_item.php',
            type: 'POST',
            data: { barcode: searchCode },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const item = response.item || {};
                    const availability = itemAvailabilityContextFromPayload(item);
                    if (!availability.canAdd && !availability.requiresManagerOverride) {
                        showUnavailableItemMessage(availability, item.name || '');
                        return;
                    }

                    requestRecipeStockOverride(availability, item.name || '', item.id).then(function(managerApprovalId) {
                        beginAddItemToOrder(item.id, item.name, item.price, item.barcode, qty, '', '', {
                            hasVariants: itemHasVariantsValue(item.has_variants),
                            sugarAllowed: item.allows_sugar_spoons === true || String(item.allows_sugar_spoons || '') === '1',
                            managerApprovalId: managerApprovalId
                        });
                    });
                } else {
                    alert('الصنف غير موجود');
                }
            },
            error: function() {
                alert('خطأ في البحث عن الصنف');
            }
        });
    }

    // ========================================
    // Item Click Events
    // ========================================
    $('#itemsGrid').on('click', '.item-card.itemButton', function(e) {
        if ($(e.target).closest('.item-details-btn').length) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();

        let card = $(this);
        let itemId = card.data('item-id');
        let itemName = card.data('item-name');
        let itemPrice = String(card.attr('data-item-price') || '0');
        let itemBarcode = card.data('item-barcode');
        let imageHtml = card.find('.item-image-container').html();
        let hasVariants = itemHasVariantsValue(card.attr('data-has-variants'));
        let sugarAllowed = String(card.attr('data-sugar-spoons') || '0') === '1';
        let availability = itemAvailabilityContext(card);
        registerVariantCacheFromCard(card);

        if (!availability.canAdd) {
            showUnavailableItemMessage(availability, itemName);
            return;
        }

        if (availability.warnOnly && !availability.requiresManagerOverride) {
            // True warn-only: sale is allowed, show a non-blocking toast and proceed
            // without manager approval.
            showAvailabilityWarnToast(availability, itemName);
            beginAddItemToOrder(itemId, itemName, itemPrice, itemBarcode, 1, imageHtml, '', {
                hasVariants: hasVariants,
                sugarAllowed: sugarAllowed,
                managerApprovalId: null
            });
            return;
        }

        requestRecipeStockOverride(availability, itemName, itemId).then(function(managerApprovalId) {
            beginAddItemToOrder(itemId, itemName, itemPrice, itemBarcode, 1, imageHtml, '', {
                hasVariants: hasVariants,
                sugarAllowed: sugarAllowed,
                managerApprovalId: managerApprovalId
            });
        });
    });

    $('#itemsGrid').on('click', '.item-details-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        let card = $(this).closest('.item-card');
        let itemId = card.data('item-id');
        let itemName = card.data('item-name');
        let itemPrice = String(card.attr('data-item-price') || '0');
        let itemBarcode = card.data('item-barcode');
        let itemDesc = card.data('item-desc') || 'لا يوجد وصف';
        let hasVariants = itemHasVariantsValue(card.attr('data-has-variants'));
        let sugarAllowed = String(card.attr('data-sugar-spoons') || '0') === '1';
        let availability = itemAvailabilityContext(card);

        let imageHtml = card.find('.item-image-container').html();
        registerVariantCacheFromCard(card);

        $('#modal_item_name').text(itemName);
        $('#modal_item_barcode').text(itemBarcode || '-');
        $('#modal_item_price').text(Number(itemPrice).toFixed(2) + ' ج.م');
        $('#modal_item_desc').text(itemDesc);
        $('#modal_item_image').html(imageHtml);
        $('#modal_add_item')
            .prop('disabled', !availability.canAdd)
            .toggleClass('disabled', !availability.canAdd)
            .attr('title', availability.canAdd ? 'إضافة للطلب' : itemUnavailableMessage(availability, itemName));

        $('#modal_add_item').data({
            'id': itemId,
            'name': itemName,
            'price': itemPrice,
            'barcode': itemBarcode,
            'image': imageHtml,
            'hasVariants': hasVariants,
            'sugarAllowed': sugarAllowed,
            'isAvailable': availability.isAvailable,
            'canAdd': availability.canAdd,
            'availabilityStatus': availability.status,
            'unavailableReason': availability.reason,
            'requiresManagerOverride': availability.requiresManagerOverride,
            'overrideAllowed': availability.overrideAllowed,
            'overridePermission': availability.overridePermission,
            'warnOnly': availability.warnOnly,
            'recipeQty': availability.recipeQty
        });

        $('#itemDetailsModal').modal('show');
    });

    $(document).on('click', '#modal_add_item', function() {
        let data = $(this).data();
        if (data.canAdd === false || String(data.canAdd) === 'false') {
            showUnavailableItemMessage({
                isAvailable: false,
                canAdd: false,
                status: String(data.availabilityStatus || 'manual_unavailable'),
                reason: String(data.unavailableReason || '')
            }, data.name);
            return;
        }
        let itemPrice = String(data.price || '0');
        const warnOnly = data.warnOnly === true || String(data.warnOnly) === 'true';
        const availability = {
            isAvailable: data.isAvailable === true || String(data.isAvailable) === 'true',
            canAdd: true,
            status: String(data.availabilityStatus || 'available'),
            reason: String(data.unavailableReason || ''),
            requiresManagerOverride: data.requiresManagerOverride === true || String(data.requiresManagerOverride) === 'true',
            overrideAllowed: data.overrideAllowed === true || String(data.overrideAllowed) === 'true',
            overridePermission: String(data.overridePermission || ''),
            warnOnly: warnOnly,
            recipeQty: String(data.recipeQty || '').trim()
        };

        if (warnOnly && !availability.requiresManagerOverride) {
            showAvailabilityWarnToast(availability, data.name);
            beginAddItemToOrder(data.id, data.name, itemPrice, data.barcode, 1, data.image, '', {
                hasVariants: itemHasVariantsValue(data.hasVariants),
                sugarAllowed: data.sugarAllowed === true || String(data.sugarAllowed) === 'true',
                managerApprovalId: null
            });
            $('#itemDetailsModal').modal('hide');
            return;
        }

        requestRecipeStockOverride(availability, data.name, data.id).then(function(managerApprovalId) {
            beginAddItemToOrder(data.id, data.name, itemPrice, data.barcode, 1, data.image, '', {
                hasVariants: itemHasVariantsValue(data.hasVariants),
                sugarAllowed: data.sugarAllowed === true || String(data.sugarAllowed) === 'true',
                managerApprovalId: managerApprovalId
            });
            $('#itemDetailsModal').modal('hide');
        });
    });

    // ========================================
    // Add Item to Order
    // ========================================
    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function escapeHtmlAttribute(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    let activeLineNoteRow = null;
    const lineNoteDraftStoragePrefix = 'posmain.lineNotes.v1';

    function lineNoteDraftContext() {
        const orderId = String($('#edit_order_id').val() || $('#selected_order_id').val() || '').trim();
        if (orderId) {
            return 'order:' + orderId;
        }

        const tableId = String($('#selected_table_id').val() || '').trim();
        if (tableId && tableId !== '0') {
            return 'table:' + tableId;
        }

        return 'mode:' + String($('input[name="age"]:checked').val() || 'takeaway');
    }

    function lineNoteDraftStorageKey() {
        return lineNoteDraftStoragePrefix + ':' + lineNoteDraftContext();
    }

    function lineNoteItemKey(itemId, barcode) {
        return String(itemId || '') + ':' + String(barcode || '');
    }

    function lineNoteItemKeyForRow(row) {
        const $row = $(row);
        return lineNoteItemKey(
            $row.find('input[name="itmname[]"]').val(),
            $row.data('itemid') || $row.find('.barcode').val()
        );
    }

    function readLineNoteDrafts() {
        try {
            return JSON.parse(localStorage.getItem(lineNoteDraftStorageKey()) || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function getLineNoteDraft(itemId, barcode) {
        const drafts = readLineNoteDrafts();
        return String(drafts[lineNoteItemKey(itemId, barcode)] || '');
    }

    function saveLineNoteDraft(row, note) {
        try {
            const drafts = readLineNoteDrafts();
            const key = lineNoteItemKeyForRow(row);
            note = String(note || '').trim();
            if (note) {
                drafts[key] = note;
            } else {
                delete drafts[key];
            }
            localStorage.setItem(lineNoteDraftStorageKey(), JSON.stringify(drafts));
        } catch (error) {
            console.warn('Line note draft was not stored:', error);
        }
    }

    function ensureLineNoteModal() {
        if ($('#lineNoteModal').length > 0) {
            return;
        }

        $('body').append(`
            <div class="modal fade" id="lineNoteModal" tabindex="-1" aria-labelledby="lineNoteModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title" id="lineNoteModalLabel">
                                <i class="fas fa-sticky-note me-2"></i>ملاحظة للمطبخ
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <textarea class="form-control" id="lineNoteModalText" rows="4" maxlength="500" placeholder="اكتب ملاحظة المطبخ هنا"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-danger" id="lineNoteModalClear">مسح</button>
                            <button type="button" class="btn btn-primary" id="lineNoteModalSave">حفظ</button>
                        </div>
                    </div>
                </div>
            </div>
        `);
    }

    function toggleLineNoteModal(show) {
        const modal = document.getElementById('lineNoteModal');
        if (!modal) {
            return;
        }

        if (window.bootstrap && bootstrap.Modal) {
            const instance = typeof bootstrap.Modal.getOrCreateInstance === 'function'
                ? bootstrap.Modal.getOrCreateInstance(modal)
                : new bootstrap.Modal(modal);
            show ? instance.show() : instance.hide();
            return;
        }

        $('#lineNoteModal').modal(show ? 'show' : 'hide');
    }

    function updateLineNoteButton(row) {
        const $row = $(row);
        const $input = $row.find('.lineNoteInput');
        const $button = $row.find('.lineNoteButton');
        const note = String($input.val() || '').trim();
        const hasNote = note !== '';

        $button
            .toggleClass('line-note-has-value', hasNote)
            .toggleClass('line-note-empty', !hasNote)
            .attr('title', hasNote ? 'تعديل ملاحظة المطبخ' : 'إضافة ملاحظة للمطبخ')
            .attr('aria-label', hasNote ? 'تعديل ملاحظة المطبخ' : 'إضافة ملاحظة للمطبخ');
    }

    function initializeLineNoteButtons(scope) {
        $(scope || document).find('.item-card-order').each(function() {
            const $row = $(this);
            const $input = $row.find('.lineNoteInput');
            if (String($input.val() || '').trim() === '') {
                $input.val(getLineNoteDraft(
                    $row.find('input[name="itmname[]"]').val(),
                    $row.data('itemid') || $row.find('.barcode').val()
                ));
            }
            updateLineNoteButton(this);
        });
    }

    let activeVariantContext = null;
    let activeSugarContext = null;
    const SUGAR_SPOONS_SAFETY_LIMIT = 999;

    function normalizeSugarSpoons(value) {
        const parsed = parseInt(value, 10);
        if (!Number.isFinite(parsed)) {
            return 0;
        }
        return Math.max(0, Math.min(SUGAR_SPOONS_SAFETY_LIMIT, parsed));
    }

    function fetchItemVariants(itemId) {
        return $.ajax({
            url: 'ajax/get_item_variants.php',
            type: 'GET',
            dataType: 'json',
            data: { item_id: itemId }
        }).then(function(response) {
            const variants = response && response.success && Array.isArray(response.variants) ? response.variants : [];
            cacheItemVariants(itemId, variants);
            return variants;
        }, function() {
            return [];
        });
    }

    function beginAddItemToOrder(id, name, price, barcode, qty, imageHtml, lineNote, options) {
        const hasVariantHint = options && Object.prototype.hasOwnProperty.call(options, 'hasVariants')
            ? itemHasVariantsValue(options.hasVariants)
            : null;

        if (hasVariantHint === false) {
            completeAddItemToOrder(id, name, price, barcode, qty, imageHtml || '', lineNote || '', options || {});
            return;
        }

        const cachedVariants = cachedItemVariants(id);
        if (cachedVariants && cachedVariants.length > 0) {
            openVariantModal({
                id: id,
                name: name,
                price: String(price || '0'),
                barcode: barcode,
                qty: String(qty || '1'),
                imageHtml: imageHtml || '',
                lineNote: lineNote || '',
                sugarAllowed: !!(options && options.sugarAllowed),
                managerApprovalId: options && options.managerApprovalId ? options.managerApprovalId : null,
                variants: cachedVariants
            });
            return;
        }

        fetchItemVariants(id).then(function(variants) {
            if (!variants.length) {
                completeAddItemToOrder(id, name, price, barcode, qty, imageHtml || '', lineNote || '', options || {});
                return;
            }

            openVariantModal({
                id: id,
                name: name,
                price: String(price || '0'),
                barcode: barcode,
                qty: String(qty || '1'),
                imageHtml: imageHtml || '',
                lineNote: lineNote || '',
                sugarAllowed: !!(options && options.sugarAllowed),
                managerApprovalId: options && options.managerApprovalId ? options.managerApprovalId : null,
                variants: variants
            });
        });
    }

    function ensureSugarSpoonsModal() {
        if ($('#sugarSpoonsModal').length > 0) {
            return;
        }

        if ($('#sugarSpoonsModalStyles').length === 0) {
            $('head').append(`
                <style id="sugarSpoonsModalStyles">
                    #sugarSpoonsModal { direction: rtl; }
                    #sugarSpoonsModal .modal-content { border:0; border-radius:14px; overflow:hidden; box-shadow:0 24px 70px rgba(15,23,42,.24); }
                    #sugarSpoonsModal .modal-header { align-items:flex-start; padding:20px 22px; color:#f8fafc; background:#134e4a; border:0; }
                    #sugarSpoonsModal .modal-title { font-size:20px; font-weight:900; }
                    #sugarSpoonsModal .sugar-item-name { margin-top:5px; color:#ccfbf1; font-size:13px; }
                    #sugarSpoonsModal .modal-body { padding:28px 22px; text-align:center; background:#f8fafc; }
                    #sugarSpoonsModal .sugar-counter { display:grid; grid-template-columns:64px minmax(110px,1fr) 64px; gap:10px; max-width:330px; margin:0 auto; direction:ltr; }
                    #sugarSpoonsModal .sugar-counter button { height:58px; border-radius:11px; font-size:27px; font-weight:900; }
                    #sugarSpoonsModal .sugar-counter input { height:58px; color:#0f172a; background:#fff; border:2px solid #99f6e4; border-radius:11px; font-size:25px; font-weight:900; text-align:center; }
                    #sugarSpoonsModal .sugar-counter input::-webkit-inner-spin-button { display:none; }
                    #sugarSpoonsModal .sugar-counter-state { min-height:24px; margin-top:12px; color:#0f766e; font-weight:900; }
                    #sugarSpoonsModal .sugar-counter-error { min-height:20px; margin-top:5px; color:#b91c1c; font-size:12px; font-weight:800; }
                    #sugarSpoonsModal .modal-footer { padding:13px 22px; background:#fff; border-top-color:#e5eaf0; }
                    #sugarSpoonsModal .modal-footer .btn { min-width:105px; border-radius:8px; font-weight:800; }
                </style>
            `);
        }
        $('body').append(`
            <div class="modal" id="sugarSpoonsModal" tabindex="-1" aria-labelledby="sugarSpoonsModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h5 class="modal-title" id="sugarSpoonsModalLabel">عدد ملاعق السكر</h5>
                                <div class="sugar-item-name" id="sugarSpoonsItemName"></div>
                            </div>
                            <button type="button" class="close text-white" data-dismiss="modal" data-bs-dismiss="modal" aria-label="إغلاق"><span aria-hidden="true">&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <div class="sugar-counter">
                                <button type="button" class="btn btn-outline-secondary" id="sugarSpoonsDecrease" aria-label="تقليل عدد ملاعق السكر">−</button>
                                <input type="number" id="sugarSpoonsValue" value="0" min="0" step="1" inputmode="numeric" aria-label="عدد ملاعق السكر">
                                <button type="button" class="btn btn-success" id="sugarSpoonsIncrease" aria-label="زيادة عدد ملاعق السكر">+</button>
                            </div>
                            <div class="sugar-counter-state" id="sugarSpoonsState">بدون سكر</div>
                            <div class="sugar-counter-error" id="sugarSpoonsError" aria-live="polite"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-dismiss="modal" data-bs-dismiss="modal">إلغاء</button>
                            <button type="button" class="btn btn-success" id="sugarSpoonsConfirm">إضافة للصنف</button>
                        </div>
                    </div>
                </div>
            </div>
        `);
    }

    function toggleSugarSpoonsModal(show) {
        const modal = document.getElementById('sugarSpoonsModal');
        if (!modal) {
            return;
        }
        if (window.bootstrap && bootstrap.Modal) {
            const instance = typeof bootstrap.Modal.getOrCreateInstance === 'function'
                ? bootstrap.Modal.getOrCreateInstance(modal)
                : new bootstrap.Modal(modal);
            show ? instance.show() : instance.hide();
            return;
        }
        $('#sugarSpoonsModal').modal(show ? 'show' : 'hide');
    }

    function completeAddItemToOrder(id, name, price, barcode, qty, imageHtml, lineNote, options) {
        options = options || {};
        if (options.sugarAllowed && !Object.prototype.hasOwnProperty.call(options, 'sugarSpoons')) {
            ensureSugarSpoonsModal();
            activeSugarContext = {
                id: id,
                name: name,
                price: price,
                barcode: barcode,
                qty: qty,
                imageHtml: imageHtml,
                lineNote: lineNote,
                options: options
            };
            $('#sugarSpoonsItemName').text(name || '');
            $('#sugarSpoonsValue').val(0);
            $('#sugarSpoonsState').text('بدون سكر');
            $('#sugarSpoonsError').text('');
            toggleSugarSpoonsModal(true);
            return;
        }
        addItemToOrder(id, name, price, barcode, qty, imageHtml, lineNote, options);
    }

    function sugarSpoonsFromPreparation(values) {
        if (!Array.isArray(values)) {
            return null;
        }
        for (let index = 0; index < values.length; index += 1) {
            const value = values[index] || {};
            if (String(value.code || value.field_code || '') === 'sugar_spoons') {
                return normalizeSugarSpoons(value.value !== undefined ? value.value : value.selected_value);
            }
        }
        return null;
    }

    function ensureVariantModal() {
        if ($('#itemVariantModal').length > 0) {
            return;
        }

        $('body').append(`
            <div class="modal" id="itemVariantModal" tabindex="-1" aria-labelledby="itemVariantModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title" id="itemVariantModalLabel">
                                <i class="fas fa-list-ul me-2"></i>اختر النوع
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="fw-bold mb-3" id="itemVariantParentName"></div>
                            <div class="row g-2" id="itemVariantChoices"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                        </div>
                    </div>
                </div>
            </div>
        `);
    }

    function toggleVariantModal(show) {
        const modal = document.getElementById('itemVariantModal');
        if (!modal) {
            return;
        }

        if (window.bootstrap && bootstrap.Modal) {
            const instance = typeof bootstrap.Modal.getOrCreateInstance === 'function'
                ? bootstrap.Modal.getOrCreateInstance(modal)
                : new bootstrap.Modal(modal);
            show ? instance.show() : instance.hide();
            return;
        }

        $('#itemVariantModal').modal(show ? 'show' : 'hide');
    }

    function openVariantModal(context) {
        ensureVariantModal();
        activeVariantContext = context;
        $('#itemVariantParentName').text(context.name || '');
        $('#itemVariantChoices').html((context.variants || []).map(function(variant) {
            const variantItemId = parseInt(variant.item_id || variant.variant_item_id || variant.id || 0, 10) || 0;
            const variantName = String(variant.name || variant.iname || variant.variant_label || '').trim();
            const variantLabel = String(variant.variant_label || variant.label || '').trim();
            const variantBarcode = String(variant.barcode || '');
            const variantPrice = window.POSOrderApi.decimalString(variant.price1 || variant.price || '0', 6, '0');
            return `
                <div class="col-md-6">
                    <button type="button"
                            class="btn btn-outline-primary w-100 text-start itemVariantChoice"
                            data-item-id="${variantItemId}"
                            data-item-name="${escapeHtml(variantName)}"
                            data-item-price="${variantPrice}"
                            data-item-barcode="${escapeHtml(variantBarcode)}">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <span class="fw-bold">${escapeHtml(variantLabel || variantName)}</span>
                            <span class="text-success fw-bold">${Number(variantPrice).toFixed(2)} ج.م</span>
                        </div>
                        <small class="text-muted d-block">${escapeHtml(variantName)}</small>
                    </button>
                </div>
            `;
        }).join(''));

        toggleVariantModal(true);
    }

    function touchOrderDraft() {
        if (window.POSOrderDraft && typeof window.POSOrderDraft.markDirty === 'function') {
            window.POSOrderDraft.markDirty();
        }
    }

    // Compatibility call form: addItemToOrder(id, name, price, barcode, qty = 1, imageHtml = '', lineNote = '')
    function addItemToOrder(id, name, price, barcode, qty = 1, imageHtml = '', lineNote = '', options = {}) {
        const money = window.POSOrderApi;
        if (!money) {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        const managerApprovalId = parseInt(options && options.managerApprovalId ? options.managerApprovalId : 0, 10) || 0;
        const unitValue = money.decimalString(options && options.uVal ? options.uVal : '1', 6, '1');
        const quantity = money.decimalString(qty, 6, '1');
        const persisted = !!(options && options.persisted);
        const persistedQty = money.decimalString(
            options && options.persistedQty !== undefined ? options.persistedQty : quantity,
            6,
            quantity
        );
        const hasSugarSelection = Object.prototype.hasOwnProperty.call(options || {}, 'sugarSpoons')
            && options.sugarSpoons !== null
            && options.sugarSpoons !== undefined;
        const sugarSpoons = hasSugarSelection
            ? normalizeSugarSpoons(options.sugarSpoons)
            : null;
        const preparationValues = sugarSpoons === null
            ? []
            : [{ code: 'sugar_spoons', value: sugarSpoons }];
        const preparationJson = JSON.stringify(preparationValues);
        let existingItem = $('.item-card-order').filter(function() {
            return String($(this).find('input[name="itmname[]"]').val()) === String(id)
                && String($(this).find('.preparationValuesInput').val() || '[]') === preparationJson;
        });

        if (existingItem.length > 0) {
            let qtyInput = existingItem.find('.quantityInput');
            const currentQty = money.decimalString(qtyInput.val(), 6, '0');
            const newQty = money.addDecimalStrings(currentQty, quantity, 6);
            qtyInput.val(newQty);

            let priceInput = existingItem.find('.priceInput');
            const itemPrice = money.decimalString(priceInput.val(), 6, '0');
            const subtotal = money.lineTotalFromQuantityAndUnitPrice(newQty, itemPrice);
            existingItem.find('.subtotal').val(subtotal);
            existingItem.find('.pos-cart-price-display').html(subtotal + ' <span class="pos-currency">ج.م</span>');
            if (managerApprovalId > 0) {
                existingItem.find('.managerApprovalInput').val(managerApprovalId);
            }

            updateTotal();
            $('#barcodeInput').val('').focus();
            return;
        }

        const unitPrice = money.decimalString(price, 6, '0');
        const subtotal = money.lineTotalFromQuantityAndUnitPrice(quantity, unitPrice);
        const noteValue = String(lineNote || '').trim() || getLineNoteDraft(id, barcode);
        const safeName = escapeHtml(name);
        const safeLineNote = escapeHtmlAttribute(noteValue);
        const safePreparationJson = escapeHtmlAttribute(preparationJson);
        const preparationLabel = sugarSpoons === null
            ? ''
            : `<small class="pos-cart-preparation text-muted">السكر: ${sugarSpoons === 0 ? 'بدون' : sugarSpoons + ' ملعقة'}</small>`;

        let itemCard = `
            <div class="item-card-order pos-cart-row" data-itemid="${escapeHtml(barcode)}" data-catalog-price="${unitPrice}"${persisted ? ` data-persisted-line="1" data-persisted-qty="${escapeHtml(persistedQty)}"` : ''}>
                <div class="pos-cart-row-inner">
                    <div class="pos-cart-price-display" aria-hidden="true">${subtotal} <span class="pos-currency">ج.م</span></div>
                    <div class="pos-cart-qty">
                        <button type="button" class="btn qty-step qty-decrease" title="تقليل">−</button>
                        <input type="number"
                               class="form-control form-control-sm text-center quantityInput nozero fw-bold"
                               value="${quantity}"
                               name="itmqty[]"
                               min="1"
                               step="1"
                               title="الكمية">
                        <button type="button" class="btn qty-step qty-increase" title="زيادة">+</button>
                        <input type="hidden" name="u_val[]" value="${escapeHtml(unitValue)}">
                    </div>
                    <div class="pos-cart-main">
                        <input type="hidden" value='${id}' name="itmname[]">
                        <input type="hidden" class="barcode" value="${escapeHtml(barcode)}">
                        <div class="pos-cart-name" title="${safeName}">${safeName}</div>
                        ${preparationLabel}
                        <input type="hidden"
                               class="preparationValuesInput"
                               name="itmpreparation[]"
                               value="${safePreparationJson}">
                        <input type="hidden"
                               class="lineNoteInput"
                               name="itmnote[]"
                               value="${safeLineNote}">
                        <input type="hidden"
                               class="managerApprovalInput"
                               name="itmmanagerapproval[]"
                               value="${managerApprovalId > 0 ? managerApprovalId : ''}">
                    </div>
                    <div class="pos-cart-note">
                        <button type="button" class="btn lineNoteButton line-note-empty" title="إضافة ملاحظة للمطبخ" aria-label="إضافة ملاحظة للمطبخ">
                            <i class="fas fa-sticky-note"></i>
                        </button>
                    </div>
                    <button type="button" class="btn delRow" title="حذف" aria-label="حذف">
                        <i class="fas fa-times"></i>
                    </button>
                    <div class="pos-cart-value d-none">
                        <input type="hidden" name="itmdisc[]" value="0">
                        <input type="text"
                               class="form-control form-control-sm text-center subtotal fw-bold"
                               readonly
                               value="${subtotal}"
                               name="itmval[]"
                               title="القيمة">
                    </div>
                    <div class="pos-cart-price d-none">
                        <input type="number"
                               class="form-control form-control-sm text-center priceInput nozero"
                               value="${unitPrice}"
                               name="itmprice[]"
                               step="0.000001"
                               title="السعر">
                    </div>
                </div>
            </div>
        `;

        $('#itemData').append(itemCard);
        initializeLineNoteButtons($('#itemData .item-card-order').last());
        if (persisted) {
            posmainRefreshPersistedLineLocks();
        }
        updateItemCount();
        updateTotal();
        $('#barcodeInput').val('').focus();
    }

    // ========================================
    // Update Functions
    // ========================================
    function updateItemCount() {
        let count = $('#itemData .item-card-order').length;
        $('#itemCount').text(count);
        updatePayOrderButtonState();
    }

    window.clearAllItems = function() {
        confirmPOSAction('مسح الطلب', 'هل تريد مسح كل الأصناف من الطلب الحالي؟', 'مسح الكل').then(function(confirmed) {
            if (!confirmed) {
                return;
            }
            $('#itemData').empty();
            $('#discount').val('0');
            $('#modal_discperc').val('0');
            $('#modal_discount').val('0');
            $('#modal_paid').val('0.00');
            $('#modal_change').val('0.00');
            updateItemCount();
            updateTotal();
        });
    };

    function updateTotal() {
        const money = window.POSOrderApi;
        if (!money) {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        let total = '0.00';
        $('.subtotal').each(function() {
            total = money.addDecimalStrings(
                total,
                money.decimalString($(this).val(), 2, '0'),
                2
            );
        });
        const deliveryFee = money.decimalString(
            typeof window.posDeliveryGetFee === 'function' ? window.posDeliveryGetFee() : '0',
            2,
            '0'
        );
        if (money.compareDecimalStrings(deliveryFee, '0.00', 2) > 0) {
            total = money.addDecimalStrings(total, deliveryFee, 2);
        }
        $('#total').val(total);
        $('#total_display').text(total + ' ج.م');
        $('#total_display_btn').text(total + ' ج.م');
        $('#modal_total').text(total + ' ج.م');

        let discount = money.decimalString($('#discount').val(), 2, '0');
        if (money.compareDecimalStrings(discount, total, 2) > 0) {
            discount = total;
            $('#discount, #modal_discount').val(discount);
        }
        const net = money.subtractDecimalStrings(total, discount, 2);
        $('#net_val').val(net);
        $('#net_display').text(net + ' ج.م');
        $('#modal_net').text(net + ' ج.م');
        $('#headplus').val(money.compareDecimalStrings(deliveryFee, '0.00', 2) > 0 ? deliveryFee : '0.00');

        setDefaultCashPaymentToNet(net);
        updatePayOrderButtonState();
        touchOrderDraft();
    }
    window.recalculateOrderTotals = updateTotal;

    function setDefaultCashPaymentToNet(netAmount) {
        updatePosPaymentAmountLayout();
        applyPosPaymentMethodAmounts(netAmount);
    }

    // ========================================
    // Tables System
    // ========================================

    let tablesRefreshTimer = null;
    let tablesRefreshInFlight = false;
    let tablesPreloadStarted = false;
    let tableTransferMode = false;
    let tableTransferInFlight = false;
    let latestTablesState = [];
    const defaultTablesModalTitle = $('#tablesModalLabel').html() || '<i class="fas fa-th-large me-2"></i>اختر الطاولة';

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function getSelectedTableId() {
        return parseInt($('#selected_table_id').val() || 0, 10) || 0;
    }

    function getActiveTableOrderId() {
        return String($('#selected_order_id').val() || $('#edit_order_id').val() || '').trim();
    }

    function isHeldTableWithoutActiveOrder() {
        return getSelectedTableId() > 0
            && String($('#selected_table_case').val() || '0') === '1'
            && getActiveTableOrderId() === ''
            && $('#itemData .item-card-order').length === 0;
    }
    window.POSMainIsHeldTableWithoutActiveOrder = isHeldTableWithoutActiveOrder;

    function updatePayOrderButtonState() {
        const $button = $('.pos-pay-order-btn');
        if (!$button.length) {
            return;
        }

        const shouldFreeOnly = isHeldTableWithoutActiveOrder();
        const $label = $button.find('span');
        const $amount = $('#total_display_btn');
        if (shouldFreeOnly) {
            $button.removeAttr('data-bs-toggle data-bs-target');
            $label.html('<i class="fas fa-chair me-1"></i>أفرغ الطاولة');
            $amount.hide();
            return;
        }

        $button.attr('data-bs-toggle', 'modal');
        $button.attr('data-bs-target', '#paymentModal');
        $label.html('<i class="fas fa-money-bill-wave me-1"></i>دفع');
        $amount.show();
    }

    function updateTransferTableButton() {
        const hasActiveTableOrder = getSelectedTableId() > 0 && getActiveTableOrderId() !== '';
        $('#transferTableBtn').toggle(hasActiveTableOrder);
    }

    function selectedSplitPaymentRows() {
        const rows = [];
        const money = window.POSOrderApi;
        if (!money) {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        $('#itemData .item-card-order').each(function(index) {
            const $row = $(this);
            const qty = money.decimalString($row.find('.quantityInput').val(), 6, '0');
            const grossAmount = money.decimalString($row.find('.subtotal').val(), 2, '0');
            rows.push({
                row_index: index,
                name: String($row.find('.pos-cart-name').text() || '').trim() || 'صنف',
                qty: qty,
                gross_amount: grossAmount
            });
        });

        return rows;
    }

    function renderSplitPaymentRows() {
        const $body = $('#pos_split_payment_rows');
        if (!$body.length) {
            return;
        }

        const rows = selectedSplitPaymentRows();
        if (!rows.length) {
            $body.html('<div class="pos-split-lines-empty">لا توجد أصناف في الطلب</div>');
            updateSplitPaymentTotal();
            return;
        }

        const html = rows.map(function(row) {
            return `
                <div class="pos-split-line-item"
                     data-row-index="${row.row_index}"
                     data-available-qty="${row.qty}"
                     data-line-gross="${row.gross_amount}">
                    <label class="pos-split-line-select">
                        <input type="checkbox" class="pos-split-line-check">
                        <span class="pos-split-line-check-ui" aria-hidden="true"></span>
                    </label>
                    <div class="pos-split-line-body">
                        <span class="pos-split-line-name">${escapeHtml(row.name)}</span>
                        <div class="pos-split-line-meta">
                            <div class="pos-split-line-qty-wrap">
                                <span class="pos-split-line-qty-label">الكمية</span>
                                <input type="number"
                                       class="pos-split-line-qty"
                                       value="${row.qty}"
                                       min="0"
                                       max="${row.qty}"
                                       step="1"
                                       inputmode="numeric"
                                       data-max-qty="${row.qty}">
                            </div>
                            <div class="pos-split-line-amount-wrap">
                                <span class="pos-split-line-amount-label">القيمة</span>
                                <span class="pos-split-line-total">${row.gross_amount}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        $body.html(html);
        updateSplitPaymentTotal();
    }

    function refreshSplitPaymentLineAmounts() {
        const rowsByIndex = {};
        selectedSplitPaymentRows().forEach(function(row) {
            rowsByIndex[row.row_index] = row;
        });

        $('#pos_split_payment_rows .pos-split-line-item').each(function() {
            const $row = $(this);
            const rowIndex = parseInt($row.data('row-index'), 10);
            const sourceRow = rowsByIndex[rowIndex];
            if (!sourceRow) {
                return;
            }

            $row.attr('data-available-qty', sourceRow.qty);
            $row.attr('data-line-gross', sourceRow.gross_amount);
            $row.find('.pos-split-line-qty').attr('max', sourceRow.qty).attr('data-max-qty', sourceRow.qty);
        });

        updateSplitPaymentTotal();
    }

    function updateSplitPaymentTotal() {
        const money = window.POSOrderApi;
        if (!money) {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        $('#pos_split_payment_rows .pos-split-line-item').each(function() {
            const $row = $(this);
            const checked = $row.find('.pos-split-line-check').prop('checked');
            $row.toggleClass('is-selected', checked);
            const maxQty = money.decimalString($row.attr('data-available-qty'), 6, '0');
            let qty = money.decimalString($row.find('.pos-split-line-qty').val(), 6, '0');
            if (money.compareDecimalStrings(qty, maxQty, 6) > 0) {
                qty = maxQty;
            }
            $row.find('.pos-split-line-qty').val(qty);
            const lineTotal = money.prorateMoneyByQuantity(
                $row.attr('data-line-gross'),
                qty,
                maxQty
            );
            $row.find('.pos-split-line-total').text(lineTotal);
        });

        const payload = splitPaymentPayloadFromModal();
        $('#pos_split_payment_total').text(payload.total + ' ج.م');
        if ($('#pos_split_payment_enabled').prop('checked')) {
            const mode = getPosPaymentMethod();
            if (mode === 'bank') {
                $('#modal_paid_cash').val('0.00');
                $('#modal_paid_bank').val(payload.total);
            } else if (mode === 'mixed') {
                const current = money.addDecimalStrings(
                    money.decimalString($('#modal_paid_cash').val(), 2, '0'),
                    money.decimalString($('#modal_paid_bank').val(), 2, '0'),
                    2
                );
                if (money.compareDecimalStrings(current, payload.total, 2) !== 0) {
                    $('#modal_paid_cash').val(payload.total);
                    $('#modal_paid_bank').val('0.00');
                }
            } else {
                $('#modal_paid_cash').val(payload.total);
                $('#modal_paid_bank').val('0.00');
            }
            calculateChange();
        }
    }

    function updateSplitPaymentButtons() {
        const splitEnabled = $('#pos_split_payment_enabled').prop('checked');
        $('.pos-pay-confirm-btn').toggle(!splitEnabled);
        $('.pos-split-pay-confirm-btn').toggle(splitEnabled);
    }

    function splitPaymentPayloadFromModal() {
        const money = window.POSOrderApi;
        if (!money) {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        const rows = [];
        let selectedGross = '0.00';
        $('#pos_split_payment_rows .pos-split-line-item').each(function() {
            const $row = $(this);
            if (!$row.find('.pos-split-line-check').prop('checked')) {
                return;
            }
            const rowIndex = parseInt($row.data('row-index'), 10);
            const maxQty = money.decimalString($row.attr('data-available-qty'), 6, '0');
            const qty = money.decimalString($row.find('.pos-split-line-qty').val(), 6, '0');
            if (
                Number.isNaN(rowIndex)
                || money.compareDecimalStrings(qty, '0.000000', 6) <= 0
                || money.compareDecimalStrings(qty, maxQty, 6) > 0
            ) {
                return;
            }
            rows.push({ row_index: rowIndex, qty: qty });
            selectedGross = money.addDecimalStrings(
                selectedGross,
                money.prorateMoneyByQuantity($row.attr('data-line-gross'), qty, maxQty),
                2
            );
        });

        const orderGross = money.decimalString($('#total').val(), 2, '0');
        let discount = money.decimalString($('#discount').val(), 2, '0');
        if (money.compareDecimalStrings(discount, orderGross, 2) > 0) {
            discount = orderGross;
        }
        const allocatedDiscount = money.compareDecimalStrings(selectedGross, '0.00', 2) > 0
            ? money.allocateProportionalMoney(discount, selectedGross, orderGross)
            : '0.00';
        const selectedNet = money.subtractDecimalStrings(selectedGross, allocatedDiscount, 2);

        return {
            rows: rows,
            gross: selectedGross,
            discount: allocatedDiscount,
            total: selectedNet
        };
    }

    function ensureHiddenFormInput(form, name) {
        let input = form.querySelector(`input[name="${name}"]`);
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }

        return input;
    }

    window.POSMainPrepareSplitPaymentFields = function(form, paymentState) {
        if (!$('#pos_split_payment_enabled').prop('checked')) {
            $('#pos_split_payment_enabled').prop('checked', true);
            $('#pos_split_payment_panel').show();
        }

        if (getSelectedTableId() <= 0) {
            alert('سداد أصناف محددة متاح لطلبات الطاولات فقط');
            return false;
        }

        const payload = splitPaymentPayloadFromModal();
        const money = window.POSOrderApi;
        if (!payload.rows.length || money.compareDecimalStrings(payload.total, '0.00', 2) <= 0) {
            alert('اختر صنف واحد على الأقل وكمية صحيحة للسداد');
            return false;
        }

        const paidCash = money.decimalString(paymentState.paidCash, 2, '0');
        const paidBank = money.decimalString(paymentState.paidBank, 2, '0');
        const totalPaid = money.addDecimalStrings(paidCash, paidBank, 2);
        if (money.compareDecimalStrings(totalPaid, payload.total, 2) !== 0) {
            alert('مبلغ الدفع يجب أن يساوي إجمالي الأصناف المحددة');
            return false;
        }

        ensureHiddenFormInput(form, 'pos_split_payment_payload').value = JSON.stringify(payload.rows);
        ensureHiddenFormInput(form, 'pos_split_payment_total').value = payload.total;
        ensureHiddenFormInput(form, 'pos_split_payment_method').value =
            money.compareDecimalStrings(paidCash, '0.00', 2) > 0
            && money.compareDecimalStrings(paidBank, '0.00', 2) > 0
                ? 'mixed'
                : (money.compareDecimalStrings(paidBank, '0.00', 2) > 0 ? 'bank' : 'cash');
        return true;
    };

    window.POSMainGetSplitPaymentPayload = function() {
        const payload = splitPaymentPayloadFromModal();
        return {
            rows: payload.rows,
            order_id: getActiveTableOrderId() || parseInt($('#selected_order_id').val() || '0', 10) || 0,
            table_id: getSelectedTableId(),
            paid_amount: payload.total,
            payment_method: window.POSOrderApi.compareDecimalStrings(
                window.POSOrderApi.decimalString($('#modal_paid_bank').val(), 2, '0'),
                '0.00',
                2
            ) > 0 ? 'bank' : 'cash'
        };
    };

    function showPOSNotice(message, type) {
        type = type || 'success';
        if (window.Swal && typeof window.Swal.fire === 'function') {
            Swal.fire({
                icon: type === 'danger' ? 'error' : type,
                title: message,
                timer: 1600,
                showConfirmButton: false
            });
            return;
        }

        const alertClass = type === 'danger' ? 'alert-danger' : 'alert-success';
        const alertDiv = $(`<div class="alert ${alertClass} position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999;">${escapeHtml(message)}</div>`);
        $('body').append(alertDiv);
        setTimeout(() => alertDiv.fadeOut(() => alertDiv.remove()), 2000);
    }

    function confirmPOSAction(title, text, confirmButtonText) {
        const confirmText = confirmButtonText || 'تأكيد';
        if (window.Swal && typeof window.Swal.fire === 'function') {
            return Swal.fire({
                title: title || 'تأكيد',
                text: text || '',
                showCancelButton: true,
                confirmButtonText: confirmText,
                cancelButtonText: 'إلغاء',
                reverseButtons: true,
                focusCancel: true,
                buttonsStyling: false,
                customClass: {
                    popup: 'pos-swal-premium',
                    title: 'pos-swal-premium__title',
                    htmlContainer: 'pos-swal-premium__text',
                    actions: 'pos-swal-premium__actions',
                    confirmButton: 'pos-swal-premium__confirm',
                    cancelButton: 'pos-swal-premium__cancel',
                },
            }).then(function(result) {
                return !!result.isConfirmed;
            });
        }

        return Promise.resolve(confirm(text || title));
    }

    function setTableTransferMode(enabled) {
        tableTransferMode = !!enabled;
        $('#tableTransferHint').toggleClass('d-none', !tableTransferMode);
        $('#tablesModalLabel').html(tableTransferMode
            ? '<i class="fas fa-exchange-alt me-2"></i>نقل الطاولة'
            : defaultTablesModalTitle
        );

        if ($('#tablesGrid').length && latestTablesState.length > 0) {
            renderTablesGrid(latestTablesState);
        }
    }

    window.openTableTransferFlow = function() {
        const sourceTableId = getSelectedTableId();
        const sourceOrderId = getActiveTableOrderId();

        if (!sourceTableId || !sourceOrderId) {
            showPOSNotice('اختر طاولة عليها طلب محفوظ أولاً', 'danger');
            return;
        }

        setTableTransferMode(true);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('tablesModal')).show();
        window.refreshTablesState();
    };

    function formatTableAmount(value) {
        const amount = parseFloat(value) || 0;
        return amount.toFixed(2);
    }

    function renderTableButton(table) {
        const tableId = parseInt(table.id || table.table_id || 0, 10);
        const tableName = String(table.tname || table.table_name || '');
        const tableCase = parseInt(table.table_case || table.has_active_order || 0, 10) ? 1 : 0;
        const orderId = table.order_id ? parseInt(table.order_id, 10) : '';
        const mutationVersion = table.mutation_version ? Math.max(1, parseInt(table.mutation_version, 10) || 1) : '';
        const orderTotal = parseFloat(table.fat_net || 0) || 0;
        let statusClass = tableCase ? 'btn-danger' : 'btn-success';
        let statusIcon = tableCase ? 'fa-utensils' : 'fa-check-circle';
        let statusText = tableCase ? 'مشغولة' : 'متاحة';
        let transferAction = '';
        let transferClass = '';
        let disabledAttr = '';

        if (tableTransferMode) {
            if (tableId === getSelectedTableId()) {
                statusClass = 'btn-secondary';
                statusIcon = 'fa-map-marker-alt';
                statusText = 'الطاولة الحالية';
                transferClass = 'pos-transfer-source';
                disabledAttr = 'disabled';
            } else if (tableCase) {
                statusClass = 'btn-outline-success';
                statusIcon = 'fa-compress-arrows-alt';
                statusText = 'مشغولة - دمج هنا';
                transferAction = 'merge';
                transferClass = 'pos-transfer-target pos-transfer-target-merge';
            } else {
                statusClass = 'btn-outline-primary';
                statusIcon = 'fa-exchange-alt';
                statusText = 'متاحة - انقل هنا';
                transferAction = 'move';
                transferClass = 'pos-transfer-target pos-transfer-target-move';
            }
        }

        const totalBadge = tableCase && orderTotal > 0
            ? `<div class="mt-2 badge bg-white text-dark">${formatTableAmount(orderTotal)} ج.م</div>`
            : '';

        return `
            <div class="col-md-4 col-sm-6">
                <button type="button"
                    class="btn ${statusClass} w-100 table-select-btn position-relative ${transferClass}"
                    data-table-id="${tableId}"
                    data-table-name="${escapeHtml(tableName)}"
                    data-table-case="${tableCase}"
                    data-order-id="${orderId}"
                    data-mutation-version="${mutationVersion}"
                    data-has-active-order="${tableCase}"
                    data-transfer-action="${transferAction}"
                    data-destination-order-id="${orderId}"
                    data-destination-mutation-version="${mutationVersion}"
                    ${disabledAttr}
                    style="min-height: 120px; font-size: 1.1rem;">
                    <div class="d-flex flex-column align-items-center justify-content-center">
                        <i class="fas ${statusIcon} fa-2x mb-2"></i>
                        <h6 class="mb-1">${escapeHtml(tableName)}</h6>
                        <small class="d-flex align-items-center">
                            <i class="fas ${statusIcon} me-1"></i>
                            ${statusText}
                        </small>
                        ${totalBadge}
                    </div>
                </button>
            </div>
        `;
    }

    function renderTablesStateMessage(iconClass, message) {
        const $grid = $('#tablesGrid');
        if (!$grid.length) {
            return;
        }

        $grid.html(`
            <div class="col-12 text-center pos-tables-state py-4">
                <i class="fas ${iconClass} fa-3x mb-3" aria-hidden="true"></i>
                <p class="mb-0">${escapeHtml(message)}</p>
            </div>
        `);
    }

    function renderTablesGrid(tables) {
        const $grid = $('#tablesGrid');
        if (!$grid.length) {
            return;
        }

        if (!Array.isArray(tables) || tables.length === 0) {
            latestTablesState = [];
            renderTablesStateMessage('fa-chair', 'لا توجد طاولات مُعرَّفة. أضف الطاولات من إعدادات المطعم ثم أعد المحاولة.');
            return;
        }

        latestTablesState = tables;
        $grid.html(tables.map(renderTableButton).join(''));
    }

    function handleTablesApiResponse(response, jqXHR) {
        if (response && response.success === true) {
            renderTablesGrid(response.tables || []);
            return;
        }

        if (Array.isArray(latestTablesState) && latestTablesState.length > 0) {
            renderTablesGrid(latestTablesState);
            return;
        }

        const code = String((response && (response.code || response.message)) || '').trim();
        if (code === 'POS_AUTH_REQUIRED' || code === 'AUTH_REQUIRED') {
            renderTablesStateMessage(
                'fa-lock',
                'انتهت جلسة نقطة البيع. سيتم إعادة تسجيل الدخول خلال لحظات.'
            );
            if (typeof window.posmainMarkShiftClosing === 'function') {
                window.posmainMarkShiftClosing();
            }
            window.setTimeout(function() {
                window.location.replace('pos_barcode.php?logout=1');
            }, 2200);
            return;
        }

        if (code === 'PERMISSION_DENIED') {
            renderTablesStateMessage(
                'fa-user-lock',
                'لا تملك صلاحية فتح الطاولات. اطلب من المدير تفعيل صلاحية «فتح طاولة» لحسابك.'
            );
            return;
        }

        if (code === 'DATABASE_ERROR' || code === 'DATABASE_CONNECTION_FAILED') {
            renderTablesStateMessage(
                'fa-database',
                'تعذر الاتصال بقاعدة البيانات أثناء تحميل الطاولات. أعد المحاولة أو تواصل مع الدعم.'
            );
            return;
        }

        if (jqXHR && jqXHR.status === 0) {
            renderTablesStateMessage(
                'fa-wifi',
                'تعذر الاتصال بالخادم المحلي. تحقق من الاتصال ثم أعد المحاولة.'
            );
            return;
        }

        renderTablesStateMessage(
            'fa-exclamation-triangle',
            'تعذر تحميل الطاولات. حاول مرة أخرى.'
        );
    }

    window.refreshTablesState = function() {
        if (tablesRefreshInFlight || !$('#tablesGrid').length) {
            return;
        }

        if (!canUseTableOrders() && !(window.POSMAIN_TABLE_OPEN_OVERRIDE && window.POSMAIN_TABLE_OPEN_OVERRIDE.approval_id)) {
            renderTablesStateMessage(
                'fa-user-lock',
                'لا تملك صلاحية فتح الطاولات. اضغط على تبويب الطاولة وأدخل رمز مدير للمتابعة.'
            );
            return;
        }

        tablesRefreshInFlight = true;
        $.ajax({
            url: 'ajax/get_tables.php',
            method: 'GET',
            data: posmainTableOpenOverrideParams(),
            dataType: 'json',
            cache: false,
            success: function(response, _textStatus, jqXHR) {
                handleTablesApiResponse(response, jqXHR);
            },
            error: function(jqXHR) {
                let response = jqXHR.responseJSON;
                if (!response && jqXHR.responseText) {
                    try {
                        response = JSON.parse(jqXHR.responseText);
                    } catch (parseError) {
                        response = null;
                    }
                }
                handleTablesApiResponse(response, jqXHR);
            },
            complete: function() {
                tablesRefreshInFlight = false;
            }
        });
    };

    function startTablesAutoRefresh() {
        if (tablesPreloadStarted || !$('#tablesGrid').length) {
            return;
        }

        tablesPreloadStarted = true;
        setTimeout(function() {
            if (typeof window.refreshTablesState === 'function') {
                window.refreshTablesState();
            }
        }, 800);
        tablesRefreshTimer = setInterval(function() {
            if (typeof window.refreshTablesState === 'function') {
                window.refreshTablesState();
            }
        }, 5000);
    }

    $('#tablesModal').on('shown.bs.modal', function() {
        window.refreshTablesState();
    });

    $('#tablesModal').on('hidden.bs.modal', function() {
        setTableTransferMode(false);
    });

    startTablesAutoRefresh();

    function clearPosOrderContextForModeSwitch() {
        resetPosOrderScreenCore({});
    }

    function preparePosOrderContextForModeSwitch(options) {
        options = options || {};
        const keepCart = options.keepCart === true;
        const itemCountBefore = getPosCartItemCount();

        if (!keepCart || itemCountBefore <= 0) {
            resetPosOrderScreenCore({});
            return { kept: false, itemCount: 0 };
        }

        // Keep cart lines + discounts; detach order identity so the draft becomes a new order
        // in the destination mode (saved source order stays untouched).
        if (window.POSOrderApi && typeof window.POSOrderApi.clearCashierEditState === 'function') {
            window.POSOrderApi.clearCashierEditState();
        } else {
            $('#edit_order_id').val('');
            $('#selected_order_id').val('');
        }

        if (window.history && typeof window.history.replaceState === 'function') {
            const params = new URLSearchParams(window.location.search);
            params.delete('edit');
            params.delete('table');
            const qs = params.toString();
            window.history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : ''));
        }

        touchOrderDraft();
        updateTransferTableButton();
        updatePayOrderButtonState();
        return { kept: true, itemCount: itemCountBefore };
    }

    function resetPosOrderScreenCore(options) {
        options = options || {};
        $('#itemData').empty();
        $('#discount').val('0');
        $('#modal_discperc').val('0');
        $('#modal_discount').val('0');
        $('#modal_paid').val('0.00');
        $('#modal_change').val('0.00');
        updateItemCount();
        updateTotal();

        if (window.POSOrderApi && typeof window.POSOrderApi.clearCashierEditState === 'function') {
            window.POSOrderApi.clearCashierEditState();
        } else {
            $('#edit_order_id').val('');
            $('#selected_order_id').val('');
        }

        const age = String($('input[name="age"]:checked').val() || '1');
        const orderId = parseInt(options.orderId || 0, 10) || 0;
        if (age === '2' && orderId > 0) {
            $('#selected_order_id').val(String(orderId));
        }

        if (window.history && typeof window.history.replaceState === 'function') {
            const params = new URLSearchParams(window.location.search);
            if (age !== '2') {
                params.delete('edit');
                params.delete('table');
            }
            const qs = params.toString();
            window.history.replaceState({}, '', window.location.pathname + (qs ? '?' + qs : ''));
        }

        if (typeof window.posCustomerDetach === 'function') {
            window.posCustomerDetach();
        }

        if (window.POSOrderDraft && typeof window.POSOrderDraft.reset === 'function') {
            window.POSOrderDraft.reset();
        }

        updateTransferTableButton();
        updatePayOrderButtonState();
    }

    window.POSMainResetOrderScreen = function(options) {
        resetPosOrderScreenCore(options || {});
    };

    let lastAgeMode = String($('input[name="age"]:checked').val() || '1');

    // تبديل نوع الطلب: انقل الأصناف تلقائياً بدل مسحها بصمت
    $('input[name="age"]').on('change', function() {
        const val = String($(this).val() || '');
        const prevVal = lastAgeMode;
        if (prevVal !== val) {
            let keepCart = getPosCartItemCount() > 0;
            if (typeof window.__posModeSwitchKeepCart === 'boolean') {
                keepCart = window.__posModeSwitchKeepCart && getPosCartItemCount() > 0;
                delete window.__posModeSwitchKeepCart;
            }

            const silent = window.__posModeSwitchSilent === true;
            if (silent) {
                delete window.__posModeSwitchSilent;
            }

            const transfer = preparePosOrderContextForModeSwitch({ keepCart: keepCart });
            if (transfer.kept && !silent) {
                showModeTransferToast(transfer.itemCount, val);
            } else if (transfer.kept && silent) {
                flashPosCartTransfer();
            }
            lastAgeMode = val;
        }
        syncModeTabs();
        if (val === '2') {
            const tableOrderId = parseInt($('#selected_order_id').val() || '0', 10) || 0;
            if (tableOrderId <= 0 && window.POSOrderApi && typeof window.POSOrderApi.clearCashierEditState === 'function') {
                window.POSOrderApi.clearCashierEditState();
            }
        } else if (val === '1' || val === '3') {
            // تيك أواي أو دليفري - امسح الطاولة المختارة
            $('#selected_table_id').val('');
            $('#selected_table_name').val('');
            $('#selected_table_case').val('0');
            $('#selected_order_id').val('');
            $('#edit_order_id').val('');
            $('#selected_table_display').html('اختر طاولة');
            updateTransferTableButton();
            updatePayOrderButtonState();
        }
    });

    function bindEmptyTableToCurrentCart(tableName) {
        $('#selected_order_id').val('');
        $('#edit_order_id').val('');
        if (window.POSOrderApi && typeof window.POSOrderApi.clearCashierEditState === 'function') {
            window.POSOrderApi.clearCashierEditState();
        }

        const hasItems = getPosCartItemCount() > 0;
        if (!hasItems) {
            $('#itemData').empty();
            updateItemCount();
            updateTotal();
            if (window.POSOrderDraft && typeof window.POSOrderDraft.reset === 'function') {
                window.POSOrderDraft.reset();
            }
        } else {
            touchOrderDraft();
            flashPosCartTransfer();
        }

        updateTransferTableButton();
        updatePayOrderButtonState();
        console.log(hasItems
            ? ('طاولة فاضية مع أصناف منقولة: ' + tableName)
            : ('طاولة فاضية: ' + tableName + ' - طلب جديد'));
    }

    function openOccupiedTableOrder(orderId, tableName) {
        $('#selected_order_id').val(orderId);
        updateTransferTableButton();
        loadExistingOrder(orderId, tableName);
    }

    $(document).on('click', '.table-select-btn', function() {
        if (tableTransferMode) {
            handleTableTransferDestination($(this));
            return;
        }

        const tableId = $(this).data('table-id');
        const tableName = $(this).data('table-name');
        const tableCase = $(this).data('table-case');
        const orderId = $(this).data('order-id');
        const pendingCount = getPosCartItemCount();

        const applyTableSelection = function() {
            $('#selected_table_id').val(tableId);
            $('#selected_table_name').val(tableName);
            $('#selected_table_case').val(tableCase ? '1' : '0');
            $('#selected_table_display').html('<i class="fas fa-chair me-1"></i>' + tableName);
            $('#age2').prop('checked', true);
            lastAgeMode = '2';
            syncModeTabs();
            $('#tablesModal').modal('hide');
        };

        if (tableCase != 0 && orderId) {
            if (pendingCount > 0) {
                confirmPOSAction(
                    'طاولة عليها طلب',
                    'هذه الطاولة عليها طلب محفوظ. فتحها يستبدل الأصناف الحالية (' + pendingCount + ').',
                    'فتح طلب الطاولة'
                ).then(function(confirmed) {
                    if (!confirmed) {
                        return;
                    }
                    applyTableSelection();
                    openOccupiedTableOrder(orderId, tableName);
                });
                return;
            }
            applyTableSelection();
            openOccupiedTableOrder(orderId, tableName);
            return;
        }

        applyTableSelection();
        bindEmptyTableToCurrentCart(tableName);
    });

    window.selectNoTable = function() {
        $('#selected_table_id').val('');
        $('#selected_table_name').val('');
        $('#selected_table_case').val('0');
        $('#selected_order_id').val('');
        $('#edit_order_id').val('');
        $('#selected_table_display').html('بدون طاولة');
        $('#age1').prop('checked', true);
        syncModeTabs();
        if (typeof window.posCustomerDetach === 'function') {
            window.posCustomerDetach();
        }
        $('#tablesModal').modal('hide');
        clearAllItems();
        updateTransferTableButton();
        updatePayOrderButtonState();
    };

    function handleTableTransferDestination($button) {
        if (tableTransferInFlight || $button.prop('disabled')) {
            return;
        }

        const sourceTableId = getSelectedTableId();
        const sourceOrderId = getActiveTableOrderId();
        const destinationTableId = parseInt($button.data('table-id') || 0, 10) || 0;
        const destinationName = String($button.data('table-name') || '');
        const destinationOrderId = $button.data('destination-order-id') || $button.data('order-id') || '';
        const transferAction = String($button.data('transfer-action') || '');

        if (!sourceTableId || !sourceOrderId) {
            showPOSNotice('لا يوجد طلب نشط لنقله', 'danger');
            return;
        }

        if (!destinationTableId || destinationTableId === sourceTableId || !transferAction) {
            showPOSNotice('اختر طاولة أخرى', 'danger');
            return;
        }

        const isMerge = transferAction === 'merge';
        const title = isMerge ? 'دمج الطلبين؟' : 'نقل الطلب؟';
        const text = isMerge
            ? 'سيتم نقل أصناف الطلب المحفوظ إلى ' + destinationName + ' وإفراغ الطاولة الحالية. احفظ أي تعديل قبل الدمج.'
            : 'سيتم نقل الطلب المحفوظ بالكامل إلى ' + destinationName + '. احفظ أي تعديل قبل النقل.';
        const confirmText = isMerge ? 'دمج الطلبين' : 'نقل الطلب';

        confirmPOSAction(title, text, confirmText).then(function(confirmed) {
            if (!confirmed) {
                return;
            }

            performTableTransfer(transferAction, {
                sourceTableId: sourceTableId,
                sourceOrderId: sourceOrderId,
                sourceMutationVersion: parseInt($('#order_mutation_version').val() || '0', 10) || 0,
                destinationTableId: destinationTableId,
                destinationTableName: destinationName,
                destinationOrderId: destinationOrderId,
                destinationMutationVersion: parseInt($button.data('destination-mutation-version') || $button.data('mutation-version') || '0', 10) || 0
            });
        });
    }

    function performTableTransfer(transferAction, transferData) {
        tableTransferInFlight = true;
        const isMerge = transferAction === 'merge';
        const requestScope = isMerge ? 'pos.table.merge' : 'pos.table.move';
        const ajaxData = {
            source_table_id: transferData.sourceTableId,
            destination_table_id: transferData.destinationTableId,
            idempotency_key: createPOSIdempotencyKey(requestScope)
        };

        if (isMerge) {
            ajaxData.source_order_id = transferData.sourceOrderId;
            ajaxData.destination_order_id = transferData.destinationOrderId;
            ajaxData.source_mutation_version = transferData.sourceMutationVersion
                || parseInt($('#order_mutation_version').val() || '0', 10)
                || '';
            ajaxData.destination_mutation_version = transferData.destinationMutationVersion || '';
        } else {
            ajaxData.order_id = transferData.sourceOrderId;
            ajaxData.mutation_version = transferData.sourceMutationVersion
                || parseInt($('#order_mutation_version').val() || '0', 10)
                || '';
        }

        $('.pos-transfer-target').prop('disabled', true).addClass('disabled');

        $.ajax({
            url: isMerge ? 'ajax/merge_table_orders.php' : 'ajax/move_table_order.php',
            method: 'POST',
            dataType: 'json',
            data: ajaxData,
            beforeSend: window.POSMAIN_ATTACH_CSRF_HEADER,
            success: function(response) {
                if (!response || !response.success) {
                    showPOSNotice((response && response.message) || 'فشل نقل الطاولة', 'danger');
                    return;
                }

                const nextOrderId = isMerge
                    ? (response.destination_order_id || transferData.destinationOrderId)
                    : (response.order_id || transferData.sourceOrderId);

                    $('#selected_table_id').val(transferData.destinationTableId);
                    $('#selected_table_name').val(transferData.destinationTableName);
                    $('#selected_table_case').val('1');
                    $('#selected_order_id').val(nextOrderId);
                    $('#edit_order_id').val(nextOrderId);
                $('#selected_table_display').html('<i class="fas fa-chair me-1"></i>' + escapeHtml(transferData.destinationTableName));
                $('#age2').prop('checked', true);
                syncModeTabs();
                updateTransferTableButton();
                $('#tablesModal').modal('hide');
                loadExistingOrder(nextOrderId, transferData.destinationTableName, { silent: true });
                window.refreshTablesState();
                showPOSNotice(isMerge ? 'تم دمج الطلب في الطاولة الجديدة' : 'تم نقل الطلب إلى الطاولة الجديدة', 'success');
            },
            error: function(xhr, status, error) {
                console.error('Table transfer error:', xhr.responseText || error);
                showPOSNotice('حدث خطأ أثناء نقل الطاولة', 'danger');
            },
            complete: function() {
                tableTransferInFlight = false;
                $('.pos-transfer-target').prop('disabled', false).removeClass('disabled');
            }
        });
    }

    function loadExistingOrder(orderId, tableName, options) {
        options = options || {};
        console.log('🔄 Loading existing order:', orderId, 'Table:', tableName);

        $.ajax({
            url: 'ajax/load_order.php',
            method: 'POST',
            data: { order_id: orderId, table_id: $('#selected_table_id').val() },
            dataType: 'json',
            success: function(response) {
                console.log('📥 Load Order Response:', response);

                if (response.success) {
                    $('#itemData').empty();

                    if (response.items && response.items.length > 0) {
                        console.log('📦 Found items:', response.items.length);
                        response.items.forEach(function(item) {
                            console.log('➕ Adding item:', item);
                            const persistedSugarSpoons = sugarSpoonsFromPreparation(item.preparation_values);
                            addItemToOrder(
                                item.item_id,
                                item.item_name || 'Unknown Item',
                                item.price || '0.000000',
                                item.barcode || item.item_desc || item.item_id, // Use explicit barcode first
                                item.qty || '1.000000',
                                '',
                                item.note || item.kitchen_note || item.notes || '',
                                {
                                    uVal: item.u_val || 1,
                                    persisted: true,
                                    persistedQty: item.qty || '1.000000',
                                    sugarSpoons: persistedSugarSpoons === null && item.allows_sugar_spoons
                                        ? 0
                                        : persistedSugarSpoons,
                                }
                            );
                        });
                    } else {
                        console.warn('⚠️ No items found in order');
                    }

                    if (response.order) {
                        $('#discount').val(response.order.discount || 0);
                        if (response.order.emp_id) $('input[name="emp_id"]').val(response.order.emp_id);
                        // Set hidden edit_order_id
                        $('#edit_order_id').val(response.order.id);
                        $('#selected_order_id').val(response.order.id);
                        $('#order_mutation_version').val(String(parseInt(response.order.mutation_version || 1, 10) || 1));
                    }

                    updateItemCount();
                    updateTotal();
                    updateTransferTableButton();

                    if (window.POSOrderDraft && typeof window.POSOrderDraft.bootstrapSaved === 'function') {
                        window.POSOrderDraft.bootstrapSaved({
                            order_id: response.order ? response.order.id : orderId,
                            kitchen_revision: response.order && response.order.kitchen_revision
                                ? response.order.kitchen_revision
                                : 0,
                            mutation_version: response.order && response.order.mutation_version
                                ? response.order.mutation_version
                                : 0
                        });
                    }

                    // Show success message briefly
                    if (!options.silent) {
                        const alertDiv = $('<div class="alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999;">تم تحميل الطلب بنجاح</div>');
                        $('body').append(alertDiv);
                        setTimeout(() => alertDiv.fadeOut(() => alertDiv.remove()), 2000);
                    }
                    posmainRefreshPersistedLineLocks();

                } else {
                    console.error('❌ Load failed:', response.error);
                    alert('خطأ في تحميل طلب الطاولة: ' + (response.error || 'غير معروف'));
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX Error:', error);
                console.error('Response:', xhr.responseText);
                alert('خطأ في الاتصال بالخادم');
            }
        });
    }

    updateTransferTableButton();

    // ========================================
    // Modal Calculations
    // ========================================
    $('#modal_discperc').on('input', function() {
        const money = window.POSOrderApi;
        if (!money) {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        const total = money.decimalString($('#total').val(), 2, '0');
        let percentage;
        try {
            percentage = money.decimalString($(this).val(), 6, '0');
        } catch (error) {
            return;
        }
        if (money.compareDecimalStrings(percentage, '100.000000', 6) > 0) {
            percentage = '100.000000';
            $(this).val(percentage);
        }
        const discount = money.moneyFromPercentage(total, percentage);
        $('#modal_discount').val(discount);
        $('#discount').val(discount);
        const net = money.subtractDecimalStrings(total, discount, 2);
        $('#modal_net').text(net + ' ج.م');
        $('#net_val').val(net);
        $('#net_display').text(net + ' ج.م');

        setDefaultCashPaymentToNet(net);
        touchOrderDraft();
    });

    $('#modal_discount').on('input', function() {
        const money = window.POSOrderApi;
        if (!money) {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        const total = money.decimalString($('#total').val(), 2, '0');
        let discount;
        try {
            discount = money.decimalString($(this).val(), 2, '0');
        } catch (error) {
            return;
        }
        if (money.compareDecimalStrings(discount, total, 2) > 0) {
            discount = total;
            $(this).val(discount);
        }
        $('#discount').val(discount);
        const percentage = money.percentageFromMoney(discount, total);
        $('#modal_discperc').val(percentage);
        const net = money.subtractDecimalStrings(total, discount, 2);
        $('#modal_net').text(net + ' ج.م');
        $('#net_val').val(net);
        $('#net_display').text(net + ' ج.م');

        setDefaultCashPaymentToNet(net);
        touchOrderDraft();
    });

    // حساب الباقي عند تغيير المدفوع كاش أو صرافة
    $('#modal_paid_cash, #modal_paid_bank').on('input', function() {
        calculateChange();
    });

    $(document).on('change', 'input[name="pos_payment_method"]', function() {
        updatePosPaymentAmountLayout();
        applyPosPaymentMethodAmounts();
    });

    $('#paymentModal').on('shown.bs.modal', function() {
        if (window.POSOrderApi && typeof window.POSOrderApi.restorePayConfirmButton === 'function') {
            window.POSOrderApi.restorePayConfirmButton();
        }
        resetPosPaymentMethodToCash();
        syncPaymentFundOptions();
        loadBankOptions();
        const isTableOrder = getSelectedTableId() > 0 && $('#age2').prop('checked');
        $('.pos-empty-table-option').toggle(isTableOrder);
        $('#pos_empty_table_after_payment').prop('checked', true);
        renderSplitPaymentRows();
        applyPosPaymentMethodAmounts();
        calculateChange();
    });

    $(document).on('change', '#pos_split_payment_enabled', function() {
        const enabled = $(this).prop('checked');
        $('.pos-payment-split-section').toggleClass('is-active', enabled);
        $('#pos_split_payment_panel').toggle(enabled);
        updateSplitPaymentButtons();
        if (enabled) {
            renderSplitPaymentRows();
            resetPosPaymentMethodToCash();
            applyPosPaymentMethodAmounts();
            updateSplitPaymentTotal();
        } else {
            $('#pos_split_payment_total').text('0.00 ج.م');
            updateTotal();
        }
    });

    $(document).on('change input', '.pos-split-line-check, .pos-split-line-qty', updateSplitPaymentTotal);

    $(document).on('click', '.pos-pay-order-btn', function(event) {
        if (!isHeldTableWithoutActiveOrder()) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        submitPOS('free_table');
    });

    function paymentAmountDue() {
        const money = window.POSOrderApi;
        if (!money) {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        if ($('#pos_split_payment_enabled').prop('checked')) {
            return splitPaymentPayloadFromModal().total;
        }

        return money.decimalString($('#net_val').val(), 2, '0');
    }

    function updateModalChangeDisplay(change) {
        const $change = $('#modal_change');
        $change.text(change + ' ج.م');
        $change.toggleClass('is-short', String(change).charAt(0) === '-');
    }

    function calculateChange() {
        const money = window.POSOrderApi;
        if (!money) {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        const amountDue = paymentAmountDue();
        const paidCash = money.decimalString($('#modal_paid_cash').val(), 2, '0');
        const paidBank = money.decimalString($('#modal_paid_bank').val(), 2, '0');
        const totalPaid = money.addDecimalStrings(paidCash, paidBank, 2);
        const change = money.subtractDecimalStrings(totalPaid, amountDue, 2);

        // الباقي = المدفوع - المستحق (كامل الصافي أو إجمالي المحدد في سداد الأصناف)
        updateModalChangeDisplay(change);
    }

    // ========================================
    // Delete & Update Row
    // ========================================
    $(document).on('click', '.delRow', function(e) {
        const $card = $(this).closest('.item-card-order');
        const itemName = String($card.find('.pos-cart-name').text() || '').trim() || 'الصنف';
        const removeLine = function () {
            $card.remove();
            updateItemCount();
            updateTotal();
            touchOrderDraft();
        };

        if (!posmainPersistedLineNeedsVoidApproval($card)) {
            removeLine();
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        posmainRequestItemVoidOverride('إزالة صنف محفوظ: ' + itemName, posmainCurrentOrderId())
            .done(removeLine);
    });

    $(document).on('click', '.qty-increase', function() {
        const money = window.POSOrderApi;
        const card = $(this).closest('.item-card-order');
        const qtyInput = card.find('.quantityInput');
        const currentQty = money.decimalString(qtyInput.val(), 6, '0');
        qtyInput.val(money.addDecimalStrings(currentQty, '1.000000', 6)).trigger('input');
        touchOrderDraft();
    });

    $(document).on('click', '.qty-decrease', function(e) {
        const money = window.POSOrderApi;
        const card = $(this).closest('.item-card-order');
        const qtyInput = card.find('.quantityInput');
        const currentQty = money.decimalString(qtyInput.val(), 6, '0');

        if (money.compareDecimalStrings(currentQty, '1.000000', 6) <= 0) {
            card.find('.delRow').trigger('click');
            return;
        }
        const nextQty = money.subtractDecimalStrings(currentQty, '1.000000', 6);

        const applyQty = function () {
            qtyInput.val(nextQty).trigger('input');
            touchOrderDraft();
        };

        if (!posmainPersistedLineNeedsVoidApproval(card, nextQty)) {
            applyQty();
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        const itemName = String(card.find('.pos-cart-name').text() || '').trim() || 'الصنف';
        posmainRequestItemVoidOverride('تقليل كمية صنف محفوظ: ' + itemName, posmainCurrentOrderId())
            .done(applyQty);
    });

    $(document).on('input', '.quantityInput, .priceInput', function() {
        const money = window.POSOrderApi;
        const card = $(this).closest('.item-card-order');
        const qty = money.decimalString(card.find('.quantityInput').val(), 6, '0');
        const price = money.decimalString(card.find('.priceInput').val(), 6, '0');
        const subtotal = money.lineTotalFromQuantityAndUnitPrice(qty, price);
        card.find('.subtotal').val(subtotal);
        card.find('.pos-cart-price-display').html(subtotal + ' <span class="pos-currency">ج.م</span>');
        updateTotal();
    });

    $(document).on('click', '.itemVariantChoice', function() {
        if (!activeVariantContext) {
            return;
        }

        const $choice = $(this);
        const variantContext = activeVariantContext;
        toggleVariantModal(false);
        window.setTimeout(function() {
            completeAddItemToOrder(
            parseInt($choice.data('item-id'), 10) || 0,
            String($choice.data('item-name') || ''),
            String($choice.attr('data-item-price') || '0'),
            String($choice.data('item-barcode') || ''),
            variantContext.qty || 1,
            variantContext.imageHtml || '',
            variantContext.lineNote || '',
            {
                sugarAllowed: !!variantContext.sugarAllowed,
                managerApprovalId: variantContext.managerApprovalId || null
            }
            );
        }, 150);
    });

    $(document).on('hidden.bs.modal', '#itemVariantModal', function() {
        activeVariantContext = null;
    });

    function refreshSugarSpoonsCounter(value) {
        const normalized = normalizeSugarSpoons(value);
        $('#sugarSpoonsValue').val(normalized);
        $('#sugarSpoonsState').text(normalized === 0 ? 'بدون سكر' : normalized + ' ملعقة سكر');
        $('#sugarSpoonsError').text('');
        return normalized;
    }

    $(document).on('click', '#sugarSpoonsDecrease', function() {
        refreshSugarSpoonsCounter(normalizeSugarSpoons($('#sugarSpoonsValue').val()) - 1);
    });

    $(document).on('click', '#sugarSpoonsIncrease', function() {
        refreshSugarSpoonsCounter(normalizeSugarSpoons($('#sugarSpoonsValue').val()) + 1);
    });

    $(document).on('input', '#sugarSpoonsValue', function() {
        const raw = String($(this).val() || '');
        if (raw === '') {
            $('#sugarSpoonsState').text('');
            $('#sugarSpoonsError').text('');
            return;
        }
        if (!/^\d+$/.test(raw) || parseInt(raw, 10) > SUGAR_SPOONS_SAFETY_LIMIT) {
            $('#sugarSpoonsError').text('أدخل عدداً صحيحاً من الملاعق.');
            return;
        }
        $('#sugarSpoonsState').text(parseInt(raw, 10) === 0 ? 'بدون سكر' : parseInt(raw, 10) + ' ملعقة سكر');
        $('#sugarSpoonsError').text('');
    });

    $(document).on('keydown', '#sugarSpoonsValue', function(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            $('#sugarSpoonsConfirm').trigger('click');
        }
    });

    $(document).on('click', '#sugarSpoonsConfirm', function() {
        if (!activeSugarContext) {
            return;
        }
        const raw = String($('#sugarSpoonsValue').val() || '');
        if (!/^\d+$/.test(raw) || parseInt(raw, 10) > SUGAR_SPOONS_SAFETY_LIMIT) {
            $('#sugarSpoonsError').text('أدخل عدداً صحيحاً من الملاعق.');
            $('#sugarSpoonsValue').focus();
            return;
        }
        const context = activeSugarContext;
        const options = Object.assign({}, context.options || {}, {
            sugarSpoons: normalizeSugarSpoons(raw)
        });
        activeSugarContext = null;
        toggleSugarSpoonsModal(false);
        addItemToOrder(
            context.id,
            context.name,
            context.price,
            context.barcode,
            context.qty,
            context.imageHtml,
            context.lineNote,
            options
        );
    });

    $(document).on('hidden.bs.modal', '#sugarSpoonsModal', function() {
        activeSugarContext = null;
    });

    $(document).on('click', '.lineNoteButton', function() {
        ensureLineNoteModal();
        activeLineNoteRow = $(this).closest('.item-card-order');
        $('#lineNoteModalText').val(activeLineNoteRow.find('.lineNoteInput').val() || '');
        toggleLineNoteModal(true);
    });

    $(document).on('click', '#lineNoteModalSave', function() {
        if (!activeLineNoteRow || activeLineNoteRow.length === 0) {
            toggleLineNoteModal(false);
            return;
        }

        const note = $('#lineNoteModalText').val() || '';
        activeLineNoteRow.find('.lineNoteInput').val(note);
        saveLineNoteDraft(activeLineNoteRow, note);
        updateLineNoteButton(activeLineNoteRow);
        touchOrderDraft();
        toggleLineNoteModal(false);
    });

    $(document).on('click', '#lineNoteModalClear', function() {
        $('#lineNoteModalText').val('');
        if (activeLineNoteRow && activeLineNoteRow.length > 0) {
            activeLineNoteRow.find('.lineNoteInput').val('');
            saveLineNoteDraft(activeLineNoteRow, '');
            updateLineNoteButton(activeLineNoteRow);
            touchOrderDraft();
        }
    });

    $(document).on('hidden.bs.modal', '#lineNoteModal', function() {
        activeLineNoteRow = null;
    });

    initializeLineNoteButtons();

    // ========================================
    // Form Submission
    // ========================================
    window.submitPOS = function(action) {
        console.log('✅ submitPOS called with action:', action);

        const form = document.getElementById('posForm');
        if (!form) {
            console.error('❌ Form with id "posForm" not found!');
            alert('حدث خطأ في النظام. يرجى إعادة تحميل الصفحة.');
            return false;
        }

        const isFreeTableOnly = action === 'free_table';
        if (isFreeTableOnly && !isHeldTableWithoutActiveOrder()) {
            alert('إفراغ الطاولة متاح فقط لطاولة مشغولة بدون طلب مفتوح');
            return false;
        }

        console.log('🔍 Validating form...');
        if (!isFreeTableOnly && !validatePOSForm()) {
            console.log('❌ Validation failed, form not submitted');
            return false;
        }
        console.log('✅ Validation passed');

        if (action === 'cash' && $('#pos_split_payment_enabled').prop('checked')) {
            action = 'split_cash';
        }

        // جمع بيانات الدفع
        const isSaveOnly = action === 'save';
        const isPrintReceiptOnly = action === 'print_receipt';
        if ((isSaveOnly || isPrintReceiptOnly) && window.POSOrderDraft && !window.POSOrderDraft.canSave(action)) {
            return false;
        }

        const isSplitLinePayment = action === 'split_cash';
        const money = window.POSOrderApi;
        if (!money) {
            alert('تعذر تحميل وحدة الحساب المالي. يرجى تحديث الصفحة والمحاولة مرة أخرى.');
            return false;
        }
        let paidCash = money.decimalString($('#modal_paid_cash').val(), 2, '0');
        let paidBank = money.decimalString($('#modal_paid_bank').val(), 2, '0');
        if (isSaveOnly || isPrintReceiptOnly || isFreeTableOnly) {
            paidCash = '0.00';
            paidBank = '0.00';
        }
        syncPaymentFundOptions();
        window.POSMainEnsurePaymentAccountDefaults();
        let fundId = $('#payment_fund_id').val();
        let bankId = $('#payment_bank_id').val();
        const net = money.decimalString($('#net_val').val(), 2, '0');

        console.log('=== PAYMENT DATA DEBUG ===');
        console.log('modal_paid_cash value:', $('#modal_paid_cash').val());
        console.log('modal_paid_bank value:', $('#modal_paid_bank').val());
        console.log('payment_fund_id value:', $('#payment_fund_id').val());
        console.log('payment_bank_id value:', $('#payment_bank_id').val());
        console.log('Processed:', {
            paidCash: paidCash,
            paidBank: paidBank,
            fundId: fundId,
            bankId: bankId,
            net: net
        });
        console.log('==========================');

        // التحقق من صحة البيانات
        if (
            !isSaveOnly
            && !isPrintReceiptOnly
            && !isFreeTableOnly
            && !isSplitLinePayment
            && money.compareDecimalStrings(net, '0.00', 2) > 0
            && money.compareDecimalStrings(money.addDecimalStrings(paidCash, paidBank, 2), '0.00', 2) <= 0
        ) {
            alert('يجب إدخال مبلغ الدفع قبل تأكيد الدفع');
            return false;
        }

        if (isSplitLinePayment && typeof window.POSMainPrepareSplitPaymentFields === 'function') {
            if (!window.POSMainPrepareSplitPaymentFields(form, {
                paidCash: paidCash,
                paidBank: paidBank,
                fundId: fundId,
                bankId: bankId
            })) {
                return false;
            }
            paidCash = money.decimalString($('#modal_paid_cash').val(), 2, '0');
            paidBank = money.decimalString($('#modal_paid_bank').val(), 2, '0');
        }

        // إضافة حقول الدفع المخفية
        let paidCashInput = form.querySelector('input[name="paid_cash"]');
        if (!paidCashInput) {
            paidCashInput = document.createElement('input');
            paidCashInput.type = 'hidden';
            paidCashInput.name = 'paid_cash';
            form.appendChild(paidCashInput);
            console.log('✅ Created paid_cash input');
        }
        paidCashInput.value = paidCash;
        console.log('Set paid_cash =', paidCash);

        let paidBankInput = form.querySelector('input[name="paid_bank"]');
        if (!paidBankInput) {
            paidBankInput = document.createElement('input');
            paidBankInput.type = 'hidden';
            paidBankInput.name = 'paid_bank';
            form.appendChild(paidBankInput);
            console.log('✅ Created paid_bank input');
        }
        paidBankInput.value = paidBank;
        console.log('Set paid_bank =', paidBank);

        let paymentFundInput = form.querySelector('input[name="payment_fund_id"]');
        if (!paymentFundInput) {
            paymentFundInput = document.createElement('input');
            paymentFundInput.type = 'hidden';
            paymentFundInput.name = 'payment_fund_id';
            form.appendChild(paymentFundInput);
            console.log('✅ Created payment_fund_id input');
        }
        paymentFundInput.value = fundId;
        console.log('Set payment_fund_id =', fundId);

        let paymentBankInput = form.querySelector('input[name="payment_bank_id"]');
        if (!paymentBankInput) {
            paymentBankInput = document.createElement('input');
            paymentBankInput.type = 'hidden';
            paymentBankInput.name = 'payment_bank_id';
            form.appendChild(paymentBankInput);
            console.log('✅ Created payment_bank_id input');
        }
        paymentBankInput.value = bankId || '';
        console.log('Set payment_bank_id =', bankId || '');

        // إضافة المدفوع الإجمالي (للتوافق مع الكود القديم)
        const totalPaid = money.addDecimalStrings(paidCash, paidBank, 2);
        let paidInput = form.querySelector('input[name="paid"]');
        if (!paidInput) {
            paidInput = document.createElement('input');
            paidInput.type = 'hidden';
            paidInput.name = 'paid';
            form.appendChild(paidInput);
        }
        paidInput.value = totalPaid;
        ensureHiddenFormInput(form, 'empty_table_after_payment').value = $('#pos_empty_table_after_payment').prop('checked') ? '1' : '0';

        const api = window.POSOrderApi;
        const editId = api && typeof api.readEditId === 'function'
            ? api.readEditId(form)
            : (parseInt($('#edit_order_id').val() || $('#selected_order_id').val() || '0', 10) || 0);
        if (editId > 0) {
            console.log('✏️ Edit Mode: ID', editId);
            let editIdInput = form.querySelector('input[name="edit_id"]');
            if (!editIdInput) {
                editIdInput = document.createElement('input');
                editIdInput.type = 'hidden';
                editIdInput.name = 'edit_id';
                form.appendChild(editIdInput);
            }
            editIdInput.value = editId;
        } else {
            if (api && typeof api.clearCashierEditState === 'function') {
                api.clearCashierEditState();
            }
        }

        const existingSubmits = form.querySelectorAll('input[name="submit"]');
        existingSubmits.forEach(input => input.remove());

        const submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = 'submit';
        submitInput.value = action;
        form.appendChild(submitInput);

        if (typeof window.posCustomerSyncHiddenFields === 'function') {
            window.posCustomerSyncHiddenFields();
        }

        console.log('➕ Added submit input with value:', action);

        let saveBtn = $(".pos-save-order-btn");
        let printOrderBtn = $(".pos-print-order-btn");
        let printBtn = $(".pos-pay-confirm-btn");

        if (saveBtn.length > 0 && !isSaveOnly && !isPrintReceiptOnly && !isFreeTableOnly) {
            saveBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...');
        }
        if (printOrderBtn.length > 0 && isPrintReceiptOnly) {
            printOrderBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الطباعة...');
        }
        if (printBtn.length > 0 && (action === 'cash' || action === 'split_cash')) {
            printBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الدفع...');
        }

        if (action === 'cash' || action === 'split_cash') {
            // Keep the modal visible while payment is processing.
        } else {
            $('#paymentModal').modal('hide');
        }

        if (api && typeof api.submitFromForm === 'function') {
            posmainCollectPreSubmitEscalations(form, action).then(function () {
                api.submitFromForm(form, action);
            }).fail(function () {
                if (saveBtn.length > 0) {
                    saveBtn.prop('disabled', false).html('<i class="fas fa-save"></i> حفظ');
                }
                if (printOrderBtn.length > 0) {
                    printOrderBtn.prop('disabled', false).html('<i class="fas fa-print"></i> طباعة');
                }
                if (printBtn.length > 0) {
                    printBtn.prop('disabled', false).html('دفع وطباعة');
                }
            });
            return true;
        }

        console.warn('POSOrderApi unavailable');
        if (saveBtn.length > 0) {
            saveBtn.prop('disabled', false).html('<i class="fas fa-save me-1"></i>حفظ الطلب');
        }
        if (printOrderBtn.length > 0) {
            printOrderBtn.prop('disabled', false).html('<i class="fas fa-print me-1"></i>طباعة');
        }
        if (printBtn.length > 0) {
            printBtn.prop('disabled', false).html('<i class="fas fa-receipt me-1"></i>دفع وطباعة');
        }
        alert('تعذر إرسال الطلب عبر واجهة البرنامج الموحدة. يرجى تحديث الصفحة والمحاولة مرة أخرى.');

        return false;
    };

    $('#barcodeInput').focus();

    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl + F or F3 for search focus
        if ((e.ctrlKey && e.key === 'f') || e.key === 'F3') {
            e.preventDefault();
            $filterInput.focus().select();
        }

        // Escape to clear search
        if (e.key === 'Escape') {
            if ($filterInput.is(':focus') && $filterInput.val() !== '') {
                $('#clearFilter').click();
            }
        }

        // Alt + B for barcode input focus
        if (e.altKey && e.key === 'b') {
            e.preventDefault();
            $('#barcodeInput').focus().select();
        }

        // Alt + S for search input focus
        if (e.altKey && e.key === 's') {
            e.preventDefault();
            $filterInput.focus().select();
        }
    });

    window.handleFormSubmit = function(form) {
        console.log('Form submit handler called');
        return true;
    };

    if (window.POSMAIN_IDENTITY && typeof window.POSMAIN.renderIdentityBadge === 'function') {
        window.POSMAIN.renderIdentityBadge(window.POSMAIN_IDENTITY);
    }
    if (typeof window.POSMAIN.refreshIdentityBadge === 'function') {
        window.POSMAIN.refreshIdentityBadge();
    }
}); // End of document.ready

window.POSMainResetCartAfterPayment = function() {
    if (typeof window.POSMainResetOrderScreen === 'function') {
        window.POSMainResetOrderScreen();
        return;
    }
    $('#edit_order_id').val('');
    $('#selected_order_id').val('');
    $('#order_mutation_version').val('0');
    const form = document.getElementById('posForm');
    if (form) {
        const editInput = form.querySelector('input[name="edit_id"]');
        if (editInput) {
            editInput.remove();
        }
    }
    if (typeof window.clearAllItems === 'function') {
        const itemCount = document.querySelectorAll('#itemData .item-card-order, #itemData .pos-cart-row').length;
        if (itemCount > 0) {
            $('#itemData').empty();
            if (typeof window.updateTotal === 'function') {
                window.updateTotal();
            }
        }
    }
    if (window.POSOrderDraft && typeof window.POSOrderDraft.reset === 'function') {
        window.POSOrderDraft.reset();
    }
};

window.POSMainRefreshTableState = function() {
    if (typeof window.loadTables === 'function') {
        window.loadTables();
    } else if (typeof window.POSMainLoadTables === 'function') {
        window.POSMainLoadTables();
    }
};

// ========================================
// Form Validation
// ========================================
function validatePOSForm() {
    console.log('=== validatePOSForm() called ===');

    let items = $('#itemData .item-card-order');
    console.log('📊 Items in order:', items.length);

    if (items.length === 0) {
        console.log('⚠️ No items found in order');
        alert('يجب إضافة صنف واحد على الأقل للطلب');
        return false;
    }

    let itmnames = $('input[name="itmname[]"]');
    let itmqtys = $('input[name="itmqty[]"]');
    let itmprices = $('input[name="itmprice[]"]');

    console.log('📋 Form fields check:');
    console.log('  - Items names:', itmnames.length);
    console.log('  - Items quantities:', itmqtys.length);
    console.log('  - Items prices:', itmprices.length);

    if (itmnames.length === 0) {
        console.error('❌ No item names found!');
        alert('خطأ: لا توجد أصناف في النموذج');
        return false;
    }

    let pro_tybe = $('input[name="pro_tybe"]').val();
    let store_id = $('input[name="store_id"]').val();
    let acc2_id = $('input[name="acc2_id"]').val();
    let emp_id = $('input[name="emp_id"]').val();

    console.log('🔍 Required fields check:');
    console.log('  - pro_tybe:', pro_tybe);
    console.log('  - store_id:', store_id);
    console.log('  - acc2_id:', acc2_id);
    console.log('  - emp_id:', emp_id);

    if (!pro_tybe || pro_tybe == '0') {
        console.error('❌ pro_tybe is missing or zero');
        alert('خطأ: نوع الفاتورة غير محدد');
        return false;
    }

    if (!store_id || store_id == '0') {
        console.error('❌ store_id is missing or zero');
        alert('خطأ: لا يوجد مخزن مُعد لهذا الفرع. من الإعدادات تأكد من وجود مخزن (حساب مخزون) أو تواصل مع الدعم.');
        return false;
    }

    if (!acc2_id || acc2_id == '0') {
        console.error('❌ acc2_id is missing or zero');
        alert('خطأ: يجب اختيار العميل');
        return false;
    }

    if (!emp_id || emp_id == '0') {
        console.error('❌ emp_id is missing or zero');
        alert('خطأ: يجب اختيار الموظف');
        return false;
    }

    const orderMode = $('input[name="age"]:checked').val();
    if (orderMode === '2') {
        const tableId = parseInt($('#selected_table_id').val() || 0, 10) || 0;
        const selectedOrderId = parseInt($('#selected_order_id').val() || 0, 10) || 0;
        if (tableId <= 0 && selectedOrderId <= 0) {
            alert('يجب اختيار طاولة قبل إتمام طلب الطاولة');
            const tablesModal = document.getElementById('tablesModal');
            if (tablesModal) {
                bootstrap.Modal.getOrCreateInstance(tablesModal).show();
            }
            return false;
        }
    }
    if (orderMode === '3') {
        const hasDeliveryCustomer = (typeof window.posDeliveryIsReadyForSubmit === 'function')
            ? window.posDeliveryIsReadyForSubmit()
            : ($('input[name="delivery_customer_phone"]').length > 0);
        if (!hasDeliveryCustomer) {
            alert('يجب تأكيد بيانات عميل الدليفري قبل حفظ الطلب');
            if (typeof window.openDeliveryModal === 'function') {
                window.openDeliveryModal('أكمل بيانات العميل أولاً');
            }
            return false;
        }
    }

    console.log('✅ Validation passed - Items found:', items.length);
    return true;
}

function dis() {
    return validatePOSForm();
}

// ========================================
// Recent Orders Functions
// ========================================
const recentOrdersState = {
    offset: 0,
    limit: 30,
    loading: false,
    hasMore: false,
    ordersById: {},
    refundTenders: [],
};

function escapeRecentOrdersHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatRecentOrderCustomerDate(value) {
    if (!value) {
        return '-';
    }
    const normalized = String(value).replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) {
        return String(value);
    }
    return date.toLocaleString('ar-EG', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function renderRecentOrderCustomerCell(order) {
    const customerName = order.customer_name || '-';
    const posCustomerId = parseInt(order.pos_customer_id || 0, 10);
    if (customerName === '-' || posCustomerId < 1) {
        return escapeRecentOrdersHtml(customerName);
    }

    return `<button type="button" class="btn btn-link btn-sm p-0 recent-order-customer-link text-start"
        data-customer-id="${posCustomerId}"
        data-customer-name="${escapeRecentOrdersHtml(customerName)}"
        title="عرض بيانات العميل">
        ${escapeRecentOrdersHtml(customerName)}
    </button>`;
}

function renderRecentOrderCustomerModalContent(customer, ordersPayload) {
    const orders = ordersPayload && Array.isArray(ordersPayload.items) ? ordersPayload.items : [];
    const ordersHtml = orders.length
        ? orders.map((order) => {
            const invoice = order.pro_id ? `#${order.pro_id}` : `ORD-${order.order_id}`;
            const total = parseFloat(order.fat_net || 0).toFixed(2);
            return `<tr>
                <td>${escapeRecentOrdersHtml(invoice)}</td>
                <td>${escapeRecentOrdersHtml(formatRecentOrderCustomerDate(order.order_time))}</td>
                <td class="text-nowrap fw-bold text-success">${total} ج.م</td>
            </tr>`;
        }).join('')
        : `<tr><td colspan="3" class="text-center text-muted py-3">لا توجد طلبات سابقة</td></tr>`;

    return `
        <div class="pos-recent-order-customer-summary row g-3 mb-4">
            <div class="col-md-6">
                <div class="pos-recent-order-customer-stat">
                    <span class="pos-recent-order-customer-label">الاسم</span>
                    <strong>${escapeRecentOrdersHtml(customer.display_name || '-')}</strong>
                </div>
            </div>
            <div class="col-md-6">
                <div class="pos-recent-order-customer-stat">
                    <span class="pos-recent-order-customer-label">الهاتف</span>
                    <strong>${escapeRecentOrdersHtml(customer.primary_phone || '-')}</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="pos-recent-order-customer-stat">
                    <span class="pos-recent-order-customer-label">عدد الطلبات</span>
                    <strong>${parseInt(customer.orders_count || 0, 10)}</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="pos-recent-order-customer-stat">
                    <span class="pos-recent-order-customer-label">إجمالي الإنفاق</span>
                    <strong class="text-success">${parseFloat(customer.lifetime_paid || 0).toFixed(2)} ج.م</strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="pos-recent-order-customer-stat">
                    <span class="pos-recent-order-customer-label">آخر طلب</span>
                    <strong>${escapeRecentOrdersHtml(formatRecentOrderCustomerDate(customer.last_order_at))}</strong>
                </div>
            </div>
        </div>
        <h6 class="mb-2">آخر الطلبات</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0 pos-recent-order-customer-orders-table">
                <thead>
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>التاريخ</th>
                        <th>الإجمالي</th>
                    </tr>
                </thead>
                <tbody>${ordersHtml}</tbody>
            </table>
        </div>
    `;
}

function showRecentOrderCustomerModal(customerId, customerName) {
    const modalEl = document.getElementById('recentOrderCustomerModal');
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        return;
    }

    const modal = typeof bootstrap.Modal.getOrCreateInstance === 'function'
        ? bootstrap.Modal.getOrCreateInstance(modalEl)
        : new bootstrap.Modal(modalEl);

    const title = customerName ? `بيانات العميل - ${customerName}` : 'بيانات العميل';
    $('#recentOrderCustomerModalLabel').html(`<i class="fas fa-user me-2"></i>${escapeRecentOrdersHtml(title)}`);
    $('#recentOrderCustomerBody').html(`
        <div class="text-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">جاري التحميل...</span>
            </div>
            <p class="mt-2 mb-0">جاري تحميل بيانات العميل...</p>
        </div>
    `);

    modal.show();

    $.ajax({
        url: 'ajax/pos_customer_profile.php',
        type: 'GET',
        cache: false,
        dataType: 'json',
        data: {
            id: customerId,
            include_orders: 1,
            per_page: 10,
            _: Date.now(),
        },
        success: function(response) {
            if (response.success && response.customer) {
                $('#recentOrderCustomerBody').html(
                    renderRecentOrderCustomerModalContent(response.customer, response.orders || null)
                );
                return;
            }

            $('#recentOrderCustomerBody').html(`
                <div class="text-center py-4 text-danger">
                    <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                    <p class="mb-0">${escapeRecentOrdersHtml(response.message || 'تعذر تحميل بيانات العميل')}</p>
                </div>
            `);
        },
        error: function() {
            $('#recentOrderCustomerBody').html(`
                <div class="text-center py-4 text-danger">
                    <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                    <p class="mb-0">حدث خطأ أثناء تحميل بيانات العميل</p>
                </div>
            `);
        },
    });
}

function showRecentOrdersModal() {
    const recentOrdersModal = document.getElementById('recentOrdersModal');
    if (!recentOrdersModal || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        return null;
    }

    const modal = typeof bootstrap.Modal.getOrCreateInstance === 'function'
        ? bootstrap.Modal.getOrCreateInstance(recentOrdersModal)
        : new bootstrap.Modal(recentOrdersModal);

    modal.show();
    return modal;
}

function updateRecentOrdersLoadMoreButton() {
    const $button = $('#recentOrdersLoadMoreBtn');
    if (!$button.length) {
        return;
    }

    if (recentOrdersState.hasMore) {
        $button.removeClass('d-none').prop('disabled', recentOrdersState.loading);
    } else {
        $button.addClass('d-none').prop('disabled', false);
    }
}

function renderRecentOrdersLoadingRow(message) {
    return `
        <tr>
            <td colspan="8" class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">جاري التحميل...</span>
                </div>
                <p class="mt-2">${message || 'جاري تحميل الطلبات...'}</p>
            </td>
        </tr>
    `;
}

function renderRecentOrderRow(order, rowNumber) {
    const tableId = parseInt(order.table_id || 0, 10);
    const deleteEligible = order.delete_eligible === true || order.delete_eligible === 1 || order.delete_eligible === '1'
        || order.can_delete === true || order.can_delete === 1 || order.can_delete === '1';
    const editEligible = order.edit_eligible === true || order.edit_eligible === 1 || order.edit_eligible === '1';
    const refundEligible = order.refund_eligible === true || order.refund_eligible === 1 || order.refund_eligible === '1'
        || order.can_refund === true || order.can_refund === 1 || order.can_refund === '1';
    const voidEligible = order.void_eligible === true || order.void_eligible === 1 || order.void_eligible === '1'
        || order.can_void === true || order.can_void === 1 || order.can_void === '1';
    const canDeleteDirect = window.POSMAIN && typeof window.POSMAIN.can === 'function'
        ? window.POSMAIN.can('pos.cancel.unpaid') === true
        : false;
    const canRefundDirect = window.POSMAIN && typeof window.POSMAIN.can === 'function'
        ? window.POSMAIN.can('pos.refund') === true
        : false;
    const canVoidDirect = window.POSMAIN && typeof window.POSMAIN.can === 'function'
        ? window.POSMAIN.can('pos.void.paid') === true
        : false;
    const statusBadge = (order.status === 'ملغى' || order.status === 'مسترد' || order.status === 'مسترد بالكامل')
        ? 'bg-danger'
        : (order.status === 'مكتمل' ? 'bg-success' : 'bg-warning text-dark');
    const typeBadge = order.type === 'دليفري'
        ? 'bg-info text-dark'
        : (order.type === 'طاولة' ? 'bg-warning text-dark' : 'bg-secondary');
    const customerCell = renderRecentOrderCustomerCell(order);
    const deleteButton = deleteEligible
        ? `<button class="btn btn-danger delete-order${canDeleteDirect ? '' : ' pos-action-locked'}" data-id="${order.id}" data-table-id="${tableId}" title="إلغاء طلب غير مدفوع">
                <i class="fas fa-ban"></i>
           </button>`
        : `<button class="btn btn-outline-secondary" disabled title="الطلب المدفوع يُعالج بالاسترداد، ولا يُحذف">
                <i class="fas fa-ban"></i>
           </button>`;
    const editButton = editEligible
        ? `<button class="btn btn-warning edit-order" data-id="${order.id}" title="تعديل طلب غير مدفوع">
                <i class="fas fa-edit"></i>
           </button>`
        : `<button class="btn btn-outline-secondary" disabled title="لا يمكن تعديل طلب بعد تسجيل دفعة؛ استخدم الاسترداد عند الحاجة">
                <i class="fas fa-edit"></i>
           </button>`;
    const reversalLocked = (refundEligible && !canRefundDirect) || (voidEligible && !canVoidDirect);
    const paidReversalButton = (refundEligible || voidEligible)
        ? `<button type="button" class="btn btn-outline-danger reverse-paid-order${reversalLocked ? ' pos-action-locked' : ''}" data-id="${order.id}" data-refund-eligible="${refundEligible ? '1' : '0'}" data-void-eligible="${voidEligible ? '1' : '0'}" title="استرداد مبلغ أو إلغاء طلب مدفوع">
                <i class="fas fa-undo"></i>
           </button>`
        : '';

    return `
        <tr data-order-id="${order.id}">
            <td>${rowNumber}</td>
            <td><strong>${order.invoice_number}</strong></td>
            <td>${order.date}</td>
            <td>${customerCell}</td>
            <td><span class="badge ${typeBadge}">${order.type}</span></td>
            <td class="text-nowrap fw-bold text-success">
                ${parseFloat(order.total || 0).toFixed(2)} ج.م
            </td>
            <td>
                <span class="badge ${statusBadge}">
                    ${order.status}
                </span>
            </td>
            <td class="text-nowrap">
                <div class="btn-group btn-group-sm" role="group">
                    ${editButton}
                    <button class="btn btn-secondary print-order" data-id="${order.id}" title="طباعة الفاتورة">
                        <i class="fas fa-print"></i>
                    </button>
                    ${deleteButton}
                    ${paidReversalButton}
                </div>
                ${order.notes ? `<span class="text-muted ms-2" title="${order.notes}"><i class="fas fa-sticky-note"></i></span>` : ''}
            </td>
        </tr>
    `;
}

function loadRecentOrders(append = false) {
    if (recentOrdersState.loading) {
        return;
    }

    if (!append) {
        recentOrdersState.offset = 0;
        recentOrdersState.hasMore = false;
        $('#recentOrdersList').html(renderRecentOrdersLoadingRow());
        updateRecentOrdersLoadMoreButton();
    } else {
        recentOrdersState.loading = true;
        updateRecentOrdersLoadMoreButton();
    }

    $.ajax({
        url: 'ajax/get_recent_orders.php',
        type: 'GET',
        cache: false,
        data: {
            limit: recentOrdersState.limit,
            offset: recentOrdersState.offset,
            _: Date.now(),
        },
        dataType: 'json',
        success: function(response) {
            recentOrdersState.loading = false;

            if (response.success && Array.isArray(response.orders)) {
                if (!append) {
                    recentOrdersState.ordersById = {};
                    recentOrdersState.refundTenders = Array.isArray(response.refund_tenders)
                        ? response.refund_tenders
                        : [];
                }
                response.orders.forEach((order) => {
                    recentOrdersState.ordersById[String(order.id)] = order;
                });
                if (!append) {
                    if (response.orders.length === 0) {
                        $('#recentOrdersList').html(`
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">لا توجد طلبات سابقة</p>
                                </td>
                            </tr>
                        `);
                    } else {
                        let html = '';
                        response.orders.forEach((order, index) => {
                            html += renderRecentOrderRow(order, index + 1);
                        });
                        $('#recentOrdersList').html(html);
                    }
                } else if (response.orders.length > 0) {
                    const startIndex = $('#recentOrdersList tr[data-order-id]').length;
                    let html = '';
                    response.orders.forEach((order, index) => {
                        html += renderRecentOrderRow(order, startIndex + index + 1);
                    });
                    $('#recentOrdersList').append(html);
                }

                recentOrdersState.offset += response.orders.length;
                recentOrdersState.hasMore = response.has_more === true;
                updateRecentOrdersLoadMoreButton();
                return;
            }

            if (!append) {
                $('#recentOrdersList').html(`
                    <tr>
                        <td colspan="8" class="text-center py-5 text-danger">
                            <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                            <p>فشل تحميل الطلبات</p>
                            <small class="d-block">${response.error || 'خطأ غير معروف'}</small>
                        </td>
                    </tr>
                `);
            }
            recentOrdersState.hasMore = false;
            updateRecentOrdersLoadMoreButton();
        },
        error: function(xhr, status, error) {
            recentOrdersState.loading = false;
            console.error('Error loading recent orders:', error);

            if (!append) {
                $('#recentOrdersList').html(`
                    <tr>
                        <td colspan="8" class="text-center py-5 text-danger">
                            <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                            <p>حدث خطأ أثناء تحميل الطلبات</p>
                            <small class="d-block">${error}</small>
                            <button class="btn btn-sm btn-outline-primary mt-2" onclick="loadRecentOrders(false)">
                                <i class="fas fa-sync-alt me-1"></i> إعادة المحاولة
                            </button>
                        </td>
                    </tr>
                `);
            }
            recentOrdersState.hasMore = false;
            updateRecentOrdersLoadMoreButton();
        },
    });
}

window.showRecentOrdersModal = showRecentOrdersModal;
window.loadRecentOrders = loadRecentOrders;
window.showRecentOrderCustomerModal = showRecentOrderCustomerModal;

function createPOSIdempotencyKey(scope) {
    if (window.POSOrderDraft && typeof window.POSOrderDraft.rotateIdempotencyKey === 'function') {
        return window.POSOrderDraft.rotateIdempotencyKey(scope);
    }
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return scope + ':' + window.crypto.randomUUID();
    }
    return scope + ':' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2);
}

const paidReversalState = {
    orderId: 0,
    mutationVersion: 0,
    canRefund: false,
    canVoid: false,
    submitting: false,
    pendingApprovalId: 0,
    idempotencyKey: '',
    idempotencyAction: '',
    originalPayments: [],
    refundTenders: [],
    refundableLines: [],
    originalTotal: '0.00',
    refundedAmount: '0.00',
    remainingRefundableAmount: '0.00',
};

function paidReversalMoney(value) {
    if (!window.POSOrderApi) {
        throw new Error('POS_MONEY_API_REQUIRED');
    }
    return window.POSOrderApi.decimalString(value, 2, '0');
}

function paidReversalQuantity(value) {
    if (!window.POSOrderApi) {
        throw new Error('POS_MONEY_API_REQUIRED');
    }
    return window.POSOrderApi.decimalString(value, 6, '0');
}

function resetPaidReversalValidation() {
    $('#paidReversalValidationAlert').addClass('d-none').text('');
}

function showPaidReversalValidation(message) {
    $('#paidReversalValidationAlert').removeClass('d-none').text(message);
}

function populatePaidReversalActionSelect(refundEligible, voidEligible) {
    const $select = $('#paid-reversal-action');
    $select.empty();
    if (refundEligible) {
        $select.append('<option value="refund">استرداد مبلغ الطلب</option>');
    }
    if (voidEligible) {
        $select.append('<option value="void">إلغاء الطلب المدفوع</option>');
    }
}

function invalidatePaidReversalIdempotency() {
    if (!paidReversalState.submitting) {
        paidReversalState.idempotencyKey = '';
        paidReversalState.idempotencyAction = '';
    }
}

function populatePaidReversalTenderContext() {
    const payments = Array.isArray(paidReversalState.originalPayments)
        ? paidReversalState.originalPayments
        : [];
    const tenders = Array.isArray(paidReversalState.refundTenders)
        ? paidReversalState.refundTenders
        : [];
    const originalHtml = payments.length > 0
        ? payments.map((payment) => {
            const label = escapeRecentOrdersHtml(payment.label || payment.payment_method || '-');
            const amount = paidReversalMoney(payment.refundable_amount || payment.original_amount || '0');
            return `<span class="badge bg-light text-dark border me-1">${label}: ${amount} ج.م</span>`;
        }).join('')
        : '<span class="text-danger">تعذر تحميل طرق الدفع الأصلية لهذا الطلب.</span>';
    $('#paid-reversal-original-tenders').html(
        '<strong class="d-block mb-2">طرق الدفع الأصلية والمتبقي القابل للاسترداد</strong>' + originalHtml
    );
    $('#paid-reversal-balance-summary').html(
        `<strong class="d-block mb-1">رصيد الاسترداد</strong>
         إجمالي البيع: ${paidReversalState.originalTotal} ج.م
         <span class="mx-1">•</span>
         المسترد سابقاً: ${paidReversalState.refundedAmount} ج.م
         <span class="mx-1">•</span>
         <strong>المتبقي: ${paidReversalState.remainingRefundableAmount} ج.م</strong>`
    );

    const $select = $('#paid-reversal-tender');
    $select.empty().append('<option value="">اختر طريقة صرف الاسترداد</option>');
    tenders.forEach((tender) => {
        const code = escapeRecentOrdersHtml(tender.code || '');
        const label = escapeRecentOrdersHtml(tender.label || tender.code || '');
        const type = escapeRecentOrdersHtml(tender.type || '');
        $select.append(`<option value="${code}" data-type="${type}">${label}</option>`);
    });
    $('#paid-reversal-reference').val('');
    updatePaidReversalTenderVisibility();
}

function formatPaidReversalQuantity(value) {
    return paidReversalQuantity(value).replace(/\.?0+$/, '');
}

function populatePaidReversalRefundLines() {
    const lines = Array.isArray(paidReversalState.refundableLines)
        ? paidReversalState.refundableLines
        : [];
    const html = lines.length > 0
        ? lines.map((line) => {
            const detailId = parseInt(line.original_detail_id || 0, 10);
            const remainingQty = paidReversalQuantity(line.remaining_quantity || '0');
            const remainingAmount = paidReversalMoney(line.remaining_amount || '0');
            const originalDiscount = paidReversalMoney(line.original_discount || '0');
            return `<tr data-refund-detail-id="${detailId}" data-remaining-amount="${remainingAmount}">
                <td>
                    <input class="form-check-input paid-reversal-line-check" type="checkbox"
                        aria-label="اختيار ${escapeRecentOrdersHtml(line.label || '')}">
                </td>
                <td>
                    <strong>${escapeRecentOrdersHtml(line.label || `#${line.item_id || detailId}`)}</strong>
                    ${window.POSOrderApi.compareDecimalStrings(originalDiscount, '0.00', 2) > 0
                        ? `<small class="d-block text-muted">يشمل خصماً ${originalDiscount} ج.م</small>`
                        : ''}
                </td>
                <td class="text-nowrap">${formatPaidReversalQuantity(remainingQty)}</td>
                <td style="min-width: 8rem">
                    <input class="form-control form-control-sm paid-reversal-line-qty" type="number"
                        min="0.000001" max="${remainingQty}" step="0.000001"
                        value="${remainingQty}" disabled>
                </td>
                <td class="text-nowrap paid-reversal-line-estimate">${remainingAmount} ج.م</td>
            </tr>`;
        }).join('')
        : '<tr><td colspan="5" class="text-center text-muted py-3">لا توجد سطور قابلة للاسترداد.</td></tr>';
    $('#paid-reversal-items-list').html(html);
    updatePaidReversalItemsTotal();
}

function updatePaidReversalItemsTotal() {
    const money = window.POSOrderApi;
    if (!money) {
        throw new Error('POS_MONEY_API_REQUIRED');
    }
    let total = '0.00';
    $('#paid-reversal-items-list tr[data-refund-detail-id]').each(function() {
        const $row = $(this);
        const selected = $row.find('.paid-reversal-line-check').is(':checked');
        const $qty = $row.find('.paid-reversal-line-qty');
        const maxQty = paidReversalQuantity($qty.attr('max') || '0');
        let qty = '0.000000';
        try {
            qty = paidReversalQuantity($qty.val() || '0');
        } catch (ignored) {
            qty = '0.000000';
        }
        if (money.compareDecimalStrings(qty, maxQty, 6) > 0) {
            qty = maxQty;
        }
        const remainingAmount = paidReversalMoney($row.attr('data-remaining-amount') || '0');
        const estimate = money.compareDecimalStrings(maxQty, '0.000000', 6) > 0
            ? money.prorateMoneyByQuantity(remainingAmount, qty, maxQty)
            : '0.00';
        $row.find('.paid-reversal-line-estimate').text(`${estimate} ج.م`);
        if (selected) {
            total = money.addDecimalStrings(total, estimate, 2);
        }
    });
    $('#paid-reversal-items-total').text(`الإجمالي التقريبي المحدد: ${total} ج.م — الخادم يحسب القيمة النهائية من لقطة البيع.`);
}

function updatePaidReversalRefundModeVisibility() {
    const action = $('#paid-reversal-action').val() === 'void' ? 'void' : 'refund';
    const mode = String($('#paid-reversal-refund-mode').val() || 'full');
    const isRefund = action === 'refund';
    $('#paid-reversal-refund-mode').prop('disabled', !isRefund);
    $('#paid-reversal-amount-section').toggleClass('d-none', !isRefund || mode !== 'amount');
    $('#paid-reversal-items-section').toggleClass('d-none', !isRefund || mode !== 'items');
}

function updatePaidReversalTenderVisibility() {
    const action = $('#paid-reversal-action').val() === 'void' ? 'void' : 'refund';
    const isRefund = action === 'refund';
    $('#paid-reversal-tender-section').toggleClass('d-none', !isRefund);
    updatePaidReversalRefundModeVisibility();
    if (!isRefund) {
        return;
    }

    const selected = $('#paid-reversal-tender option:selected');
    const type = String(selected.data('type') || '');
    const reference = String($('#paid-reversal-reference').val() || '').trim();
    if (type === 'cash') {
        $('#paid-reversal-settlement-hint').text(
            'سيُسجل الصرف النقدي مرة واحدة كحركة استرداد نقدي على جلسة الدرج المفتوحة.'
        );
    } else if (type && reference) {
        $('#paid-reversal-settlement-hint').text(
            'سيُسجل الاسترداد غير النقدي كتسوية مكتملة باستخدام المرجع المدخل.'
        );
    } else {
        $('#paid-reversal-settlement-hint').text(
            'الاسترداد غير النقدي بدون مرجع سيُسجل كعملية معلقة حتى إدخال مرجع التسوية.'
        );
    }
}

function openPaidOrderReversalModal(orderId, refundEligible, voidEligible, options) {
    refundEligible = refundEligible === true || refundEligible === 1 || refundEligible === '1';
    voidEligible = voidEligible === true || voidEligible === 1 || voidEligible === '1';
    if (!refundEligible && !voidEligible) {
        return;
    }

    const modalEl = document.getElementById('paidOrderReversalModal');
    if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        return;
    }

    paidReversalState.orderId = parseInt(orderId || 0, 10);
    paidReversalState.mutationVersion = Math.max(
        1,
        parseInt(options && options.mutationVersion || 1, 10) || 1
    );
    paidReversalState.canRefund = refundEligible;
    paidReversalState.canVoid = voidEligible;
    paidReversalState.submitting = false;
    paidReversalState.idempotencyKey = '';
    paidReversalState.idempotencyAction = '';
    paidReversalState.originalPayments = Array.isArray(options && options.originalPayments)
        ? options.originalPayments
        : [];
    paidReversalState.refundTenders = Array.isArray(options && options.refundTenders)
        ? options.refundTenders
        : [];
    paidReversalState.refundableLines = Array.isArray(options && options.refundableLines)
        ? options.refundableLines
        : [];
    paidReversalState.originalTotal = paidReversalMoney(options && options.originalTotal || '0');
    paidReversalState.refundedAmount = paidReversalMoney(options && options.refundedAmount || '0');
    paidReversalState.remainingRefundableAmount = paidReversalMoney(
        options && options.remainingRefundableAmount || '0'
    );
    if (!options || !options.keepApproval) {
        paidReversalState.pendingApprovalId = 0;
    }

    $('#paid-reversal-refund-mode').val('full');
    populatePaidReversalActionSelect(refundEligible, voidEligible);
    populatePaidReversalTenderContext();
    populatePaidReversalRefundLines();
    $('#paid-reversal-amount')
        .val('')
        .attr('max', paidReversalState.remainingRefundableAmount);
    $('#paid-reversal-policy').val('waste');
    $('#paid-reversal-reason').val('');
    resetPaidReversalValidation();
    $('#paidReversalSubmitBtn').prop('disabled', false).html('<i class="fas fa-check me-1"></i>تأكيد العملية');

    const modal = typeof bootstrap.Modal.getOrCreateInstance === 'function'
        ? bootstrap.Modal.getOrCreateInstance(modalEl)
        : new bootstrap.Modal(modalEl);

    modal.show();
}

function submitPaidOrderReversal(approvalId) {
    if (paidReversalState.submitting) {
        return;
    }

    const effectiveApprovalId = approvalId || paidReversalState.pendingApprovalId || null;

    const reason = ($('#paid-reversal-reason').val() || '').trim();
    if (!reason) {
        showPaidReversalValidation('اكتب سبب الاسترداد أو الإلغاء قبل المتابعة.');
        $('#paid-reversal-reason').trigger('focus');
        return;
    }

    const orderId = parseInt(paidReversalState.orderId || 0, 10);
    if (orderId <= 0) {
        showPaidReversalValidation('تعذر تحديد الطلب');
        return;
    }

    const action = $('#paid-reversal-action').val() === 'void' ? 'void' : 'refund';
    const policy = $('#paid-reversal-policy').val() || 'waste';
    const refundTender = String($('#paid-reversal-tender').val() || '').trim();
    const refundReference = String($('#paid-reversal-reference').val() || '').trim();
    if (action === 'refund' && !refundTender) {
        showPaidReversalValidation('اختر طريقة صرف مبلغ الاسترداد قبل المتابعة.');
        $('#paid-reversal-tender').trigger('focus');
        return;
    }
    const refundMode = String($('#paid-reversal-refund-mode').val() || 'full');
    let refundAmount = '';
    let refundLines = [];
    if (action === 'refund' && refundMode === 'amount') {
        try {
            refundAmount = paidReversalMoney($('#paid-reversal-amount').val());
        } catch (ignored) {
            refundAmount = '';
        }
        if (refundAmount === ''
            || window.POSOrderApi.compareDecimalStrings(refundAmount, '0.00', 2) <= 0
            || window.POSOrderApi.compareDecimalStrings(
                refundAmount,
                paidReversalState.remainingRefundableAmount,
                2
            ) > 0) {
            showPaidReversalValidation('أدخل مبلغاً صحيحاً لا يتجاوز الرصيد القابل للاسترداد.');
            $('#paid-reversal-amount').trigger('focus');
            return;
        }
    } else if (action === 'refund' && refundMode === 'items') {
        $('#paid-reversal-items-list tr[data-refund-detail-id]').each(function() {
            const $row = $(this);
            if (!$row.find('.paid-reversal-line-check').is(':checked')) {
                return;
            }
            const $qty = $row.find('.paid-reversal-line-qty');
            let quantity = '';
            let maxQuantity = '';
            try {
                quantity = paidReversalQuantity($qty.val());
                maxQuantity = paidReversalQuantity($qty.attr('max'));
            } catch (ignored) {
                quantity = '';
            }
            if (quantity !== ''
                && window.POSOrderApi.compareDecimalStrings(quantity, '0.000000', 6) > 0
                && window.POSOrderApi.compareDecimalStrings(quantity, maxQuantity, 6) <= 0) {
                refundLines.push({
                    original_detail_id: parseInt($row.data('refund-detail-id') || 0, 10),
                    quantity: quantity,
                    stock_disposition: policy === 'return_to_stock' ? 'restock' : 'waste',
                });
            }
        });
        if (refundLines.length === 0) {
            showPaidReversalValidation('اختر صنفاً واحداً على الأقل وحدد كمية صحيحة.');
            return;
        }
    }
    const permissionKey = action === 'void' ? 'pos.void.paid' : 'pos.refund';
    if (!paidReversalState.idempotencyKey || paidReversalState.idempotencyAction !== action) {
        paidReversalState.idempotencyKey = createPOSIdempotencyKey(
            action === 'void' ? 'pos.order.void' : 'pos.order.refund'
        );
        paidReversalState.idempotencyAction = action;
    }
    paidReversalState.submitting = true;
    resetPaidReversalValidation();
    $('#paidReversalSubmitBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>جارٍ تنفيذ العملية...');

    const postData = {
        order_id: orderId,
        mutation_version: paidReversalState.mutationVersion,
        action: action,
        refund_stock_policy: policy,
        reason: reason,
        idempotency_key: paidReversalState.idempotencyKey,
    };
    if (action === 'refund') {
        postData.refund_payment_method = refundTender;
        postData.refund_external_reference = refundReference;
        postData.refund_mode = refundMode;
        if (refundMode === 'amount') {
            postData.refund_amount = refundAmount;
        } else if (refundMode === 'items') {
            postData.refund_lines = JSON.stringify(refundLines);
        }
    }
    if (approvalId) {
        postData.manager_approval_id = approvalId;
    } else if (effectiveApprovalId) {
        postData.manager_approval_id = effectiveApprovalId;
    }

    function handleApprovalRequired() {
        paidReversalState.submitting = false;
        $('#paidReversalSubmitBtn').prop('disabled', false).html('<i class="fas fa-check me-1"></i>تأكيد العملية');
        const actionLabel = action === 'void' ? 'إلغاء الطلب المدفوع' : 'استرداد مبلغ الطلب';
        window.POSMAIN.requestManagerOverride(permissionKey, {
            message: actionLabel + ' يحتاج إلى اعتماد مدير',
            target_type: 'order',
            target_id: orderId,
        }).done(function (approval) {
            paidReversalState.pendingApprovalId = approval.approval_id;
            submitPaidOrderReversal(approval.approval_id);
        }).fail(function (err) {
            const msg = (window.POSMAIN && typeof window.POSMAIN.overrideErrorMessage === 'function')
                ? window.POSMAIN.overrideErrorMessage(err)
                : 'تم إلغاء اعتماد المدير';
            showPaidReversalValidation(msg);
        });
    }

    $.ajax({
        url: 'ajax/refund_order.php',
        method: 'POST',
        dataType: 'json',
        data: postData,
        success: function(response) {
            try {
                if (typeof response === 'string') {
                    response = JSON.parse(response);
                }
                if (response.success) {
                    paidReversalState.submitting = false;
                    paidReversalState.pendingApprovalId = 0;
                    paidReversalState.idempotencyKey = '';
                    paidReversalState.idempotencyAction = '';
                    const modalEl = document.getElementById('paidOrderReversalModal');
                    if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        const instance = bootstrap.Modal.getInstance(modalEl);
                        if (instance) {
                            instance.hide();
                        }
                    }
                    if (window.Swal && typeof window.Swal.fire === 'function') {
                        const pendingExternal = paidReversalMoney(
                            (response.data && response.data.pending_external_amount) || '0'
                        );
                        Swal.fire({
                            icon: 'success',
                            title: action === 'void'
                                ? 'تم إلغاء الطلب المدفوع'
                                : (window.POSOrderApi.compareDecimalStrings(pendingExternal, '0.00', 2) > 0
                                    ? 'تم تسجيل الاسترداد وهو بانتظار التسوية الخارجية'
                                    : 'تم استرداد مبلغ الطلب وحفظ المرتجع'),
                            timer: 1800,
                            showConfirmButton: false,
                        });
                    }
                    loadRecentOrders(false);
                    $('#paidReversalSubmitBtn').prop('disabled', false).html('<i class="fas fa-check me-1"></i>تأكيد العملية');
                } else if (paidReversalNeedsApproval(response.code) && !effectiveApprovalId) {
                    handleApprovalRequired();
                } else {
                    paidReversalState.submitting = false;
                    showPaidReversalValidation(paidReversalFriendlyError(response));
                    $('#paidReversalSubmitBtn').prop('disabled', false).html('<i class="fas fa-check me-1"></i>تأكيد العملية');
                }
            } catch (e) {
                paidReversalState.submitting = false;
                showPaidReversalValidation('خطأ في استجابة الخادم');
                $('#paidReversalSubmitBtn').prop('disabled', false).html('<i class="fas fa-check me-1"></i>تأكيد العملية');
            }
        },
        error: function(xhr) {
            let message = 'خطأ في الاتصال';
            let code = '';
            let payload = null;
            try {
                payload = xhr.responseJSON || JSON.parse(xhr.responseText || '{}');
                message = payload.message || payload.code || message;
                code = payload.code || '';
            } catch (e) {
                // keep default message
            }
            if (paidReversalNeedsApproval(code) && !effectiveApprovalId) {
                handleApprovalRequired();
                return;
            }
            paidReversalState.submitting = false;
            showPaidReversalValidation(paidReversalFriendlyError(payload || { code: code, message: message }));
            $('#paidReversalSubmitBtn').prop('disabled', false).html('<i class="fas fa-check me-1"></i>تأكيد العملية');
        },
    });
}

function paidReversalNeedsApproval(code) {
    return code === 'MANAGER_APPROVAL_REQUIRED'
        || code === 'PERMISSION_DENIED';
}

function paidReversalFriendlyError(payload) {
    const code = String((payload && (payload.code || payload.error)) || '').trim();
    const message = String((payload && payload.message) || '').trim();
    switch (code) {
        case 'PERMISSION_DENIED':
            return 'ليست لديك صلاحية لهذه العملية. يلزم اعتماد بصلاحية مناسبة.';
        case 'MANAGER_APPROVAL_REQUIRED':
            return 'يتطلب اعتماد مستخدم بصلاحية مناسبة.';
        case 'MANAGER_APPROVAL_INVALID':
        case 'MANAGER_APPROVAL_SCOPE_MISMATCH':
            return 'اعتماد المدير غير صالح لهذه العملية. أعد الاعتماد ثم نفّذ.';
        case 'MANAGER_APPROVAL_EXPIRED':
        case 'APPROVAL_EXPIRED':
            return 'انتهت صلاحية الاعتماد. أعد إدخال الرمز ثم نفّذ.';
        case 'ORDER_ALREADY_REVERSED':
            return 'لا يمكن تنفيذ العملية لأن الطلب تم استرداده أو إلغاؤه من قبل.';
        case 'ORDER_NOT_PAID':
            return 'الطلب غير مدفوع ولا يمكن استرداده من هنا.';
        case 'ORDER_NOT_FOUND':
            return 'تعذر العثور على الطلب.';
        case 'REFUND_TENDER_REQUIRED':
            return 'اختر طريقة صرف مبلغ الاسترداد.';
        case 'PAYMENT_METHOD_NOT_FOUND':
        case 'PAYMENT_METHOD_ACCOUNT_REQUIRED':
            return 'طريقة الاسترداد غير متاحة أو غير مرتبطة بحساب مالي صالح.';
        case 'DRAWER_SESSION_REQUIRED':
            return 'الاسترداد النقدي يحتاج إلى جلسة درج مفتوحة لنفس المستخدم والفرع.';
        default:
            if (message && message !== code) {
                return message;
            }
            if (window.POSMAIN && typeof window.POSMAIN.overrideErrorMessage === 'function' && code) {
                const mapped = window.POSMAIN.overrideErrorMessage({ code: code });
                if (mapped && mapped.indexOf('تعذر') !== 0) {
                    return mapped;
                }
            }
            return message || 'تعذر تنفيذ العملية';
    }
}
window.reversePaidOrder = openPaidOrderReversalModal;

function editOrder(orderId) {
    console.log('Edit order:', orderId);
    window.location.href = 'pos_barcode.php?edit=' + orderId;
}

function deleteOrder(orderId, tableId, approvalId) {
    const runDelete = function () {
        const order = recentOrdersState.ordersById[String(orderId)] || {};
        const postData = {
            order_id: orderId,
            table_id: parseInt(tableId || 0, 10),
            mutation_version: Math.max(1, parseInt(order.mutation_version || 1, 10) || 1),
            idempotency_key: createPOSIdempotencyKey('pos.order.cancel'),
        };
        if (approvalId) {
            postData.manager_approval_id = approvalId;
        }
        $.ajax({
            url: 'ajax/delete_order.php',
            type: 'POST',
            data: postData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    loadRecentOrders(false);
                    alert('تم إلغاء الطلب غير المدفوع مع الاحتفاظ بسجل الإلغاء.');
                } else if ((response.code || '') === 'MANAGER_APPROVAL_REQUIRED' && !approvalId) {
                    window.POSMAIN.requestManagerOverride('pos.cancel.unpaid', {
                        message: 'إلغاء الطلب غير المدفوع يحتاج إلى اعتماد مدير',
                        target_type: 'pos_order',
                        target_id: orderId,
                    }).done(function (approval) {
                        deleteOrder(orderId, tableId, approval.approval_id);
                    });
                } else {
                    const message = (response.code || '') === 'ORDER_HAS_PAYMENT_USE_REFUND'
                        ? 'تم تسجيل دفعة على هذا الطلب، لذلك لا يمكن إلغاؤه كطلب غير مدفوع. استخدم الاسترداد.'
                        : (response.message || 'تعذر إلغاء الطلب');
                    alert(message);
                }
            },
            error: function(xhr) {
                const payload = xhr.responseJSON || {};
                if ((payload.code || '') === 'MANAGER_APPROVAL_REQUIRED' && !approvalId) {
                    window.POSMAIN.requestManagerOverride('pos.cancel.unpaid', {
                        message: 'إلغاء الطلب غير المدفوع يحتاج إلى اعتماد مدير',
                        target_type: 'pos_order',
                        target_id: orderId,
                    }).done(function (approval) {
                        deleteOrder(orderId, tableId, approval.approval_id);
                    });
                    return;
                }
                alert((payload.code || '') === 'ORDER_HAS_PAYMENT_USE_REFUND'
                    ? 'تم تسجيل دفعة على هذا الطلب، لذلك استخدم الاسترداد بدلاً من الإلغاء.'
                    : 'تعذر إلغاء الطلب. تحقق من الاتصال ثم حاول مرة أخرى.');
            }
        });
    };

    const needsOverride = window.POSMAIN && typeof window.POSMAIN.can === 'function'
        && window.POSMAIN.can('pos.cancel.unpaid') !== true;
    if (needsOverride && !approvalId) {
        window.POSMAIN.requestManagerOverride('pos.cancel.unpaid', {
            message: 'إلغاء الطلب غير المدفوع يحتاج إلى اعتماد مدير',
            target_type: 'pos_order',
            target_id: orderId,
        }).done(function (approval) {
            if (confirm('هل تريد إلغاء هذا الطلب غير المدفوع؟ سيبقى سجل الإلغاء محفوظاً.')) {
                deleteOrder(orderId, tableId, approval.approval_id);
            }
        });
        return;
    }

    if (confirm('هل تريد إلغاء هذا الطلب غير المدفوع؟ سيبقى سجل الإلغاء محفوظاً.')) {
        runDelete();
    }
}

// Initialize recent orders functionality
$(document).ready(function() {
    $(document).on('click', '.recent-orders-btn, #recentOrdersBtn1, #recentOrdersBtn2, #cornerRecentOrdersBtn', function(e) {
        e.preventDefault();
        showRecentOrdersModal();
    });

    $('#recentOrdersLoadMoreBtn').on('click', function(e) {
        e.preventDefault();
        if (!recentOrdersState.loading && recentOrdersState.hasMore) {
            loadRecentOrders(true);
        }
    });

    // Handle edit order button
    $(document).on('click', '.edit-order', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const orderId = $(this).data('id');
        console.log('Edit button clicked for order:', orderId);
        editOrder(orderId);
    });

    // Handle delete order button
    $(document).on('click', '.delete-order', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const orderId = $(this).data('id');
        const tableId = $(this).data('table-id');
        deleteOrder(orderId, tableId);
    });

    $(document).on('click', '.reverse-paid-order', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const orderId = $(this).data('id');
        const refundEligible = $(this).data('refund-eligible') === 1 || $(this).data('refund-eligible') === '1';
        const voidEligible = $(this).data('void-eligible') === 1 || $(this).data('void-eligible') === '1';
        const order = recentOrdersState.ordersById[String(orderId)] || {};
        // Open the modal first; PIN is requested on submit for the selected action
        // (refund vs void) so the approval permission key always matches.
        openPaidOrderReversalModal(orderId, refundEligible, voidEligible, {
            mutationVersion: order.mutation_version,
            originalPayments: Array.isArray(order.original_payments) ? order.original_payments : [],
            refundTenders: recentOrdersState.refundTenders,
            refundableLines: Array.isArray(order.refundable_lines) ? order.refundable_lines : [],
            originalTotal: order.total,
            refundedAmount: order.refunded_amount,
            remainingRefundableAmount: order.remaining_refundable_amount,
        });
    });

    // Handle print order button
    $(document).on('click', '.print-order', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const orderId = $(this).data('id');
        console.log('Print button clicked for order:', orderId);
        window.open('print/receipt.php?id=' + orderId, '_blank');
    });

    $(document).on('click', '.recent-order-customer-link', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const customerId = parseInt($(this).data('customer-id') || 0, 10);
        const customerName = String($(this).data('customer-name') || '').trim();
        if (customerId > 0) {
            showRecentOrderCustomerModal(customerId, customerName);
        }
    });

    $('#paidReversalSubmitBtn').on('click', function(e) {
        e.preventDefault();
        submitPaidOrderReversal();
    });

    $('#paidOrderReversalModal').on('shown.bs.modal', function() {
        resetPaidReversalValidation();
        const action = $('#paid-reversal-action').val() === 'void' ? 'void' : 'refund';
        $(action === 'refund' ? '#paid-reversal-tender' : '#paid-reversal-reason').trigger('focus');
    });

    $('#paid-reversal-action').on('change', function() {
        invalidatePaidReversalIdempotency();
        updatePaidReversalTenderVisibility();
        resetPaidReversalValidation();
    });

    $('#paid-reversal-tender, #paid-reversal-reference').on('change input', function() {
        invalidatePaidReversalIdempotency();
        updatePaidReversalTenderVisibility();
        resetPaidReversalValidation();
    });

    $('#paid-reversal-refund-mode, #paid-reversal-amount').on('change input', function() {
        invalidatePaidReversalIdempotency();
        updatePaidReversalRefundModeVisibility();
        resetPaidReversalValidation();
    });

    $(document).on('change', '.paid-reversal-line-check', function() {
        const $row = $(this).closest('tr');
        $row.find('.paid-reversal-line-qty').prop('disabled', !$(this).is(':checked'));
        invalidatePaidReversalIdempotency();
        updatePaidReversalItemsTotal();
        resetPaidReversalValidation();
    });

    $(document).on('input change', '.paid-reversal-line-qty', function() {
        invalidatePaidReversalIdempotency();
        updatePaidReversalItemsTotal();
        resetPaidReversalValidation();
    });

    $('#paid-reversal-policy, #paid-reversal-reason').on('change input', function() {
        invalidatePaidReversalIdempotency();
        if (($(this).val() || '').trim() !== '') {
            resetPaidReversalValidation();
        }
    });

    // Load orders when modal is shown
    $('#recentOrdersModal').on('shown.bs.modal', function() {
        loadRecentOrders(false);
    });
});

// ========================================
// Daily Sales Report Print Function
// ========================================
function printDailySalesReport() {
    console.log('Opening daily sales report...');
    window.open('print/daily_sales_receipt.php', '_blank');
}



// ========================================
// Shift Management Functions
// ========================================
// Shift functions moved to pos_barcode.php inline script for better error handling

function closeShift() {
    if (confirm('هل أنت متأكد من إغلاق الشيفت؟')) {
        window.location.href = 'logout.php';
    }
}
