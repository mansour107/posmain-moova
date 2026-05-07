<?php
include("../includes/connect.php");
$id = $_GET['del'];

$sql1 = "SELECT * from  ot_head where id = $id";
$row = $conn->query($sql1)->fetch_assoc();
$pro_tybe = $row['pro_tybe'];
if($pro_tybe != 1 OR $pro_tybe != 2){
    echo "هذا السند مرتبط بعمليات أخري لا يمكن حذف هذا السند ";die;
} ;

$sql2 = "DELETE FROM `journal_entries` WHERE op2  = $id";
$sql3 = "DELETE FROM `journal_heads` WHERE op2  = $id";
$sql4 = "DELETE FROM `ot_head` WHERE id  = $id";

$conn->query($sql2);$conn->query($sql3);$conn->query($sql4);
if ($pro_tybe == 1){header('location:../vouchers.php?t=receive');}
if ($pro_tybe == 2){header('location:../vouchers.php?t=payment');}