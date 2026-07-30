<style>
.loading {
    opacity: 0.6;
    pointer-events: none;
}
.loading::after {
    content: "";
    position: absolute;
    top: 50%;
    left: 50%;
    width: 20px;
    height: 20px;
    margin: -10px 0 0 -10px;
    border: 2px solid #fff;
    border-top: 2px solid transparent;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
#paymentModal .form-control:focus {
    border-color: #28a745;
    box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
}
#paymentModal .card {
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
</style>

<!-- مودال السداد -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="paymentModalLabel">
                    <i class="fas fa-credit-card"></i> سداد الطلب
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <h6 class="mb-0">تفاصيل الطلب</h6>
                            </div>
                            <div class="card-body">
                                <div class="form-group mb-2">
                                    <label>الطاولة:</label>
                                    <input type="text" class="form-control" id="payment_table_name" readonly>
                                </div>
                                <div class="form-group mb-2">
                                    <label>التاريخ:</label>
                                    <input type="text" class="form-control" id="payment_date" readonly>
                                </div>
                                <div class="form-group mb-2">
                                    <label>رقم الطلب:</label>
                                    <input type="text" class="form-control" id="payment_order_id" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0">المبالغ</h6>
                            </div>
                            <div class="card-body">
                                <div class="row mb-2">
                                    <div class="col-6"><strong>الإجمالي:</strong></div>
                                    <div class="col-6">
                                        <input type="number" class="form-control" id="payment_total" readonly>
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>الخصم:</strong></div>
                                    <div class="col-6">
                                        <input type="number" class="form-control" id="payment_discount" step="0.01" readonly>
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>الصافي:</strong></div>
                                    <div class="col-6">
                                        <input type="number" class="form-control bg-success text-white" id="payment_net" readonly>
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>المدفوع:</strong></div>
                                    <div class="col-6">
                                        <input type="number" class="form-control" id="payment_paid" step="0.01">
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>المتبقي:</strong></div>
                                    <div class="col-6">
                                        <input type="number" class="form-control bg-danger text-white" id="payment_remaining" readonly>
                                    </div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-6"><strong>الباقي للعميل:</strong></div>
                                    <div class="col-6">
                                        <input type="number" class="form-control bg-light" id="payment_change" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0">طريقة الدفع</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="payment_method" id="cash_payment" value="cash" checked>
                                            <label class="form-check-label" for="cash_payment">
                                                <i class="fas fa-money-bill-wave"></i> نقدي
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="payment_method" id="card_payment" value="card">
                                            <label class="form-check-label" for="card_payment">
                                                <i class="fas fa-credit-card"></i> بطاقة
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="payment_method" id="mixed_payment" value="mixed">
                                            <label class="form-check-label" for="mixed_payment">
                                                <i class="fas fa-coins"></i> مختلط
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="mixed_payment_details" style="display: none;" class="mt-3">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label>نقدي:</label>
                                            <input type="number" class="form-control" id="cash_amount" step="0.01" value="0">
                                        </div>
                                        <div class="col-md-6">
                                            <label>بطاقة:</label>
                                            <input type="number" class="form-control" id="card_amount" step="0.01" value="0">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="form-group">
                            <label>ملاحظات:</label>
                            <textarea class="form-control" id="payment_notes" rows="2" placeholder="ملاحظات إضافية..."></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button type="button" class="btn btn-info" id="save_without_print">
                    <i class="fas fa-save"></i> حفظ فقط
                </button>
                <button type="button" class="btn btn-success" id="save_and_print">
                    <i class="fas fa-print"></i> حفظ وطباعة
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let posTablePaymentRequestActive = false;

function tablePaymentMoneyApi() {
    if (!window.POSOrderApi || typeof window.POSOrderApi.decimalString !== 'function') {
        throw new Error('POS_MONEY_KERNEL_UNAVAILABLE');
    }

    return window.POSOrderApi;
}

function tablePaymentMoney(value) {
    return tablePaymentMoneyApi().decimalString(
        value === null || value === undefined || value === '' ? '0' : value,
        2,
        '0'
    );
}

function tablePaymentIsPositive(value) {
    return tablePaymentMoneyApi().compareDecimalStrings(tablePaymentMoney(value), '0.00', 2) > 0;
}

