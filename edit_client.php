<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">
<?php
$id = $_GET['id'];
$rowcl= $conn->query("SELECT * FROM clients where id = $id")->fetch_assoc() ?>
<form action="do/doedit_client.php?id=<?= $id ?>" method="post">

<div class="card card-warning">
        <div class="card-header">
            <h3>تعديل العميل <?= $rowcl['name']?> </h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col">
                    <div class="form-group">
                        <label for="name">الاسم</label>
                        <input value="<?= $rowcl['name']?>" placeholder="ادخل اسم العميل" class="form-control" type="text" name="name" id="">
                    </div>
                </div>
                <div class="col">
                    
                <div class="form-group">
                        <label for="phone">phone</label>
                        <input value="<?= $rowcl['phone']?>" placeholder="ادخل تليفون" class="form-control" type="text" name="phone" id="">
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col">
                    <label for="town">المدينه</label>
                    <select class="form-control" name="city" id="">
                        <?php 
                        while ($rowtwn = $restwn->fetch_assoc()) {
                          ?>
                          <option <?php if ($rowcl['city'] = $rowtwn['id']) {
                            echo 'selected';
                          } ?> value="<?= $rowtwn['id']?>"><?= $rowtwn['name']?></option>
                          <?php }?>

                    </select>


                </div>
                <div class="col">
                <div class="form-group">
                        <label for="address">العنوان</label>
                        <input value="<?= $rowcl['address']?>" placeholder="ادخل العنوان" class="form-control" type="text" name="address" id="">
                    </div>

                </div>
            </div>





            <div class="row">
                <div class="col">
                <div class="form-group">
                        <label for="height">height</label>
                        <select name="gender" id="" class="form-control">
                            <option <?php if ($rowcl['gender']==0) {
                                echo "selected";
                            }?> value="0">ذكر</option>
                            <option <?php if ($rowcl['gender']==1) {
                                echo "selected";
                            }?> value="1">انثي</option>
                        </select>

                    </div>

                </div>
                <div class="col">
                <div class="form-group">
                        <label for="height">height</label>
                        <input value="<?= $rowcl['height']?>" placeholder="ادخل الطول" class="form-control" type="number" name="height" id="">
                    </div>

                </div>
                <div class="col">
                <div class="form-group">
                        <label for="weight">weight</label>
                        <input value="<?= $rowcl['weight']?>" placeholder="الوزن بالkg" class="form-control" type="number" name="weight" id="">
                    </div>

                </div>
            </div>




            <div class="row">
                <div class="col">
                <div class="form-group">
                        <label for="dateofbirth">تاريخ الميلاد</label>
                        <input value="<?= $rowcl['dateofbirth']?>" placeholder="" class="form-control" type="date" name="dateofbirth" id="">
                    </div>

                </div>
                <div class="col">
                <div class="form-group">
                        <label for="">رقم الرفيق</label>
                        <input value="<?= $rowcl['ref']?>" placeholder="ادخل تليفون" class="form-control" type="text" name="ref" id="">
                    </div>

                </div>
            </div>
            </div>
             <div class="row">
                <div class="col">
                <div class="form-group">
                        <label for="weight">امراض مزمنه</label>
                        <textarea  placeholder="ادخل الامراض المزمنه" class="form-control"  name="diseses" id="" cols="30" rows="5"><?= $rowcl['diseses']?></textarea>
                    </div>


                </div>
                <div class="col">
                <div class="form-group">
                        <label for="weight">ملاحظات اخري</label>
                        <textarea  placeholder="ادخل الملاحظات" class="form-control"  name="info" id="" cols="30" rows="5"><?= $rowcl['info']?></textarea>
                    </div>
                </div>
             </div>
        </div>
        <div class="card-footer">
            <div class="row">
                <div class="col">
            <button class="btn btn-warning btn-flat btn-block" type="submit">تأكيد</button>
            </div>
                <div class="col">
                    <button class="btn btn-secondary btn-flat btn-block" type="reset">مسح البيانات</button>
                    </div>
                    </div>
                    </div>
                    </form>
             
        



</div>
</section>
</div>

<?php include('includes/footer.php') ?>
