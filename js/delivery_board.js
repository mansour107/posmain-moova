(function (window, $) {
    'use strict';
    if (!$) return;

    const statusColumns = window.DELIVERY_STATUS_COLUMNS || {};
    const nextStatus = window.DELIVERY_NEXT_STATUS || {};
    const csrfToken = window.DELIVERY_DISPATCH_CSRF || '';

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function channelLabel(channel) {
        if (channel === 'moova_delivery') return 'موفا';
        if (channel === 'cashier') return 'كاشير';
        return channel || '—';
    }

    function renderBoard(orders) {
        const grouped = {};
        Object.keys(statusColumns).forEach(function (status) {
            grouped[status] = [];
        });
        orders.forEach(function (order) {
            const status = order.delivery_status || 'pending';
            if (!grouped[status]) grouped[status] = [];
            grouped[status].push(order);
        });

        let html = '';
        Object.keys(statusColumns).forEach(function (status) {
            const label = statusColumns[status];
            const cards = (grouped[status] || []).map(function (order) {
                const next = nextStatus[status];
                const driver = order.metadata && order.metadata.driver_name
                    ? '<div class="small text-muted">السائق: ' + escapeHtml(order.metadata.driver_name) + '</div>'
                    : '';
                return '<div class="card delivery-board-card mb-2 shadow-sm" data-order-id="' + order.order_id + '">' +
                    '<div class="card-body p-2">' +
                    '<div class="d-flex justify-content-between align-items-start mb-1">' +
                    '<strong>#' + escapeHtml(order.pro_id) + '</strong>' +
                    '<span class="badge bg-secondary">' + escapeHtml(channelLabel(order.order_channel)) + '</span>' +
                    '</div>' +
                    '<div class="fw-bold">' + escapeHtml(order.customer_name || '—') + '</div>' +
                    '<div class="small"><a href="tel:' + escapeHtml(order.customer_phone) + '">' + escapeHtml(order.customer_phone || '') + '</a></div>' +
                    '<div class="small text-muted mb-1">' + escapeHtml(order.customer_address || '') + '</div>' +
                    '<div class="small">المنطقة: ' + escapeHtml(order.delivery_zone || '—') + ' | ' + Number(order.fat_net || 0).toFixed(2) + ' ج.م</div>' +
                    driver +
                    '<div class="d-flex gap-1 mt-2 flex-wrap">' +
                    (next ? '<button type="button" class="btn btn-sm btn-success delivery-advance-btn" data-order-id="' + order.order_id + '" data-next-status="' + next + '">تقدم</button>' : '') +
                    '<a class="btn btn-sm btn-outline-secondary" target="_blank" href="print/receipt.php?id=' + order.order_id + '">إيصال</a>' +
                    (status !== 'delivered' && status !== 'cancelled'
                        ? '<button type="button" class="btn btn-sm btn-outline-danger delivery-cancel-btn" data-order-id="' + order.order_id + '">إلغاء</button>'
                        : '') +
                    '</div></div></div>';
            }).join('');

            html += '<div class="col-lg-4 col-xl-2">' +
                '<div class="delivery-board-column h-100">' +
                '<div class="delivery-board-column__title">' + escapeHtml(label) +
                ' <span class="badge bg-light text-dark">' + (grouped[status] || []).length + '</span></div>' +
                '<div class="delivery-board-column__body">' + (cards || '<div class="text-muted small p-2">لا توجد طلبات</div>') + '</div>' +
                '</div></div>';
        });
        $('#deliveryBoardColumns').html(html);
    }

    function loadBoard() {
        $.getJSON('ajax/delivery_orders_list.php')
            .done(function (response) {
                if (response && response.success) {
                    renderBoard(response.orders || []);
                }
            });
    }

    function updateStatus(orderId, status, extra) {
        const payload = Object.assign({
            order_id: orderId,
            delivery_status: status,
            csrf_token: csrfToken,
        }, extra || {});
        return $.ajax({
            url: 'ajax/delivery_status_update.php',
            method: 'POST',
            dataType: 'json',
            data: payload,
        });
    }

    $(document).on('click', '.delivery-advance-btn', function () {
        const orderId = $(this).data('order-id');
        const next = $(this).data('next-status');
        if (next === 'picked_up') {
            const driverName = window.prompt('اسم السائق (اختياري):', '') || '';
            const driverPhone = window.prompt('هاتف السائق (اختياري):', '') || '';
            updateStatus(orderId, next, { driver_name: driverName, driver_phone: driverPhone }).always(loadBoard);
            return;
        }
        updateStatus(orderId, next).always(loadBoard);
    });

    $(document).on('click', '.delivery-cancel-btn', function () {
        const orderId = $(this).data('order-id');
        if (!window.confirm('إلغاء طلب الدليفري؟')) return;
        updateStatus(orderId, 'cancelled', { force: 1 }).always(loadBoard);
    });

    $('#deliveryBoardRefresh').on('click', loadBoard);
    $(document).ready(function () {
        loadBoard();
        setInterval(loadBoard, 30000);
    });
})(window, window.jQuery);
