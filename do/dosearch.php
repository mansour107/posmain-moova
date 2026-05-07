<?php include('../includes/header.php')?>
<style>
    body{width:1240px;text-align:right}
    table{border:3px solid;direction:rtl}
    th ,td{border:1px solid;padding:5px}
    th{font-size:18px}

</style>
<?php
include('../includes/connect.php');
if (!isset($_POST['zname'])) { 
    echo "يجب الرجوع للخلف .. لم تدخل 
    هذه الصفحة من المكان المناسب";
    die;}else{
$zname = $_POST['zname'];
$colors = $_POST['colors'];
$ptype = $_POST['ptype'];
$service = $_POST['service'];
$prod = $_POST['prod'];
$info = $_POST['info'];
$mindate = $_POST['mindate'];
$maxdate = $_POST['maxdate'];
$user = $_POST['user'];
$print = $_POST['print'];
$ctp = $_POST['ctp'];
    }
?>
<center>
<div class="rtl overflow-auto">
      
      <table>
  
<h1>قائمة الزنكات</h1>
<h4>من تاريخ <?= $mindate ?> الي تاريخ <?= $maxdate ?></h4>
      <thead>
                  <tr>
                  <th>#</th>
                    <th>اسم الزنكة</th>
                    <th>عددالالوان</th>
                    <th>ctp</th>
                    <th>المورد</th>
                    <th>المطبعة</th>
                    <th>نوع الورق</th>
                    <th>خدمات</th>
                    <th>المقاس</th>
                    <th>عدد السحبات </th>
                    <th>عدد الافرخ</th>
                    <th>التاريخ</th>
                    <th>ملاحظات</th>
                    
                  </tr>
                  </thead>
                  
                  <tbody>
                  
                  <?php
                  $s0 =''; 
                  $s1 ='';
                  $s2 ='';
                  $s3 ='';
                  $s4 ='';
                  $s5 ='';
                  $s6 ='';
                  $s7 ='';


                  if(isset($mindate) && !empty($mindate)){
                    $s0 = " WHERE date >= '$mindate' AND date <= '$maxdate' ";}else{
                    $s0 = "WHERE id != 0";}

                    if(isset($zname) && !empty($zname)){
                      $s1 = " AND `zname` like '%".$zname."%'";}

                      if(($colors == '0')){
                        $s2 = " AND `colors`='0'";}  

                    if(isset($colors) && !empty($colors)){
                      $s2 = " AND `colors`='".$colors."'";}
                      
                    if(isset($ptype) && !empty($ptype)){
                     $s3 = " AND `ptype`='".$ptype."'";}
                     
                    if(isset($service) && !empty($service)){
                      $s4 = " AND `service`='".$service."'";}

                    if(isset($prod) && !empty($prod)){
                      $s5 = " AND `prod`='".$prod."'";}

                    if(isset($print) && !empty($print)){
                      $s6 = " AND `print`='".$print."'";}

                    
                      if(isset($ctp) && !empty($ctp)){
                        $s7 = " AND `ctp`='".$ctp."'";}
  


                  
                   $sql = "SELECT * FROM `zankat` ".$s0."".$s1." ".$s2." ".$s3." ".$s4." ".$s5."".$s6."".$s7."";
                
                // $sql= "SELECT * FROM zankat  WHERE date >= '$mindate' AND date <= '$maxdate' or colors = '$colors' or ptype = '$ptype' or service = '$service' or prod = '$prod' or info '$info' or print = '$print' or ctp = '$ctp'";
                  
                  $reszn = $conn->query($sql);
                  $mslsl = 0;
                  // if (($rowzn=$reszn->fetch_assoc()) == 0) {
                    // echo "لا يوجد بيانات طبقا للشروط المختارة حاول تقليل الشروط";
                  // }
                  $ptypesarray = [];
                  while ($rowzn = $reszn->fetch_assoc() ) {
                    $mslsl++;
                  array_push($ptypesarray ,$rowzn['ptype']);
                    
                  ?>
                  
                  <tr>
                  <th><a href="../deletezanka.php?id=<?= $rowzn['id'] ?>"><?= $mslsl ?></a></th>
                    <th><a href="../deletezanka.php?id=<?= $rowzn['id'] ?>"><?= $rowzn['zname'] ?></a></th>
                    <th><?= $rowzn['colors'] ?></th>
                    <th><?php
                    
                    $ctpid = $rowzn['ctp'];
                    if ($rowzn['ctp'] != 0) {
                  $rowctp =($conn->query("select * from ctp where id = $ctpid "))->fetch_assoc();
                    echo $rowctp['cname'];
                  }
                  ?></th>
                  <th><?php
                    
                    $prodid = $rowzn['prod'];
                    if ($rowzn['prod'] != 0) {
                  $rowprod =($conn->query("select * from prods where id = $prodid "))->fetch_assoc();
                    echo $rowprod['pname'];
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
                    
                  </tr>
                  <?php } ?>
              
                
                </tbody>
                </table>
                <table>
                  <tbody>
                    <tr>
                      <th>اسم الورقه</th>
                      <th> عدد السحبات </th>
                      <th>عدد الافرخ</th>
                    </tr>
                    <tr>
                      <?php
                      $sqlpt = "select * from paper_types where id in (".implode(',',$ptypesarray).")";
                      

                      $respt = $conn->query($sqlpt);
                      $p1 ='';

                      while ($rowpt = $respt->fetch_assoc()) {
                      ?>
                      <th><?= $rowpt['pname'] ?></th>
                      <th>
                      <?php
                      $paperid = $rowpt['id'];
                      $p1 = "and  ptype = $paperid  ";
                      $sqlsum = "SELECT SUM(draw)
                      FROM zankat
                       ".$s0."".$s1." ".$s2." ".$s3." ".$s4." ".$s5."".$s6."".$s7."".$p1."";
                      $ressum = $conn->query($sqlsum);
                      $rowsum = $ressum->fetch_assoc();
                      echo $rowsum['SUM(draw)']
                      
                      ?>
                      </th>
                      <th>
                      <?php
                      $paperid = $rowpt['id'];
                      $p1 = "and ptype = $paperid  ";
                      $sqlsum = "SELECT SUM(farkh)
                      FROM zankat
                       ".$s0."".$s1." ".$s2." ".$s3." ".$s4." ".$s5."".$s6."".$s7."".$p1."";
                      $ressum = $conn->query($sqlsum);
                      $rowsum = $ressum->fetch_assoc();
                      echo $rowsum['SUM(farkh)']
                      
                      ?>
                      </th>
                      
                    </tr>
                  <?php } ?>
                </tbody>
                </table>
      </div>
    </center>


<?php include('../includes/footer.php') ?>