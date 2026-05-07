<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php $id = $_GET['id'];
$rowacc = $conn->query("SELECT * FROM acc_head where id = $id")->fetch_assoc();
?>
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
        <form action="do/doedit_account.php?id=<?= $rowacc['id'] ?>" method="post">
           
        <div class="card card-warning">
            <div class="card-header">
                <h3>تعديل حساب</h3>
            </div>
             <div class="card-body">
            <div class="row">
                <div class="col col-3">
                <div class="form-group">
                            <label for="">الكود</label>
                            <input class="form-control form-control .bg-gradient-dark" type="text" name="code" id="" value="<?= $rowacc['code'] ?>">
                        </div>
                </div>
                <div class="col">
                    
                <div class="form-group">
                            <label for="">الاسم</label>
                            <input class="form-control form-control .bg-gradient-dark" type="text" name="aname" id="" value="<?= $rowacc['aname'] ?>">
                        </div>
                </div>
            </div>

            <div class="row">
                <div class="col col-4">
                    
                <div class="form-group">
                            <label for="">نوع الحساب</label>
                            <select class="form-control form-control " name="is_basic" id="">
                                <option value="1" <?php if ($rowacc['is_basic'] == 1) {echo "selected";} ?>>اساسي</option>
                                <option value="0" <?php if ($rowacc['is_basic'] == 0) {echo "selected";} ?> >حساب عادي</option>
                            </select>
                        </div>
                </div>
                <div class="col">
                    
                <div class="form-group">
                            <label for="">يتبع ل</label>
                            <select class="form form-control" name="parent_id" id="">
                                
                                <?php
                                $resacs =$conn->query("SELECT * FROM acc_head where is_basic = 1 order by aname desc");
                                while ($rowacs = $resacs->fetch_assoc()) {?>
                                   <option  value="<?= $rowacs['id'] ?>" 
                                   <?php if ($rowacs['id'] == $rowacc['parent_id'] ) {echo "selected";} ?>>
                                   <?= $rowacs['aname'] ?>-<?= $rowacs['code'] ?>
                                </option>
                               <?php }?>


                               <option value="0" <?php if ($rowacc['parent_id'] == 0 ) {echo "selected";} ?>>--</option>
                            </select>

                        </div>
                </div>
            </div>

            <div class="row">
                <div class="col col-4">
                <div class="form-group">
                            <label for="">تليفون</label>
                            <input class="form-control form-control .bg-gradient-dark" type="text" name="phone" id="" value="<?= $rowacc['phone'] ?>">
                        </div>
                </div>
                <div class="col">
                    
                <div class="form-group">
                            <label for="">العنوان</label>
                            <input class="form-control form-control .bg-gradient-dark" type="text" name="address" id="" value="<?= $rowacc['address'] ?>">
                        </div>
                </div>
            </div>


            <div class="row">
                <div class="col">
                    <div class="row">
                        <div class="col">
                        <div class="form-group">
                            <label for="">مخزون</label>
                            <input type="checkbox" name="is_stock" id="" value="1"  <?php if($rowacc['is_stock'] == 1) echo "checked" ?>>
                                </div>

                        </div>
                        <div class="col">
                            
                <div class="form-group">
                            <label for="">حساب سري</label>
                            <input type="checkbox" name="secret" id="" value="1" <?php if($rowacc['secret'] == 1) echo "checked" ?>>
                        </div>
                        </div>

                        <div class="col">
                        <div class="form-group">
                            <label for="">حساب نقدي</label>
                            <input type="checkbox" name="is_fund" id="" value="1" <?php if($rowacc['is_fund'] == 1) echo "checked" ?>>
                        </div>
                        </div>
                        <div class="col">
                        <div class="form-group">
                            <label for="">قابل للتأجير</label>
                            <input type="checkbox" name="rentable" id="" value="1" <?php if($rowacc['rentable'] == 1) echo "checked" ?>>
                        </div>
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





        </div>
    </section>
</div>
<?php include('includes/footer.php') ?>