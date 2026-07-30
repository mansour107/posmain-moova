// متغيرات عامة
let currentOrder = {
    items: [],
    total: '0.00',
    discount: '0.00',
    net: '0.00',
    mutation_version: 0
};
let posTableRequestKeys = {};
let pendingTablePreparation = null;

function posTableMoneyApi() {
    if (!window.POSOrderApi
        || typeof window.POSOrderApi.decimalString !== 'function'
        || typeof window.POSOrderApi.lineTotalFromQuantityAndUnitPrice !== 'function') {
        throw new Error('POS_MONEY_API_UNAVAILABLE');
    }
    return window.POSOrderApi;
}

function posTableDecimalString(value, scale, fallback) {
    let raw = value === null || value === undefined || value === ''
        ? String(fallback === undefined ? '0' : fallback)
        : String(value);
    raw = raw.trim();
    if (/^\d+\.\d+$/.test(raw)) {
        raw = raw.replace(/0+$/, '').replace(/\.$/, '');
    }

    return posTableMoneyApi().decimalString(raw, scale, fallback === undefined ? '0' : fallback);
}

function posTableQuantity(value, fallback) {
    return posTableDecimalString(value, 6, fallback === undefined ? '0' : fallback);
}

function posTableUnitPrice(value) {
    return posTableDecimalString(value, 6, '0');
}

function posTableMoney(value) {
    return posTableDecimalString(value, 2, '0');
}

function posTableLineTotal(quantity, unitPrice) {
    return posTableMoneyApi().lineTotalFromQuantityAndUnitPrice(
        posTableQuantity(quantity),
        posTableUnitPrice(unitPrice)
    );
}

function posTableEscapeHtml(value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function posTableSerializableItems(items) {
    return items.map(function(item) {
        const serialized = {
            id: parseInt(item.id, 10) || 0,
            qty: posTableQuantity(item.qty, '1'),
            price: posTableUnitPrice(item.price),
            discount: posTableDecimalString(item.discount || '0', 6, '0'),
            note: String(item.note || '')
        };
        if (Array.isArray(item.modifiers)) {
            serialized.modifiers = item.modifiers;
        }
        if (Array.isArray(item.preparation_values)) {
            serialized.preparation_values = item.preparation_values;
        }

        return serialized;
    });
}

function touchTableOrderDraft() {
    if (window.POSOrderDraft && typeof window.POSOrderDraft.markDirty === 'function') {
        window.POSOrderDraft.markDirty();
    }
}

function buildTableOrderFingerprint() {
    const lines = currentOrder.items.map(function(item) {
        return {
            id: parseInt(item.id, 10) || 0,
            qty: posTableQuantity(item.qty, '1'),
            price: posTableUnitPrice(item.price),
            discount: posTableDecimalString(item.discount || '0', 6, '0'),
            note: String(item.note || ''),
            preparation_values: Array.isArray(item.preparation_values) ? item.preparation_values : []
        };
    }).sort(function(a, b) {
        if (a.id !== b.id) {
            return a.id - b.id;
        }
        return a.note.localeCompare(b.note);
    });

    return JSON.stringify({
        lines: lines,
        headdisc: posTableDecimalString(currentOrder.discount, 2, '0'),
        headplus: '0.00',
        headnet: posTableDecimalString(currentOrder.net, 2, '0'),
        age: 0,
        table_id: parseInt($('#selected_table_id').val() || 0, 10) || 0,
        order_id: parseInt($('#current_order_id').val() || 0, 10) || 0
    });
}

function bootstrapTableOrderDraft(response) {
    if (!window.POSOrderDraft || typeof window.POSOrderDraft.bootstrapSaved !== 'function') {
        return;
    }

    const order = response && response.order ? response.order : {};
    window.POSOrderDraft.bootstrapSaved({
        order_id: order.id || $('#current_order_id').val(),
        kitchen_revision: order.kitchen_revision || 0,
        mutation_version: order.mutation_version || 0
    });
}

function getPOSTableCsrfToken() {
    const tokenElement = document.querySelector('meta[name="posmain-csrf-token"]');
    return window.POSMAIN_CSRF_TOKEN || (tokenElement ? tokenElement.getAttribute('content') : '');
}

function attachPOSTableCsrfHeader(xhr) {
    const token = getPOSTableCsrfToken();
    if (token) {
        xhr.setRequestHeader(window.POSMAIN_CSRF_HEADER || 'X-CSRF-Token', token);
        xhr.setRequestHeader('X-POSMAIN-CSRF-Token', token);
    }
}

function createPOSTableIdempotencyKey(scope) {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return scope + ':' + window.crypto.randomUUID();
    }

    return scope + ':' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2);
}

