<div class="tables bg-slate-700" id="tables"  style="width:50%;height:100%;position:absolute;left:0px;top:0px;z-index: 999;display:none;">
<style>
.table-container {
    display: inline-block;
    margin: 10px;
    text-align: center;
}
.table-actions {
    display: flex;
    gap: 5px;
    justify-content: center;
    margin-top: 5px;
}
.table-actions .btn {
    padding: 4px 8px;
    font-size: 12px;
    min-width: 50px;
}
.tab {
    display: flex;
    flex-direction: column;
    align-items: center;
    cursor: pointer;
    border-radius: 8px;
}
</style>
<button class="btn btn-danger absolute  top-1 left-1" id="closeTables">x</button>



<!-- Tables -->
<div class="table-container" data-table="1">
  <div class="tab bg-green-500 text-slate-50 text-lg p-7 m-4" onclick="selectTable(1)">
    <i class="fa fa-tv"></i>
    <p>1</p>
  </div>
  <div class="table-actions">
    <button class="btn btn-sm btn-warning" onclick="clearTable(1)">تفريغ</button>
    <button class="btn btn-sm btn-info" onclick="activateTable(1)">تشغيل</button>
  </div>
</div>

<div class="table-container" data-table="2">
  <div class="tab bg-green-500 text-slate-50 text-lg p-7 m-4" onclick="selectTable(2)">
    <i class="fa fa-tv"></i>
    <p>2</p>
  </div>
  <div class="table-actions">
    <button class="btn btn-sm btn-warning" onclick="clearTable(2)">تفريغ</button>
    <button class="btn btn-sm btn-info" onclick="activateTable(2)">تشغيل</button>
  </div>
</div>

<div class="table-container" data-table="3">
  <div class="tab bg-green-500 text-slate-50 text-lg p-7 m-4" onclick="selectTable(3)">
    <i class="fa fa-tv"></i>
    <p>3</p>
  </div>
  <div class="table-actions">
    <button class="btn btn-sm btn-warning" onclick="clearTable(3)">تفريغ</button>
    <button class="btn btn-sm btn-info" onclick="activateTable(3)">تشغيل</button>
  </div>
</div>

<div class="table-container" data-table="4">
  <div class="tab bg-green-500 text-slate-50 text-lg p-7 m-4" onclick="selectTable(4)">
    <i class="fa fa-tv"></i>
    <p>4</p>
  </div>
  <div class="table-actions">
    <button class="btn btn-sm btn-warning" onclick="clearTable(4)">تفريغ</button>
    <button class="btn btn-sm btn-info" onclick="activateTable(4)">تشغيل</button>
  </div>
</div>

<div class="table-container" data-table="5">
  <div class="tab bg-green-500 text-slate-50 text-lg p-7 m-4" onclick="selectTable(5)">
    <i class="fa fa-tv"></i>
    <p>5</p>
  </div>
  <div class="table-actions">
    <button class="btn btn-sm btn-warning" onclick="clearTable(5)">تفريغ</button>
    <button class="btn btn-sm btn-info" onclick="activateTable(5)">تشغيل</button>
  </div>
</div>


</div>

<!-- مودال السداد -->
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
                    <input type="text" class="form-control" id="payment_table" readonly>
                </div>
                <div class="mb-3">
                    <label>المبلغ:</label>
                    <input type="number" class="form-control" id="payment_amount" value="150.00">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-success" onclick="payNow()">تأكيد السداد</button>
            </div>
        </div>
    </div>
</div>

<script>
var selectedTableId = 0;

function selectTable(tableId) {
    // تغيير لون الطاولات
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('bg-blue-500');
        tab.classList.add('bg-green-500');
    });
    
    const selectedTab = document.querySelector(`[data-table="${tableId}"] .tab`);
    if (selectedTab) {
        selectedTab.classList.remove('bg-green-500');
        selectedTab.classList.add('bg-blue-500');
    }
    
    localStorage.setItem('selectedTable', tableId);
    selectedTableId = tableId;
    document.getElementById('tables').style.display = 'none';
    
    // جلب الإجمالي الفعلي للطاولة
    const formData = new FormData();
    formData.append('table_id', tableId);
    
    fetch('ajax/get_table_amount.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        console.log('استجابة الخادم:', data);
        if (data.success) {
            document.getElementById('payment_table').value = 'طاولة رقم ' + tableId;
            const amount = data.remaining > 0 ? data.remaining : data.total;
            document.getElementById('payment_amount').value = amount.toFixed(2);
            
            // فتح المودال باستخدام Bootstrap
            const modal = new bootstrap.Modal(document.getElementById('paymentModal'));
            modal.show();
        } else {
            alert('خطأ في جلب بيانات الطاولة: ' + (data.message || 'خطأ غير معروف'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('خطأ في الاتصال بالخادم');
    });
}

function payNow() {
    const amount = document.getElementById('payment_amount').value;
    if (amount > 0) {
        alert('تم السداد بنجاح!\nالطاولة: ' + selectedTableId + '\nالمبلغ: ' + amount + ' جنيه');
        const modal = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
        if (modal) modal.hide();
        printReceipt(selectedTableId, amount);
    } else {
        alert('يرجى إدخال مبلغ صحيح');
    }
}

function clearTable(tableId) {
    if(confirm('هل تريد تفريغ الطاولة رقم ' + tableId + '؟')) {
        alert('تم تفريغ الطاولة رقم ' + tableId + ' بنجاح');
        const tab = document.querySelector(`[data-table="${tableId}"] .tab`);
        if (tab) {
            tab.classList.remove('bg-red-500');
            tab.classList.add('bg-green-500');
        }
    }
}

function activateTable(tableId) {
    alert('تم تشغيل الطاولة رقم ' + tableId);
    const tab = document.querySelector(`[data-table="${tableId}"] .tab`);
    if (tab) {
        tab.classList.remove('bg-green-500');
        tab.classList.add('bg-red-500');
    }
}

function printReceipt(tableId, amount) {
    // فتح نافذة طباعة جديدة
    var printWindow = window.open('', '_blank');
    var receiptContent = `
        <html>
        <head>
            <title>فاتورة - طاولة ${tableId}</title>
            <style>
                body { font-family: Arial; text-align: center; padding: 20px; }
                .receipt { border: 1px solid #000; padding: 20px; margin: 20px; }
            </style>
        </head>
        <body>
            <div class="receipt">
                <h2>فاتورة سداد</h2>
                <p>الطاولة: ${tableId}</p>
                <p>المبلغ: ${amount} جنيه</p>
                <p>التاريخ: ${new Date().toLocaleDateString('ar-EG')}</p>
                <p>الوقت: ${new Date().toLocaleTimeString('ar-EG')}</p>
            </div>
        </body>
        </html>
    `;
    printWindow.document.write(receiptContent);
    printWindow.document.close();
    printWindow.print();
}

// إضافة مستمع لإغلاق المودال
document.addEventListener('DOMContentLoaded', function() {
    const closeBtn = document.getElementById('closeTables');
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            document.getElementById('tables').style.display = 'none';
        });
    }
});
</script>