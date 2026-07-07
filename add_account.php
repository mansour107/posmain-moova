<?php
require_once __DIR__ . '/includes/auth_guard.php';
include __DIR__ . '/includes/connect.php';
require_once __DIR__ . '/includes/page_guard.php';
page_guard('accounting.view', $conn);
?>
<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php if (isset($_GET['parent_id'])) {    
    $parent =  $_GET['parent_id'];
    $sqllst = "SELECT * FROM acc_head where code like '$parent%' AND is_basic = 0 order by id desc";
    $rowlast = $conn->query($sqllst)->fetch_assoc();if ($rowlast != null ) {
    $acccode = explode($parent,$rowlast['code']);
    $lstacc = $acccode[1] ;

        $lstacc_int = (int)$lstacc; // Convert to integer
        $lstacc_int++; // Increment
        $lstacc_new = sprintf("%03d", $lstacc_int); // Format back to string with leading zeros



    $last_id = $parent.$lstacc_new;
    

        }else {$last_id = $parent."001";}
        }else{$parent = 0 ;$last_id="";}
        if (isset($_GET['parent_id'])) {
        // جلب جميع الحسابات الأساسية + الحساب الأب المحدد
        $first_digit = substr($parent, 0, 1); // أول رقم من الكود
        $sqlasc = "SELECT * FROM acc_head 
                   WHERE (is_basic = 1 AND code LIKE '$first_digit%') 
                   OR code = '$parent' 
                   ORDER BY code";
        $resacs = $conn->query($sqlasc);
        } else {
            $sqlasc = "SELECT * FROM acc_head WHERE is_basic = 1 ORDER BY code";
            $resacs = $conn->query($sqlasc);
        }
        ?>
    <div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">

