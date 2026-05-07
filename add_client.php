<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
        <?php if ($role['add_clients'] == 1) { ?>

    <div class="card card-primary">
        <form action="do/doadd_client.php" method="post">
        <div class="card-header">
            <h3>عميل جديد</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col">
                    <div class="form-group">
                        <label for="name">الاسم</label>
                        <input placeholder="ادخل اسم العميل" class="form-control" type="text" name="name" id="">
                    </div>
                </div>
                <div class="col">
                    
                <div class="form-group">
                        <label for="phone">phone</label>
                        <input placeholder="ادخل تليفون" class="form-control" type="text" name="phone" id="">
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
                          <option value="<?= $rowtwn['id']?>"><?= $rowtwn['name']?></option>
                          <?php }?>

                    </select>


                </div>
                <div class="col">
                <div class="form-group">
                        <label for="height">العنوان</label>
                        <input placeholder="ادخل العنوان" class="form-control" type="text" name="address" id="">
                    </div>

                </div>
            </div>





            <div class="row">
                <div class="col">
                <div class="form-group">
                        <label for="gender">gender</label>
                    <select class="form-control" name="gender" id="" >
                        <option value="0">ذكر</option>
                        <option value="1">انثي</option>
                    </select>        
                    </div>
                </div>

        
                <div class="col">
                <div class="form-group">
                        <label for="height">height</label>
                        <input placeholder="ادخل الطول" class="form-control" type="number" name="height" id="">
                    </div>

                </div>
                <div class="col">
                <div class="form-group">
                        <label for="weight">weight</label>
                        <input placeholder="الوزن بالkg" class="form-control" type="number" name="weight" id="">
                    </div>

                </div>
                </div>
          




            <div class="row">
                <div class="col">
                <div class="form-group">
                        <label for="dateofbirth">تاريخ الميلاد</label>
                        <input placeholder="" class="form-control" type="date" name="dateofbirth" id="">
                    </div>

                </div>
                <div class="col">
                <div class="form-group">
                        <label for="weight">رقم الرفيق</label>
                        <input placeholder="ادخل تليفون" class="form-control" type="text" name="ref" id="">
                    </div>

                </div>
            </div>
            </div>
             <div class="row">
                <div class="col">
                <div class="form-group">
                        <label for="weight">امراض مزمنه</label>
                        <textarea  placeholder="ادخل الامراض المزمنه" class="form-control"  name="diseses" id="" cols="30" rows="5"></textarea>
                    </div>


                </div>
                <div class="col">
                <div class="form-group">
                        <label for="weight">ملاحظات اخري</label>
                        <textarea  placeholder="ادخل الملاحظات" class="form-control"  name="info" id="" cols="30" rows="5"></textarea>
                    </div>
                </div>
             </div>
        </div>
        <div class="card-footer">
            <div class="row">
                <div class="col">
            <button class="btn btn-primary btn-flat btn-block" type="submit">تأكيد</button>
            </div>
                <div class="col">
                    <button class="btn btn-secondary btn-flat btn-block" type="reset">مسح البيانات</button>
       </div>
             
        </form>


</div>
<?php }else{echo $userErrorMassage;} ?>


</div>
</section>
</div>

<?php include('includes/footer.php') ?>
