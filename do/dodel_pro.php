<?php
include('../includes/connect.php');
$id = $_GET['id'];
$editpass = $_POST['editpass'];

if ($editpass != $edit_pass) {
    echo "تأكد من كلمة المرور ";
    die;
}else {
    $sql = "SELECT * FROM ot_head where id = $id";
    $rowop = $conn->query($sql)->fetch_assoc();
        if ($rowop) { 
        $op2 = $rowop['op2'];
        $pro_tybe = $rowop['pro_tybe'];
        }else{echo "لا توجد عمليات لهذا المعرف";}
    
        // التأكد من أن العملية مرتبطة بعمليات أخري
        if ($op2 > 0) {
        echo "توجد عمليات مرتبطة بالعملية المحددة >> برجاء مسح العملية الاساسية";
        die;
        }else{
            //مسح تفاصيل القيد للعمليه المصاحبة
            $conn->query("DELETE FROM journal_entries WHERE op2 =  '$id'");
            //مسح القيد للعملية المصاحبة
            $conn->query("DELETE FROM journal_heads WHERE op2 =  '$id'");
            //مسح العملية المصاحبة
            $sqldelot1 = "DELETE FROM ot_head WHERE op2 = '$id'";

            $conn->query($sqldelot1);
        }

// مسح تفاصيل العملية 
    $conn->query("DELETE FROM fat_details where pro_id = '$id'");
// مسح تفاصيل القيد
    $conn->query("DELETE FROM journal_entries where op_id = '$id'");
// مسح القيد 
    $conn->query("DELETE FROM journal_heads where op_id = '$id'");
// مسح العملية نفسها

    $conn->query("DELETE FROM ot_head where id = '$id'");
// العودة للتقرير
}
$process = "مسح عملية _ id ".$id." بواسطة ".$user ;
$conn->query("INSERT INTO process(type) VALUES ('$process')");


if ($pro_tybe == 1) {header('location:../operations_summary.php?q=receipt');}
if ($pro_tybe == 2) {header('location:../operations_summary.php?q=payment');}
if ($pro_tybe == 3) {header('location:../operations_summary.php?q=sale');}
if ($pro_tybe == 4) {header('location:../operations_summary.php?q=buy');}
if ($pro_tybe == 9) {header('location:../operations_summary.php?q=buy');}

