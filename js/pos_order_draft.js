(function (window, $) {
    'use strict';

    const STATE_EMPTY = 'empty';
    const STATE_DIRTY = 'dirty';
    const STATE_SAVING = 'saving';
    const STATE_SAVED = 'saved';

    const LABELS = {
        empty: 'حفظ',
        dirty: 'حفظ',
        saving: 'جاري الحفظ…',
        saved: 'تم الحفظ'
    };

    let currentState = STATE_EMPTY;
    let savedFingerprint = '';
    let orderId = 0;
    let kitchenRevision = 0;
    let externalFingerprintBuilder = null;
    let standaloneIdempotencyKey = '';

    function setFingerprintBuilder(builder) {
        externalFingerprintBuilder = typeof builder === 'function' ? builder : null;
    }

    function createIdempotencyKey(scope) {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return scope + ':' + window.crypto.randomUUID();
        }

        return scope + ':' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2);
    }

    function readFieldValue(name, fallback) {
        const form = document.getElementById('posForm');
        if (!form) {
            return fallback !== undefined ? fallback : '';
        }
        const field = form.querySelector('[name="' + name + '"]');
        if (!field) {
            return fallback !== undefined ? fallback : '';
        }
        return field.value;
    }

    function readCheckedAge() {
        const checked = document.querySelector('input[name="age"]:checked');
        return checked ? (parseInt(checked.value, 10) || 1) : 1;
    }

    function collectLines() {
        const form = document.getElementById('posForm');
        if (!form) {
            return [];
        }

        const names = form.querySelectorAll('[name="itmname[]"], [name="itmname"]');
        const qtyFields = form.querySelectorAll('[name="itmqty[]"], [name="itmqty"]');
        const priceFields = form.querySelectorAll('[name="itmprice[]"], [name="itmprice"]');
        const discFields = form.querySelectorAll('[name="itmdisc[]"], [name="itmdisc"]');
        const noteFields = form.querySelectorAll('[name="itmnote[]"], [name="itmnote"]');
        const lines = [];

        names.forEach(function (field, index) {
            const id = parseInt(field.value, 10);
            if (!id) {
                return;
            }
            lines.push({
                id: id,
                qty: parseFloat((qtyFields[index] || {}).value || 1),
                price: parseFloat((priceFields[index] || {}).value || 0),
                discount: parseFloat((discFields[index] || {}).value || 0),
                note: String((noteFields[index] || {}).value || '')
            });
        });

        lines.sort(function (a, b) {
            if (a.id !== b.id) {
                return a.id - b.id;
            }
            return a.note.localeCompare(b.note);
        });

        return lines;
    }

    function fingerprint() {
        if (externalFingerprintBuilder) {
            return externalFingerprintBuilder();
        }

        const lines = collectLines();
        const payload = {
            lines: lines,
            headdisc: parseFloat(readFieldValue('headdisc', '0') || $('#headdisc').val() || 0),
            headplus: parseFloat(readFieldValue('headplus', '0') || 0),
            headnet: parseFloat(readFieldValue('headnet', '0') || $('#net_val').val() || 0),
            age: readCheckedAge(),
            table_id: parseInt($('#selected_table_id').val() || readFieldValue('selected_table_id', 0), 10) || 0,
            order_id: parseInt($('#selected_order_id').val() || $('#edit_order_id').val() || $('#current_order_id').val() || readFieldValue('edit_id', 0), 10) || 0
        };

        return JSON.stringify(payload);
    }

    function hasLineItems() {
        if (externalFingerprintBuilder) {
            try {
                const parsed = JSON.parse(fingerprint());
                return Array.isArray(parsed.lines) && parsed.lines.length > 0;
            } catch (error) {
                return false;
            }
        }

        return collectLines().length > 0;
    }

    function resolveOrderId() {
        const fromHidden = parseInt(
            $('#edit_order_id').val() || $('#selected_order_id').val() || $('#current_order_id').val() || '0',
            10
        ) || 0;
        return orderId > 0 ? orderId : fromHidden;
    }

    function applySaveButtonState() {
        const buttons = $('.pos-save-order-btn, #save-order');
        if (!buttons.length) {
            return;
        }

        const label = LABELS[currentState] || LABELS.dirty;
        const stateClass = 'pos-save--' + currentState;
        const disabled = currentState === STATE_EMPTY
            || currentState === STATE_SAVING
            || currentState === STATE_SAVED;

        buttons.each(function () {
            const btn = $(this);
            btn.removeClass('pos-save--empty pos-save--dirty pos-save--saving pos-save--saved');
            btn.addClass(stateClass);
            btn.attr('data-pos-save-state', currentState);
            btn.prop('disabled', disabled);
            btn.attr('aria-disabled', disabled ? 'true' : 'false');

            const icon = btn.find('i.fas').first();
            if (currentState === STATE_SAVING) {
                if (icon.length) {
                    icon.attr('class', 'fas fa-spinner fa-spin me-1');
                }
            } else if (currentState === STATE_SAVED) {
                if (icon.length) {
                    icon.attr('class', 'fas fa-check me-1');
                }
            } else if (icon.length) {
                icon.attr('class', 'fas fa-bookmark me-1');
            }

            if (btn.is('#save-order')) {
                btn.text(label);
            } else {
                const textNode = btn.contents().filter(function () {
                    return this.nodeType === 3;
                }).last();
                if (textNode.length) {
                    textNode[0].nodeValue = ' ' + label;
                }
            }
        });
    }

    function recomputeStateFromCart() {
        if (currentState === STATE_SAVING) {
            return;
        }

        if (!hasLineItems()) {
            currentState = STATE_EMPTY;
            applySaveButtonState();
            return;
        }

        const current = fingerprint();
        if (savedFingerprint && current === savedFingerprint) {
            currentState = STATE_SAVED;
        } else {
            currentState = STATE_DIRTY;
        }
        applySaveButtonState();
    }

    function markDirty() {
        if (currentState === STATE_SAVING) {
            return;
        }
        if (!hasLineItems()) {
            currentState = STATE_EMPTY;
        } else if (savedFingerprint && fingerprint() === savedFingerprint) {
            currentState = STATE_SAVED;
        } else {
            currentState = STATE_DIRTY;
        }
        applySaveButtonState();
    }

    function markSaving() {
        currentState = STATE_SAVING;
        applySaveButtonState();
    }

    function markSaved(response) {
        const body = response || {};
        const state = body.updated_state || {};
        const resolvedOrderId = parseInt(state.order_id || body.order_id || resolveOrderId(), 10) || 0;
        if (resolvedOrderId > 0) {
            orderId = resolvedOrderId;
        }
        kitchenRevision = parseInt(state.kitchen_revision || body.kitchen_revision || kitchenRevision, 10) || kitchenRevision;
        savedFingerprint = fingerprint();
        currentState = STATE_SAVED;
        applySaveButtonState();
    }

    function markSaveFailed() {
        if (currentState === STATE_SAVING) {
            currentState = STATE_DIRTY;
        }
        recomputeStateFromCart();
    }

    function reset() {
        orderId = 0;
        kitchenRevision = 0;
        savedFingerprint = '';
        standaloneIdempotencyKey = '';
        currentState = hasLineItems() ? STATE_DIRTY : STATE_EMPTY;
        clearIdempotencyKey();
        applySaveButtonState();
    }

    function bootstrapSaved(options) {
        options = options || {};
        orderId = parseInt(options.order_id || options.orderId || 0, 10) || 0;
        kitchenRevision = parseInt(options.kitchen_revision || options.kitchenRevision || 0, 10) || 0;
        savedFingerprint = fingerprint();
        currentState = hasLineItems() ? STATE_SAVED : STATE_EMPTY;
        applySaveButtonState();
    }

    function isDirty() {
        return currentState === STATE_DIRTY;
    }

    function canSave(action) {
        if (action && action !== 'save' && action !== 'print_receipt') {
            return true;
        }
        return currentState === STATE_DIRTY;
    }

    function getState() {
        return currentState;
    }

    function clearIdempotencyKey() {
        standaloneIdempotencyKey = '';
        const form = document.getElementById('posForm');
        if (!form) {
            return;
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

    function rotateIdempotencyKey(action) {
        const scope = action === 'save'
            ? 'pos.order.save'
            : (action === 'print_receipt'
                ? 'pos.order.print'
                : (action === 'free_table' ? 'pos.table.free' : 'pos.order.pay'));

        const resolvedOrderId = resolveOrderId();
        const revisionPart = kitchenRevision > 0 ? String(kitchenRevision + 1) : '1';
        const orderPart = resolvedOrderId > 0 ? String(resolvedOrderId) : 'new';
        const key = createIdempotencyKey(scope + ':' + orderPart + ':' + revisionPart);
        standaloneIdempotencyKey = key;

        const form = document.getElementById('posForm');
        if (form) {
            let keyInput = form.querySelector('input[name="idempotency_key"]');
            if (!keyInput) {
                keyInput = document.createElement('input');
                keyInput.type = 'hidden';
                keyInput.name = 'idempotency_key';
                form.appendChild(keyInput);
            }
            keyInput.value = key;
            keyInput.dataset.action = action;
            keyInput.dataset.age = String(readCheckedAge());
            keyInput.dataset.orderId = String(resolvedOrderId);
            keyInput.dataset.revision = String(kitchenRevision);
        }

        return key;
    }

    function ensureFormIdempotencyKey(form, action) {
        if (!form) {
            form = document.getElementById('posForm');
        }

        if (form) {
            const keyInput = form.querySelector('input[name="idempotency_key"]');
            if (keyInput && keyInput.value) {
                return keyInput.value;
            }
        }

        if (standaloneIdempotencyKey) {
            return standaloneIdempotencyKey;
        }

        return rotateIdempotencyKey(action);
    }

    function getStandaloneIdempotencyKey() {
        return standaloneIdempotencyKey;
    }

    function init() {
        recomputeStateFromCart();
    }

    window.POSOrderDraft = {
        init: init,
        fingerprint: fingerprint,
        markDirty: markDirty,
        markSaving: markSaving,
        markSaved: markSaved,
        markSaveFailed: markSaveFailed,
        reset: reset,
        bootstrapSaved: bootstrapSaved,
        isDirty: isDirty,
        canSave: canSave,
        getState: getState,
        rotateIdempotencyKey: rotateIdempotencyKey,
        ensureFormIdempotencyKey: ensureFormIdempotencyKey,
        clearIdempotencyKey: clearIdempotencyKey,
        recomputeStateFromCart: recomputeStateFromCart,
        applySaveButtonState: applySaveButtonState,
        setFingerprintBuilder: setFingerprintBuilder,
        getStandaloneIdempotencyKey: getStandaloneIdempotencyKey
    };

    $(function () {
        init();
    });
})(window, window.jQuery);
