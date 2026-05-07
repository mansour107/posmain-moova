<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php 
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $sqledit = "SELECT * FROM ot_head where id = $edit_id";
    $resedit = $conn->query($sqledit);
    $rowedit = $resedit->fetch_assoc();
}

// تحديد نوع السند
$voucher_type = '';
$voucher_icon = '';
$voucher_color = '';
$pro_tybe = 0;

if (isset($_GET['t'])) {
    if ($_GET['t'] == "recive") {
        $voucher_type = "سند قبض";
        $voucher_icon = "fa-hand-holding-usd";
        $voucher_color = "success";
        $pro_tybe = 1;
    } elseif ($_GET['t'] == "payment") {
        $voucher_type = "سند دفع";
        $voucher_icon = "fa-money-bill-wave";
        $voucher_color = "danger";
        $pro_tybe = 2;
    }
}

$is_edit = isset($_GET['edit']);
?>

<style>
.content-wrapper {
    background: #f8f9fa;
    min-height: 100vh;
}

.voucher-body {
    padding: 1.5rem;
    background: white;
}

.form-section {
    margin-bottom: 1.5rem;
    padding: 1.25rem;
    background: #f9fafb;
    border-radius: 12px;
    border: 1px solid #e5e7eb;
}

.section-title {
    font-size: 1rem;
    font-weight: 700;
    color: #1f2937;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e5e7eb;
}

.section-title i {
    color: #6366f1;
    font-size: 1.1rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 600;
    color: #374151;
    margin-bottom: 0.4rem;
}

.form-control {
    width: 100%;
    padding: 0.85rem 1rem;
    font-size: 0.95rem;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
    transition: all 0.3s;
    background: white;
    height: 48px;
}

select.form-control {
    height: 48px;
    line-height: 1.5;
    padding-top: 0.6rem;
    padding-bottom: 0.6rem;
}

select.form-control option {
    padding: 0.75rem 1rem;
    font-size: 0.95rem;
    line-height: 1.8;
    min-height: 40px;
}

/* Select2 dropdown styling */
.select2-container--default .select2-results__option {
    padding: 0.75rem 1rem;
    font-size: 0.95rem;
    line-height: 1.8;
    min-height: 40px;
}

.select2-container--default .select2-selection--single {
    height: 48px !important;
    padding: 0.6rem 1rem;
    border: 2px solid #e5e7eb !important;
    border-radius: 10px !important;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 28px !important;
    padding-left: 0 !important;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 46px !important;
}

.select2-dropdown {
    border: 2px solid #e5e7eb !important;
    border-radius: 10px !important;
}

.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: #6366f1 !important;
}

.select2-container--default.select2-container--focus .select2-selection--single {
    border-color: #6366f1 !important;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1) !important;
}

