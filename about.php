<?php include("includes/header.php");?>
<?php include("includes/navbar.php");?>
<?php include("includes/sidebar.php");?>

<?php
$result = $conn->query("SHOW COLUMNS FROM settings LIKE 'logo'");
if ($result->num_rows == 0) {
    $conn->query("ALTER TABLE settings ADD COLUMN logo VARCHAR(255) AFTER branch;");
}
?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
        <div class="card">
            <div class="card-body">
                
<h1>about </h1>


<div class="form-group">
    <label for=""></label>
        <div class="col">
            <?php
        
if ($lc_hadi = $rowstg['lic']) {
    echo '<h1> نسخه مرخصه</h1>';
  }else {
    echo '<h1>نسخه غير مرخصه</h1>';
  }
        ?>
</div>
<br>
        <input type="text" class="form-control" value="<?php $MAC = exec('getmac');$MAC2 = strtok($MAC, ' ');echo $MAC; ?>">
        
        <input type="text" class="form-control" name="" id="" value="<?= $lc_hadi ?>">
        
        <input type="text" class="form-control" value="<?= $MAC2 ?>">
        <br>
        <?= print_r($_SESSION) ?>
               <br>
                <button type="button" class="btn btn-warning" data-toggle="modal" data-target="#TurncateModal">مسح الداتا</button>
                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#FinishModal">انهاء المدة و بداية مدة جديدة</button>



                <!-- Modal -->
                <div class="modal fade" id="TurncateModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                <form action="do/dbase/do_turncate.php" method="post">
                    <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLabel">مسح العمليات</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                    <p>هل تريد بالتأكيد مسح كل العمليات</p>
                    <input type="password" name="password" class="form-control" placeholder="اكتب الباسوورد"> 
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger">Save changes</button>
                    </div>
                    </div>
                        </form>
                </div>
                </div>



                <!-- Modal -->
                <div class="modal fade" id="FinishModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
                <div class="modal-dialog" role="document">
                <form action="do/dbase/do_turncate.php" method="post">
                    <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLabel">مسح العمليات</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body">
                    هل تريد بالتأكيد مسح كل العمليات
                    </div>
                    <div class="modal-footer">
                        <input type="password" class="form-control">

                        <button type="button" class="btn btn-danger">Save changes</button>
                    
                    </div>
                   
                    </div>
                    </form>
                </div>
                </div>



</div>
</div>
</div>
</section>
</div>


<?php include("includes/footer.php");?>