function getPOSTableIdempotencyKey(scope) {
    if (!posTableRequestKeys[scope]) {
        posTableRequestKeys[scope] = createPOSTableIdempotencyKey(scope);
    }

    return posTableRequestKeys[scope];
}

function clearPOSTableIdempotencyKey(scope) {
    delete posTableRequestKeys[scope];
}

// تحميل الطاولات عند بدء الصفحة
$(document).ready(function() {
    if (window.POSOrderDraft && typeof window.POSOrderDraft.setFingerprintBuilder === 'function') {
        window.POSOrderDraft.setFingerprintBuilder(buildTableOrderFingerprint);
        window.POSOrderDraft.init();
    }

    $('#payment-btn, #print-order, #cancel-order').prop('disabled', true);
    
    loadTables();
    loadItems();
    
    // الأحداث
    $('#disc_percent').on('input', calculateDiscount);
    $('#discount').on('input', calculateNet);
    $('#save-order').on('click', saveOrder);
    $('#payment-btn').on('click', openPayment);
    $('#print-order').on('click', printOrder);
    $('#cancel-order').on('click', cancelOrder);
    $('#item-search').on('input', searchItems);
    $(document).on('click', '.table-btn', function() {
        selectTable(
            parseInt($(this).attr('data-table-id') || '0', 10) || 0,
            String($(this).attr('data-table-name') || '')
        );
    });
    $(document).on('click', '.item-card', function() {
        addItemToOrder(
            parseInt($(this).attr('data-item-id') || '0', 10) || 0,
            String($(this).attr('data-item-name') || ''),
            String($(this).attr('data-item-price') || '0'),
            String($(this).attr('data-item-barcode') || ''),
            String($(this).attr('data-has-variants') || '') === '1',
            String($(this).attr('data-sugar-spoons') || '') === '1'
        );
    });
    
    // دعم الباركود
    $('#barcode-input').on('keypress', function(e) {
        if (e.which == 13) {
            e.preventDefault();
            const barcode = $(this).val();
            if (barcode) {
                addItemByBarcode(barcode);
                $(this).val('');
            }
        }
    });
    
    // تصفية الفئات
    $('.category-btn').on('click', function() {
        $('.category-btn').removeClass('active');
        $(this).addClass('active');
        const category = $(this).data('category');
        filterByCategory(category);
    });
});

// تحميل الطاولات
function loadTables() {
    $.ajax({
        url: 'ajax/get_tables.php',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displayTables(response.tables);
            } else {
                alert('خطأ في تحميل الطاولات');
            }
        },
        error: function() {
            alert('خطأ في الاتصال بالخادم');
        }
    });
}

// عرض الطاولات
function displayTables(tables) {
    let html = '';
    tables.forEach(function(table) {
        const statusClass = table.table_case == 0 ? 'table-available' : 'table-occupied';
        const tableId = parseInt(table.id || 0, 10) || 0;
        const tableName = posTableEscapeHtml(table.tname || '');
        html += `
            <button type="button" class="btn table-btn ${statusClass}" data-table-id="${tableId}" data-table-name="${tableName}">
                ${tableName}
            </button>
        `;
    });
    $('#tables-container').html(html);
}

// اختيار طاولة
function selectTable(tableId, tableName) {
    // تحديث واجهة المستخدم
    $('.table-btn').removeClass('table-selected');
    $(`.table-btn[data-table-id="${tableId}"]`).addClass('table-selected');
    
    $('#selected_table_id').val(tableId);
    $('#table_name').val(tableName);
    
    // تحميل بيانات الطلب إن وجد
    loadTableOrder(tableId, tableName);
    
    // تمكين أزرار العمليات (حالة زر الحفظ يديرها POSOrderDraft)
    $('#payment-btn, #print-order, #cancel-order').prop('disabled', false);
}

