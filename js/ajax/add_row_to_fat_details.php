<?php
include('../includes/connect.php');
$selectedItemId = $_POST['myitm'];
$quantity = $_POST['itmqty'];
$price = $_POST['itmprice'];
$discount = $_POST['itmdisc'];
$det_value = ($quantity * $price) - $discount ;

$conn->query("INSERT INTO  fat_details ( item_id ,  qty ,  price ,  discount ,  det_value ,  fatid ,  fat_tybe ) VALUES ('$selectedItemId','$quantity','$price','$discount','$det_value','0','1')");


$response = array("success" => true, "message" => "Row added successfully");

echo json_encode($response);
