// الثوابت
const SELECTORS = {
    BARCODE_INPUT: '#barcodeInput',
    ITEM_DATA: '#itemData',
    TOTAL: '#total',
    DISCOUNT: '#discount',
    NET_VALUE: '#net_val',
    ITEM_SEARCH: '#itemSearch',
    CALC: '#calc'
};

// الدوال المساعدة
const updateTotal = () => {
    let total = 0;
    $('.subtotal').each(function() {
        total += parseFloat($(this).val());
    });
    $(SELECTORS.TOTAL).val(total.toFixed(2));
};

const updateNetValue = () => {
    const total = parseFloat($(SELECTORS.TOTAL).val());
    const discount = parseFloat($(SELECTORS.DISCOUNT).val());
    const netValue = total - discount;
    $(SELECTORS.NET_VALUE).val(netValue.toFixed(2));
};

const fetchData = (barcode) => {
    if (!barcode) {
        alert('الرجاء إدخال الباركود.');
        return;
    }

    $.ajax({
        url: 'js/ajax/getbycode.php',
        method: 'GET',
        data: { barcode },
        success: (response) => {
            if (response.error) {
                alert("لا يوجد صنف لهذا الباركود");
            } else {
                addOrUpdateRow(response);
                updateTotal();
                updateNetValue();
            }
        },
        error: (error) => {
            console.error('خطأ في جلب البيانات:', error);
            $(SELECTORS.ITEM_DATA).html('<tr><td colspan="6">خطأ في جلب البيانات. الرجاء المحاولة مرة أخرى.</td></tr>');
        }
    });
};

const addOrUpdateRow = (itemData) => {
    const barcode = itemData.barcode;
    const price = parseFloat(itemData.price1);

    if (isNaN(price)) {
        alert('تم استلام سعر غير صالح من الخادم.');
        return;
    }

    const $existingRow = $(`${SELECTORS.ITEM_DATA} tr[data-itemid="${barcode}"]`);

    if ($existingRow.length > 0) {
        updateExistingRow($existingRow, price);
    } else {
        addNewRow(itemData, price);
    }
};

const updateExistingRow = ($row, price) => {
    const $qtyInput = $row.find('.quantityInput');
    const currentQty = parseInt($qtyInput.val());
    const newQty = currentQty + 1;
    $qtyInput.val(newQty);
    const newSubtotal = newQty * price;
    $row.find('.subtotal').val(newSubtotal.toFixed(2));
};

const addNewRow = (itemData, price) => {
    const rownum = $(`${SELECTORS.ITEM_DATA} tr`).length + 1;
    const qty = 1;
    const subtotal = qty * price;
    const newRow = `
        <tr data-itemid="${itemData.barcode}">
            <td>${rownum}</td>
            <td class="barcode" hidden>${itemData.barcode}</td>
            <td class="iname"><input hidden value='${itemData.id}' name="itmname[]">${itemData.iname}</td>
            <td class="qty"><input type="number" class="cashInput quantityInput select-all nozero bg-slate-100" value="${qty}" name="itmqty[]"><input type="text" name="u_val[]" value="1" hidden></td>
            <td class="price"><input type="number" class="cashInput priceInput select-all nozero bg-slate-100" value="${price.toFixed(2)}" name="itmprice[]"> ج</td>
            <td><input hidden name="itmdisc[]"><input type="text" class="subtotal cashInput" readonly value="${subtotal.toFixed(2)}" name="itmval[]"></td>
            <td class="delRow"><button class="btn btn-danger">X</button></td>
        </tr>
    `;
    $(SELECTORS.ITEM_DATA).append(newRow);
};

// معالجات الأحداث
const handleItemButtonClick = function() {
    const barcode = $(this).attr('itemid');
    fetchData(barcode);
};

const handleItemSearch = function() {
    const query = this.value.toLowerCase();
    $('#items .cat').each(function() {
        const itemName = $(this).find('.itemname p:first-of-type').text().toLowerCase();
        $(this).toggle(itemName.includes(query));
    });
};

