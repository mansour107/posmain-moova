let counter = 1;
let isProcessing = false; // منع التنفيذ المتعدد

$(document).ready(function() {
    initializeSelect2();
    handleItemSelectionChange();
    handleRowAddition();
    handleInputChanges();
    handleRowDeletion();
    handleFormSubmission();
    handleKeyboardShortcuts();
    
    // تحديث المدفوع عند تغيير الخصم أو الإضافات أو الإجمالي
    $(document).on('input change', '#headdisc, #headplus, #headtotal, #headnet', function() {
        // إعادة حساب الإجمالي عند تغيير الخصم أو الإضافات
        if ($(this).attr('id') === 'headdisc' || $(this).attr('id') === 'headplus') {
            updateTotal();
        }
        
        const headnet = parseFloat($('#headnet').val()) || 0;
        $('#paid').val(headnet.toFixed(2));
        $('#change').val('0.00');
        console.log('Event triggered - Updated paid to:', headnet.toFixed(2));
    });
    
    // تحديث المدفوع عند تحميل الصفحة
    setTimeout(function() {
        const headnet = parseFloat($('#headnet').val()) || 0;
        if (headnet > 0) {
            $('#paid').val(headnet.toFixed(2));
            $('#change').val('0.00');
            console.log('Page load - Updated paid to:', headnet.toFixed(2));
        }
    }, 500);
    
    // تحديث فوري عند أي تغيير في الصفوف
    $(document).on('input', '.itmqty, .itmprice, .itmdisc', function() {
        setTimeout(function() {
            const headnet = parseFloat($('#headnet').val()) || 0;
            $('#paid').val(headnet.toFixed(2));
            $('#change').val('0.00');
        }, 200);
    });
});

function initializeSelect2() {
    if ($('#mySelectitm').hasClass('select2-hidden-accessible')) {
        $('#mySelectitm').select2('destroy');
    }
    $('#mySelectitm').select2({
        placeholder: "اختر صنف",
        ajax: {
            url: 'js/ajax/sales_myitems.php',
            dataType: 'json',
            delay: 250, // زيادة التأخير لتقليل الطلبات
            data: (params) => ({ search: params.term }),
            processResults: (data) => ({ results: data }),
            cache: true
        },
        minimumInputLength: 1 // لا تبحث إلا بعد كتابة حرف واحد
    });
}

function handleItemSelectionChange() {
    // استخدام event delegation مرة واحدة فقط
    $(document).off('change', 'select.mySelectitm').on('change', 'select.mySelectitm', function() {
        if (isProcessing) return;
        isProcessing = true;
        
        const row = $(this).closest('tr');
        fetchItemInfo($(this).val(), row);
        
        setTimeout(() => { isProcessing = false; }, 300);
    });
}

function fetchItemInfo(itemId, row) {
    $.ajax({
        url: 'get/get_iteminfo.php?id=' + itemId,
        method: 'GET',
        dataType: 'json',
        cache: true, // تفعيل الكاش
        success: function(data) {
            const isSale = getParameterByName('q') === 'sale';
            const price = isSale ? data.last_price : data.price1;
            
            // تحديث الحقول دفعة واحدة
            row.find("#itmprice").val(price);
            row.find("#itmval").val(price);
            row.find("#itmqty").val(1);
            
            // تحديث العناصر بدون استخدام .html() المتكرر
            const updates = {
                '#storeqty': data.itmqty?.toFixed(2) || '0',
                '#price1': data.price1 || '0',
                '#market_price': data.market_price || '0',
                '#storemdtime': data.mdtime || '',
                '#cost_price': data.cost_price || '0',
                '#last_price': data.last_price || '0'
            };
            
            Object.entries(updates).forEach(([selector, value]) => {
                $(selector).text(value);
            });
            
            const unitSelect = row.find('select[name="u_val[]"]');
            unitSelect.empty();
            
            if (data.units && data.units.length) {
                const options = data.units.map(unit => 
                    `<option value="${unit.unit_value}">${unit.unit_name}</option>`
                ).join('');
                unitSelect.html(options);
                
                // Unit change event - استخدام off قبل on لمنع التكرار
                unitSelect.off('change').on('change', function() {
                    const selectedUnit = data.units.find(u => u.unit_value == $(this).val());
                    if (selectedUnit) {
                        const newPrice = isSale ? data.last_price * selectedUnit.unit_value : selectedUnit.uprice1;
                        row.find("#itmprice").val(newPrice);
                        row.find("#itmqty").val(1);
                        
                        $('#storeqty').text((data.itmqty/selectedUnit.unit_value).toFixed(2) + " (" + selectedUnit.unit_value + ")");
                        $('#price1').text(selectedUnit.uprice1);
                        $('#market_price').text(selectedUnit.uprice3);
                        $('#cost_price').text(data.cost_price * selectedUnit.unit_value);
                        $('#last_price').text(data.last_price * selectedUnit.unit_value);
                        
                        calculateItemValue(row);
                        updateTotal();
                    }
                });
            }
        },
        error: function(xhr, status, error) {
            console.error("خطأ في استدعاء البيانات:", error);
        }
    });
}
        
        function handleRowAddition() {
            $(document).off("click", "#addRow").on("click", "#addRow", function(e) {
                e.preventDefault();
                const itemId = $("#selectedItemId").val();
                const itemName = $("#itemSearchInput").val().trim();
                if (itemId && itemName) {
                    addNewRow(itemId, itemName);
                } else {
                    alert("يرجى اختيار صنف.");
                }
            });
        }

        function addNewRow(itemId, itemName) {
            const qty    = parseFloat($("#itmqty").val())   || 1;
            const price  = parseFloat($("#itmprice").val()) || 0;
            const disc   = parseFloat($("#itmdisc").val())  || 0;
            const val    = parseFloat($("#itmval").val())   || (qty * price - disc);
            const unitVal  = parseFloat($("#inputUnitSelect").val()) || 1;
            const unitName = $("#inputUnitSelect option:selected").text() || '-';

            const newRow = $(`<tr>
                <td class="col-1">${counter++}</td>
                <td class="col-lg-5">
                    <p>${itemName}</p>
                    <input type="number" name="itmname[]" hidden value="${itemId}">
                </td>
                <td>
                    <select name="u_val[]" class="form-control form-control-sm" style="width:100px;">
                        <option value="${unitVal}">${unitName}</option>
                    </select>
                </td>
                <td><input type="number" name="itmqty[]"   value="${qty}"   class="itmqty  form-control form-control-sm" style="width:90px;"  onclick="sT(this)"></td>
                <td><input type="number" name="itmprice[]" value="${price}" class="itmprice form-control form-control-sm" style="width:90px;"  onclick="sT(this)" step="0.001"></td>
                <td><input type="number" name="itmdisc[]"  value="${disc}"  class="itmdisc  form-control form-control-sm" style="width:120px;" onclick="sT(this)" step="0.001"></td>
                <td><input type="number" name="itmval[]"   value="${val}"   class="itmval   bg-light form-control form-control-sm" style="width:150px;" readonly step="0.001"></td>
                <td><input name="itmprofit" hidden><button type="button" class="deleteRow btn btn-danger">X</button></td>
            </tr>`);

            newRow.appendTo("#itmrow");

            // مسح حقول الإدخال بعد الإضافة
            $("#itemSearchInput").val('');
            $("#selectedItemId").val('');
            $("#itmprice").val('0.00');
            $("#itmqty").val('1.00');
            $("#itmdisc").val('0.00');
            $("#itmval").val('0.00');
            $("#inputUnitSelect").empty().append('<option value="">اختر وحدة</option>');
            $("#itemSearchInput").focus();
            updateTotal();
        }

