<?php
require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';

require_admin_or_permission('customers.manage', $conn);
$crmCsrfToken = csrf_token('customers_manage');

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
include __DIR__ . '/includes/sidebar.php';
?>

<link href="dist/css/pos_barcode.css?v=<?= (int) (@filemtime(__DIR__ . '/dist/css/pos_barcode.css') ?: 1) ?>" rel="stylesheet">

<div class="content-wrapper pos-customers-admin">
    <div class="crm-wrap">
        <div class="crm-hero">
            <h1 class="h4 mb-1">عملاء نقاط البيع</h1>
            <p class="mb-0 crm-subtitle">بحث، ترتيب، وتاريخ طلبات العملاء المرتبطين بالهاتف</p>
        </div>

        <div class="crm-kpis" id="crmKpis">
            <div class="crm-kpi"><span>إجمالي العملاء</span><strong id="kpiTotal">—</strong></div>
            <div class="crm-kpi"><span>جدد هذا الشهر</span><strong id="kpiNew">—</strong></div>
            <div class="crm-kpi"><span>نشطون (30 يوم)</span><strong id="kpiActive">—</strong></div>
            <div class="crm-kpi"><span>أعلى إنفاق</span><strong id="kpiTop">—</strong></div>
        </div>

        <div class="crm-panel">
            <div class="crm-toolbar">
                <input type="search" class="form-control" id="crmSearch" placeholder="بحث بالاسم أو رقم الهاتف">
                <select class="form-select" id="crmSort">
                    <option value="lifetime_paid">الإنفاق</option>
                    <option value="orders_count">عدد الطلبات</option>
                    <option value="last_order_at">آخر طلب</option>
                    <option value="display_name">الاسم</option>
                </select>
                <input type="number" class="form-control" id="crmMinSpend" placeholder="حد أدنى للإنفاق" min="0" step="1">
                <input type="number" class="form-control" id="crmMinOrders" placeholder="حد أدنى للطلبات" min="0" step="1">
                <input type="date" class="form-control" id="crmLastOrderFrom" title="آخر طلب من">
                <input type="date" class="form-control" id="crmLastOrderTo" title="آخر طلب إلى">
                <button type="button" class="btn btn-warning" id="crmRefreshBtn">تحديث</button>
            </div>
            <div class="table-responsive">
                <table class="table crm-table mb-0">
                    <thead>
                        <tr>
                            <th>العميل</th>
                            <th>الهاتف</th>
                            <th>الطلبات</th>
                            <th>إجمالي المدفوع</th>
                            <th>آخر طلب</th>
                        </tr>
                    </thead>
                    <tbody id="crmTableBody">
                        <tr><td colspan="5" class="text-center crm-empty-state py-4">جاري التحميل...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="crm-panel crm-detail d-none" id="crmDetail">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h2 class="h5 mb-0" id="crmDetailName">—</h2>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-danger" id="crmDeleteBtn">حذف العميل</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="crmDetailClose">إغلاق</button>
                </div>
            </div>
            <div class="crm-detail-grid">
                <div>
                    <div id="crmDetailProfile"></div>
                    <div class="mt-3 p-3 border rounded">
                        <h3 class="h6">دمج عميل مكرر</h3>
                        <p class="small text-muted mb-2">انقل هذا العميل (#<span id="crmMergeSourceId">—</span>) إلى عميل آخر (يبقى الهدف).</p>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control" id="crmMergeTargetPhone" placeholder="هاتف العميل الهدف">
                            <button type="button" class="btn btn-outline-secondary" id="crmMergeLookupBtn">بحث</button>
                        </div>
                        <div class="small text-muted mb-2" id="crmMergeTargetPreview">—</div>
                        <input type="hidden" id="crmMergeTargetId" value="">
                        <button type="button" class="btn btn-sm btn-warning" id="crmMergeBtn">دمج في العميل الهدف</button>
                    </div>
                </div>
                <div>
                    <h3 class="h6">سجل الطلبات</h3>
                    <div class="table-responsive">
                        <table class="table crm-table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>التاريخ</th>
                                    <th>النوع</th>
                                    <th>الصافي</th>
                                    <th>المدفوع</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="crmOrdersBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.POSMAIN_CRM_CSRF_TOKEN = <?= json_encode($crmCsrfToken, JSON_UNESCAPED_UNICODE) ?>;
(function () {
    let searchTimer = null;
    let activeCustomerId = 0;

    function money(v) {
        return (parseFloat(v) || 0).toFixed(2) + ' ج.م';
    }

    function crmPost(url, data, callback) {
        const payload = Object.assign({}, data || {}, { csrf_token: window.POSMAIN_CRM_CSRF_TOKEN });
        $.post(url, payload, callback, 'json');
    }

    function loadList() {
        const params = {
            q: $('#crmSearch').val() || '',
            sort: $('#crmSort').val() || 'lifetime_paid',
            min_spend: $('#crmMinSpend').val() || 0,
            min_orders: $('#crmMinOrders').val() || 0,
            last_order_from: $('#crmLastOrderFrom').val() || '',
            last_order_to: $('#crmLastOrderTo').val() || '',
            page: 1,
            per_page: 50
        };
        $.getJSON('ajax/pos_customers_admin_list.php', params, function (response) {
            if (!response || !response.success) {
                return;
            }
            const dash = response.dashboard || {};
            $('#kpiTotal').text(dash.total_customers || 0);
            $('#kpiNew').text(dash.new_this_month || 0);
            $('#kpiActive').text(dash.active_30d || 0);
            $('#kpiTop').text(dash.top_spender ? dash.top_spender.display_name : '—');

            const rows = (response.list && response.list.items) || [];
            if (!rows.length) {
                $('#crmTableBody').html('<tr><td colspan="5" class="text-center crm-empty-state py-4">لا توجد نتائج</td></tr>');
                return;
            }
            let html = '';
            rows.forEach(function (row) {
                html += '<tr data-id="' + row.id + '">' +
                    '<td>' + $('<div>').text(row.display_name).html() + '</td>' +
                    '<td>' + $('<div>').text(row.primary_phone || '—').html() + '</td>' +
                    '<td>' + (row.orders_count || 0) + '</td>' +
                    '<td>' + money(row.lifetime_paid) + '</td>' +
                    '<td>' + (row.last_order_at ? String(row.last_order_at).slice(0, 16) : '—') + '</td>' +
                    '</tr>';
            });
            $('#crmTableBody').html(html);
        });
    }

    function loadDetail(id) {
        activeCustomerId = id;
        $('#crmMergeSourceId').text(id);
        $('#crmMergeTargetId').val('');
        $.getJSON('ajax/pos_customers_admin_detail.php', { id: id }, function (response) {
            if (!response || !response.success) {
                return;
            }
            const customer = response.customer || {};
            $('#crmDetailName').text(customer.display_name || '—');
            let phones = (customer.phones || []).map(function (p) {
                return '<div>' + $('<div>').text(p.phone).html() + (p.is_primary ? ' ★' : '') + '</div>';
            }).join('');
            let addresses = (customer.addresses || []).map(function (a) {
                return '<div class="text-muted small">' + $('<div>').text(a.address_text).html() + '</div>';
            }).join('');
            $('#crmDetailProfile').html(
                '<p><strong>الهاتف الأساسي:</strong> ' + $('<div>').text(customer.primary_phone || '—').html() + '</p>' +
                '<p><strong>ملاحظات:</strong> ' + $('<div>').text(customer.notes || '—').html() + '</p>' +
                '<p><strong>إجمالي المدفوع:</strong> ' + money(customer.lifetime_paid) + '</p>' +
                '<p><strong>عدد الطلبات:</strong> ' + (customer.orders_count || 0) + '</p>' +
                '<div class="mt-2"><strong>الأرقام</strong>' + phones + '</div>' +
                (addresses ? '<div class="mt-2"><strong>العناوين</strong>' + addresses + '</div>' : '')
            );

            const orders = (response.orders && response.orders.items) || [];
            let ordersHtml = '';
            orders.forEach(function (order) {
                ordersHtml += '<tr>' +
                    '<td>' + (order.order_time ? String(order.order_time).slice(0, 16) : '—') + '</td>' +
                    '<td>' + $('<div>').text(order.order_type || '—').html() + '</td>' +
                    '<td>' + money(order.fat_net) + '</td>' +
                    '<td>' + money(order.paid_amount) + '</td>' +
                    '<td><a class="btn btn-sm btn-outline-warning" target="_blank" href="print/receipt.php?id=' + order.order_id + '">إيصال</a></td>' +
                    '</tr>';
            });
            $('#crmOrdersBody').html(ordersHtml || '<tr><td colspan="5" class="crm-empty-state text-center">لا توجد طلبات مرتبطة</td></tr>');
            $('#crmDetail').removeClass('d-none');
        });
    }

    $(document).ready(function () {
        loadList();
        $('#crmRefreshBtn').on('click', loadList);
        $('#crmSearch, #crmSort, #crmMinSpend, #crmMinOrders, #crmLastOrderFrom, #crmLastOrderTo').on('change input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(loadList, 300);
        });
        $(document).on('click', '#crmTableBody tr[data-id]', function () {
            loadDetail($(this).data('id'));
        });
        $('#crmDetailClose').on('click', function () {
            $('#crmDetail').addClass('d-none');
            activeCustomerId = 0;
        });
        $('#crmDeleteBtn').on('click', function () {
            if (!activeCustomerId || !confirm('حذف العميل؟ الطلبات السابقة تحتفظ بلقطة البيانات.')) {
                return;
            }
            crmPost('do/pos_customer_delete.php', { customer_id: activeCustomerId }, function (response) {
                if (response && response.success) {
                    $('#crmDetail').addClass('d-none');
                    activeCustomerId = 0;
                    loadList();
                    return;
                }
                alert((response && response.message) || 'تعذر حذف العميل');
            });
        });
        $('#crmMergeLookupBtn').on('click', function () {
            const phone = ($('#crmMergeTargetPhone').val() || '').trim();
            if (phone.length < 3) {
                alert('أدخل رقم هاتف للبحث');
                return;
            }
            $.getJSON('ajax/pos_customer_search.php', { phone: phone }, function (response) {
                if (response && response.success && response.exact) {
                    const customer = response.exact;
                    $('#crmMergeTargetId').val(customer.id);
                    $('#crmMergeTargetPreview').text('#' + customer.id + ' — ' + (customer.display_name || '') + ' — ' + (customer.primary_phone || customer.phone || ''));
                    return;
                }
                $('#crmMergeTargetId').val('');
                $('#crmMergeTargetPreview').text('لم يُعثر على عميل بهذا الرقم');
            });
        });
        $('#crmMergeBtn').on('click', function () {
            const targetId = parseInt($('#crmMergeTargetId').val(), 10) || 0;
            if (!activeCustomerId || targetId < 1) {
                alert('ابحث عن العميل الهدف بالهاتف أولاً');
                return;
            }
            if (targetId === activeCustomerId) {
                alert('لا يمكن دمج العميل مع نفسه');
                return;
            }
            const preview = $('#crmMergeTargetPreview').text();
            if (!confirm('دمج العميل #' + activeCustomerId + ' في ' + preview + '؟')) {
                return;
            }
            crmPost('do/pos_customer_merge.php', {
                source_id: activeCustomerId,
                target_id: targetId
            }, function (response) {
                if (response && response.success) {
                    alert('تم الدمج بنجاح');
                    loadDetail(targetId);
                    loadList();
                    return;
                }
                alert((response && response.message) || 'تعذر دمج العملاء');
            });
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