// تحميل طلب الطاولة
function loadTableOrder(tableId, tableName) {
    $.ajax({
        url: 'ajax/get_table_order.php',
        method: 'GET',
        data: { table_id: tableId, table_name: tableName },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.order) {
                // تحميل الطلب الموجود
                $('#current_order_id').val(response.order.id);
                currentOrder.mutation_version = parseInt(response.order.mutation_version || 1, 10) || 1;
                currentOrder.items = (response.items || []).map(function(item) {
                    return Object.assign({}, item, {
                        qty: posTableQuantity(item.qty, '1'),
                        price: posTableUnitPrice(item.price),
                        subtotal: posTableMoney(item.subtotal)
                    });
                });
                displayOrderItems();
                calculateTotal();
                bootstrapTableOrderDraft(response);
            } else {
                // طلب جديد
                $('#current_order_id').val('');
                currentOrder.mutation_version = 0;
                currentOrder.items = [];
                displayOrderItems();
                if (window.POSOrderDraft && typeof window.POSOrderDraft.reset === 'function') {
                    window.POSOrderDraft.reset();
                }
            }
        }
    });
}

// تحميل الأصناف
function loadItems() {
    $.ajax({
        url: 'ajax/get_items.php',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displayItems(response.items);
            }
        }
    });
}

// عرض الأصناف
function displayItems(items) {
    let html = '';
    items.forEach(function(item) {
        const price = posTableUnitPrice(item.price1 || '0');
        const displayedPrice = posTableLineTotal('1.000000', price);
        const itemId = parseInt(item.id || 0, 10) || 0;
        const itemName = posTableEscapeHtml(item.iname || '');
        const barcode = posTableEscapeHtml(item.barcode || '');
        const category = posTableEscapeHtml(item.group1 || '');
        const hasVariants = item.has_variants ? '1' : '0';
        const allowsSugarSpoons = item.allows_sugar_spoons ? '1' : '0';
        html += `
            <div class="col-md-4 col-lg-3 mb-3">
                <div class="card item-card"
                    data-category="${category}"
                    data-item-id="${itemId}"
                    data-item-name="${itemName}"
                    data-item-price="${price}"
                    data-item-barcode="${barcode}"
                    data-has-variants="${hasVariants}"
                    data-sugar-spoons="${allowsSugarSpoons}">
                    <div class="card-body text-center">
                        <i class="fas fa-utensils fa-2x mb-2"></i>
                        <h6>${itemName}</h6>
                        <p class="mb-0 text-success font-weight-bold">${item.has_variants ? 'اختيارات' : displayedPrice + ' ج'}</p>
                    </div>
                </div>
            </div>
        `;
    });
    $('#items-container').html(html);
}

// إضافة صنف للطلب
function addItemToOrder(itemId, itemName, price, barcode, hasVariants, sugarAllowed) {
    const hasKnownNormalHint = hasVariants === false || hasVariants === 0 || String(hasVariants || '') === '0' || String(hasVariants || '').toLowerCase() === 'false';
    if (hasKnownNormalHint) {
        addSellableItemToOrder(itemId, itemName, price, barcode, { sugarAllowed: !!sugarAllowed });
        return;
    }

    $.ajax({
        url: 'ajax/get_item_variants.php',
        method: 'GET',
        dataType: 'json',
        data: { item_id: itemId },
        success: function(response) {
            const variants = response && response.success && Array.isArray(response.variants) ? response.variants : [];
            if (variants.length > 0) {
                showTableVariantPicker(itemName, variants);
                return;
            }
            addSellableItemToOrder(itemId, itemName, price, barcode, { sugarAllowed: !!sugarAllowed });
        },
        error: function() {
            addSellableItemToOrder(itemId, itemName, price, barcode, { sugarAllowed: !!sugarAllowed });
        }
    });
}

