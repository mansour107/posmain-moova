<?php
include('../includes/connect.php');
session_start();
$user = $_SESSION['userid'];
if ($_POST['vdate'] == null) {
    $currentDate = date('Y-m-d');
    $vdate = $currentDate;
}else{$vdate = $_POST['vdate'];};
$tybe = $_POST['tybe'];
$val = $_POST['val'];
$account = $_POST['account'];
$fund_account = $_POST['fund_account'];
if ($tybe == 1) {
    $debit_acc =$fund_account;
    $credit_acc = $account;

}elseif ($tybe == 2) {
    $debit_acc = $account;
    $credit_acc = $fund_account;
}

$voucher_id = $_POST['voucher_id'];

$cost_center = $_POST['cost_center'];
$info = $_POST['info'];


$sql1 = "INSERT INTO  ot_head (pro_id,branch_id,pro_tybe,is_finance, is_journal,journal_tybe,info,pro_date,pro_num,acc1,acc2,pro_value,cost_center,user) VALUES ('$voucher_id' ,'1','$tybe','1','1','$tybe','$info','$vdate','$voucher_id','$debit_acc','$credit_acc','$val','$cost_center','$user')";

$conn->query($sql1);

$ins_voucher =  $conn->insert_id;

$rowjournal = $conn->query("SELECT journal_id FROM journal_heads ORDER BY journal_id DESC LIMIT 1;")->fetch_assoc();
$journal_id = $rowjournal['journal_id']+1;
$sql2 =$conn->query("INSERT INTO journal_heads(journal_id , total, jdate, details,op2, user) VALUES ('$journal_id','$val','$vdate',' سند مالي _ $info','$ins_voucher','$user')");
$journal_lastid =  $conn->insert_id;
$sql3 = $conn->query("INSERT INTO journal_entries(journal_id, account_id, debit, credit, tybe,op2) VALUES ('$journal_lastid','$debit_acc','$val','0','0','$ins_voucher')");
$sql5 = $conn->query("INSERT INTO journal_entries(journal_id, account_id, debit, credit, tybe,op2) VALUES ('$journal_lastid','$credit_acc','0','$val','1','$ins_voucher')");

$conn->query("INSERT INTO `process`(`type`) VALUES ('add voucher')");




if ($_POST['ins_id']) { $ins = $_POST['ins_id'] ;$conn->query("UPDATE myinstallments set ins_case = 3 ,ins_paid = $val ,voucher = $ins_voucher,ins_paid = $val where id =  $ins ");}
header('location:../vouchers.php');
