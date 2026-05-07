<?php include('../includes/connect.php');
$name = $_POST['name'];
$conn->query("INSERT INTO towns (name) values ('$name')");
$conn->query("INSERT INTO `process`(`type`) VALUES ('add town')");

header('location:../mytowns.php');