function showTableVariantPicker(parentName, variants) {
    let html = variants.map(function(variant) {
        const name = String(variant.name || variant.iname || variant.variant_label || '');
        const label = String(variant.variant_label || name);
        const price = posTableUnitPrice(variant.price1 || variant.price || '0');
        const displayedPrice = posTableLineTotal('1.000000', price);
        return `<button type="button" class="btn btn-outline-primary btn-block text-right mb-2 tableVariantChoice"
                    data-item-id="${parseInt(variant.item_id || variant.variant_item_id || 0, 10) || 0}"
                    data-item-name="${posTableEscapeHtml(name)}"
                    data-item-price="${price}"
                    data-item-barcode="${posTableEscapeHtml(variant.barcode || '')}"
                    data-sugar-spoons="${variant.allows_sugar_spoons ? '1' : '0'}">
                    <span class="font-weight-bold">${posTableEscapeHtml(label)}</span>
                    <span class="float-left text-success">${displayedPrice} ج</span>
                </button>`;
    }).join('');

    if ($('#tableVariantModal').length === 0) {
        $('body').append(`
            <div class="modal" id="tableVariantModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title" id="tableVariantTitle"></h5>
                            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body" id="tableVariantChoices"></div>
                    </div>
                </div>
            </div>
        `);
    }

    $('#tableVariantTitle').text(parentName);
    $('#tableVariantChoices').html(html);
    $('#tableVariantModal').modal('show');
}

$(document).on('click', '.tableVariantChoice', function() {
    addSellableItemToOrder(
        $(this).data('item-id'),
        $(this).attr('data-item-name'),
        $(this).attr('data-item-price'),
        $(this).attr('data-item-barcode'),
        { sugarAllowed: String($(this).attr('data-sugar-spoons') || '') === '1' }
    );
    $('#tableVariantModal').modal('hide');
});

function normalizeTableSugarSpoons(value) {
    const raw = String(value === null || value === undefined ? '' : value).trim();
    if (!/^\d+$/.test(raw)) {
        throw new Error('PREPARATION_VALUE_INVALID');
    }
    const normalized = parseInt(raw, 10);
    if (normalized < 0 || normalized > 999) {
        throw new Error('PREPARATION_VALUE_OUT_OF_RANGE');
    }
    return normalized;
}

function tablePreparationLabel(values) {
    if (!Array.isArray(values)) {
        return '';
    }
    const sugar = values.find(function(value) {
        return String(value.code || value.field_code || '') === 'sugar_spoons';
    });
    if (!sugar) {
        return '';
    }
    const count = normalizeTableSugarSpoons(
        sugar.value !== undefined ? sugar.value : sugar.selected_value
    );
    return '<small class="d-block text-muted">السكر: '
        + (count === 0 ? 'بدون' : count + ' ملعقة')
        + '</small>';
}

function ensureTablePreparationModal() {
    if ($('#tablePreparationModal').length > 0) {
        return;
    }
    $('body').append(`
        <div class="modal" id="tablePreparationModal" tabindex="-1" aria-labelledby="tablePreparationTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="tablePreparationTitle">عدد ملاعق السكر</h5>
                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                    </div>
                    <div class="modal-body text-center">
                        <div id="tablePreparationItemName" class="font-weight-bold mb-3"></div>
                        <label for="tableSugarSpoonsValue">اختر العدد صراحة، ويُسمح بصفر</label>
                        <input type="number" id="tableSugarSpoonsValue" class="form-control form-control-lg text-center"
                               value="0" min="0" max="999" step="1" inputmode="numeric">
                        <div id="tablePreparationError" class="text-danger mt-2" aria-live="polite"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-dismiss="modal">إلغاء</button>
                        <button type="button" class="btn btn-success" id="tablePreparationConfirm">إضافة للصنف</button>
                    </div>
                </div>
            </div>
        </div>
    `);
}

function openTablePreparationModal(context) {
    ensureTablePreparationModal();
    pendingTablePreparation = context;
    $('#tablePreparationItemName').text(context.itemName || '');
    $('#tableSugarSpoonsValue').val('0');
    $('#tablePreparationError').text('');
    $('#tablePreparationModal').modal('show');
}

$(document).on('click', '#tablePreparationConfirm', function() {
    if (!pendingTablePreparation) {
        return;
    }
    let sugarSpoons;
    try {
        sugarSpoons = normalizeTableSugarSpoons($('#tableSugarSpoonsValue').val());
    } catch (error) {
        $('#tablePreparationError').text('أدخل عدداً صحيحاً من صفر إلى 999.');
        $('#tableSugarSpoonsValue').focus();
        return;
    }
    const context = pendingTablePreparation;
    pendingTablePreparation = null;
    $('#tablePreparationModal').modal('hide');
    addSellableItemToOrder(
        context.itemId,
        context.itemName,
        context.price,
        context.barcode,
        Object.assign({}, context.options, { sugarSpoons: sugarSpoons })
    );
});

