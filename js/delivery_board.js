(function (window, document) {
    'use strict';

    const columns = window.DELIVERY_STATUS_COLUMNS || {};
    const nextStatus = window.DELIVERY_NEXT_STATUS || {};
    const dispatchCsrf = window.DELIVERY_DISPATCH_CSRF || '';
    const operationsCsrf = window.DELIVERY_OPERATIONS_CSRF || '';
    const root = document.getElementById('deliveryBoardColumns');
    const filtersRoot = document.getElementById('deliveryBoardFilters');
    const summaryRoot = document.getElementById('deliveryBoardSummary');
    const paginationRoot = document.getElementById('deliveryBoardPagination');
    const searchInput = document.getElementById('deliveryBoardSearch');
    const alertBox = document.getElementById('deliveryBoardAlert');
    const refreshButton = document.getElementById('deliveryBoardRefresh');
    const pageSize = 12;
    let orders = [];
    let workers = [];
    let canAssign = false;
    let activeStatus = 'all';
    let searchTerm = '';
    let currentPage = 1;

    function escapeHtml(value) {
        const element = document.createElement('div');
        element.textContent = value == null ? '' : String(value);
        return element.innerHTML;
    }

    function moneyApi() {
        if (!window.POSOrderApi
            || typeof window.POSOrderApi.decimalString !== 'function'
            || typeof window.POSOrderApi.addDecimalStrings !== 'function') {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        return window.POSOrderApi;
    }

    function money(value) {
        return moneyApi().decimalString(value, 2, '0');
    }

    function channelLabel(channel) {
        return channel === 'moova_delivery' ? 'موفا' : (channel === 'cashier' ? 'الكاشير' : (channel || '—'));
    }

    function timeLabel(value) {
        if (!value) return '—';
        const parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return value;
        const today = new Date();
        const sameDay = parsed.toDateString() === today.toDateString();
        return new Intl.DateTimeFormat('ar-EG', sameDay
            ? { hour: 'numeric', minute: '2-digit' }
            : { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }
        ).format(parsed);
    }

    function actionLabel(status) {
        return ({
            accepted: 'قبول الطلب',
            preparing: 'بدء التحضير',
            ready: 'الطلب جاهز',
            picked_up: 'تسليم للعامل',
            delivered: 'تأكيد التسليم'
        })[status] || 'المرحلة التالية';
    }

    function friendlyError(message) {
        const labels = {
            DELIVERY_WORKER_REQUIRED_BEFORE_PICKUP: 'اختر عامل توصيل قبل تسليم الطلب له.',
            DELIVERY_WORKER_UNAVAILABLE: 'عامل التوصيل المحدد غير متاح حالياً.',
            DELIVERY_ASSIGNMENT_LOCKED: 'لا يمكن تغيير العامل بعد خروج الطلب للتوصيل.',
            DELIVERY_COD_AMOUNT_MUST_MATCH_REMAINING: 'مبلغ التحصيل يجب أن يطابق الرصيد المتبقي على الطلب.',
            DELIVERY_STATUS_TRANSITION_NOT_ALLOWED: 'لا يمكن نقل الطلب مباشرة إلى هذه المرحلة.'
        };
        return labels[message] || message || 'تعذر إتمام العملية';
    }

    function showError(message) {
        if (!alertBox) return;
        alertBox.textContent = friendlyError(message);
        alertBox.classList.remove('d-none');
        window.setTimeout(function () { alertBox.classList.add('d-none'); }, 5000);
    }

    function workerOptions(selected) {
        return '<option value="">غير معيّن</option>' + workers.map(function (worker) {
            const load = Number(worker.active_orders || 0);
            return '<option value="' + worker.id + '" ' + (Number(selected) === Number(worker.id) ? 'selected' : '') + '>'
                + escapeHtml(worker.name) + (load ? ' · ' + load + ' طلب' : '') + '</option>';
        }).join('');
    }

    function workerControl(order) {
        if (!canAssign) {
            return '<div class="delivery-worker-readonly"><span>عامل التوصيل</span><strong>'
                + escapeHtml(order.delivery_worker_name || 'لم يُعيّن بعد') + '</strong></div>';
        }
        const locked = ['picked_up', 'delivered'].indexOf(order.delivery_status) >= 0;
        return '<label class="delivery-worker-field"><span>عامل التوصيل</span><select class="delivery-worker-select" data-order-id="'
            + order.order_id + '" ' + (locked ? 'disabled' : '') + '>' + workerOptions(order.delivery_worker_id) + '</select></label>';
    }

    function card(order) {
        const next = nextStatus[order.delivery_status];
        const isCod = order.collection_mode === 'cod';
        const codBadge = isCod
            ? '<span class="delivery-chip delivery-chip--cod"><i class="fas fa-wallet"></i> تحصيل ' + money(order.cod_amount || order.fat_net) + '</span>'
            : '<span class="delivery-chip delivery-chip--paid"><i class="fas fa-check"></i> مدفوع</span>';
        return '<article class="delivery-order-card" data-order-id="' + order.order_id + '" data-mutation-version="' + Number(order.mutation_version || 1) + '" data-status="' + escapeHtml(order.delivery_status) + '">'
            + '<div class="delivery-order-card__top"><div><strong>#' + escapeHtml(order.pro_id) + '</strong><span>' + escapeHtml(channelLabel(order.order_channel)) + '</span></div><time>' + escapeHtml(timeLabel(order.order_time)) + '</time></div>'
            + '<div class="delivery-order-card__customer"><div class="delivery-customer-mark">' + escapeHtml((order.customer_name || 'ع').trim().charAt(0)) + '</div><div><h3>' + escapeHtml(order.customer_name || 'بدون اسم') + '</h3><a class="delivery-phone" href="tel:' + escapeHtml(order.customer_phone) + '">' + escapeHtml(order.customer_phone || 'لا يوجد هاتف') + '</a></div></div>'
            + '<p class="delivery-address"><i class="fas fa-map-marker-alt" aria-hidden="true"></i><span>' + escapeHtml(order.customer_address || 'لم يُسجل عنوان') + '</span></p>'
            + '<div class="delivery-order-card__meta"><span><small>المنطقة</small>' + escapeHtml(order.delivery_zone || 'غير محددة') + '</span><strong><small>الإجمالي</small>' + money(order.fat_net) + ' ج.م</strong></div>'
            + '<div class="delivery-chip-row">' + codBadge + '<span class="delivery-chip"><i class="fas fa-receipt"></i> ' + Number(order.line_count || 0) + ' أصناف</span><span class="delivery-status-pill">' + escapeHtml(columns[order.delivery_status] || order.delivery_status) + '</span></div>'
            + workerControl(order)
            + '<div class="delivery-order-card__actions">'
            + (next ? '<button class="delivery-primary delivery-advance" data-order-id="' + order.order_id + '" data-next="' + next + '">' + escapeHtml(actionLabel(next)) + '</button>' : '')
            + '<a href="print/receipt.php?id=' + order.order_id + '" target="_blank" rel="noopener"><i class="fas fa-print"></i> الإيصال</a>'
            + (['delivered', 'cancelled', 'failed'].indexOf(order.delivery_status) < 0 ? '<button class="delivery-cancel" data-order-id="' + order.order_id + '">إلغاء</button>' : '')
            + '</div></article>';
    }

    function statusCounts() {
        const counts = { all: orders.length };
        Object.keys(columns).forEach(function (status) { counts[status] = 0; });
        orders.forEach(function (order) { counts[order.delivery_status] = Number(counts[order.delivery_status] || 0) + 1; });
        return counts;
    }

    function renderSummary(counts) {
        if (!summaryRoot) return;
        const codTotal = orders.reduce(function (sum, order) {
            return order.collection_mode === 'cod'
                ? moneyApi().addDecimalStrings(sum, money(order.cod_amount || order.fat_net || '0'), 2)
                : sum;
        }, '0.00');
        const metrics = [
            ['كل الطلبات', counts.all, 'fas fa-layer-group'],
            ['بانتظار القبول', counts.pending || 0, 'far fa-clock'],
            ['جاهزة للخروج', counts.ready || 0, 'fas fa-box'],
            ['تحصيلات معلقة', money(codTotal) + ' ج.م', 'fas fa-wallet']
        ];
        summaryRoot.innerHTML = metrics.map(function (metric) {
            return '<div class="delivery-summary-item"><i class="' + metric[2] + '"></i><span>' + escapeHtml(metric[0]) + '<strong>' + escapeHtml(metric[1]) + '</strong></span></div>';
        }).join('');
    }

    function renderFilters(counts) {
        if (!filtersRoot) return;
        const statuses = [['all', 'الكل']].concat(Object.keys(columns).map(function (status) { return [status, columns[status]]; }));
        filtersRoot.innerHTML = statuses.map(function (entry) {
            const selected = activeStatus === entry[0];
            return '<button type="button" class="delivery-status-filter ' + (selected ? 'is-active' : '') + '" data-status="' + entry[0] + '" role="tab" aria-selected="' + (selected ? 'true' : 'false') + '"><span>' + escapeHtml(entry[1]) + '</span><b>' + Number(counts[entry[0]] || 0) + '</b></button>';
        }).join('');
    }

    function filteredOrders() {
        const needle = searchTerm.trim().toLocaleLowerCase('ar');
        return orders.filter(function (order) {
            if (activeStatus !== 'all' && order.delivery_status !== activeStatus) return false;
            if (!needle) return true;
            return [order.pro_id, order.customer_name, order.customer_phone, order.customer_address, order.delivery_zone, order.delivery_worker_name]
                .some(function (value) { return String(value || '').toLocaleLowerCase('ar').includes(needle); });
        });
    }

    function renderPagination(total) {
        if (!paginationRoot) return;
        const pages = Math.max(1, Math.ceil(total / pageSize));
        currentPage = Math.min(currentPage, pages);
        if (total <= pageSize) {
            paginationRoot.innerHTML = '';
            return;
        }
        paginationRoot.innerHTML = '<button type="button" data-page="' + (currentPage - 1) + '" ' + (currentPage === 1 ? 'disabled' : '') + '><i class="fas fa-chevron-right"></i> السابق</button>'
            + '<span>صفحة <strong>' + currentPage + '</strong> من ' + pages + '</span>'
            + '<button type="button" data-page="' + (currentPage + 1) + '" ' + (currentPage === pages ? 'disabled' : '') + '>التالي <i class="fas fa-chevron-left"></i></button>';
    }

    function render() {
        if (!root) return;
        const counts = statusCounts();
        const matching = filteredOrders();
        currentPage = Math.min(currentPage, Math.max(1, Math.ceil(matching.length / pageSize)));
        const start = (currentPage - 1) * pageSize;
        const visible = matching.slice(start, start + pageSize);
        renderSummary(counts);
        renderFilters(counts);
        root.innerHTML = visible.map(card).join('') || '<div class="delivery-empty-state"><i class="fas fa-motorcycle"></i><h3>لا توجد طلبات هنا</h3><p>' + (searchTerm ? 'جرّب كلمة بحث أخرى أو اعرض كل المراحل.' : 'ستظهر الطلبات الجديدة هنا فور تسجيلها.') + '</p></div>';
        renderPagination(matching.length);
    }

    async function request(url, options) {
        const response = await fetch(url, Object.assign({ headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } }, options || {}));
        const payload = await response.json().catch(function () { return {}; });
        if (!response.ok || !payload.success) throw new Error(payload.error || payload.message || 'REQUEST_FAILED');
        return payload;
    }

    async function load() {
        if (!root || root.classList.contains('is-loading')) return;
        root.classList.add('is-loading');
        if (refreshButton) refreshButton.classList.add('is-refreshing');
        try {
            const payload = await request('ajax/delivery_orders_list.php?limit=200');
            orders = payload.orders || [];
            workers = payload.workers || [];
            canAssign = Boolean(payload.can_assign);
            render();
        } catch (error) {
            showError(error.message);
        } finally {
            root.classList.remove('is-loading');
            if (refreshButton) refreshButton.classList.remove('is-refreshing');
        }
    }

    function post(url, data) {
        return request(url, { method: 'POST', body: new URLSearchParams(data) });
    }

    document.addEventListener('change', async function (event) {
        const select = event.target.closest('.delivery-worker-select');
        if (!select) return;
        select.disabled = true;
        try {
            await post('ajax/delivery_assign.php', { order_id: select.dataset.orderId, delivery_worker_id: select.value, csrf_token: operationsCsrf });
            await load();
        } catch (error) {
            showError(error.message);
            select.disabled = false;
        }
    });

    document.addEventListener('click', async function (event) {
        const filter = event.target.closest('.delivery-status-filter');
        if (filter) {
            activeStatus = filter.dataset.status || 'all';
            currentPage = 1;
            render();
            return;
        }
        const pager = event.target.closest('[data-page]');
        if (pager && pager.closest('#deliveryBoardPagination')) {
            currentPage = Math.max(1, Number(pager.dataset.page || 1));
            render();
            root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }
        const advance = event.target.closest('.delivery-advance');
        const cancel = event.target.closest('.delivery-cancel');
        if (!advance && !cancel) return;
        const button = advance || cancel;
        const orderCard = button.closest('.delivery-order-card');
        const mutationVersion = Number(orderCard ? orderCard.dataset.mutationVersion : 0);
        const targetStatus = advance ? advance.dataset.next : 'cancelled';
        const data = {
            order_id: button.dataset.orderId,
            delivery_status: targetStatus,
            mutation_version: mutationVersion,
            idempotency_key: 'delivery:' + targetStatus + ':' + button.dataset.orderId + ':v' + mutationVersion,
            csrf_token: dispatchCsrf
        };
        if (cancel && !window.confirm('إلغاء طلب التوصيل؟')) return;
        if (cancel) data.force = '1';
        if (advance && advance.dataset.next === 'delivered') {
            const codChip = orderCard.querySelector('.delivery-chip--cod');
            if (codChip) {
                const suggested = (codChip.textContent.match(/[\d.,]+/) || ['0'])[0].replace(',', '.');
                const collected = window.prompt('المبلغ الذي تم تحصيله من العميل', suggested);
                if (collected === null) return;
                data.cod_amount = collected;
            }
            const tip = window.prompt('إكرامية العامل (اختياري)', '0');
            if (tip === null) return;
            data.driver_tip = tip || '0';
        }
        button.disabled = true;
        try {
            await post('ajax/delivery_status_update.php', data);
            await load();
        } catch (error) {
            showError(error.message);
            button.disabled = false;
        }
    });

    if (searchInput) searchInput.addEventListener('input', function () {
        searchTerm = searchInput.value || '';
        currentPage = 1;
        render();
    });
    if (refreshButton) refreshButton.addEventListener('click', load);
    document.addEventListener('DOMContentLoaded', function () {
        load();
        window.setInterval(load, 30000);
    });
})(window, document);
