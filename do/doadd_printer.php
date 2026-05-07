<?php
include('../includes/connect.php');
$pname = $_POST['pname'];
$type = $_POST['type'];
$number = $_POST['number'];
$info = $_POST['info'];

$sql="INSERT INTO print( pname, type, number, info) VALUES ( '$pname', '$type', '$number', '$info') ";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add printer')");

header('location:../prints.php');
?>
