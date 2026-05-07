<?php
include('../includes/connect.php');
$name = $_POST['name'];
$value = $_POST['value'];

$sql ="INSERT INTO visittybes (name, value) VALUES ('$name', '$value')";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add vtybe')");

header('location:../vtybes.php');
?>