<?php
include('../includes/connect.php');
$name = $_POST['name'];
$info = $_POST['info'];

$sql="INSERT INTO jops( name, info) VALUES ( '$name',  '$info') ";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add jop')");

header('location:../jops.php');
?>
