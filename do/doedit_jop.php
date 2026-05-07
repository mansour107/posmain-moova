<?php
include('../includes/connect.php');
$id = $_GET['id'];
$name = $_POST['name'];
$info = $_POST['info'];

$sql = "UPDATE `jops` SET `name` = '$name' , `info` = '$info' WHERE `id` = '$id'";
$conn->query($sql);
header('location:../jops.php');
