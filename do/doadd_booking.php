<?php include('../includes/connect.php') ;

$cname = $_POST['cname'];
// تأكد ان االاسم موجود 
$cname = trim($_POST['cname'] ?? '');
$chkname = $conn->query("SELECT name FROM clients WHERE name = '$cname'");
if ($chkname->num_rows == 0) {
    $conn->query("INSERT INTO clients (name) VALUES ('$cname')");
}

$barcode = trim($_POST['barcode'] ?? '');
$chkcode = $conn->query("SELECT barcode FROM booking_cards WHERE barcode = '$barcode'");
if (!$chkcode->num_rows == 0) {
    echo "الرقم ده موجود بالفعل :: أظن انه بتم التلاعب";die;
}

$rtybe = $_POST['rtybe'];
$rcost = $_POST['rcost'];
$qty = $_POST['qty'];
$fromdate = $_POST['fromdate'];
$todate = $_POST['todate'];

$conn->query("INSERT INTO booking_cards (barcode , client , rtybe, rcost, qty, remain, fromdate, todate) VALUES ('$barcode' , '$cname' , '$rtybe' , '$rcost', '$qty', '$qty' , '$fromdate', '$todate')");

header('location:../add_booking.php');
?>