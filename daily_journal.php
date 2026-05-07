<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">



        <div class="card">
            <div class="card_head">
            <div class="row">
                <div class="col">
                <h2>اليوميه العامة</h2>
                </div>
                <div class="col">
                    <div class="row">
                        <div class="col"><input  class="form-control" type="date" name="date"></div>
                        <div class="col"><input  class="form-control" type="text" name="search" id="search"></div>
                    </div>
                </div>
            </div>    
            </div>
            <div class="card_body">

            <div class="table table_responsive">
                <table class="table  table-striped  table-bordered">
                    <thead>
                       <tr> 
                    <th>م</th>
                    <th>تاريخ</th>
                    <th>رقم القيد</th>
                    <th>مدين</th>
                    <th>دائن</th>
                    <th>الحساب</th>
                    <th>بيان</th>
                    </tr>
                    </thead>

                    



                    <tbody>
    <?php
    $resjournal = $conn->query("SELECT * FROM journal_heads WHERE isdeleted = 0");
    $x = 0;
    
    while ($rowjournal = $resjournal->fetch_assoc()) {
        $jrnlid = $rowjournal['id']; 
        
        // استعلام لإدخالات الدين
        $resdebit = $conn->query("SELECT * FROM journal_entries WHERE journal_id = '$jrnlid' AND tybe = '0'");
        
        // استعلام لإدخالات الائتمان
        $rescredit = $conn->query("SELECT * FROM journal_entries WHERE journal_id = '$jrnlid' AND tybe = '1'");
        
        $x++;
        
        // عرض إدخالات الدين
        if ($resdebit->num_rows > 0) {
            // عرض الصف الأول للدين
            $rowdebit = $resdebit->fetch_assoc();
            ?>
            <tr>
                <td rowspan="<?= $resdebit->num_rows + 1 ?>"> <?= $x ?> </td>
                <td rowspan="<?= $resdebit->num_rows + 1 ?>"> <?= $rowjournal['jdate'] ?> </td>
                <td rowspan="<?= $resdebit->num_rows + 1 ?>"> <?= $rowjournal['journal_id'] ?> </td>
                <td><?= $rowdebit['debit'] ?></td>
                <td><?= $rowdebit['credit'] ?></td>
                <td>
                    <?php 
                    $dbid = $rowdebit['account_id'];
                    $rowdb = $conn->query("SELECT * FROM acc_head WHERE id = $dbid")->fetch_assoc();
                    echo isset($rowdb['aname']) ? $rowdb['aname'] : "حساب غير موجود";
                    ?>
                </td>
                <td rowspan="<?= $resdebit->num_rows + 1 ?>"> <?= $rowjournal['details'] ?> </td>
            </tr>
            <?php
            
            // عرض بقية إدخالات الدين
            while ($rowdebit = $resdebit->fetch_assoc()) {
                if ($rowdebit['account_id'] != $dbid) { // تجنب تكرار الصف الأول
                    ?>
                    <tr>
                        <td><?= $rowdebit['debit'] ?></td>
                        <td><?= $rowdebit['credit'] ?></td>
                        <td>
                            <?php 
                            $dbid = $rowdebit['account_id'];
                            $rowdb = $conn->query("SELECT * FROM acc_head WHERE id = $dbid")->fetch_assoc();
                            echo isset($rowdb['aname']) ? $rowdb['aname'] : "حساب غير موجود";
                            ?>
                        </td>
                    </tr>
                    <?php
                }
            }
        } else {
            // إذا لم يكن هناك إدخالات دين
            ?>
            <tr>
                <td colspan="6">لا توجد إدخالات دين</td>
                <td rowspan="1"><?= $rowjournal['details'] ?></td>
            </tr>
            <?php
        }

        // عرض إدخالات الائتمان
        if ($rescredit->num_rows > 0) {
            while ($rowcredit = $rescredit->fetch_assoc()) {
                ?>
                <tr>
                    <td><?php echo isset($rowcredit['debit']) ? $rowcredit['debit'] : ""; ?></td>
                    <td><?php echo isset($rowcredit['credit']) ? $rowcredit['credit'] : ""; ?></td>
                    <td>
                        <?php 
                        if (isset($rowcredit['account_id'])) {
                            $crid = $rowcredit['account_id'];
                            $rowcr = $conn->query("SELECT * FROM acc_head WHERE id = $crid")->fetch_assoc();
                            echo isset($rowcr['aname']) ? $rowcr['aname'] : "حساب غير موجود";
                        }
                        ?>
                    </td>
                </tr>

                <?php
            }
        } else {
            // إذا لم يكن هناك إدخالات ائتمان
            ?>
            <tr>
                <td colspan="6">لا توجد إدخالات ائتمان</td>
            </tr>
            <?php
        }
    } ?>

</tbody>





                </table>
            </div>

            </div>
        </div>



        </div>
    </section>
</div>
<?php include('includes/footer.php') ?>
