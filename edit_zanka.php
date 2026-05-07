<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php') ?>
<style>
    .ltr{float:left;width:80%}
</style>
<?php
$id = $_GET['id'];
$row = $conn->query("SELECT * FROM zankat where id = $id")->fetch_assoc();
?>
 
<div class="ltr">
    <div class="row">
        <div class="col">
<div class="card card-warning">
    <div class="card-header"><h2>تعديل زنكة</h2></div>


    <form action="do/doedit_zanka.php?id=<?= $id ?>" method="post">

    <div class="form-group">
                    <label for="exampleInputEmail1">اسم الزنكة</label>
                    <input value="<?= $row['zname']?>" name="zname" type="text" class="form-control" id="exampleInputEmail1" placeholder="اكتب اسم الزنكة">
                  </div>
                  <div class="row">

                  <div class="col">
                  <div class="form-group">
                    <label for="exampleInputEmail1">عدد الالوان</label>
                    <input value="<?= $row['colors']?>" name="colors" type="number" class="form-control" id="exampleInputEmail1" placeholder="1">
                  </div></div>
                  <div class="col">
                  <div class="form-group">
                    <label for="ctp">الخدمة</label>
                    <select class="form-control" name="service" id="ctp">
                    <?php
                     $ressrv = $conn->query("select * from services");
                        while ($rowsrv = $ressrv->fetch_assoc()) { ?>
                        <option <?php if ($row['service'] ==  $rowsrv['id'] ) {
                            echo "selected";
                        } ?> value="<?= $rowsrv['id'] ?>"><?= $rowsrv['sname'] ?></option>
                        <?php } ?>
                    </select>
                  </div>  
                  </div>
                </div>


                <div class="row">
                    <div class="col">
                  <div class="form-group">
                    <label for="ctp">CTP</label>
                    <select class="form-control" name="ctp" id="ctp">
                    <?php
                     $resctp = $conn->query("select * from ctp");
                        while ($rowctp = $resctp->fetch_assoc()) { ?>
                        <option <?php if ($row['ctp'] ==  $rowctp['id'] ) {
                            echo "selected";
                        } ?> value="<?= $rowctp['id'] ?>"><?= $rowctp['cname'] ?></option>
                        <?php } ?>
                    </select>
                  </div>
                    </div>

                    <div class="col">

                    <div class="form-group">
                    <label for="print">مطبعة</label>
                    <select name="print" id="print" class="form-control">
                    <?php
                     $resprnt = $conn->query("select * from print");
                        while ($rowprnt = $resprnt->fetch_assoc()) { ?>
                        <option <?php if ($row['print'] ==  $rowprnt['id'] ) {
                            echo "selected";
                        } ?> value="<?= $rowprnt['id'] ?>"><?= $rowprnt['pname'] ?></option>
                        <?php } ?>

                    </select>
                  </div>
                    </div>
                    
                </div>
                <div class="row">
                    <div class="col">
                        
                    <div class="form-group">
                    <label for="ptype">نوع الورقة</label>
                    <select name="ptype" id="ptype" class="form-control">
                    <?php
                     $respt = $conn->query("select * from paper_types");
                        while ($rowpt = $respt->fetch_assoc()) { ?>
                        <option  <?php if ($row['ptype'] ==  $rowpt['id'] ) {
                            echo "selected";
                        } ?>  value="<?= $rowpt['id'] ?>"><?= $rowpt['pname'] ?></option>
                        <?php } ?>

                    </select>
                  </div>

                    </div>
                    <div class="col">
                        
                    <div class="form-group">
                    <label for="service">المورد</label>
                    <select name="prod" id="prod" class="form-control">
                    <?php
                     $resprod = $conn->query("select * from prods");
                        while ($rowprod = $resprod->fetch_assoc()) { ?>
                        <option <?php if ($row['prod'] ==  $rowprod['id'] ) {
                            echo "selected";
                        } ?>  value="<?= $rowprod['id'] ?>"><?= $rowprod['pname'] ?></option>
                        <?php } ?>

                    </select>
                  </div>

                    </div>
                </div>

                <div class="row">
                    <div class="col">
                    <div class="form-group">
                    <label for="measure">المقاس</label>
                    <input value="<?= $row['measure']?>" name="measure" type="number" class="form-control" id="measure" placeholder="0">
                  </div>
 

                    </div>
                    <div class="col">
                    <div class="form-group">
                    <label for="draw"> السحبات </label>
                    <input value="<?= $row['draw']?>" name="draw" type="number" class="form-control" id="draw" placeholder="0">
                  </div>
 

                    </div>
                    <div class="col">
                    <div class="form-group">
                    <label for="farkh"> عدد الافرخ </label>
                    <input value="<?= $row['farkh']?>" name="farkh" type="number" class="form-control" id="farkh" disabled placeholder="0">
                  </div>
 

                    </div>
                </div>
                <div class="row">
                    <div class="col">
                        
                    <div class="form-group">
                    <label for="user">مدخل البيانات</label>
                    <input name="user" type="text" class="form-control" id="user" placeholder="0" value="<?=$_SESSION['login'] ?>" disabled>
                  </div>

                    </div>


                    <div class="col">
                    <div class="form-group">
                    <label for="date">تاريخ الاستلام</label>
                    <input name="date" type="date" class="form-control" id="date" placeholder="0" value="<?= $row['date']?>" >
                  </div>
                    </div>
                </div>
                <div class="row">
                  <div class="col">
                <div class="form-group">
                    <label for="info">ملاحظات</label>
                    <input name="info" type="info" class="form-control" id="info" placeholder="____" value="<?= $row['info']?>" >
                  </div>
                </div>
                </div>
                <button type="submit" class="btn btn-warning btn-block">ارسال</button>
                <br>
                <br>


    </form>



    
</div>
        </div>
        
    </div>
</div>
<script>
$('#draw').keyup(function () {
  $measure =  $('#measure').val();
 $draw = $('#draw').val();
 $fa = $measure * $draw;
  $('#farkh').val($fa);
  });

  $('#measure').keyup(function () {
$measure =  $('#measure').val();
$draw = $('#draw').val();
$fa = $measure * $draw;
$('#farkh').val($fa);
})
</script>



<?php include('includes/footer.php') ?>