$(document).on('hidden.bs.modal', '#tablePreparationModal', function() {
    pendingTablePreparation = null;
});

function addSellableItemToOrder(itemId, itemName, price, barcode, options) {
    options = options || {};
    if (options.sugarAllowed && !Object.prototype.hasOwnProperty.call(options, 'sugarSpoons')) {
        openTablePreparationModal({
            itemId: itemId,
            itemName: itemName,
            price: price,
            barcode: barcode,
            options: options
        });
        return;
    }
    const preparationValues = Object.prototype.hasOwnProperty.call(options, 'sugarSpoons')
        ? [{ code: 'sugar_spoons', value: normalizeTableSugarSpoons(options.sugarSpoons) }]
        : [];
    const preparationFingerprint = JSON.stringify(preparationValues);
    // التحقق من وجود الصنف
    const existingItem = currentOrder.items.find(function(item) {
        return item.id == itemId
            && JSON.stringify(Array.isArray(item.preparation_values) ? item.preparation_values : []) === preparationFingerprint;
    });
    
    if (existingItem) {
        // زيادة الكمية
        existingItem.qty = posTableMoneyApi().addDecimalStrings(
            posTableQuantity(existingItem.qty),
            '1.000000',
            6
        );
        existingItem.subtotal = posTableLineTotal(existingItem.qty, existingItem.price);
    } else {
        // إضافة صنف جديد
        const unitPrice = posTableUnitPrice(price);
        currentOrder.items.push({
            id: itemId,
            name: itemName,
            price: unitPrice,
            qty: '1.000000',
            subtotal: posTableLineTotal('1.000000', unitPrice),
            barcode: barcode,
            preparation_values: preparationValues
        });
    }
    
    displayOrderItems();
    calculateTotal();
}

