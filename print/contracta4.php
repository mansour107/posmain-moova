<?php include('../../../includes/connect.php');
$id = $_GET['id'];

    $sqlcnt = "SELECT * FROM hiringcontracts where id = $id";
    $rescnt = $conn->query($sqlcnt);
    $rowcnt = $rescnt->fetch_assoc();
  
     ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../plugins/tempusdominus-bootstrap-4/css/tempusdominus-bootstrap-4.min.css">
    <link rel="stylesheet" href="../plugins/hadi/bootstrap.css" >


    <title>Document</title>
</head>
<style>
    .a4{
        width:28cm;
        height:28cm;
    }
</style>
<body>
<div class="a4">
<center>

<h1>HORSTEC</h1>
<h3>Lorem ipsum dolor sit amet consectetur adipisicing elit. Vitae ipsam cupiditate possimus illo quibusdam odit, accusamus delectus, expedita reiciendis odio velit harum molestiae dolorem magni eveniet aliquam adipisci ea nulla.</h3>

<h2>الاسم: <?= $rowcnt['name']?></h2>
<h3> السيد\السيده:<?= $rowcnt['employee']?></h3>
<h3>الوظيفه:<?= $rowcnt['jop']?></h3>
<h5> تفاصيل العمل ::<?= $rowcnt['jopdescription']?></h5>
<h5>البند الاول : <?= $rowcnt['joprule1']?></h5>
<h5>البند الثاني : <?= $rowcnt['joprule2']?></h5>
<h5>البند الثالث : <?= $rowcnt['joprule3']?></h5>
<h5>البند الرابع : <?= $rowcnt['joprule4']?></h5>
<h3>ساعات العمل : <?= $rowcnt['workhours']?></h3>
<h3>:ساعات العمل تحت الطلب : <?= $rowcnt['inorderhours']?></h3>
<h3>عدد ايا م الاجازه السنويه : <?= $rowcnt['workdaysoff']?></h3>
<h3>الراتب الاساسي: <?= $rowcnt['salary']?></h3>
<h3>الزياده السنويه : <?= $rowcnt['salaryraise']?> % </h3>
<h3><?= $rowcnt['info']?></h3>
<h3><?= $rowcnt['crtime']?></h3>
</center>
<div class="row">
    <a href=""><div class="col btn btn-info">
طباعه
    </div></a>
    <a href=""><div class="col btn btn-secondary">
رجوع
    </div></a>
</div>
</div>

</body>
</html>
<?php include('../includes/connect.php'); ?>
<?php $id = $_GET['id'];?>