function handleInputChanges() {
    // استخدام debounce لتقليل عدد الحسابات
    let timeout;
    $(document).off('input', '.itmqty, .itmprice, .itmdisc').on('input', '.itmqty, .itmprice, .itmdisc', function() {
        clearTimeout(timeout);
        const row = $(this).closest('tr');
        timeout = setTimeout(() => {
            calculateItemValue(row);
            updateTotal();
        }, 150);
    });
}

        function calculateItemValue(row) {
            const itmQty = parseFloat(row.find('.itmqty').val()) || 0;
            const itmPrice = parseFloat(row.find('.itmprice').val()) || 0;
            const itmDisc = parseFloat(row.find('.itmdisc').val()) || 0;
            const itmVal = (itmQty * itmPrice) - itmDisc;
            row.find('.itmval').val(itmVal.toFixed(2) || '');
        }

        function handleRowDeletion() {
            $("#itmrow").on("click", ".deleteRow", function() {
                $(this).closest("tr").remove();
                updateTotal();
            });
        }

        function handleFormSubmission() {
            $('#addItemForm').on('submit', function(event) {
                event.preventDefault();
                const formData = new FormData(this);
                $.ajax({
                    url: 'js/ajax/doadd_item.php',
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function(response) {
                        $('#msgitem').html(response);
                        refreshSelect();
                    },
                    error: function(xhr) {
                        console.error('خطأ:', xhr.status);
                    }
                });
            });
        }

        function refreshSelect() {
            $.get('js/ajax/refresh_select.php', function(data) {
                $('#mySelectitm').html(data);
            });
        }

        function getParameterByName(name, url = window.location.href) {
            const regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)');
            const results = regex.exec(url);
            return results ? (results[2] ? decodeURIComponent(results[2].replace(/\+/g, ' ')) : '') : null;
        }

function updateTotal() {
    let total = 0;
    $('#itmrow .itmval').each(function() {
        total += parseFloat(this.value) || 0;
    });
    
    const headtotal = total;
    const headdisc = parseFloat($("#headdisc").val()) || 0;
    const headplus = parseFloat($("#headplus").val()) || 0;
    const headnet = headtotal - headdisc + headplus;
    
    $('#headtotal').val(headtotal.toFixed(2));
    $("#headnet").val(headnet.toFixed(2));
    
    // نقل الإجمالي إلى المدفوع تلقائياً - استخدام طرق متعددة
    const paidValue = headnet.toFixed(2);
    $("#paid").val(paidValue);
    $("input[name='paid']").val(paidValue);
    $("input#paid").val(paidValue);
    
    // استخدام vanilla JS كمان
    const paidInput = document.getElementById('paid');
    if (paidInput) {
        paidInput.value = paidValue;
    }
    
    console.log('Updated paid to:', paidValue, 'Element found:', !!paidInput);
    
    // حساب الباقي
    $("#change").val("0.00");
}



// تم نقل معالجة Enter إلى handleKeyboardShortcuts
        

function handleKeyboardShortcuts() {
    $(document).off('keydown').on('keydown', function(event) {
        if (event.key === 'F11') {
            event.preventDefault();
            $('#submit2').click();
        } else if (event.key === 'F12') {
            event.preventDefault();
            $('#submit').click();
        } else if (event.key === "Enter" && $(event.target).is('input, select')) {
            event.preventDefault();
            let nextElement = $(event.target).closest('td').next().find('input, select');
            if (nextElement.length) {
                nextElement.focus();
                if (nextElement.attr('name') === 'u_val[]') {
                    nextElement.select2('open');
                }
            }
        }
    });
}
