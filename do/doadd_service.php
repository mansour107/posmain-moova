<?php
include('../includes/connect.php');
$sname = $_POST['sname'];
$info = $_POST['info'];

$sql="INSERT INTO services( sname, info) VALUES ( '$sname','$info') ";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add service')");

header('location:../services.php');
?>
