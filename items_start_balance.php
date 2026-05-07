<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                <div class="row">
                    <div class="col">
                <h2 class="hadi-fade-in2 bg-zinc-200">ضبط الارصدة الافتتاحية للمحازن</h2>
                    </div>
                    <div class="col text-left" >
                    <ul style="float: left;">
                    <li>
                                <button class="btn bg-red-400" id="btnReset">تصفير الرصيد الافتتاحي</button>
                                <button class="btn bg-yellow-400" id="btnEdit">تعديل بضاعه اول المدة</button>
                                <button class="btn bg-green-400" id="btnSave" style="display:none;">حفظ</button>
                        </li>
                    </ul>    
                    </div>
                </div>    
            </div>






            <div class="card-body">
                <div class="table table-responsive table-stripped" id="horsTable">
                    <table class="table" id="myTable" data-page-length="50">
                        <thead>
                            <tr class="bg-gray-300">
                                <th>#</th>
                                <th>كود الصنف</th>
                                <th>اسم الصنف</th>
                                <th>الوحده</th>
                                <th>رصيد اول المدة الجديد</th>
                                <th>رصيد اول المدة الحالي</th>
                                <th>سعر اول المدة الجديد</th>
                                <th>سعر اول المدة الحالي</th>
                                <th>التسوية</th>   
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql = "SELECT * FROM myitems WHERE isdeleted = 0 ORDER BY iname";
                            $result = $conn->query($sql);
                            $count = 0;
                            while ($row = $result->fetch_assoc()) {
                                $itmid = $row['id'];
                                
                                // جلب اسم الوحدة
                                $unit_result = $conn->query("SELECT uname FROM myunits WHERE id = (SELECT unit_id FROM item_units WHERE item_id = $itmid)");
                                $unit_name = '';
                                if ($unit_result && $unit_result->num_rows > 0) {
                                    $unit_row = $unit_result->fetch_assoc();
                                    $unit_name = $unit_row['uname'];
                                }
                                
                                // الرصيد الحالي من جدول myitems
                                $current_qty = isset($row['itmqty']) ? floatval($row['itmqty']) : 0;
                                $current_price = isset($row['cost_price']) ? floatval($row['cost_price']) : 0;
                                
                                $count++;
                            ?>
                            <tr>
                                <td><?= $count ?></td>
                                <td><?= htmlspecialchars($row['code']) ?></td>
                                <td><?= htmlspecialchars($row['iname']) ?></td>
                                <td><?= htmlspecialchars($unit_name) ?></td>
                                <td>
                                    <input type="number" 
                                           class="form-control form-control-sm new-qty" 
                                           name="new_qty[<?= $itmid ?>]" 
                                           step="0.01" 
                                           value="<?= $current_qty ?>"
                                           data-item-id="<?= $itmid ?>"
                                           readonly
                                           style="width: 120px; background-color: #f5f5f5;">
                                </td>
                                <td class="current-qty"><?= number_format($current_qty, 2) ?></td>
                                <td>
                                    <input type="number" 
                                           class="form-control form-control-sm new-price" 
                                           name="new_price[<?= $itmid ?>]" 
                                           step="0.01" 
                                           value="<?= $current_price ?>"
                                           data-item-id="<?= $itmid ?>"
                                           readonly
                                           style="width: 120px; background-color: #f5f5f5;">
                                </td>
                                <td class="current-price"><?= number_format($current_price, 2) ?></td>
                                <td class="settlement-<?= $itmid ?>"><?= number_format($current_qty * $current_price, 2) ?></td>
                            </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
            
        </div>
    </section>
</div>

<?php include('includes/footer.php') ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const qtyInputs = document.querySelectorAll('.new-qty');
    const priceInputs = document.querySelectorAll('.new-price');
    const btnEdit = document.getElementById('btnEdit');
    const btnSave = document.getElementById('btnSave');
    const btnReset = document.getElementById('btnReset');

    // تفعيل التعديل عند الضغط على زر "تعديل بضاعة اول المدة"
    btnEdit.addEventListener('click', function() {
        qtyInputs.forEach(input => {
            input.removeAttribute('readonly');
            input.style.backgroundColor = '';
        });
        priceInputs.forEach(input => {
            input.removeAttribute('readonly');
            input.style.backgroundColor = '';
        });
        btnEdit.style.display = 'none';
        btnSave.style.display = '';
    });

    // تصفير الرصيد الافتتاحي
    btnReset.addEventListener('click', function() {
        if (!confirm('هل أنت متأكد من تصفير جميع الأرصدة الافتتاحية؟')) return;
        qtyInputs.forEach(input => {
            input.value = '0.00';
            const itemId = input.getAttribute('data-item-id');
            calculateSettlement(itemId);
        });
        priceInputs.forEach(input => {
            input.value = '0.00';
            const itemId = input.getAttribute('data-item-id');
            calculateSettlement(itemId);
        });
    });

    // حفظ البيانات عبر AJAX
    btnSave.addEventListener('click', function() {
        const data = { action: 'save_balances', new_qty: {}, new_price: {} };

        qtyInputs.forEach(input => {
            data.new_qty[input.getAttribute('data-item-id')] = input.value;
        });
        priceInputs.forEach(input => {
            data.new_price[input.getAttribute('data-item-id')] = input.value;
        });

        // تحويل البيانات لـ FormData
        const formData = new FormData();
        formData.append('action', 'save_balances');
        Object.entries(data.new_qty).forEach(([id, val]) => formData.append(`new_qty[${id}]`, val));
        Object.entries(data.new_price).forEach(([id, val]) => formData.append(`new_price[${id}]`, val));

        btnSave.disabled = true;
        btnSave.textContent = 'جاري الحفظ...';

        fetch('save_start_balance.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    alert(res.message);
                    // إعادة الـ inputs لـ readonly بعد الحفظ
                    qtyInputs.forEach(input => {
                        input.setAttribute('readonly', true);
                        input.style.backgroundColor = '#f5f5f5';
                    });
                    priceInputs.forEach(input => {
                        input.setAttribute('readonly', true);
                        input.style.backgroundColor = '#f5f5f5';
                    });
                    btnSave.style.display = 'none';
                    btnEdit.style.display = '';
                } else {
                    alert('حدث خطأ: ' + res.message);
                }
            })
            .catch(() => alert('فشل الاتصال بالسيرفر'))
            .finally(() => {
                btnSave.disabled = false;
                btnSave.textContent = 'حفظ';
            });
    });
    function calculateSettlement(itemId) {
        const qtyInput = document.querySelector(`.new-qty[data-item-id="${itemId}"]`);
        const priceInput = document.querySelector(`.new-price[data-item-id="${itemId}"]`);
        const settlementCell = document.querySelector(`.settlement-${itemId}`);
        
        const qty = parseFloat(qtyInput.value) || 0;
        const price = parseFloat(priceInput.value) || 0;
        settlementCell.textContent = (qty * price).toFixed(2);
    }
    
    qtyInputs.forEach(input => {
        input.addEventListener('input', function() {
            calculateSettlement(this.getAttribute('data-item-id'));
        });
    });
    
    priceInputs.forEach(input => {
        input.addEventListener('input', function() {
            calculateSettlement(this.getAttribute('data-item-id'));
        });
    });
});
</script>

