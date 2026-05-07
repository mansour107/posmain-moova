<?php
include('../includes/connect.php');
$id = $_GET['id'];

if (isset($_POST['client'])) {
    $client =  $_POST['client'];
 $rowcl = $conn->query("select * from clients where name = '$client'")->fetch_assoc();
 print_r($rowcl);
 if (!isset($rowcl)) {
    $conn->query("INSERT INTO `clients`(`name`) VALUES ('$client')");
    $client = $conn->insert_id;}else {
       $client=$rowcl['id'];
    }};
$date = $_POST['date'];
$time = $_POST['time'];
$visittybe = $_POST['visittybe'];
$paid = $_POST['paid'];
$info = $_POST['info'];


$sql = "UPDATE `reservations` SET `client`='$client',`date`='$date',`time`='$time',`visittybe`='$visittybe',`paid`='$paid',`info`='$info' WHERE id = $id";
$conn->query($sql);
header('location:../reservations.php');
?>