$(document).ready(function() {
    // إظهار/إخفاء تفاصيل الدفع المختلط
    $('input[name="payment_method"]').change(function() {
        if ($(this).val() === 'mixed') {
            $('#mixed_payment_details').show();
        } else {
            $('#mixed_payment_details').hide();
            $('#cash_amount').val(0);
            $('#card_amount').val(0);
        }
    });
    
    // حساب الصافي والمتبقي تلقائياً
    $('#payment_discount, #payment_paid').on('input', function() {
        calculatePaymentTotals();
    });
    
    // حساب المبالغ في الدفع المختلط
    $('#cash_amount, #card_amount').on('input', function() {
        const cash = tablePaymentMoney($('#cash_amount').val());
        const card = tablePaymentMoney($('#card_amount').val());
        $('#payment_paid').val(tablePaymentMoneyApi().addDecimalStrings(cash, card, 2));
        calculatePaymentTotals();
    });
    
    // حفظ بدون طباعة
    $('#save_without_print').click(function() {
        processPayment(false);
    });
    
    // حفظ مع الطباعة
    $('#save_and_print').click(function() {
        processPayment(true);
    });
});

function calculatePaymentTotals() {
    const money = tablePaymentMoneyApi();
    const total = tablePaymentMoney($('#payment_total').val());
    const discount = tablePaymentMoney($('#payment_discount').val());
    const paid = tablePaymentMoney($('#payment_paid').val());
    const amountDue = tablePaymentMoney($('#payment_amount_due').val());
    const net = money.subtractDecimalStrings(total, discount, 2);
    const paidCoversDue = money.compareDecimalStrings(paid, amountDue, 2) >= 0;
    const remaining = paidCoversDue
        ? '0.00'
        : money.subtractDecimalStrings(amountDue, paid, 2);
    const change = paidCoversDue
        ? money.subtractDecimalStrings(paid, amountDue, 2)
        : '0.00';
    
    $('#payment_net').val(net);
    $('#payment_remaining').val(remaining);
    $('#payment_change').val(change);
    
    // تغيير لون المتبقي
    if (money.compareDecimalStrings(remaining, '0.00', 2) > 0) {
        $('#payment_remaining').removeClass('bg-success').addClass('bg-danger');
    } else {
        $('#payment_remaining').removeClass('bg-danger').addClass('bg-success');
    }
}

function openPaymentModal(tableId, tableName) {
    // جلب بيانات الطلب
    $.ajax({
        url: 'ajax/get_table_amount.php',
        method: 'POST',
        data: { table_id: tableId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#payment_table_name').val(tableName);
                $('#payment_date').val(new Date().toLocaleDateString('ar-EG'));
                $('#payment_order_id').val(response.order_id || '');
                $('#payment_total').val(tablePaymentMoney(response.total));
                $('#payment_discount').val(tablePaymentMoney(response.discount));
                $('#payment_paid').val(tablePaymentMoney(response.remaining));
                
                // حفظ معرف الطاولة في حقل مخفي
                if (!$('#hidden_table_id').length) {
                    $('#paymentModal .modal-body').append('<input type="hidden" id="hidden_table_id">');
                }
                $('#hidden_table_id').val(tableId);
                if (!$('#hidden_order_id').length) {
                    $('#paymentModal .modal-body').append('<input type="hidden" id="hidden_order_id">');
                }
                $('#hidden_order_id').val(response.order_id || '');
                if (!$('#payment_mutation_version').length) {
                    $('#paymentModal .modal-body').append('<input type="hidden" id="payment_mutation_version">');
                }
                $('#payment_mutation_version').val(parseInt(response.mutation_version || '0', 10) || '');
                if (!$('#payment_amount_due').length) {
                    $('#paymentModal .modal-body').append('<input type="hidden" id="payment_amount_due">');
                }
                $('#payment_amount_due').val(tablePaymentMoney(response.remaining));
                
                calculatePaymentTotals();
                $('#paymentModal').modal('show');
            } else {
                alert('خطأ: ' + response.message);
            }
        },
        error: function() {
            alert('خطأ في جلب بيانات الطلب');
        }
    });
}