const handleQuantityPriceChange = function() {
    const $row = $(this).closest('tr');
    const qty = parseInt($row.find('.quantityInput').val());
    const price = parseFloat($row.find('.priceInput').val());
    const subtotal = qty * price;
    $row.find('.subtotal').val(subtotal.toFixed(2));
    updateTotal();
    updateNetValue();
};

const handleDeleteRow = function() {
    $(this).closest('tr').remove();
    updateTotal();
    updateNetValue();
};

const handleDiscountChange = () => {
    updateNetValue();
};

const handleCalcButtonClick = function() {
    const $calc = $(SELECTORS.CALC);
    const currentVal = $calc.val();
    const newVal = $(this).text();
    
    if (newVal === 'C') {
        $calc.val('');
    } else {
        $calc.val(currentVal + newVal);
    }
};

const handleBarcodeInput = function(event) {
    if (event.which === 13) {
        event.preventDefault();
        const barcode = $(this).val();
        fetchData(barcode);
        $(this).val('');
    }
};

// التهيئة
$(document).ready(() => {
    $('#items').on('click', '.itemButton', handleItemButtonClick);
    $(SELECTORS.ITEM_SEARCH).on('input', handleItemSearch);
    $(SELECTORS.ITEM_DATA).on('input', '.quantityInput, .priceInput', handleQuantityPriceChange);
    $(SELECTORS.ITEM_DATA).on('click', '.delRow button', handleDeleteRow);
    $(SELECTORS.DISCOUNT).on('input focusout', handleDiscountChange);
    $('#calcNum .btn-num').on('click', handleCalcButtonClick);
    $(SELECTORS.BARCODE_INPUT).on('keypress', handleBarcodeInput);

    updateTotal();
    updateNetValue();
    
    // إدارة الطاولات
    initTableSystem();
});

// دالة تصفية الفئات
function filterItemsByCategory(categoryId) {
    const items = document.querySelectorAll('#items .cat');
    items.forEach(function(item) {
        const itemCatId = item.querySelector('input[type="text"]').value;
        item.style.display = (categoryId === null || itemCatId == categoryId) ? 'block' : 'none';
    });
}

// ========================================
// نظام الطاولات
// ========================================

function initTableSystem() {
    // عند تغيير نوع الطلب
    $('input[name="age"]').on('change', function() {
        if ($('#age2').is(':checked')) {
            // نوع الطلب = طاولة
            $('#tablesModal').modal('show');
            loadTables();
        } else {
            // إخفاء معلومات الطاولة
            clearTableSelection();
        }
    });
    
    // إذا كان هناك طاولة محددة من URL
    const urlParams = new URLSearchParams(window.location.search);
    const tableName = urlParams.get('table');
    if (tableName) {
        $('#age2').prop('checked', true);
        $('#table_name').val(decodeURIComponent(tableName)).show();
        loadTableOrder(tableName);
    }
}

function loadTables() {
    $.ajax({
        url: 'ajax/get_tables.php',
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                displayTablesInModal(response.tables);
            } else {
                alert('خطأ في تحميل الطاولات');
            }
        },
        error: function() {
            $('#tables-grid').html('<p class="text-danger">خطأ في الاتصال بالخادم</p>');
        }
    });
}

function displayTablesInModal(tables) {
    let html = '';
    tables.forEach(function(table) {
        const statusClass = table.table_case == 0 ? 'table-available' : 'table-occupied';
        const statusIcon = table.table_case == 0 ? '✓' : '⚠';
        html += `
            <button class="btn table-modal-btn ${statusClass}" 
                    onclick="selectTable(${table.id}, '${table.tname}', ${table.table_case})">
                ${table.tname}<br>
                <small>${statusIcon}</small>
            </button>
        `;
    });
    $('#tables-grid').html(html);
}