.form-control:focus {
    outline: none;
    border-color: #6366f1;
    background: white;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.form-control:disabled,
.form-control[readonly] {
    background: #f3f4f6;
    cursor: not-allowed;
    color: #6b7280;
}

.amount-input {
    font-size: 1.75rem;
    font-weight: 700;
    text-align: center;
    color: #059669;
    border-width: 3px;
    background: #ecfdf5 !important;
}

.voucher-footer {
    padding: 1.5rem;
    background: white;
    border-top: 2px solid #e5e7eb;
}

.btn-modern {
    padding: 0.75rem 2rem;
    font-size: 1rem;
    font-weight: 600;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.btn-primary-modern {
    background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
    color: white;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

.btn-primary-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
}

.btn-secondary-modern {
    background: white;
    color: #6b7280;
    border: 2px solid #e5e7eb;
}

.btn-secondary-modern:hover {
    background: #f9fafb;
    border-color: #d1d5db;
}

.alert-warning-modern {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 2px solid #fbbf24;
    border-radius: 12px;
    padding: 1.5rem;
    margin: 1.5rem;
}

.alert-warning-modern i {
    font-size: 2.5rem;
    color: #d97706;
}

@media (max-width: 768px) {
    .voucher-body {
        padding: 1rem;
    }
    
    .voucher-footer {
        padding: 1rem;
    }
    
    .form-section {
        padding: 1rem;
    }
    
    .alert-warning-modern {
        margin: 1rem;
    }
}
</style>

<div class="content-wrapper">
    <section class="content-header p-0">
        <?php if ($_GET == null): ?>
            <div class="alert-warning-modern text-center">
                <i class="fas fa-exclamation-triangle mb-3"></i>
                <h4 class="mb-2">تحذير</h4>
                <p class="mb-0">يبدو أنك دخلت بطريقة مخالفة للمعايير، برجاء الرجوع لمدير النظام</p>
            </div>
        <?php else: ?>
            <form action="<?php 
                if(isset($_GET['edit'])){
                    $edit = $_GET['edit'];
                    echo 'do/doedit_voucher.php?id='.$edit;
                } else {
                    echo 'do/doadd_voucher.php';
                }
            ?>" method="post" id="myForm">
                
                <input type="hidden" name="tybe" value="<?= $pro_tybe ?>">
                <?php if (isset($_GET['ins'])): ?>
                    <input type="hidden" name="ins_id" value="<?= $_GET['ins'] ?>">
                <?php endif; ?>

                <!-- Body -->
                <div class="voucher-body">
                    <!-- معلومات أساسية -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-info-circle"></i>
                            <span>المعلومات الأساسية</span>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label">رقم السند</label>
                                    <input type="text" name="voucher_id" class="form-control" readonly 
                                        value="<?php 
                                            $rowid = $conn->query("SELECT pro_id FROM ot_head where pro_tybe = $pro_tybe order by id desc limit 1")->fetch_assoc();
                                            if(isset($_GET['edit'])){
                                                echo $rowedit['pro_id'];
                                            } else {
                                                if ($rowid > 0) {
                                                    $pr_id = $rowid['pro_id']+1;
                                                    echo $pr_id;
                                                } else {
                                                    echo 1;
                                                }
                                            }
                                        ?>">
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label">التاريخ</label>
                                    <input type="date" name="vdate" class="form-control" 
                                        value="<?= isset($_GET['edit']) ? $rowedit['pro_date'] : date('Y-m-d') ?>">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">القيمة</label>
                                    <input required type="number" name="val" class="form-control amount-input frst" 
                                        id="value" step="0.01" min="0"
                                        value="<?php 
                                            if(isset($_GET['v'])){
                                                echo $_GET['v'];
                                            } elseif(isset($_GET['edit'])){
                                                echo $rowedit['pro_value'];
                                            }
                                        ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- الحسابات -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-wallet"></i>
                            <span>الحسابات</span>
                        </div>

                        <div class="row">
                            <div class="col-md-5">
                                <div class="form-group">
                                    <label class="form-label">الحساب</label>
                                    <select required name="account" class="form-control" 
                                        <?php if (!isset($_GET['acc'])) { echo 'id="myAccount"'; } ?>>
                                        <?php if (!isset($_GET['acc'])) { echo '<option value="">اختر حساب</option>'; } ?>
                                        <?php
                                        if (isset($_GET['acc'])) {
                                            $acc = $_GET['acc'];
                                            $resacc = $conn->query("SELECT * FROM acc_head where id = $acc");
                                        } else {
                                            $resacc = $conn->query("SELECT * FROM acc_head where is_basic = 0 AND is_fund != 1");
                                        }
                                        while ($rowacc = $resacc->fetch_assoc()) {
                                        ?>
                                        <option <?php 
                                            if(isset($_GET['edit'])){
                                                if(($_GET['t']=="payment") && ($rowacc['id'] == $rowedit['acc1'])){
                                                    echo "selected";
                                                } elseif(($_GET['t']=="recive") && ($rowacc['id'] == $rowedit['acc2'])){
                                                    echo "selected";
                                                }
                                            }
                                        ?> value="<?= $rowacc['id'] ?>">
                                            <?= $rowacc['code'] ?> - <?= $rowacc['aname'] ?>
                                        </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-5">
                                <div class="form-group">
                                    <label class="form-label">حساب الصندوق</label>
                                    <select name="fund_account" class="form-control" id="fund_account">
                                        <?php
                                        $resacc = $conn->query("SELECT * FROM acc_head where is_basic = 0 AND is_fund = 1");
                                        while ($rowacc = $resacc->fetch_assoc()) {
                                        ?>
                                        <option <?php 
                                            if(isset($_GET['edit'])){
                                                if(($_GET['t']=="payment") && ($rowacc['id'] == $rowedit['acc2'])){
                                                    echo "selected";
                                                } elseif(($_GET['t']=="recive") && ($rowacc['id'] == $rowedit['acc1'])){
                                                    echo "selected";
                                                }
                                            }
                                        ?> value="<?= $rowacc['id'] ?>">
                                            <?= $rowacc['code'] ?> - <?= $rowacc['aname'] ?>
                                        </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- تفاصيل إضافية -->
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-clipboard-list"></i>
                            <span>تفاصيل إضافية</span>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">مركز التكلفة</label>
                                    <select name="cost_center" class="form-control">
                                        <option value="">بدون مركز تكلفة</option>
                                        <?php
                                        $rescst = $conn->query("SELECT * FROM cost_centers");
                                        while ($rowcst = $rescst->fetch_assoc()) {
                                        ?>
                                        <option <?php
                                            if(isset($_GET['edit'])){
                                                if($rowcst['id'] == $rowedit['cost_center']){
                                                    echo "selected";
                                                }
                                            }
                                        ?> value="<?= $rowcst['id'] ?>">
                                            <?= $rowcst['cname'] ?>
                                        </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-8">
                                <div class="form-group">
                                    <label class="form-label">ملاحظات</label>
                                    <input type="text" name="info" class="form-control" 
                                        placeholder="أدخل ملاحظات إضافية..."
                                        value="<?= isset($_GET['edit']) ? $rowedit['info'] : '' ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="voucher-footer">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <button type="submit" class="btn-modern btn-primary-modern w-100" id="submit">
                                <i class="fas fa-save"></i>
                                <span>حفظ السند (F12)</span>
                            </button>
                        </div>
                        <div class="col-md-6">
                            <button type="reset" class="btn-modern btn-secondary-modern w-100">
                                <i class="fas fa-redo"></i>
                                <span>إعادة تعيين</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    if ($('#myAccount').length) {
        $('#myAccount').select2({
            placeholder: 'اختر حساب',
            dir: 'rtl',
            language: 'ar'
        });
    }
    
    // Show submit button always (removed hide logic)
    
    $('#value, #myAccount').on('change input', function() {
        const value = parseFloat($('#value').val()) || 0;
        const account = $('#myAccount').val();
        
        if (value > 0 && (account || !$('#myAccount').length)) {
            $('#submit').show();
        } else {
            $('#submit').hide();
        }
    });
    
    // Trigger check on page load
    $('#value').trigger('input');
    
    // F12 shortcut
    $(document).keydown(function(e) {
        if (e.key === 'F12') {
            e.preventDefault();
            $('#myForm').submit();
        }
    });
    
    // Auto-focus on amount input
    $('.frst').focus();
});
</script>

<?php include('includes/footer.php') ?>