function processPayment(printInvoice) {
    if (posTablePaymentRequestActive) {
        return;
    }
    const requestScope = 'pos.payment.table';
    const orderId = $('#hidden_order_id').val() || $('#payment_order_id').val();
    const tableId = $('#hidden_table_id').val();
    const mutationVersion = parseInt($('#payment_mutation_version').val() || '0', 10) || 0;
    const total = tablePaymentMoney($('#payment_total').val());
    const discount = tablePaymentMoney($('#payment_discount').val());
    const net = tablePaymentMoney($('#payment_net').val());
    const amountDue = tablePaymentMoney($('#payment_amount_due').val());
    const paymentMethod = $('input[name="payment_method"]:checked').val();
    const notes = $('#payment_notes').val();
    const cashAmount = paymentMethod === 'mixed'
        ? tablePaymentMoney($('#cash_amount').val())
        : (paymentMethod === 'cash' ? tablePaymentMoney($('#payment_paid').val()) : '0.00');
    const cardAmount = paymentMethod === 'mixed'
        ? tablePaymentMoney($('#card_amount').val())
        : (paymentMethod === 'card' ? tablePaymentMoney($('#payment_paid').val()) : '0.00');
    const paid = paymentMethod === 'mixed'
        ? tablePaymentMoneyApi().addDecimalStrings(cashAmount, cardAmount, 2)
        : tablePaymentMoney($('#payment_paid').val());
    
    if (!tableId || !orderId || mutationVersion < 1) {
        alert('خطأ: لم يتم تحديد الطاولة أو الطلب');
        return;
    }
    
    if (!tablePaymentIsPositive(paid)) {
        alert('يجب إدخال مبلغ الدفع');
        return;
    }

    if (paymentMethod !== 'cash'
        && paymentMethod !== 'mixed'
        && tablePaymentMoneyApi().compareDecimalStrings(paid, amountDue, 2) > 0) {
        alert('لا يمكن أن تتجاوز الدفعة غير النقدية المبلغ المتبقي');
        return;
    }
    if (paymentMethod === 'mixed'
        && tablePaymentMoneyApi().compareDecimalStrings(cardAmount, amountDue, 2) > 0) {
        alert('لا يمكن أن يتجاوز جزء البطاقة المبلغ المتبقي');
        return;
    }
    
    // تعطيل الأزرار أثناء المعالجة
    posTablePaymentRequestActive = true;
    $('#save_without_print, #save_and_print').prop('disabled', true).addClass('loading');
    
    const paymentData = {
        table_id: tableId,
        order_id: orderId,
        mutation_version: mutationVersion,
        total: total,
        discount: discount,
        net: net,
        paid: paid,
        payment_method: paymentMethod,
        notes: notes,
        idempotency_key: getPOSTableIdempotencyKey(requestScope)
    };
    
    if (paymentMethod === 'mixed') {
        paymentData.tenders = [];
        if (tablePaymentIsPositive(cardAmount)) {
            paymentData.tenders.push({
                payment_method: 'card',
                amount: cardAmount,
                reference_no: notes
            });
        }
        if (tablePaymentIsPositive(cashAmount)) {
            paymentData.tenders.push({
                payment_method: 'cash',
                amount: cashAmount
            });
        }
    } else {
        paymentData.tenders = [{
            payment_method: paymentMethod,
            amount: paid,
            reference_no: paymentMethod === 'cash' ? '' : notes
        }];
    }
    
    $.ajax({
        url: 'ajax/process_table_payment.php',
        method: 'POST',
        data: paymentData,
        dataType: 'json',
        beforeSend: attachPOSTableCsrfHeader,
        success: function(response) {
            if (response.success) {
                clearPOSTableIdempotencyKey(requestScope);
                alert('تم السداد بنجاح');
                $('#paymentModal').modal('hide');
                
                // مسح البيانات من المودال
                resetPaymentModal();
                
                if (printInvoice && response.invoice_id) {
                    // فتح نافذة الطباعة
                    window.open('print/table_invoice.php?invoice_id=' + response.invoice_id, '_blank');
                }
                
                // تحديث الطاولات إذا كانت الدالة موجودة
                if (typeof loadTables === 'function') {
                    loadTables();
                }
                
                // مسح الطلب الحالي إذا كانت الدالة موجودة
                if (typeof clearOrder === 'function') {
                    clearOrder();
                }
                
                // إعادة تحميل الصفحة لتحديث حالة الطاولات
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                posTablePaymentRequestActive = false;
                alert('خطأ: ' + response.message);
            }
        },
        error: function() {
            posTablePaymentRequestActive = false;
            alert('خطأ في معالجة السداد');
        },
        complete: function() {
            // إعادة تفعيل الأزرار
            $('#save_without_print, #save_and_print').prop('disabled', false).removeClass('loading');
        }
    });
}

// إعادة تعيين مودال الدفع
function resetPaymentModal() {
    $('#payment_table_name').val('');
    $('#payment_date').val('');
    $('#payment_order_id').val('');
    $('#payment_total').val('0.00');
    $('#payment_discount').val('0.00');
    $('#payment_net').val('0.00');
    $('#payment_paid').val('0.00');
    $('#payment_remaining').val('0.00');
    $('#payment_change').val('0.00');
    $('#payment_notes').val('');
    $('#hidden_order_id').val('');
    $('#hidden_table_id').val('');
    $('#payment_mutation_version').val('');
    $('#payment_amount_due').val('0.00');
    $('#cash_amount').val('0');
    $('#card_amount').val('0');
    $('input[name="payment_method"][value="cash"]').prop('checked', true);
    $('#mixed_payment_details').hide();
}
</script>
