<!-- مودال السداد البسيط -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">سداد نقدي</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label>الطاولة:</label>
                    <input type="text" class="form-control" id="payment_table_name" readonly>
                </div>
                <div class="mb-3">
                    <label>المبلغ المطلوب:</label>
                    <input type="number" class="form-control" id="payment_amount" value="100.00">
                </div>
                <input type="hidden" id="table_id_hidden">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-success" onclick="confirmPayment()">تأكيد السداد</button>
            </div>
        </div>
    </div>
</div>

<script>
function openPaymentModal(tableId) {
    $('#payment_table_name').val('طاولة رقم ' + tableId);
    $('#table_id_hidden').val(tableId);
    $('#payment_amount').val('150.00');
    $('#paymentModal').modal('show');
}

function confirmPayment() {
    const tableId = $('#table_id_hidden').val();
    const amount = $('#payment_amount').val();
    
    if (!tableId || !amount || amount <= 0) {
        alert('بيانات غير صحيحة');
        return;
    }
    
    alert('تم السداد بنجاح!\nالطاولة: ' + tableId + '\nالمبلغ: ' + amount + ' جنيه');
    $('#paymentModal').modal('hide');
}
</script>