function selectTable(tableId, tableName, tableCase) {
    // حفظ معلومات الطاولة
    $('#selected_table_id').val(tableId);
    $('#table_name').val(tableName).show();
    
    // إضافة مؤشر بصري
    if ($('.table-selected-indicator').length == 0) {
        $('#upRight0').prepend(`<span class="table-selected-indicator">📍 ${tableName}</span>`);
    } else {
        $('.table-selected-indicator').text(`📍 ${tableName}`);
    }
    
    // إغلاق Modal
    $('#tablesModal').modal('hide');
    
    // التحقق من وجود طلب نشط
    if (tableCase == 1) {
        if (confirm('هذه الطاولة مشغولة. هل تريد تحميل الطلب الموجود؟')) {
            loadTableOrder(tableName, tableId);
        }
    }
}

function loadTableOrder(tableName, tableId) {
    $.ajax({
        url: 'ajax/get_table_order.php',
        method: 'GET',
        data: { 
            table_name: tableName,
            table_id: tableId 
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.order) {
                // تحميل بيانات الطلب
                $('#current_order_id').val(response.order.id);
                
                // تحميل الأصناف
                $(SELECTORS.ITEM_DATA).empty();
                if (response.items && response.items.length > 0) {
                    response.items.forEach(function(item, index) {
                        const rownum = index + 1;
                        const newRow = `
                            <tr data-itemid="${item.barcode || item.id}">
                                <td>${rownum}</td>
                                <td class="barcode" hidden>${item.barcode || item.id}</td>
                                <td class="iname"><input hidden value='${item.id}' name="itmname[]">${item.name}</td>
                                <td class="qty"><input type="number" class="cashInput quantityInput select-all nozero bg-slate-100" value="${item.qty}" name="itmqty[]"><input type="text" name="u_val[]" value="1" hidden></td>
                                <td class="price"><input type="number" class="cashInput priceInput select-all nozero bg-slate-100" value="${item.price.toFixed(2)}" name="itmprice[]"> ج</td>
                                <td><input hidden name="itmdisc[]"><input type="text" class="subtotal cashInput" readonly value="${item.subtotal.toFixed(2)}" name="itmval[]"></td>
                                <td class="delRow"><button class="btn btn-danger">X</button></td>
                            </tr>
                        `;
                        $(SELECTORS.ITEM_DATA).append(newRow);
                    });
                }
                
                // تحديث الإجماليات
                updateTotal();
                updateNetValue();
                
                alert('تم تحميل الطلب بنجاح!');
            }
        },
        error: function() {
            console.error('خطأ في تحميل طلب الطاولة');
        }
    });
}

function clearTableSelection() {
    $('#selected_table_id').val('');
    $('#table_name').val('').hide();
    $('#current_order_id').val('');
    $('.table-selected-indicator').remove();
}

// دالة إغلاق الشيفت
function closeShift() {
    const expenses = $('#shift_expenses').val() || 0;
    const expNotes = $('#shift_exp_notes').val() || '';
    const cash = $('#shift_cash').val() || 0;
    const fundAfter = $('#shift_fund_after').val() || 0;
    const notes = $('#shift_notes').val() || '';
    
    console.log('Shift data:', { expenses, expNotes, cash, fundAfter, notes });
    
    // إنشاء form وإرسال البيانات
    const form = $('<form>', {
        method: 'POST',
        action: 'close_shift.php'
    });
    
    form.append($('<input>', { type: 'hidden', name: 'expenses', value: expenses }));
    form.append($('<input>', { type: 'hidden', name: 'exp_notes', value: expNotes }));
    form.append($('<input>', { type: 'hidden', name: 'cash', value: cash }));
    form.append($('<input>', { type: 'hidden', name: 'fund_after', value: fundAfter }));
    form.append($('<input>', { type: 'hidden', name: 'notes', value: notes }));
    
    $('body').append(form);
    form.submit();
}

