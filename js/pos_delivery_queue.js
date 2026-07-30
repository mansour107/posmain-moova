/**
 * Compact cashier delivery queue. Order creation remains owned by pos_delivery.js.
 */
(function (window, document, $) {
    'use strict';

    if (!$ || !window.bootstrap) {
        return;
    }

    const endpoint = 'ajax/pos_delivery_queue.php';
    const state = {
        orders: [],
        workers: [],
        filter: 'all',
        loading: false,
    };
    let pollTimer = null;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function money(value) {
        if (!window.POSOrderApi || typeof window.POSOrderApi.decimalString !== 'function') {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        return window.POSOrderApi.decimalString(value, 2, '0');
    }

    function moneyIsPositive(value) {
        return window.POSOrderApi.compareDecimalStrings(money(value), '0.00', 2) > 0;
    }

    function orderNumber(order) {
        return Number(order.pro_id || order.order_id || 0);
    }

    function statusGroup(order) {
        return order.delivery_status === 'picked_up' ? 'out' : 'waiting';
    }

    function statusLabel(order) {
        if (order.delivery_status === 'picked_up') {
            return 'مع المندوب';
        }
        if (order.delivery_status === 'ready') {
            return 'جاهز للخروج';
        }
        return 'في التجهيز';
    }

    function elapsedLabel(value) {
        const timestamp = Date.parse(String(value || '').replace(' ', 'T'));
        if (!Number.isFinite(timestamp)) {
            return '';
        }
        const minutes = Math.max(0, Math.floor((Date.now() - timestamp) / 60000));
        if (minutes < 1) return 'الآن';
        if (minutes < 60) return 'منذ ' + minutes + ' د';
        return 'منذ ' + Math.floor(minutes / 60) + ' س';
    }

    function courierLabel(order) {
        if (order.delivery_worker_name) {
            return order.delivery_worker_name;
        }
        if (order.metadata && order.metadata.driver_name) {
            return order.metadata.driver_name;
        }
        return '';
    }

    function friendlyError(code) {
        const labels = {
            DELIVERY_SCHEMA_MIGRATIONS_PENDING: 'يلزم تطبيق تحديثات التوصيل قبل متابعة الطلبات.',
            CSRF_INVALID: 'انتهت صلاحية الجلسة. حدّث الصفحة ثم حاول مرة أخرى.',
            DELIVERY_WORKER_REQUIRED_BEFORE_PICKUP: 'اختر مندوباً مسجلاً أو استخدم خيار مندوب خارجي.',
            DELIVERY_WORKER_UNAVAILABLE: 'هذا المندوب غير متاح حالياً. اختر مندوباً آخر.',
            DELIVERY_ASSIGNMENT_LOCKED: 'خرج الطلب بالفعل ولا يمكن تغيير المندوب.',
            DELIVERY_STATUS_TRANSITION_NOT_ALLOWED: 'حالة الطلب تغيّرت بالفعل. تم تحديث القائمة.',
            DELIVERY_FAILURE_REASON_REQUIRED: 'اكتب سبب تعذر التسليم.',
            DELIVERY_COD_AMOUNT_MUST_MATCH_REMAINING: 'قيمة التحصيل لا تطابق المتبقي على الطلب.',
            DELIVERY_ORDER_NOT_FOUND: 'الطلب غير موجود في هذا الفرع.',
        };
        return labels[code] || code || 'تعذر إتمام العملية الآن.';
    }

    function notifyError(message) {
        const text = friendlyError(message);
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({ icon: 'error', title: 'تعذر إتمام العملية', text: text, confirmButtonText: 'حسناً' });
            return;
        }
        window.alert(text);
    }

    async function request(url, options) {
        const response = await fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        }, options || {}));
        const body = await response.json().catch(function () { return {}; });
        if (!response.ok || !body.success) {
            throw new Error(body.code || body.error || body.message || 'DELIVERY_OPERATION_FAILED');
        }
        return body;
    }

    function post(data) {
        const payload = Object.assign({ csrf_token: window.POSMAIN_CSRF_TOKEN || '' }, data || {});
        return request(endpoint, { method: 'POST', body: new URLSearchParams(payload) });
    }

    function updateBadge(count) {
        const badge = document.getElementById('posDeliveryPendingBadge');
        if (!badge) return;
        const total = Math.max(0, Number(count || 0));
        badge.textContent = String(total);
        badge.classList.toggle('d-none', total === 0);
        badge.setAttribute('aria-label', total + ' طلب توصيل غير مكتمل');
    }

    function renderSummary(summary) {
        const values = {
            posDeliveryCountActive: summary.active || 0,
            posDeliveryCountWaiting: summary.waiting || 0,
            posDeliveryCountOut: summary.out || 0,
        };
        Object.keys(values).forEach(function (id) {
            const node = document.getElementById(id);
            if (node) node.textContent = String(values[id]);
        });
        updateBadge(summary.active || 0);
    }

    function orderRow(order) {
        const isOut = statusGroup(order) === 'out';
        const courier = courierLabel(order);
        const customer = order.customer_name || order.customer_phone || 'عميل دليفري';
        const secondary = [order.delivery_zone, elapsedLabel(order.order_time)].filter(Boolean).join(' · ');
        const courierHtml = courier
            ? '<span class="pos-delivery-order__courier"><i class="fas fa-motorcycle"></i>' + escapeHtml(courier) + '</span>'
            : '';
        const actions = isOut
            ? '<button type="button" class="pos-delivery-order__action is-delivered" data-delivery-action="delivered" data-order-id="' + order.order_id + '" data-mutation-version="' + Number(order.mutation_version || 1) + '"><i class="fas fa-check"></i> تم التسليم</button>'
                + '<button type="button" class="pos-delivery-order__icon-action" data-delivery-action="failed" data-order-id="' + order.order_id + '" data-mutation-version="' + Number(order.mutation_version || 1) + '" title="تعذر التسليم" aria-label="تعذر التسليم"><i class="fas fa-exclamation"></i></button>'
            : '<button type="button" class="pos-delivery-order__action is-dispatch" data-delivery-action="dispatch" data-order-id="' + order.order_id + '" data-mutation-version="' + Number(order.mutation_version || 1) + '"><i class="fas fa-motorcycle"></i> خرج للتوصيل</button>';

        return '<article class="pos-delivery-order" data-status-group="' + statusGroup(order) + '">'
            + '<div class="pos-delivery-order__top">'
            + '<span class="pos-delivery-order__number">#' + orderNumber(order) + '</span>'
            + '<span class="pos-delivery-order__status is-' + statusGroup(order) + '">' + statusLabel(order) + '</span>'
            + '<strong class="pos-delivery-order__amount">' + money(order.fat_net) + ' <small>ج.م</small></strong>'
            + '</div>'
            + '<div class="pos-delivery-order__main"><strong>' + escapeHtml(customer) + '</strong><span>' + escapeHtml(secondary) + '</span></div>'
            + '<div class="pos-delivery-order__bottom">' + courierHtml + '<div class="pos-delivery-order__actions">' + actions + '</div></div>'
            + '</article>';
    }

    function renderOrders() {
        const list = document.getElementById('posDeliveryOrderList');
        const queueState = document.getElementById('posDeliveryQueueState');
        if (!list || !queueState) return;
        const visible = state.orders.filter(function (order) {
            return state.filter === 'all' || statusGroup(order) === state.filter;
        });
        if (!visible.length) {
            list.classList.add('d-none');
            queueState.classList.remove('d-none');
            queueState.innerHTML = state.orders.length
                ? '<i class="fas fa-filter"></i><strong>لا توجد طلبات في هذه المرحلة</strong>'
                : '<i class="fas fa-check-circle"></i><strong>لا توجد طلبات توصيل معلّقة</strong>';
            return;
        }
        queueState.classList.add('d-none');
        list.classList.remove('d-none');
        list.innerHTML = visible.map(orderRow).join('');
    }

    function renderWorkers(selectedId) {
        const select = document.getElementById('posDeliveryWorkerSelect');
        if (!select) return;
        select.innerHTML = '<option value="">اختر المندوب</option>' + state.workers.map(function (worker) {
            return '<option value="' + worker.id + '"' + (Number(selectedId) === Number(worker.id) ? ' selected' : '') + '>'
                + escapeHtml(worker.name) + (Number(worker.active_orders || 0) ? ' · ' + worker.active_orders + ' طلب' : '') + '</option>';
        }).join('');
    }

    async function refresh(options) {
        if (state.loading) return;
        state.loading = true;
        const showLoading = options && options.showLoading;
        const queueState = document.getElementById('posDeliveryQueueState');
        if (showLoading && queueState) {
            queueState.classList.remove('d-none');
            queueState.textContent = 'جارٍ تحديث الطلبات…';
        }
        try {
            const data = await request(endpoint);
            state.orders = Array.isArray(data.orders) ? data.orders : [];
            state.workers = Array.isArray(data.workers) ? data.workers : [];
            renderSummary(data.summary || {});
            renderWorkers();
            renderOrders();
        } catch (error) {
            if (showLoading && queueState) {
                queueState.classList.remove('d-none');
                queueState.textContent = friendlyError(error.message);
            }
        } finally {
            state.loading = false;
        }
    }

    function findOrder(orderId) {
        return state.orders.find(function (order) { return Number(order.order_id) === Number(orderId); }) || null;
    }

    function setFormBusy(form, busy) {
        Array.from(form.elements).forEach(function (element) { element.disabled = busy; });
        form.classList.toggle('is-busy', busy);
    }

    function openDispatch(orderId) {
        const order = findOrder(orderId);
        const form = document.getElementById('posDeliveryDispatchForm');
        const modal = document.getElementById('posDeliveryDispatchModal');
        if (!form || !modal || !order) return;
        form.reset();
        form.elements.order_id.value = String(orderId);
        renderWorkers(order.delivery_worker_id);
        if (!state.workers.length) {
            form.elements.worker_mode.value = 'external';
        }
        syncWorkerMode(form);
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
    }

    function syncWorkerMode(form) {
        const mode = form.elements.worker_mode.value;
        form.querySelectorAll('[data-worker-mode-panel]').forEach(function (panel) {
            panel.classList.toggle('d-none', panel.getAttribute('data-worker-mode-panel') !== mode);
        });
        const select = form.elements.delivery_worker_id;
        if (select) select.required = mode === 'registered';
    }

    function openFailure(orderId) {
        const form = document.getElementById('posDeliveryFailureForm');
        const modal = document.getElementById('posDeliveryFailureModal');
        if (!form || !modal) return;
        form.reset();
        form.elements.order_id.value = String(orderId);
        window.bootstrap.Modal.getOrCreateInstance(modal).show();
        modal.addEventListener('shown.bs.modal', function focusReason() {
            modal.removeEventListener('shown.bs.modal', focusReason);
            form.elements.reason.focus();
        });
    }

    async function markDelivered(orderId, button) {
        const order = findOrder(orderId);
        if (!order) return;
        let confirmed = false;
        if (window.Swal && typeof window.Swal.fire === 'function') {
            const result = await window.Swal.fire({
                icon: 'question',
                title: 'تأكيد تسليم الطلب #' + orderNumber(order),
                text: moneyIsPositive(order.remaining_amount || '0')
                    ? 'سيتم إثبات التحصيل وتحديث حساب المندوب.'
                    : 'سيتم إغلاق طلب التوصيل وتحديث حساب المندوب.',
                showCancelButton: true,
                confirmButtonText: 'تم التسليم',
                cancelButtonText: 'رجوع',
                confirmButtonColor: '#20c997',
            });
            confirmed = !!result.isConfirmed;
        } else {
            confirmed = window.confirm('تأكيد تسليم الطلب؟');
        }
        if (!confirmed) return;
        button.disabled = true;
        try {
            await post({
                action: 'delivered',
                order_id: orderId,
                mutation_version: order.mutation_version,
                idempotency_key: 'delivery:delivered:' + orderId + ':v' + order.mutation_version
            });
            await refresh({ showLoading: false });
        } catch (error) {
            notifyError(error.message);
            await refresh({ showLoading: false });
        } finally {
            button.disabled = false;
        }
    }

    function bindEvents() {
        const badge = document.getElementById('posDeliveryPendingBadge');
        if (badge) {
            badge.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                window.bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('posDeliveryQueue')).show();
                refresh({ showLoading: true });
            });
        }

        document.querySelectorAll('[data-delivery-filter]').forEach(function (button) {
            button.addEventListener('click', function () {
                state.filter = button.getAttribute('data-delivery-filter') || 'all';
                document.querySelectorAll('[data-delivery-filter]').forEach(function (item) {
                    item.classList.toggle('is-active', item === button);
                });
                renderOrders();
            });
        });

        document.addEventListener('click', function (event) {
            const button = event.target.closest('[data-delivery-action]');
            if (!button) return;
            const orderId = Number(button.getAttribute('data-order-id') || 0);
            if (!orderId) return;
            const action = button.getAttribute('data-delivery-action');
            if (action === 'dispatch') openDispatch(orderId);
            if (action === 'delivered') markDelivered(orderId, button);
            if (action === 'failed') openFailure(orderId);
        });

        const dispatchForm = document.getElementById('posDeliveryDispatchForm');
        if (dispatchForm) {
            dispatchForm.addEventListener('change', function (event) {
                if (event.target.name === 'worker_mode') syncWorkerMode(dispatchForm);
            });
            dispatchForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                const data = Object.fromEntries(new FormData(dispatchForm).entries());
                data.action = 'dispatch';
                const order = findOrder(data.order_id);
                data.mutation_version = order ? order.mutation_version : '';
                data.idempotency_key = 'delivery:dispatch:' + data.order_id + ':v' + data.mutation_version;
                setFormBusy(dispatchForm, true);
                try {
                    await post(data);
                    window.bootstrap.Modal.getInstance(document.getElementById('posDeliveryDispatchModal')).hide();
                    await refresh({ showLoading: false });
                } catch (error) {
                    notifyError(error.message);
                    await refresh({ showLoading: false });
                } finally {
                    setFormBusy(dispatchForm, false);
                }
            });
        }

        const failureForm = document.getElementById('posDeliveryFailureForm');
        if (failureForm) {
            failureForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                const data = Object.fromEntries(new FormData(failureForm).entries());
                data.action = 'failed';
                const order = findOrder(data.order_id);
                data.mutation_version = order ? order.mutation_version : '';
                data.idempotency_key = 'delivery:failed:' + data.order_id + ':v' + data.mutation_version;
                setFormBusy(failureForm, true);
                try {
                    await post(data);
                    window.bootstrap.Modal.getInstance(document.getElementById('posDeliveryFailureModal')).hide();
                    await refresh({ showLoading: false });
                } catch (error) {
                    notifyError(error.message);
                    await refresh({ showLoading: false });
                } finally {
                    setFormBusy(failureForm, false);
                }
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) refresh({ showLoading: false });
        });
    }

    window.posDeliveryQueueRefresh = refresh;
    document.addEventListener('DOMContentLoaded', function () {
        bindEvents();
        refresh({ showLoading: false });
        pollTimer = window.setInterval(function () { refresh({ showLoading: false }); }, 20000);
    });
    window.addEventListener('beforeunload', function () {
        if (pollTimer) window.clearInterval(pollTimer);
    });
})(window, document, window.jQuery);
