<?php include('../includes/connect.php');

print_r($_POST);
$resshift = $conn->query("SELECT shift FROM closed_orders ORDER BY id DESC LIMIT 1");

if ($resshift && $resshift->num_rows > 0) {
    $rowshft = $resshift->fetch_assoc();
    $old_shift = $rowshft['shift'];
    $shift = $old_shift + 1;
} else {
    $shift = 1;
}

$user = $_POST['user'];
$useriddata = $conn->query("SELECT id FROM users where uname = '$user'")->fetch_assoc();
$userid = $useriddata['id'];

$datetext = $_POST['date'];
$datearray = explode('T', $datetext);
$date = $datearray[0];
$strttime = $_POST['strttime'];
$endtime = $_POST['endtime'];
$total_sales = $_POST['total_sales'];
$expenses = $_POST['expenses'];
$fund_before = $_POST['fund_before'];
$exp_note = $_POST['exp_note'];
$cash = $_POST['cash'];
$fund_after = $total_sales - $expenses - $cash;
$info = $_POST['info'];



$rowstrt = $conn->query("UPDATE `ot_head` set closed = '$shift' WHERE (pro_tybe = 3 OR pro_tybe = 9) AND closed = 0 AND user = $userid;");


$conn->query("INSERT INTO closed_orders(shift, user, date, strttime, endtime, total_sales, delevery, tables, takeaway, expenses, fund_before, fund_after, exp_notes, cash, info) VALUES ('$shift', '$user', '$date', '$strttime', '$endtime', '$total_sales', '$delevery', '$tables', '$takeaway', '$expenses', '$fund_before', '$fund_after', '$exp_notes', '$cash', '$info')");

header("location:../pos_barcode.php");
