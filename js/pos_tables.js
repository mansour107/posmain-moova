// متغيرات عامة
let currentOrder = {
    items: [],
    total: 0,
    discount: 0,
    net: 0
};

// تحميل الطاولات عند بدء الصفحة
$(document).ready(function() {
    // تعطيل الأزرار في البداية
    $('#save-order, #payment-btn, #print-order, #cancel-order').prop('disabled', true);
    
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
        html += `
            <button class="btn table-btn ${statusClass}" data-table-id="${table.id}" data-table-name="${table.tname}" onclick="selectTable(${table.id}, '${table.tname}')">
                ${table.tname}
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
    
    // تمكين أزرار العمليات
    $('#save-order, #payment-btn, #print-order, #cancel-order').prop('disabled', false);
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
                currentOrder.items = response.items || [];
                displayOrderItems();
                calculateTotal();
            } else {
                // طلب جديد
                $('#current_order_id').val('');
                currentOrder.items = [];
                displayOrderItems();
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
        html += `
            <div class="col-md-4 col-lg-3 mb-3">
                <div class="card item-card" data-category="${item.group1}" onclick="addItemToOrder(${item.id}, '${item.iname}', ${item.price1}, '${item.barcode}')">
                    <div class="card-body text-center">
                        <i class="fas fa-utensils fa-2x mb-2"></i>
                        <h6>${item.iname}</h6>
                        <p class="mb-0 text-success font-weight-bold">${parseFloat(item.price1).toFixed(2)} ج</p>
                    </div>
                </div>
            </div>
        `;
    });
    $('#items-container').html(html);
}

// إضافة صنف للطلب
function addItemToOrder(itemId, itemName, price, barcode) {
    // التحقق من وجود الصنف
    const existingItem = currentOrder.items.find(item => item.id == itemId);
    
    if (existingItem) {
        // زيادة الكمية
        existingItem.qty++;
        existingItem.subtotal = existingItem.qty * existingItem.price;
    } else {
        // إضافة صنف جديد
        currentOrder.items.push({
            id: itemId,
            name: itemName,
            price: parseFloat(price),
            qty: 1,
            subtotal: parseFloat(price),
            barcode: barcode
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
        html += `
            <tr>
                <td>${item.name}</td>
                <td>
                    <input type="number" class="form-control form-control-sm" value="${item.qty}" 
                           onchange="updateItemQty(${index}, this.value)" min="1" style="width:60px">
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm" value="${item.price.toFixed(2)}" 
                           onchange="updateItemPrice(${index}, this.value)" min="0" step="0.01" style="width:80px">
                </td>
                <td>${item.subtotal.toFixed(2)}</td>
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
    qty = parseInt(qty) || 1;
    currentOrder.items[index].qty = qty;
    currentOrder.items[index].subtotal = qty * currentOrder.items[index].price;
    displayOrderItems();
    calculateTotal();
}

// تحديث سعر الصنف
function updateItemPrice(index, price) {
    price = parseFloat(price) || 0;
    currentOrder.items[index].price = price;
    currentOrder.items[index].subtotal = currentOrder.items[index].qty * price;
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
    let total = 0;
    currentOrder.items.forEach(function(item) {
        total += item.subtotal;
    });
    
    currentOrder.total = total;
    $('#total').val(total.toFixed(2));
    calculateNet();
}

// حساب الخصم
function calculateDiscount() {
    const total = parseFloat($('#total').val()) || 0;
    const discPercent = parseFloat($('#disc_percent').val()) || 0;
    const discount = (total * discPercent / 100).toFixed(2);
    $('#discount').val(discount);
    calculateNet();
}

// حساب الصافي
function calculateNet() {
    const total = parseFloat($('#total').val()) || 0;
    const discount = parseFloat($('#discount').val()) || 0;
    const net = (total - discount).toFixed(2);
    $('#net').val(net);
    currentOrder.net = parseFloat(net);
    currentOrder.discount = discount;
}

// حفظ الطلب
function saveOrder() {
    const tableId = $('#selected_table_id').val();
    const tableName = $('#table_name').val();
    
    if (!tableId) {
        alert('الرجاء اختيار طاولة');
        return;
    }
    
    if (currentOrder.items.length == 0) {
        alert('الرجاء إضافة أصناف للطلب');
        return;
    }
    
    const orderData = {
        table_id: tableId,
        table_name: tableName,
        order_id: $('#current_order_id').val(),
        order_date: $('#order_date').val(),
        store_id: $('#store_id').val(),
        emp_id: $('#emp_id').val(),
        fund_id: $('#fund_id').val(),
        items: currentOrder.items,
        total: currentOrder.total,
        discount: currentOrder.discount,
        net: currentOrder.net
    };
    
    $('#save-order').addClass('loading').prop('disabled', true);
    
    $.ajax({
        url: 'ajax/save_order.php',
        method: 'POST',
        data: JSON.stringify(orderData),
        contentType: 'application/json',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert('تم حفظ الطلب بنجاح');
                $('#current_order_id').val(response.order_id);
                loadTables(); // تحديث حالة الطاولات
            } else {
                alert('خطأ: ' + response.message);
            }
        },
        error: function() {
            alert('خطأ في حفظ الطلب');
        },
        complete: function() {
            $('#save-order').removeClass('loading').prop('disabled', false);
        }
    });
}

// فتح مودال السداد
function openPayment() {
    const tableId = $('#selected_table_id').val();
    const tableName = $('#table_name').val();
    
    if (!tableId) {
        alert('الرجاء اختيار طاولة');
        return;
    }
    
    // حفظ الطلب أولاً قبل السداد
    if (currentOrder.items.length > 0) {
        saveOrder();
        // انتظار قليل لضمان حفظ الطلب
        setTimeout(function() {
            openPaymentModal(tableId, tableName);
        }, 500);
    } else {
        // التحقق من وجود طلب محفوظ للطاولة
        const orderId = $('#current_order_id').val();
        if (orderId) {
            openPaymentModal(tableId, tableName);
        } else {
            alert('لا توجد أصناف للسداد');
        }
    }
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
                table_id: tableId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    alert('تم إلغاء الطلب بنجاح');
                    currentOrder.items = [];
                    $('#current_order_id').val('');
                    displayOrderItems();
                    calculateTotal();
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
    $('#current_order_id').val('');
    displayOrderItems();
    calculateTotal();
}

