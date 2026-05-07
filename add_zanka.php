<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/connect.php') ?>
<style>
   
</style>


<div class="ltr">
    <div class="row">
        <div class="col">
<div class="card card-danger">
    <div class="card-header"><h2>اضف زنكة جديدة</h2></div>


    <form action="do/doadd_zanka.php" method="post">
      <div class="row">
        <div class="col">
        <div class="form-group">
          
        
        
        
        <div class="row">
            <div class="col">
              
            <label for="exampleInputEmail1">اسم الزنكة</label>
                    <input name="zname" type="text" class="form-control" id="exampleInputEmail1" placeholder="اكتب اسم الزنكة" required>
                  
            </div>
            <div class="col">
            <div class="form-group">
                    <label for="exampleInputEmail1">عدد الالوان</label>
                    <input name="colors" type="number" class="form-control" id="exampleInputEmail1" placeholder="">
                  </div>
            </div>
            
            <div class="col">  <div class="form-group">
                    <label for="ctp">ctp</label>
                    <select class="form-control" name="ctp" id="ctp">
                    <option value="">ctp بدون</option>
                    <?php
                     $resctp = $conn->query("select * from ctp");
                        while ($rowctp = $resctp->fetch_assoc()) { ?>
                        <option value="<?= $rowctp['id'] ?>"><?= $rowctp['cname'] ?></option>
                        <?php } ?>
                    </select>
                  </div>
                </div>
            <div class="col">    <div class="form-group">
                    <label for="print">مطبعة</label>
                    <select name="print" id="print" class="form-control">
                    <option value="">بدون مطبعة</option>
                    <?php
                     $resprnt = $conn->query("select * from print");
                        while ($rowprnt = $resprnt->fetch_assoc()) { ?>
                        <option value="<?= $rowprnt['id'] ?>"><?= $rowprnt['pname'] ?></option>
                        <?php } ?>

                    </select>
                  </div>
                </div>
            <div class="col">    
              <div class="form-group">
                    <label for="ptype">نوع الورقة</label>
                    <select name="ptype" id="ptype" class="form-control">
                    <option value="">بدون نوع</option>

                    <?php
                     $respt = $conn->query("select * from paper_types");
                        while ($rowpt = $respt->fetch_assoc()) { ?>
                        <option value="<?= $rowpt['id'] ?>"><?= $rowpt['pname'] ?></option>
                        <?php } ?>

                    </select>
                  </div>
</div>
          </div>

          <div class="row">
            
            <div class="col">                    <div class="form-group">
                    <label for="measure">المقاس</label>
                    <input name="measure" type="number" class="form-control" id="measure" placeholder="0" required>
                  </div>
</div>
            <div class="col"><div class="form-group">
                    <label for="draw"> السحبات </label>
                    <input name="draw" type="number" class="form-control" id="draw" placeholder="0" >
                  </div>
 </div>
            <div class="col"><div class="form-group">
                    <label for="farkh"> عدد الافرخ </label>
                    <input name="farkh" type="number" class="form-control" id="farkh"  placeholder="0" required readonly>
                  </div>
 </div>
 <div class="col"> <div class="form-group">
                    <label for="ctp">الخدمة</label>
                    <select class="form-control" name="service" id="service">
                    <option value="">بدون مطبعة</option>
                    <?php
                     $ressrv = $conn->query("select * from services");
                        while ($rowsrv = $ressrv->fetch_assoc()) { ?>
                        <option value="<?= $rowsrv['id'] ?>"><?= $rowsrv['sname'] ?></option>
                        <?php } ?>
                    </select>
                  </div>
                 </div>
            
                 <div class="col"><div class="form-group">
                    <label for="prod">المورد</label>
                    <select name="prod" id="prod" class="form-control">
                    <option value="">بدون مورد</option>

                    <?php
                     $resprod = $conn->query("select * from prods");
                        while ($rowprod = $resprod->fetch_assoc()) { ?>
                        <option value="<?= $rowprod['id'] ?>"><?= $rowprod['pname'] ?></option>
                        <?php } ?>

                    </select>
                  </div>
</div>
            
      
            <div class="col"><div class="form-group">
                    <label for="info">ملاحظات</label>
                    <input name="info" type="info" class="form-control" id="info" placeholder="____" value="" >
                  </div>
                </div>

                <div class="col"><div class="form-group">
                    <label for="date">تاريخ اليوم</label>
                    <input required value="<?= date('d-m-20y'); ?>" name="date" type="date" class="form-control" id="date" placeholder="0" value="" >
                  </div>
                    </div>
            

          </div>

          <div class="col">
              <div class="form-group">
                    <label hidden for="user"> اسم المستخدم </label>
                    <input  hidden name="user" type="text" class="form-control" id="user" placeholder="0" value="<?=$_SESSION['login'] ?>" readonly>
                    
                  </div>
