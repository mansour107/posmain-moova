/**
 * POS Barcode System - JavaScript
 * نظام نقاط البيع بالباركود
 */

$(document).ready(function() {
    // ========================================
    // Initialize on page load - Update totals if items exist (edit mode)
    // ========================================
    if ($('#itemData .item-card-order').length > 0) {
        updateItemCount();
        updateTotal();
    }
    
    // ========================================
    // Category Filter
    // ========================================
    $('.category-btn').on('click', function() {
        const $this = $(this);
        const categoryId = $this.data('category');
        
        // تحديث الأزرار
        $('.category-btn').removeClass('active btn-primary').addClass('btn-outline-primary');
        $this.removeClass('btn-outline-primary').addClass('btn-primary active');
        
        // مسح البحث
        $('#itemFilterInput').val('');
        
        // فلترة الأصناف
        const $items = $('.item-wrapper');
        if (categoryId === 'all') {
            $items.removeClass('hidden');
        } else {
            $items.addClass('hidden');
            $(`.item-wrapper[data-category="${categoryId}"]`).removeClass('hidden');
        }
    });

    // ========================================
    // Barcode & Search Input
    // ========================================
    $('#barcodeInput').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            let barcode = $(this).val().trim();
            if (barcode) {
                searchItemByBarcode(barcode);
                $(this).val('');
            }
        }
    });
    
    $('#searchInput').on('keypress', function(e) {
        if (e.which === 13) {
            let search = $(this).val().trim();
            if (search) {
                // Try barcode search first
                searchItemByBarcode(search);
                
                // Also trigger the filter search
                if (search.length >= 2) {
                    $('#itemFilterInput').val(search).trigger('input');
                }
                
                $(this).val('');
            }
        }
    });
    
    // البحث البسيط مع Debouncing للأداء
    let searchTimeout;
    $('#itemFilterInput').on('input', function() {
        clearTimeout(searchTimeout);
        const searchText = $(this).val().toLowerCase().trim();
        
        // لو فاضي، اعرض كل الأصناف فوراً
        if (searchText === '') {
            $('.item-wrapper').removeClass('hidden');
            return;
        }
        
        // انتظر 200ms قبل البحث (debouncing)
        searchTimeout = setTimeout(function() {
            const $items = $('.item-wrapper');
            
            // استخدم CSS classes للأداء الأفضل
            $items.each(function() {
                const $this = $(this);
                const $card = $this.find('.item-card');
                const itemName = ($card.data('item-name') || '').toString().toLowerCase();
                const itemBarcode = ($card.data('item-barcode') || '').toString().toLowerCase();
                
                // اعرض أو اخفي حسب النتيجة
                if (itemName.includes(searchText) || itemBarcode.includes(searchText)) {
                    $this.removeClass('hidden');
                } else {
                    $this.addClass('hidden');
                }
            });
        }, 200);
    });
    
    $('#clearFilter').click(function() {
        $('#itemFilterInput').val('');
        $('.item-wrapper').removeClass('hidden');
    });

    // ========================================
    // Item Filtering Functions
    // ========================================

    

    
    // ========================================
    // Item Search & Add Functions
    // ========================================
    function searchItemByBarcode(barcode) {
        let qty = 1;
        let searchCode = barcode;
        
        // Check if it's a scale barcode using config
        if (posConfig && posConfig.scale_barcode && posConfig.scale_barcode.enabled) {
            const cfg = posConfig.scale_barcode;
            
            if (barcode.length === cfg.barcode_length && 
                barcode.substring(0, cfg.prefix.length) === cfg.prefix) {
                
                searchCode = barcode.substring(cfg.item_code_start, 
                                               cfg.item_code_start + cfg.item_code_length);
                
                let weightStr = barcode.substring(cfg.weight_start, 
                                                  cfg.weight_start + cfg.weight_length);
                qty = parseFloat(weightStr) / cfg.weight_divisor;
                searchCode = parseInt(searchCode).toString();
                
                console.log('🔢 Scale Barcode Detected:', {
                    original: barcode,
                    itemCode: searchCode,
                    weight: qty
                });
            }
        }
        
        $.ajax({
            url: 'ajax/search_item.php',
            type: 'POST',
            data: { barcode: searchCode },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    addItemToOrder(response.item.id, response.item.name, response.item.price, response.item.barcode, qty);
                } else {
                    alert('الصنف غير موجود');
                }
            },
            error: function() {
                alert('خطأ في البحث عن الصنف');
            }
        });
    }

    // ========================================
    // Item Click Events
    // ========================================
    $('#itemsGrid').on('click', '.item-image-click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        let card = $(this).closest('.item-card');
        let itemId = card.data('item-id');
        let itemName = card.data('item-name');
        let itemPrice = parseFloat(card.data('item-price')) || 0;
        let itemBarcode = card.data('item-barcode');
        
        addItemToOrder(itemId, itemName, itemPrice, itemBarcode);
    });

    $('#itemsGrid').on('click', '.item-details-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        let card = $(this).closest('.item-card');
        let itemId = card.data('item-id');
        let itemName = card.data('item-name');
        let itemPrice = card.data('item-price');
        let itemBarcode = card.data('item-barcode');
        let itemDesc = card.data('item-desc') || 'لا يوجد وصف';
        
        let imageHtml = card.find('.item-image-container').html();
        
        $('#modal_item_name').text(itemName);
        $('#modal_item_barcode').text(itemBarcode || '-');
        $('#modal_item_price').text(itemPrice.toFixed(2) + ' ج.م');
        $('#modal_item_desc').text(itemDesc);
        $('#modal_item_image').html(imageHtml);
        
        $('#modal_add_item').data({
            'id': itemId,
            'name': itemName,
            'price': itemPrice,
            'barcode': itemBarcode
        });
        
        $('#itemDetailsModal').modal('show');
    });

    $(document).on('click', '#modal_add_item', function() {
        let data = $(this).data();
        let itemPrice = parseFloat(data.price) || 0;
        addItemToOrder(data.id, data.name, itemPrice, data.barcode);
        $('#itemDetailsModal').modal('hide');
    });

    // ========================================
    // Add Item to Order
    // ========================================
    function addItemToOrder(id, name, price, barcode, qty = 1) {
        let existingItem = $(`.item-card-order[data-itemid="${barcode}"]`);
        
        if (existingItem.length > 0) {
            let qtyInput = existingItem.find('.quantityInput');
            let currentQty = parseFloat(qtyInput.val()) || 0;
            let newQty = currentQty + qty;
            qtyInput.val(newQty);
            
            let priceInput = existingItem.find('.priceInput');
            let itemPrice = parseFloat(priceInput.val()) || 0;
            let subtotal = newQty * itemPrice;
            existingItem.find('.subtotal').val(subtotal.toFixed(2));
            
            updateTotal();
            $('#barcodeInput').val('').focus();
            return;
        }
        
        let subtotal = price * qty;
        let itemNumber = $('#itemData .item-card-order').length + 1;
        
        let itemCard = `
            <div class="card mb-1 item-card-order shadow-sm border-start border-3" data-itemid="${barcode}" style="border-color: #0a7ea4 !important; max-width: 100%;">
                <div class="card-body p-1">
                    <div class="d-flex align-items-center gap-1" style="font-size: 0.75rem;">
                        <span class="badge bg-primary" style="font-size: 0.7rem; min-width: 25px;">#${itemNumber}</span>
                        
                        <div style="flex: 1; min-width: 0;">
                            <input type="hidden" value='${id}' name="itmname[]">
                            <input type="hidden" class="barcode" value="${barcode}">
                            <div class="text-truncate fw-bold" style="font-size: 0.75rem;" title="${name}">${name}</div>
                        </div>
                        
                        <div style="width: 65px;">
                            <small class="d-block text-center text-muted" style="font-size: 0.6rem; margin-bottom: 1px;">كمية</small>
                            <input type="number" 
                                   class="form-control form-control-sm text-center quantityInput nozero fw-bold" 
                                   value="${qty}" 
                                   name="itmqty[]"
                                   min="1" 
                                   step="0.1"
                                   style="width: 100%; font-size: 0.75rem; padding: 3px; border: 2px solid #ff6347; height: 26px;"
                                   title="الكمية">
                            <input type="hidden" name="u_val[]" value="1">
                        </div>
                        
                        <div style="width: 55px;">
                            <small class="d-block text-center text-muted" style="font-size: 0.6rem; margin-bottom: 1px;">سعر</small>
                            <input type="number" 
                                   class="form-control form-control-sm text-center priceInput nozero" 
                                   value="${price.toFixed(2)}" 
                                   name="itmprice[]" 
                                   step="0.01"
                                   style="width: 100%; font-size: 0.7rem; padding: 3px; height: 26px;"
                                   title="السعر">
                        </div>
                        
                        <div style="width: 60px;">
                            <small class="d-block text-center text-muted" style="font-size: 0.6rem; margin-bottom: 1px;">قيمة</small>
                            <input type="hidden" name="itmdisc[]" value="0">
                            <input type="text" 
                                   class="form-control form-control-sm text-center subtotal fw-bold" 
                                   readonly 
                                   value="${subtotal.toFixed(2)}" 
                                   name="itmval[]"
                                   style="width: 100%; font-size: 0.7rem; padding: 3px; background: #fff3cd; height: 26px;"
                                   title="القيمة">
                        </div>
                        
                        <button type="button" class="btn btn-danger btn-sm delRow" style="padding: 2px 6px; font-size: 0.7rem;" title="حذف">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        $('#itemData').append(itemCard);
        updateItemCount();
        updateTotal();
        $('#barcodeInput').val('').focus();
    }
    
    // ========================================
    // Update Functions
    // ========================================
    function updateItemCount() {
        let count = $('#itemData .item-card-order').length;
        $('#itemCount').text(count);
    }
    
    window.clearAllItems = function() {
        if (confirm('مسح كل الأصناف؟')) {
            $('#itemData').empty();
            $('#discount').val('0');
            $('#modal_discperc').val('0');
            $('#modal_discount').val('0');
            $('#modal_paid').val('0.00');
            $('#modal_change').val('0.00');
            updateItemCount();
            updateTotal();
        }
    };

    function updateTotal() {
        let total = 0;
        $('.subtotal').each(function() {
            total += parseFloat($(this).val()) || 0;
        });
        $('#total').val(total.toFixed(2));
        $('#total_display').text(total.toFixed(2) + ' ج.م');
        $('#total_display_btn').text(total.toFixed(2) + ' ج.م');
        $('#modal_total').text(total.toFixed(2) + ' ج.م');
        
        let discount = parseFloat($('#discount').val()) || 0;
        let net = total - discount;
        $('#net_val').val(net.toFixed(2));
        $('#net_display').text(net.toFixed(2) + ' ج.م');
        $('#modal_net').text(net.toFixed(2) + ' ج.م');
        
        // تعبئة المدفوع كاش تلقائياً بقيمة الصافي
        $('#modal_paid_cash').val(net.toFixed(2));
        // مسح المدفوع صرافة
        $('#modal_paid_bank').val('0.00');
        // حساب الباقي (سيكون صفر لأن المدفوع = الصافي)
        $('#modal_change').text('0.00 ج.م');
    }
    
    // ========================================
    // Tables System
    // ========================================

    // مسح الطاولة عند التبديل لتيك أواي أو دليفري
    $('input[name="age"]').on('change', function() {
        const val = $(this).val();
        if (val == '1' || val == '3') {
            // تيك أواي أو دليفري - امسح الطاولة المختارة
            $('#selected_table_id').val('');
            $('#selected_table_name').val('');
            $('#selected_order_id').val('');
            $('#selected_table_display').html('اختر طاولة');
        }
    });

    $(document).on('click', '.table-select-btn', function() {
        const tableId = $(this).data('table-id');
        const tableName = $(this).data('table-name');
        const tableCase = $(this).data('table-case');
        const orderId = $(this).data('order-id');
        
        $('#selected_table_id').val(tableId);
        $('#selected_table_name').val(tableName);
        $('#selected_table_display').html('<i class="fas fa-chair me-1"></i>' + tableName);
        $('#age2').prop('checked', true);
        $('#tablesModal').modal('hide');
        
        Swal.fire({
            icon: 'success',
            title: 'تم الاختيار',
            text: 'تم اختيار ' + tableName + ' بنجاح',
            timer: 1500,
            showConfirmButton: false
        });
        
        if (tableCase != 0 && orderId) {
            // طاولة فيها طلب - حمل الطلب واضيف عليه
            $('#selected_order_id').val(orderId);
            loadExistingOrder(orderId, tableName);
        } else {
            // طاولة فاضية - طلب جديد
            $('#selected_order_id').val('');
            $('#itemData').empty();
            updateItemCount();
            updateTotal();
            console.log('طاولة فاضية: ' + tableName + ' - طلب جديد');
        }
    });
    
    window.selectNoTable = function() {
        $('#selected_table_id').val('');
        $('#selected_table_name').val('');
        $('#selected_order_id').val('');
        $('#selected_table_display').html('بدون طاولة');
        $('#age1').prop('checked', true);
        $('#tablesModal').modal('hide');
        clearAllItems();
    };
    
    function loadExistingOrder(orderId, tableName) {
        console.log('🔄 Loading existing order:', orderId, 'Table:', tableName);
        
        $.ajax({
            url: 'ajax/load_order.php',
            method: 'POST',
            data: { order_id: orderId },
            dataType: 'json',
            success: function(response) {
                console.log('📥 Load Order Response:', response);
                
                if (response.success) {
                    $('#itemData').empty();
                    
                    if (response.items && response.items.length > 0) {
                        console.log('📦 Found items:', response.items.length);
                        response.items.forEach(function(item) {
                            console.log('➕ Adding item:', item);
                            addItemToOrder(
                                item.item_id,
                                item.item_name || 'Unknown Item',
                                parseFloat(item.price) || 0,
                                item.barcode || item.item_desc || item.item_id, // Use explicit barcode first
                                parseFloat(item.qty) || 1
                            );
                        });
                    } else {
                        console.warn('⚠️ No items found in order');
                    }
                    
                    if (response.order) {
                        $('#discount').val(response.order.discount || 0);
                        if (response.order.emp_id) $('select[name="emp_id"]').val(response.order.emp_id);
                        if (response.order.acc1) $('select[name="acc2_id"]').val(response.order.acc1);
                         // Set hidden edit_order_id
                         $('#edit_order_id').val(response.order.id);
                    }
                    
                    updateItemCount();
                    updateTotal();
                    
                    // Show success message briefly
                    const alertDiv = $('<div class="alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999;">تم تحميل الطلب بنجاح</div>');
                    $('body').append(alertDiv);
                    setTimeout(() => alertDiv.fadeOut(() => alertDiv.remove()), 2000);
                    
                } else {
                    console.error('❌ Load failed:', response.error);
                    alert('خطأ في تحميل طلب الطاولة: ' + (response.error || 'غير معروف'));
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX Error:', error);
                console.error('Response:', xhr.responseText);
                alert('خطأ في الاتصال بالخادم');
            }
        });
    }

    // ========================================
    // Modal Calculations
    // ========================================
    $('#modal_discperc').on('input', function() {
        let total = parseFloat($('#total').val()) || 0;
        let discount = (total * (parseFloat($(this).val()) || 0) / 100).toFixed(2);
        $('#modal_discount').val(discount);
        $('#discount').val(discount);
        let net = (total - discount).toFixed(2);
        $('#modal_net').text(net + ' ج.م');
        $('#net_val').val(net);
        $('#net_display').text(net + ' ج.م');
        
        // حساب الباقي
        calculateChange();
    });

    $('#modal_discount').on('input', function() {
        let total = parseFloat($('#total').val()) || 0;
        let discount = parseFloat($(this).val()) || 0;
        $('#discount').val(discount);
        let percentage = total > 0 ? ((discount / total) * 100).toFixed(2) : 0;
        $('#modal_discperc').val(percentage);
        let net = (total - discount).toFixed(2);
        $('#modal_net').text(net + ' ج.م');
        $('#net_val').val(net);
        $('#net_display').text(net + ' ج.م');
        
        // حساب الباقي
        calculateChange();
    });

    // حساب الباقي عند تغيير المدفوع كاش أو صرافة
    $('#modal_paid_cash, #modal_paid_bank').on('input', function() {
        calculateChange();
    });

    function calculateChange() {
        let net = parseFloat($('#net_val').val()) || 0;
        let paidCash = parseFloat($('#modal_paid_cash').val()) || 0;
        let paidBank = parseFloat($('#modal_paid_bank').val()) || 0;
        let totalPaid = paidCash + paidBank;
        
        let change = totalPaid - net;
        
        // الباقي للحساب فقط - لا يؤثر على السند
        $('#modal_change').text(change.toFixed(2) + ' ج.م');
    }

    // ========================================
    // Delete & Update Row
    // ========================================
    $(document).on('click', '.delRow', function() {
        $(this).closest('.item-card-order').remove();
        updateItemCount();
        updateTotal();
    });
    
    $(document).on('input', '.quantityInput, .priceInput', function() {
        let card = $(this).closest('.item-card-order');
        let qty = parseFloat(card.find('.quantityInput').val()) || 0;
        let price = parseFloat(card.find('.priceInput').val()) || 0;
        let subtotal = qty * price;
        card.find('.subtotal').val(subtotal.toFixed(2));
        updateTotal();
    });

    // ========================================
    // Form Submission
    // ========================================
    window.submitPOS = function(action) {
        console.log('✅ submitPOS called with action:', action);
        
        const form = document.getElementById('posForm');
        if (!form) {
            console.error('❌ Form with id "posForm" not found!');
            alert('حدث خطأ في النظام. يرجى إعادة تحميل الصفحة.');
            return false;
        }
        
        console.log('🔍 Validating form...');
        if (!validatePOSForm()) {
            console.log('❌ Validation failed, form not submitted');
            return false;
        }
        console.log('✅ Validation passed');
        
        // جمع بيانات الدفع
        let paidCash = parseFloat($('#modal_paid_cash').val()) || 0;
        let paidBank = parseFloat($('#modal_paid_bank').val()) || 0;
        let fundId = $('#payment_fund_id').val();
        let bankId = $('#payment_bank_id').val();
        let net = parseFloat($('#net_val').val()) || 0;
        
        console.log('=== PAYMENT DATA DEBUG ===');
        console.log('modal_paid_cash value:', $('#modal_paid_cash').val());
        console.log('modal_paid_bank value:', $('#modal_paid_bank').val());
        console.log('payment_fund_id value:', $('#payment_fund_id').val());
        console.log('payment_bank_id value:', $('#payment_bank_id').val());
        console.log('Processed:', {
            paidCash: paidCash,
            paidBank: paidBank,
            fundId: fundId,
            bankId: bankId,
            net: net
        });
        console.log('==========================');
        
        // التحقق من صحة البيانات
        if (paidCash > 0 && (!fundId || fundId == '0')) {
            alert('يجب اختيار الصندوق عند الدفع كاش');
            return false;
        }
        
        if (paidBank > 0 && (!bankId || bankId == '0' || bankId == '')) {
            alert('يجب اختيار البنك عند الدفع صرافة');
            return false;
        }
        
        // إضافة حقول الدفع المخفية
        let paidCashInput = form.querySelector('input[name="paid_cash"]');
        if (!paidCashInput) {
            paidCashInput = document.createElement('input');
            paidCashInput.type = 'hidden';
            paidCashInput.name = 'paid_cash';
            form.appendChild(paidCashInput);
            console.log('✅ Created paid_cash input');
        }
        paidCashInput.value = paidCash;
        console.log('Set paid_cash =', paidCash);

        let paidBankInput = form.querySelector('input[name="paid_bank"]');
        if (!paidBankInput) {
            paidBankInput = document.createElement('input');
            paidBankInput.type = 'hidden';
            paidBankInput.name = 'paid_bank';
            form.appendChild(paidBankInput);
            console.log('✅ Created paid_bank input');
        }
        paidBankInput.value = paidBank;
        console.log('Set paid_bank =', paidBank);

        let paymentFundInput = form.querySelector('input[name="payment_fund_id"]');
        if (!paymentFundInput) {
            paymentFundInput = document.createElement('input');
            paymentFundInput.type = 'hidden';
            paymentFundInput.name = 'payment_fund_id';
            form.appendChild(paymentFundInput);
            console.log('✅ Created payment_fund_id input');
        }
        paymentFundInput.value = fundId;
        console.log('Set payment_fund_id =', fundId);

        let paymentBankInput = form.querySelector('input[name="payment_bank_id"]');
        if (!paymentBankInput) {
            paymentBankInput = document.createElement('input');
            paymentBankInput.type = 'hidden';
            paymentBankInput.name = 'payment_bank_id';
            form.appendChild(paymentBankInput);
            console.log('✅ Created payment_bank_id input');
        }
        paymentBankInput.value = bankId || '';
        console.log('Set payment_bank_id =', bankId || '');

        // إضافة المدفوع الإجمالي (للتوافق مع الكود القديم)
        let totalPaid = paidCash + paidBank;
        let paidInput = form.querySelector('input[name="paid"]');
        if (!paidInput) {
            paidInput = document.createElement('input');
            paidInput.type = 'hidden';
            paidInput.name = 'paid';
            form.appendChild(paidInput);
        }
        paidInput.value = totalPaid;

        // Check for Edit ID
        let editId = $('#edit_order_id').val();
        if (editId) {
            console.log('✏️ Edit Mode: ID', editId);
            let editIdInput = form.querySelector('input[name="edit_id"]');
            if (!editIdInput) {
                editIdInput = document.createElement('input');
                editIdInput.type = 'hidden';
                editIdInput.name = 'edit_id';
                form.appendChild(editIdInput);
            }
            editIdInput.value = editId;
        }
        
        const existingSubmits = form.querySelectorAll('input[name="submit"]');
        existingSubmits.forEach(input => input.remove());
        
        const submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = 'submit';
        submitInput.value = action;
        form.appendChild(submitInput);
        
        console.log('➕ Added submit input with value:', action);
        
        let saveBtn = $("button:contains('حفظ الطلب')");
        let printBtn = $("button:contains('حفظ وطباعة')");
        
        if (saveBtn.length > 0) {
            saveBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...');
        }
        if (printBtn.length > 0) {
            printBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...');
        }
        
        $('#paymentModal').modal('hide');
        
        console.log('📤 Submitting form to:', form.action);
        console.log('📊 Form method:', form.method);
        
        const formData = new FormData(form);
        console.log('📋 Form data:');
        for (let [key, value] of formData.entries()) {
            console.log(`  ${key}: ${value}`);
        }
        
        setTimeout(function() {
            try {
                // إرسال الفورم مباشرة بدون تأكيد
                HTMLFormElement.prototype.submit.call(form);
                console.log('✅ Form submitted successfully!');
                
            } catch (error) {
                console.error('❌ Error submitting form:', error);
                alert('حدث خطأ أثناء إرسال البيانات: ' + error.message);
                
                if (saveBtn.length > 0) {
                    saveBtn.prop('disabled', false).html('<i class="fas fa-save me-1"></i>حفظ الطلب');
                }
                if (printBtn.length > 0) {
                    printBtn.prop('disabled', false).html('<i class="fas fa-print me-1"></i>حفظ وطباعة');
                }
            }
        }, 100);
        
        return true;
    };
    
    $('#barcodeInput').focus();
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl + F or F3 for search focus
        if ((e.ctrlKey && e.key === 'f') || e.key === 'F3') {
            e.preventDefault();
            $('#itemFilterInput').focus().select();
        }
        
        // Escape to clear search
        if (e.key === 'Escape') {
            if ($('#itemFilterInput').is(':focus') && $('#itemFilterInput').val() !== '') {
                $('#clearFilter').click();
            }
        }
        
        // Alt + B for barcode input focus
        if (e.altKey && e.key === 'b') {
            e.preventDefault();
            $('#barcodeInput').focus().select();
        }
        
        // Alt + S for search input focus  
        if (e.altKey && e.key === 's') {
            e.preventDefault();
            $('#searchInput').focus().select();
        }
    });
    
    window.handleFormSubmit = function(form) {
        console.log('Form submit handler called');
        return true;
    };
}); // End of document.ready

// ========================================
// Form Validation
// ========================================
function validatePOSForm() {
    console.log('=== validatePOSForm() called ===');
    
    let items = $('#itemData .item-card-order');
    console.log('📊 Items in order:', items.length);
    
    if (items.length === 0) {
        console.log('⚠️ No items found in order');
        alert('يجب إضافة صنف واحد على الأقل للطلب');
        return false;
    }
    
    let itmnames = $('input[name="itmname[]"]');
    let itmqtys = $('input[name="itmqty[]"]');
    let itmprices = $('input[name="itmprice[]"]');
    
    console.log('📋 Form fields check:');
    console.log('  - Items names:', itmnames.length);
    console.log('  - Items quantities:', itmqtys.length);
    console.log('  - Items prices:', itmprices.length);
    
    if (itmnames.length === 0) {
        console.error('❌ No item names found!');
        alert('خطأ: لا توجد أصناف في النموذج');
        return false;
    }
    
    let pro_tybe = $('input[name="pro_tybe"]').val();
    let store_id = $('select[name="store_id"]').val();
    let acc2_id = $('select[name="acc2_id"]').val();
    let emp_id = $('select[name="emp_id"]').val();
    
    console.log('🔍 Required fields check:');
    console.log('  - pro_tybe:', pro_tybe);
    console.log('  - store_id:', store_id);
    console.log('  - acc2_id:', acc2_id);
    console.log('  - emp_id:', emp_id);
    
    if (!pro_tybe || pro_tybe == '0') {
        console.error('❌ pro_tybe is missing or zero');
        alert('خطأ: نوع الفاتورة غير محدد');
        return false;
    }
    
    if (!store_id || store_id == '0') {
        console.error('❌ store_id is missing or zero');
        alert('خطأ: يجب اختيار المخزن');
        return false;
    }
    
    if (!acc2_id || acc2_id == '0') {
        console.error('❌ acc2_id is missing or zero');
        alert('خطأ: يجب اختيار العميل');
        return false;
    }
    
    if (!emp_id || emp_id == '0') {
        console.error('❌ emp_id is missing or zero');
        alert('خطأ: يجب اختيار الموظف');
        return false;
    }
    
    console.log('✅ Validation passed - Items found:', items.length);
    return true;
}

function dis() {
    return validatePOSForm();
}

// ========================================
// Recent Orders Functions
// ========================================
function loadRecentOrders() {
    console.log('Loading recent orders...');
    $('#recentOrdersList').html(`
        <tr>
            <td colspan="8" class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">جاري التحميل...</span>
                </div>
                <p class="mt-2">جاري تحميل الطلبات...</p>
            </td>
        </tr>
    `);

    $.ajax({
        url: 'ajax/get_recent_orders.php',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            console.log('AJAX Response:', response);
            
            if (response.success && response.orders && response.orders.length > 0) {
                let html = '';
                response.orders.forEach((order, index) => {
                    html += `
                        <tr>
                            <td>${index + 1}</td>
                            <td><strong>${order.invoice_number}</strong></td>
                            <td>${order.date}</td>
                            <td>${order.customer_name}</td>
                            <td>
                                <span class="badge bg-info">${order.type}</span>
                            </td>
                            <td class="text-nowrap fw-bold text-success">
                                ${order.total.toFixed(2)} ج.م
                            </td>
                            <td>
                                <span class="badge ${order.status === 'مكتمل' ? 'bg-success' : 'bg-warning'}">
                                    ${order.status}
                                </span>
                            </td>
                            <td class="text-nowrap">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button class="btn btn-warning edit-order" data-id="${order.id}" title="تعديل">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-secondary print-order" data-id="${order.id}" title="طباعة الفاتورة">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <button class="btn btn-danger delete-order" data-id="${order.id}" title="حذف">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                                ${order.notes ? `<span class="text-muted ms-2" title="${order.notes}"><i class="fas fa-sticky-note"></i></span>` : ''}
                            </td>
                        </tr>
                    `;
                });
                $('#recentOrdersList').html(html);
                console.log('Orders loaded successfully:', response.orders.length);
            } else {
                $('#recentOrdersList').html(`
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">لا توجد طلبات سابقة</p>
                            <small class="text-muted">سيظهر هنا آخر 10 طلبات بعد إنشاء أول طلب</small>
                        </td>
                    </tr>
                `);
                console.log('No orders found');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading recent orders:', error);
            console.error('XHR status:', xhr.status);
            console.error('Response text:', xhr.responseText);
            
            $('#recentOrdersList').html(`
                <tr>
                    <td colspan="8" class="text-center py-5 text-danger">
                        <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                        <p>حدث خطأ أثناء تحميل الطلبات</p>
                        <small class="d-block">${error}</small>
                        <button class="btn btn-sm btn-outline-primary mt-2" onclick="loadRecentOrders()">
                            <i class="fas fa-sync-alt me-1"></i> إعادة المحاولة
                        </button>
                    </td>
                </tr>
            `);
        }
    });
}

function editOrder(orderId) {
    console.log('Edit order:', orderId);
    window.location.href = 'pos_barcode.php?edit=' + orderId;
}

function deleteOrder(orderId) {
    if (confirm('هل أنت متأكد من حذف هذا الطلب؟ لا يمكن التراجع عن هذه العملية.')) {
        $.ajax({
            url: 'ajax/delete_order.php',
            type: 'POST',
            data: { id: orderId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    loadRecentOrders();
                    alert('تم حذف الطلب بنجاح');
                } else {
                    alert('حدث خطأ أثناء حذف الطلب: ' + (response.message || 'خطأ غير معروف'));
                }
            },
            error: function() {
                alert('حدث خطأ في الاتصال بالخادم');
            }
        });
    }
}

// Initialize recent orders functionality
$(document).ready(function() {
    $(document).on('click', '.recent-orders-btn, #recentOrdersBtn1, #recentOrdersBtn2', function(e) {
        e.preventDefault();
        console.log('Recent orders button clicked');
        const offcanvas = new bootstrap.Offcanvas(document.getElementById('recentOrdersModal'));
        offcanvas.show();
        loadRecentOrders();
    });

    // Handle edit order button
    $(document).on('click', '.edit-order', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const orderId = $(this).data('id');
        console.log('Edit button clicked for order:', orderId);
        editOrder(orderId);
    });

    // Handle delete order button
    $(document).on('click', '.delete-order', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const orderId = $(this).data('id');
        console.log('Delete button clicked for order:', orderId);
        deleteOrder(orderId);
    });

    // Handle print order button
    $(document).on('click', '.print-order', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const orderId = $(this).data('id');
        console.log('Print button clicked for order:', orderId);
        window.open('print/receipt.php?id=' + orderId, '_blank');
    });

    // Load orders when offcanvas is shown
    $('#recentOrdersModal').on('shown.bs.offcanvas', function() {
        loadRecentOrders();
    });
});

// ========================================
// Daily Sales Report Print Function
// ========================================
function printDailySalesReport() {
    console.log('Opening daily sales report...');
    window.open('print/daily_sales_receipt.php', '_blank');
}



// ========================================
// Shift Management Functions
// ========================================
// Shift functions moved to pos_barcode.php inline script for better error handling

function closeShift() {
    if (confirm('هل أنت متأكد من إغلاق الشيفت؟')) {
        window.location.href = 'logout.php';
    }
}