// عرض أصناف الطلب
function displayOrderItems() {
    if (currentOrder.items.length == 0) {
        $('#items-tbody').html('<tr><td colspan="5" class="text-center text-muted">لا توجد أصناف</td></tr>');
        return;
    }
    
    let html = '';
    currentOrder.items.forEach(function(item, index) {
        const quantity = posTableQuantity(item.qty, '1');
        const unitPrice = posTableUnitPrice(item.price);
        const subtotal = posTableMoney(item.subtotal);
        html += `
            <tr>
                <td>
                    ${posTableEscapeHtml(item.name || '')}
                    ${tablePreparationLabel(item.preparation_values)}
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm" value="${quantity}"
                           onchange="updateItemQty(${index}, this.value)" min="0.000001" step="0.000001" style="width:90px">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm" value="${unitPrice}"
                           onchange="updateItemPrice(${index}, this.value)" min="0" step="0.000001" style="width:100px">
                </td>
                <td>${subtotal}</td>
                <td>
                    <button class="btn btn-sm btn-danger" onclick="removeItem(${index})">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    $('#items-tbody').html(html);
}

// تحديث كمية الصنف
function updateItemQty(index, qty) {
    qty = posTableQuantity(qty, '1');
    if (posTableMoneyApi().compareDecimalStrings(qty, '0.000000', 6) <= 0) {
        qty = '1.000000';
    }
    currentOrder.items[index].qty = qty;
    currentOrder.items[index].subtotal = posTableLineTotal(qty, currentOrder.items[index].price);
    displayOrderItems();
    calculateTotal();
}

// تحديث سعر الصنف
function updateItemPrice(index, price) {
    price = posTableUnitPrice(price);
    currentOrder.items[index].price = price;
    currentOrder.items[index].subtotal = posTableLineTotal(currentOrder.items[index].qty, price);
    displayOrderItems();
    calculateTotal();
}

// حذف صنف
function removeItem(index) {
    currentOrder.items.splice(index, 1);
    displayOrderItems();
    calculateTotal();
}

// حساب الإجمالي
function calculateTotal() {
    let total = '0.00';
    currentOrder.items.forEach(function(item) {
        total = posTableMoneyApi().addDecimalStrings(total, posTableMoney(item.subtotal), 2);
    });
    
    currentOrder.total = total;
    $('#total').val(currentOrder.total);
    calculateNet();
}

// حساب الخصم
function calculateDiscount() {
    const total = posTableMoney($('#total').val());
    let discPercent = posTableDecimalString($('#disc_percent').val(), 6, '0');
    if (posTableMoneyApi().compareDecimalStrings(discPercent, '100.000000', 6) > 0) {
        discPercent = '100.000000';
        $('#disc_percent').val(discPercent);
    }
    const discount = posTableMoneyApi().moneyFromPercentage(total, discPercent);
    $('#discount').val(discount);
    calculateNet();
}

// حساب الصافي
function calculateNet() {
    const total = posTableMoney($('#total').val());
    let discount = posTableMoney($('#discount').val());
    if (posTableMoneyApi().compareDecimalStrings(discount, total, 2) > 0) {
        discount = total;
        $('#discount').val(discount);
    }
    const net = posTableMoneyApi().subtractDecimalStrings(total, discount, 2);
    $('#net').val(net);
    currentOrder.net = posTableDecimalString(net, 2, '0');
    currentOrder.discount = posTableDecimalString(discount, 2, '0');
    touchTableOrderDraft();
}

// حفظ الطلب
function saveOrder() {
    const draft = window.POSOrderDraft;
    if (draft && !draft.canSave('save')) {
        return $.Deferred().reject('blocked').promise();
    }

    const tableId = $('#selected_table_id').val();
    const tableName = $('#table_name').val();
    
    if (!tableId) {
        alert('الرجاء اختيار طاولة');
        return $.Deferred().reject('no_table').promise();
    }
    
    if (currentOrder.items.length == 0) {
        alert('الرجاء إضافة أصناف للطلب');
        return $.Deferred().reject('no_items').promise();
    }
    
    const orderData = {
        table_id: tableId,
        table_name: tableName,
        order_id: $('#current_order_id').val(),
        mutation_version: currentOrder.mutation_version || null,
        order_date: $('#order_date').val(),
        store_id: $('#store_id').val(),
        emp_id: $('#emp_id').val(),
        fund_id: $('#fund_id').val(),
        items: posTableSerializableItems(currentOrder.items),
        total: posTableDecimalString(currentOrder.total, 2, '0'),
        discount: posTableDecimalString(currentOrder.discount, 2, '0'),
        net: posTableDecimalString(currentOrder.net, 2, '0'),
        idempotency_key: ''
    };

    if (draft) {
        draft.markSaving();
        draft.rotateIdempotencyKey('save');
        orderData.idempotency_key = draft.getStandaloneIdempotencyKey();
    } else {
        orderData.idempotency_key = getPOSTableIdempotencyKey('pos.table.save');
    }

    if (window.posCustomerState && window.posCustomerState.attached && window.posCustomerState.customerId) {
        orderData.pos_customer_id = window.posCustomerState.customerId;
    }

    $('#save-order').addClass('loading');

    return $.ajax({
        url: 'api/pos/index.php?route=orders.table',
        method: 'POST',
        data: JSON.stringify(orderData),
        contentType: 'application/json',
        dataType: 'json',
        beforeSend: attachPOSTableCsrfHeader
    }).done(function(response) {
        if (response.success) {
            $('#current_order_id').val(response.order_id);
            const state = response.updated_state || {};
            currentOrder.mutation_version = parseInt(state.mutation_version || currentOrder.mutation_version || 1, 10) || 1;
            if (draft && typeof draft.markSaved === 'function') {
                draft.markSaved(response);
            }
            loadTables();
        } else {
            if (draft && typeof draft.markSaveFailed === 'function') {
                draft.markSaveFailed();
            }
            alert('خطأ: ' + response.message);
        }
    }).fail(function(xhr) {
        if (draft && typeof draft.markSaveFailed === 'function') {
            draft.markSaveFailed();
        }
        const body = xhr && xhr.responseJSON ? xhr.responseJSON : {};
        if (body.code === 'STALE_ORDER_VERSION') {
            alert('تم تعديل الطلب من جهاز آخر. أعد تحميل الطلب قبل الحفظ.');
            return;
        }
        alert('خطأ في حفظ الطلب');
    }).always(function() {
        $('#save-order').removeClass('loading');
    });
}

// فتح مودال السداد
function openPayment() {
    const tableId = $('#selected_table_id').val();
    const tableName = $('#table_name').val();
    const orderId = $('#current_order_id').val();
    const draft = window.POSOrderDraft;
    
    if (!tableId) {
        alert('الرجاء اختيار طاولة');
        return;
    }
    
    // Save only a new or dirty order. Calling saveOrder() for a clean saved
    // draft is intentionally rejected by POSOrderDraft and would otherwise
    // leave the payment button doing nothing.
    const needsSave = currentOrder.items.length > 0
        && (!orderId || !draft || (typeof draft.isDirty === 'function' && draft.isDirty()));
    if (needsSave) {
        saveOrder().done(function(response) {
            if (response && response.success) {
                openPaymentModal(tableId, tableName);
            }
        });
        return;
    }
    if (orderId) {
        openPaymentModal(tableId, tableName);
        return;
    }

    alert('لا توجد أصناف للسداد');
}

// طباعة الطلب
function printOrder() {
    const orderId = $('#current_order_id').val();
    
    if (!orderId) {
        alert('لا يوجد طلب للطباعة');
        return;
    }
    
    window.open('print/table_invoice.php?invoice_id=' + orderId, '_blank');
}

// إلغاء الطلب
function cancelOrder() {
    const tableId = $('#selected_table_id').val();
    const orderId = $('#current_order_id').val();
    
    if (!tableId || !orderId) {
        alert('لا يوجد طلب للإلغاء');
        return;
    }
    
    if (confirm('هل تريد إلغاء الطلب نهائياً؟\nسيتم حذف جميع الأصناف ولا يمكن التراجع عن هذا الإجراء.')) {
        $.ajax({
            url: 'ajax/delete_order.php',
            method: 'POST',
            data: { 
                order_id: orderId,
                table_id: tableId,
                mutation_version: currentOrder.mutation_version || null,
                idempotency_key: getPOSTableIdempotencyKey('pos.order.cancel')
            },
            dataType: 'json',
            beforeSend: attachPOSTableCsrfHeader,
            success: function(response) {
                if (response.success) {
                    alert('تم إلغاء الطلب بنجاح');
                    currentOrder.items = [];
                    currentOrder.mutation_version = 0;
                    $('#current_order_id').val('');
                    clearPOSTableIdempotencyKey('pos.order.cancel');
                    displayOrderItems();
                    calculateTotal();
                    if (window.POSOrderDraft && typeof window.POSOrderDraft.reset === 'function') {
                        window.POSOrderDraft.reset();
                    }
                    loadTables(); // تحديث حالة الطاولات
                } else {
                    alert('خطأ: ' + response.message);
                }
            },
            error: function() {
                alert('خطأ في إلغاء الطلب');
            }
        });
    }
}

// البحث عن الأصناف
function searchItems() {
    const query = $('#item-search').val().toLowerCase();
    $('.item-card').each(function() {
        const itemName = $(this).find('h6').text().toLowerCase();
        if (itemName.includes(query)) {
            $(this).parent().show();
        } else {
            $(this).parent().hide();
        }
    });
}

// تصفية حسب الفئة
function filterByCategory(category) {
    if (category == 'all') {
        $('.item-card').parent().show();
    } else {
        $('.item-card').each(function() {
            if ($(this).data('category') == category) {
                $(this).parent().show();
            } else {
                $(this).parent().hide();
            }
        });
    }
}

// إضافة صنف بالباركود
function addItemByBarcode(barcode) {
    $.ajax({
        url: 'js/ajax/getbycode.php',
        method: 'GET',
        data: { barcode: barcode },
        dataType: 'json',
        success: function(response) {
            if (response.error) {
                alert('لا يوجد صنف بهذا الباركود');
            } else {
                addItemToOrder(
                    response.id,
                    response.iname,
                    response.price1,
                    response.barcode
                );
            }
        },
        error: function() {
            alert('خطأ في البحث عن الصنف');
        }
    });
}

// مسح الطلب (للاستخدام الداخلي)
function clearOrder() {
    currentOrder.items = [];
    currentOrder.mutation_version = 0;
    $('#current_order_id').val('');
    displayOrderItems();
    calculateTotal();
}