<?php 
if (($parent == '122' && auth_guard_has_legacy_flag('add_clients', $conn)) ||
($parent == '211' && auth_guard_has_legacy_flag('add_suppliers', $conn)) ||
($parent == '121' && auth_guard_has_legacy_flag('add_funds', $conn)) ||
($parent == '124' && auth_guard_has_legacy_flag('add_banks', $conn)) ||
($parent == '44' && auth_guard_has_legacy_flag('add_expenses', $conn)) ||
($parent == '32' && auth_guard_has_legacy_flag('add_revenuses', $conn)) ||
($parent == '212' && auth_guard_has_legacy_flag('add_credits', $conn)) ||
($parent == '125' && auth_guard_has_legacy_flag('add_depits', $conn)) ||
($parent == '221' && auth_guard_has_legacy_flag('add_partners', $conn)) ||
($parent == '11' && auth_guard_has_legacy_flag('add_assets', $conn)) ||
($parent == '213' && auth_guard_has_legacy_flag('add_employees', $conn)) ||
($parent == '112' && auth_guard_has_legacy_flag('add_rentables', $conn)) ||
($parent == '123' && auth_guard_has_legacy_flag('add_stock', $conn))) {
?>



        <form id="myForm" action="do/doadd_account.php" method="post">
            <input type="text" name="q"  value="<?= $parent ?>" hidden>
        <div class="card">
            <div class="card-header bg-blue-400">
                <h3>اضافه حساب</h3>
            </div>
             <div class="card-body">
            <div class="row">
                <div class="col col-3">
                <div class="form-group">
                            <label for="">الكود</label><span class="text-danger">*</span>
                            <input required class="form-control font-bold"  type="text" name="code" id="" value="<?= $last_id ?>">
                        </div>
                </div>
                <div class="col">
                    
                <div class="form-group">
                            <label for="">الاسم</label><span class="text-danger">*</span>
                            <input required class="form-control font-bold form-control font-bold frst" type="text" name="aname" id="frst">
                            <div id="resaname" ></div>
                        </div>
                </div>
            </div>

            <div class="row">
                <div class="col col-4">
                    
                <div class="form-group">
                            <label for="">نوع الحساب</label><span class="text-danger">*</span>
                            <select class="form-control font-bold" name="is_basic" id="">
                                <option value="1">اساسي</option>
                                <option selected value="0">حساب عادي</option>
                            </select>
                        </div>
                </div>
                <div class="col">
                    
                <div class="form-group">
                            <label for="">يتبع ل</label><span class="text-danger">*</span>
                            <select class="form form-control font-bold"  name="parent_id" id="">
                                
                                <?php
                                while ($rowacs = $resacs->fetch_assoc()) {
                                    // تحديد الحساب الأب المختار
                                    $selected = '';
                                    if (isset($_GET['parent_id'])) {
                                        // البحث عن الحساب الأب بناءً على الكود
                                        $parent_code = $_GET['parent_id'];
                                        if ($rowacs['code'] == $parent_code) {
                                            $selected = 'selected';
                                        }
                                    }
                                    ?>
                                   <option value="<?= $rowacs['id'] ?>" <?= $selected ?>><?= $rowacs['code'] ?>-<?= $rowacs['aname'] ?></option>
                               <?php }?>
                            </select>

                        </div>
                </div>
            </div>


            
            <div class="row">
                <div class="col col-4">
                    
                <div class="form-group">
                            <label for="">تليفون</label>
                            <input class="form-control font-bold"  type="text" name="phone" id="" value="" placeholder="التليفون او تليفون المسؤول">
                        
                            
                        </div>
                </div>
                <div class="col">
                    
                <div class="form-group">
                            <label for="">العنوان</label>
                            <input class="form-control font-bold"  type="text" name="address" id="" value="" placeholder="اكتب العنوان او عنوان المسؤول">
                        
                        </div>
                </div>
            </div>





            <div class="row">
                <div class="col">
                    <div class="row">
                        <div class="col">
                        <?php
                        if (!function_exists('posmain_single_store_mode_enabled')) {
                            require_once __DIR__ . '/includes/pos_operational_store.php';
                        }
                        if (!posmain_single_store_mode_enabled()):
                        ?>
                        <div class="form-group">
                            <label for="">مخزون</label>
                            <input type="checkbox" name="is_stock" id="" <?php if ($parent == "123"){echo "checked ";}?>>
                                </div>
                        <?php endif; ?>

                        </div>
                        <div class="col">
                            
                <div class="form-group">
                            <label for="">حساب سري</label>
                            <input type="checkbox" name="secret" id="" >
                        </div>
                        </div>
                    </div>
                   </div>

                   <div class="col">         
                <div class="form-group">
                            <label for="">حساب صندوق</label>
                            <input type="checkbox" name="is_fund" id="" <?php if ($parent == "121"){echo "checked ";}?>>
                        </div>
                        </div>
                        
                   <div class="col">         
                <div class="form-group">
                            <label for="">اصل قابل للتأجير</label>
                            <input type="checkbox" name="rentable" id="" <?php if ($parent == "112"){echo "checked ";}?>>
                        </div>
                        </div>
                    </div>
                   </div>


                   <div class="col">
                    
                </div>
            </div>

            </div>
            <div class="card-footer">
                <div class="row">
                <div class="col">
                    <button class="btn btn-primary btn-block" type="submit">تأكيد</button>
            </div>
            <div class="col">
            <button class="btn btn-default btn-block" type="reset">مسح</button>
            </div>
                </div>
            </div>
 

        </div>
        </form>


        <?php }else{ 
            echo '<div class="alert alert-danger text-center">
                    <i class="fas fa-exclamation-triangle"></i> 
                    ليس لديك صلاحية للوصول إلى هذه الصفحة
                  </div>'; 
        }?>



        </div>
    </section>
</div>



<script>
$(document).ready(function() {
    $('#frst').on('keyup', function() {
        var itemId = $(this).val();
        
        $.ajax({
            url: 'get/get_accinfo.php?id=' + itemId,
            method: 'GET',
            dataType: 'json', // Parse response as JSON
            success: function(response) {
                if (response.status === "exists") {
                    $('#resaname').text(response.message);
                } else {
                    $('#resaname').text(response.message);
                }
            },
            error: function(xhr, status, error) {
                $('#resaname').html("<p class='text-danger'>.</p>");
            }
        });
    });
});


</script>



<?php include('includes/footer.php') ?>