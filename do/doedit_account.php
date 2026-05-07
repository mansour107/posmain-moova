<?php
include '../includes/connect.php';

if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['aname'])) {
    $id = $_GET['id'];
    foreach ($_POST as $key => $value) {
        $$key = $value;
    }
   
    if (!isset($_POST['is_stock'])) {
        $is_stock = 0;
    }
    if (!isset($_POST['secret'])) {
        $secret = 0;
    }

    
    if (!isset($_POST['fund'])) {
        $secret = 0;
    }

    
    if (!isset($_POST['rentable'])) {
        $secret = 0;
    }
    $sql = "UPDATE acc_head SET code='$code',aname='$aname',is_fund='$fund',rentable='$rentable',is_stock='$is_stock',parent_id='$parent_id',is_basic='$is_basic',secret='$secret' WHERE id = '$id' ";
    
    $conn->query($sql);
    header("location:../accounts.php");
}
