$(document).ready(function() {
    let currentQty = 1;

    // Focus on barcode input initially and continually
    $('#barcodeInput').focus();
    
    $(document).on('click', function(e) {
        if (!$(e.target).closest('input, button, select, a, .modal').length) {
            $('#barcodeInput').focus();
        }
    });

    // Keyboard Shortcuts
    $(document).keydown(function(e) {
        if (e.key === 'F12') {
            e.preventDefault();
            $('#paymentModal').modal('show');
        } else if (e.altKey && e.key === 'b') {
            e.preventDefault();
            $('#barcodeInput').focus();
        } else if (e.altKey && e.key === 's') {
            e.preventDefault();
            $('#searchInput').focus();
        }
    });

    // Handle Barcode Input (scanner sends Enter — no debounced auto-add on this field)
    let barcodeCheckTimeout;
    $('#barcodeInput').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            clearTimeout(barcodeCheckTimeout);
            let barcode = $(this).val().trim();
            if (barcode === '+') {
                $(this).val('');
                if ($('.item-card-order').length === 0) {
                    Swal.fire('تنبيه', 'الفاتورة فارغة', 'warning');
                    return;
                }
                window.openedByPlus = true;
                $('#paymentModal').modal('show');
            } else if (barcode) {
                searchItemSupermarket(barcode);
                $(this).val('');
            }
        }
    });

    // Search field: debounced exact barcode match only (barcode field uses Enter above)
    $('#searchInput').on('input change', function() {
        let inputField = $(this);
        let val = inputField.val().trim();

        if (!val) {
            return;
        }

        clearTimeout(barcodeCheckTimeout);
        barcodeCheckTimeout = setTimeout(function() {
            $.ajax({
                url: 'ajax/check_exact_barcode.php',
                method: 'POST',
                data: { barcode: val },
                success: function(response) {
                    try {
                        let data = typeof response === 'object' ? response : JSON.parse(response);
                        if (data.success && data.item) {
                            if (!itemAvailabilityCanAdd(data.item)) {
                                showUnavailableItem(data.item);
                                return;
                            }
                            addItemToTable(data.item, 1);
                            inputField.val('');
                            $('#barcodeInput').focus();
                            if (inputField.data('ui-autocomplete')) {
                                inputField.autocomplete('close');
                            }
                        }
                    } catch (e) {
                        console.error(e);
                    }
                }
            });
        }, 150);
    });

    // Handle Enter key on Search Input
    $('#searchInput').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            clearTimeout(barcodeCheckTimeout);
            let query = $(this).val().trim();
            if (query) {
                $.ajax({
                    url: 'ajax/search_items_autocomplete.php',
                    method: 'GET',
                    data: { term: query },
                    success: function(response) {
                        try {
                            let items = JSON.parse(response);
                            if (items && items.length > 0) {
                                const item = items[0].item;
                                if (!itemAvailabilityCanAdd(item)) {
                                    showUnavailableItem(item);
                                    return;
                                }
                                addItemToTable(item, 1);
                                $('#searchInput').val('');
                                $('#barcodeInput').focus();
                                // Close autocomplete if open
                                if ($('#searchInput').data('ui-autocomplete')) {
                                    $('#searchInput').autocomplete('close');
                                }
                            } else {
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'غير موجود',
                                    text: 'لم يتم العثور على أي صنف مطابق',
                                    timer: 1500,
                                    showConfirmButton: false
                                });
                            }
                        } catch (e) {
                            console.error(e);
                        }
                    }
                });
            }
        }
    });

    // Autocomplete on searchInput
    $('#searchInput').autocomplete({
        source: function(request, response) {
            $.ajax({
                url: 'ajax/search_items_autocomplete.php',
                method: 'GET',
                dataType: 'json',
                data: { term: request.term },
                success: function(data) {
                    response(data);
                }
            });
        },
        minLength: 1,
        select: function(event, ui) {
            if (ui.item && ui.item.item) {
                if (!itemAvailabilityCanAdd(ui.item.item)) {
                    showUnavailableItem(ui.item.item);
                    return false;
                }
                addItemToTable(ui.item.item, 1);
                $(this).val('');
                $('#barcodeInput').focus();
                return false;
            }
        }
    });

    // Customize autocomplete layout
    if ($('#searchInput').data('ui-autocomplete')) {
        $('#searchInput').autocomplete('instance')._renderItem = function(ul, item) {
            return $('<li>')
                .append(`<div class="d-flex justify-content-between align-items-center w-100 p-2 border-bottom">
                            <div class="text-end">
                                <strong class="text-dark d-block">${item.item.name}</strong>
                                <small class="text-muted">${item.item.barcode || 'بدون باركود'}</small>
                            </div>
                            <span class="badge bg-primary fs-6">${parseFloat(item.item.price).toFixed(2)} ج.م</span>
                         </div>`)
                .appendTo(ul);
        };
    }


    // Payment Modal Calculation
    $('#paymentModal').on('shown.bs.modal', function() {
        let net = parseFloat($('#net_val').val()) || 0;
        $('#modal_net_large').text(net.toFixed(2) + ' ج.م');
        $('#modal_paid_cash').val(net.toFixed(2));
        $('#modal_change').text('0.00 ج.م');
        if (window.openedByPlus) {
            $('#btn_save_print').focus();
            window.openedByPlus = false;
        } else {
            $('#modal_paid_cash').focus().select();
        }
    });

    $('#modal_paid_cash').on('input', function() {
        let paid = parseFloat($(this).val()) || 0;
        let net = parseFloat($('#net_val').val()) || 0;
        let change = paid - net;
        if (change < 0) change = 0;
        $('#modal_change').text(change.toFixed(2) + ' ج.م');
    });

    // Submit on Enter in Payment Modal
    $('#modal_paid_cash').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            submitSupermarketPOS('save');
        }
    });

    function touchOrderDraft() {
        if (window.POSOrderDraft && typeof window.POSOrderDraft.markDirty === 'function') {
            window.POSOrderDraft.markDirty();
        }
    }

    // Dynamic Row Calculations
    $(document).on('input', '.quantityInput, .priceInput', function() {
        let row = $(this).closest('tr');
        let qty = parseFloat(row.find('.quantityInput').val()) || 0;
        let price = parseFloat(row.find('.priceInput').val()) || 0;
        let subtotal = qty * price;
        row.find('.subtotal').val(subtotal.toFixed(2));
        updateTotal();
    });

    // Delete Row
    $(document).on('click', '.delRow', function() {
        $(this).closest('tr').remove();
        updateTotal();
        $('#barcodeInput').focus();
    });

    function itemAvailabilityCanAdd(item) {
        item = item || {};
        const isAvailable = String(item.is_available !== undefined ? item.is_available : '1') !== '0';
        return String(item.availability_can_add !== undefined ? item.availability_can_add : (isAvailable ? '1' : '0')) !== '0';
    }

    function showUnavailableItem(item) {
        const reason = (item && item.unavailable_reason) ? item.unavailable_reason : 'هذا الصنف غير متاح حالياً.';
        Swal.fire({
            icon: 'warning',
            title: 'غير متاح',
            text: reason,
            timer: 1800,
            showConfirmButton: false
        });
    }

    function searchItemSupermarket(barcode) {
        // Handle Scale Barcodes based on config
        let searchCode = barcode;
        let scaleWeight = 0;
        
        if (typeof posConfig !== 'undefined' && posConfig.scale_barcode && posConfig.scale_barcode.enabled) {
            let conf = posConfig.scale_barcode;
            if (barcode.length === conf.barcode_length && barcode.startsWith(conf.prefix)) {
                let codeStart = conf.item_code_start - 1;
                let weightStart = conf.weight_start - 1;
                searchCode = barcode.substr(codeStart, conf.item_code_length);
                let rawWeight = barcode.substr(weightStart, conf.weight_length);
                scaleWeight = parseInt(rawWeight) / conf.weight_divisor;
            }
        }

        $.ajax({
            url: 'ajax/search_item_supermarket.php',
            method: 'POST',
            data: { barcode: searchCode },
            success: function(response) {
                try {
                    let data = JSON.parse(response);
                    if (data.success) {
                        if (!itemAvailabilityCanAdd(data.item)) {
                            showUnavailableItem(data.item);
                            return;
                        }
                        let qtyToAdd = scaleWeight > 0 ? scaleWeight : 1;
                        addItemToTable(data.item, qtyToAdd);
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'غير موجود',
                            text: 'الصنف غير موجود أو محذوف',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    }
                } catch (e) {
                    console.error("Parse error:", e);
                }
            },
            error: function() {
                Swal.fire('خطأ', 'فشل الاتصال بالخادم', 'error');
            }
        });
    }

    function addItemToTable(item, qtyToAdd) {
        let table = $('#itemData');
        let existingRow = table.find(`tr[data-itemid="${item.barcode}"]`);

        if (existingRow.length > 0) {
            let qtyInput = existingRow.find('.quantityInput');
            let newQty = parseFloat(qtyInput.val()) + qtyToAdd;
            qtyInput.val(newQty.toFixed(3)); // allow 3 decimals for weights
            qtyInput.trigger('input');
        } else {
            let rowCount = table.find('tr').length + 1;
            let subtotal = qtyToAdd * parseFloat(item.price);
            
            let html = `
                <tr class="item-card-order" data-itemid="${item.barcode}">
                    <td class="text-center fw-bold text-muted">${rowCount}</td>
                    <td>
                        <input type="hidden" value='${item.id}' name="itmname[]">
                        <input type="hidden" class="barcode" value="${item.barcode}">
                        <input type="hidden" name="u_val[]" value="${item.u_val || 1}">
                        <span class="fw-bold fs-6">${item.name}</span>
                        ${item.unit_name ? `<br><small class="text-primary">${item.unit_name}</small>` : ''}
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-lg text-center quantityInput fw-bold text-primary" 
                               value="${qtyToAdd}" name="itmqty[]" min="0.001" step="0.001">
                    </td>
                    <td>
                        <input type="number" class="form-control text-center priceInput fw-bold" 
                               value="${parseFloat(item.price).toFixed(2)}" name="itmprice[]" step="0.01" readonly>
                    </td>
                    <td>
                        <input type="hidden" name="itmdisc[]" value="0">
                        <input type="text" class="form-control text-center subtotal fw-bold bg-light text-danger fs-5 border-0" 
                               readonly value="${subtotal.toFixed(2)}" name="itmval[]">
                    </td>
                    <td class="text-center">
                        <button type="button" class="btn btn-danger btn-lg delRow"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>
            `;
            table.prepend(html); // add to top
            updateTotal();
        }
    }

    function updateTotal() {
        let total = 0;
        let totalQty = 0;
        
        $('.subtotal').each(function() {
            total += parseFloat($(this).val()) || 0;
        });
        
        $('.quantityInput').each(function() {
            totalQty += parseFloat($(this).val()) || 0;
        });

        $('#total').val(total.toFixed(2));
        $('#net_val').val(total.toFixed(2));
        $('#net_display').text(total.toFixed(2));
        $('#total_qty_display').text(totalQty.toFixed(2));
        
        // update row numbers
        $('#itemData tr').each(function(index) {
            $(this).find('td:first').text(index + 1);
        });
        touchOrderDraft();
    }

    window.clearAllItems = function() {
        Swal.fire({
            title: 'هل أنت متأكد؟',
            text: "سيتم مسح جميع الأصناف من الفاتورة",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، امسح',
            cancelButtonText: 'إلغاء'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#itemData').empty();
                updateTotal();
                $('#barcodeInput').focus();
            }
        });
    }

    // Submit Logic overrides
    window.submitSupermarketPOS = function(action) {
        const resolvedAction = action === 'cash' ? 'cash' : action;
        const saveAction = action === 'cash' ? 'save' : action;
        if ((saveAction === 'save' || saveAction === 'print_receipt')
            && window.POSOrderDraft
            && !window.POSOrderDraft.canSave(saveAction)) {
            return false;
        }

        if ($('.item-card-order').length === 0) {
            Swal.fire('تنبيه', 'الفاتورة فارغة', 'warning');
            return false;
        }

        const form = document.getElementById('posForm');

        let paidCash = parseFloat($('#modal_paid_cash').val()) || 0;
        let net = parseFloat($('#net_val').val()) || 0;
        
        if (paidCash < net) {
             // In POS it's usually allowed to have partial if there's customer account, but let's warn
             if (confirm('المبلغ المدفوع أقل من الإجمالي. هل تريد المتابعة؟') === false) {
                 return false;
             }
        }

        // Compatibility fields for the modern api/pos path (legacy doadd_invoice is blocked).
        $('<input>').attr({type: 'hidden', name: 'paid_cash', value: paidCash}).appendTo(form);
        $('<input>').attr({type: 'hidden', name: 'paid', value: paidCash}).appendTo(form); // compatibility
        $('<input>').attr({type: 'hidden', name: 'payment_fund_id', value: $('#payment_fund_id').val()}).appendTo(form);
        $('<input>').attr({type: 'hidden', name: 'submit', value: action}).appendTo(form);

        const api = window.POSOrderApi;
        if (api && typeof api.submitFromForm === 'function') {
            api.submitFromForm(form, resolvedAction);
            return true;
        }

        alert('تعذر إرسال الطلب عبر واجهة البرنامج الموحدة.');
        return false;
    }
});