</div>
                        </div>

                <button type="submit" class="btn btn-danger btn-block">اضافة</button>


    </form>



    
</div>
        </div>
        
    </div>
    <div class="overflow-auto">
      
    <table id="crmtable" class="table table-bordered table-striped small ">
                <thead>
                <tr>
                <th>#</th>
                  <th>اسم الزنكة</th>
                  <th>عددالالوان</th>
                  <th>ctp</th>
                  <th>المطبعة</th>
                  <th>نوع الورق</th>
                  <th>خدمات</th>
                  <th>المقاس</th>
                  <th>عدد السحبات </th>
                  <th>عدد الافرخ</th>
                  <th>التاريخ</th>
                  <th>ملاحظات</th>
                  <th>عمليات</th>
                </tr>
                </thead>
                
                <tbody>
                
                <?php 
                if (isset($_POST['search']) ) {
                  $serach =$_POST['search'];

                  $sql= "SELECT * FROM zankat  WHERE zname LIKE '%$serach%' OR  ctp LIKE '%$serach%'  OR  print LIKE '%$serach%' OR  prod LIKE '%$serach%' order by id desc"; 
                 }
                 
                    

                else{
                  
                  if(!isset($_GET['pg'])){
                    $sql = "SELECT * FROM zankat order by id desc limit 50000";
                    }
                  $sql= "select * from zankat where fatid = '0'";
                }
                echo "<br>";  
                    
                
                $reszn = $conn->query($sql);
                $mslsl = 0;
                while ($rowzn=$reszn->fetch_assoc() ) {
                  $mslsl++;
                  
                ?>
                
                <tr>
                <th><?= $mslsl ?></th>
                  <th><?= $rowzn['zname'] ?></th>
                  <th><?= $rowzn['colors'] ?></th>
                  <th><?php
                  
                  $ctpid = $rowzn['ctp'];
                  if ($rowzn['ctp'] != 0) {
                $rowctp =($conn->query("select * from ctp where id = $ctpid "))->fetch_assoc();
                  echo $rowctp['cname'];
                }
                ?></th>
                  <th><?php 
                  $prntid = $rowzn['print'];
                  if ($rowzn['print'] != 0) {

                $rowprnt =($conn->query("select * from print where id = $prntid "))->fetch_assoc();
                echo $rowprnt['pname'];}
                ?></th>
                  <th><?php 
                  $ptypeid = $rowzn['ptype'];
                  if ($rowzn['ptype'] != 0) {
                $rowptype =($conn->query("select * from paper_types where id = $ptypeid "))->fetch_assoc();
                echo $rowptype['pname'];}
                ?></th>
                  <th><?php 
                  $srid = $rowzn['service'];
                  if ($rowzn['service'] != 0) {
                $rowsr =($conn->query("select * from services where id = $srid "))->fetch_assoc();
                echo $rowsr['sname'];}
                ?></th>
                  <th><?= $rowzn['measure'] ?></th>
                  <th><?= $rowzn['draw'] ?> </th>
                  <th><?= $rowzn['farkh'] ?></th>
                  <th><?= $rowzn['date'] ?></th>
                  <th><?= $rowzn['info'] ?></th>
                  <th><a class="btn btn-warning btn-sm" href="edit_zanka.php?id=<?= $rowzn['id']?>">تعديل</a>
                  <a class="btn btn-danger btn-sm" href="do/dodel_zanka.php?id=<?=$rowzn['id'] ?>">حذف</a></th>
                </tr>
                <?php } ?>
            
              
              </tbody>
              </table>
    </div>
    <a href="do/doadd_fat.php" class='btn btn-primary btn-block'>عمل فاتوره</a>
</div>

<script>
$('#draw').keyup(function () {
  $measure =  $('#measure').val();
 $draw = $('#draw').val();
 $fa = $draw / $measure  ;
  $('#farkh').val(Math.ceil($fa));
  });

  $('#measure').keyup(function () {
$measure =  $('#measure').val();
$draw = $('#draw').val();
$fa = $draw / $measure ;
$('#farkh').val(Math.ceil($fa));
})
</script>

<?php include('includes/footer.php') ?>


