<?php
include('../includes/connect.php');
$cname = $_POST['cname'];
$type = $_POST['type'];
$number = $_POST['number'];
$info = $_POST['info'];

$sql="INSERT INTO ctp( cname, type, number, info) VALUES ( '$cname', '$type', '$number', '$info') ";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add ctp')");

header('location:../ctps.php');
?>
