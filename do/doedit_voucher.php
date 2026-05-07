<?php
include('../includes/connect.php');
$id = $_GET['id'];
$tybe = $_POST['tybe'];
if ($tybe == 2) {
    $acc1 = $_POST['account'];
    $acc2 = $_POST['fund_account'];
}elseif ($tybe == 1) {
    $acc2 = $_POST['account'];
    $acc1 = $_POST['fund_account'];
}

$voucher_id = $_POST['voucher_id'];
$vdate = $_POST['vdate'];
$val = $_POST['val'];
$cost_center = $_POST['cost_center'];
$fund_account = $_POST['fund_account'];
$info = $_POST['info'];
 print_r($_POST);


$sql = "UPDATE `ot_head` SET
`info`='$info',
`pro_id` = '$voucher_id',
`pro_date`='$vdate',
`acc1`='$acc1',
`acc2`='$acc2',
`pro_value`='$val',
`cost_center`='$cost_center',
`acc_fund`='$fund_account'
 WHERE id= $id";

 $conn->query($sql);
 if ($tybe == 1) {
    header('location:../vouchers.php?t=recive');
 }elseif($tybe == 2){
    header('location:../vouchers.php?t=payment');
 }
