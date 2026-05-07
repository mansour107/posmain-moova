<!-- معلومات الطلب -->
<div class="row " id="upRight0">
    <div class="col-12">
        <!-- نوع الطلب -->
        <div class="">
            <div class="btn-group w-100" role="group">
                <input type="radio" class="btn-check" id="age1" name="age" value="1" checked>
                <label class="btn btn-outline-success" for="age1">
                    <i class="fas fa-shopping-bag"></i> تيك أواي
                </label>
                
                <input type="radio" class="btn-check" id="age2" name="age" value="2" <?php if (isset($_GET['table'])) {echo " checked ";} ?>>
                <label class="btn btn-outline-primary" for="age2">
                    <i class="fas fa-chair"></i> طاولة
                </label>
                
                <input type="radio" class="btn-check" id="age3" name="age" value="3">
                <label class="btn btn-outline-info" for="age3">
                    <i class="fas fa-motorcycle"></i> دليفري
                </label>
            </div>
        </div>
        <script>
            $('input[name="age"]').change(() => {
                if ($('#age2').is(':checked')) {
                    $('#table_name_wrapper').fadeIn(300);
                    // فتح modal الطاولات
                    if (typeof loadTables === 'function') {
                        $('#tablesModal').modal('show');
                        loadTables();
                    }
                } else {
                    $('#table_name_wrapper').fadeOut(300);
                }
            });
            
            // تنشيط الباركود input عند التحميل
            $(document).ready(() => {
                $('#barcodeInput').focus();
            });
        </script>

        <!-- Hidden Fields -->
        <input type="hidden" name="pro_tybe" value="9">
        <input type="hidden" name="pro_serial" value="0">
        <input type="hidden" name="pro_id" value="1">
        
        <?php $posdate = isset($_GET['edit']) ? $rowed['pro_date'] : date('Y-m-d', strtotime('-4 hours')); ?>

        <!-- جميع الحقول في صف واحد -->
        <div class="row g-2">
            <!-- التواريخ -->
            <div class="col-6">
                <input type="date" name="pro_date" class="form-control form-control-sm" value="<?= $posdate ?>" title="التاريخ">
            </div>
            <div class="col-6">
                <input type="date" name="accural_date" class="form-control form-control-sm" 
                       value="<?php echo isset($_GET['edit']) ? $rowed['accural_date'] : date('Y-m-d'); ?>" title="تاريخ الاستحقاق">
            </div>

            <!-- المخزن -->
            <div class="col-6">
                <select name="store_id" class="form-select form-select-sm" title="المخزن">
                    <?php
                    $resstore = $conn->query("SELECT * FROM `acc_head` WHERE is_stock =1 AND isdeleted = 0;");
                    while ($rowstore = $resstore->fetch_assoc()) { ?>
                        <option <?php if($rowstg['def_pos_store'] == $rowstore['id']){echo "selected";} ?> 
                                value="<?= $rowstore['id'] ?>"><?= $rowstore['aname'] ?></option>
                    <?php } ?>
                </select>
            </div>

            <!-- الموظف -->
            <div class="col-6">
                <select name="emp_id" class="form-select form-select-sm" title="الموظف">
                    <?php
                    $resemp = $conn->query("SELECT * FROM `acc_head` WHERE parent_id = 35 AND is_basic = 0 AND isdeleted = 0;");
                    while ($rowemp = $resemp->fetch_assoc()) { ?>
                        <option <?php if($rowstg['def_pos_employee'] == $rowemp['id']){echo " selected ";} ?> 
                                <?php if(isset($_GET['edit']) && $rowed['emp_id'] == $rowemp['id']){echo " selected ";} ?>  
                                value="<?= $rowemp['id'] ?>"><?= $rowemp['aname'] ?></option>
                    <?php } ?>
                </select>
            </div>

            <!-- العميل -->
            <div class="col-6">
                <select name="acc2_id" class="form-select form-select-sm" title="العميل">
                    <?php
                    $resclient = $conn->query("SELECT * FROM `acc_head` WHERE code like '122%'  AND is_basic = 0 AND isdeleted = 0;");
                    if(isset($_GET['edit'])){$rowed = $conn->query("SELECT * FROM ot_head where id = $id")->fetch_assoc();};
                    while ($rowclient = $resclient->fetch_assoc()) { ?>
                        <option <?php if($rowstg['def_pos_client'] == $rowclient['id']){echo " selected ";} ?>
                                <?php if(isset($_GET['edit']) && $rowed['acc1'] == $rowclient['id']){echo " selected ";} ?>
                                value="<?= $rowclient['id'] ?>"><?= $rowclient['aname'] ?></option>
                    <?php } ?>
                </select>
            </div>

            <!-- الصندوق -->
            <div class="col-6">
                <select name="fund_id" class="form-select form-select-sm" title="الصندوق">
                    <?php
                    if(isset($_GET['edit'])){$rowed = $conn->query("SELECT * FROM ot_head where id = $id")->fetch_assoc();};
                    $resfund = $conn->query("SELECT * FROM `acc_head` WHERE is_fund =1 AND is_basic = 0 AND isdeleted = 0;");
                    while ($rowfund = $resfund->fetch_assoc()) { ?>
                        <option <?php if($rowstg['def_pos_fund'] == $rowfund['id']){echo " selected ";} ?>
                                <?php if((isset($_GET['edit'])) && $rowed['acc_fund'] == $rowfund['id']){echo " selected ";} ?>
                                value="<?= $rowfund['id'] ?>"><?= $rowfund['aname'] ?></option>
                    <?php } ?>
                </select>
            </div>

            <!-- اسم الطاولة -->
            <div class="col-6" id="table_name_wrapper" <?php if(!isset($_GET['table'])){echo 'style="display: none;"';}?>>
                <input type="text" class="form-control form-control-sm" placeholder="اسم الطاولة" 
                       id="table_name" name="table" 
                       value="<?php if(isset($_GET['table'])){$table = $_GET['table'];echo $table;}?>" readonly title="الطاولة">
            </div>

            <!-- الباركود -->
            <div class="col-6">
                <input type="text" class="form-control form-control-lg frst" 
                       placeholder="امسح الباركود أو اكتب رقمه..." 
                       id="barcodeInput"
                       style="border: 2px solid #28a745; background: #f8fff8;" title="قارئ الباركود">
            </div>
        </div>
    </div>
</div>