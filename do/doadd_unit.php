<?php 
include('../includes/connect.php');
$uname = $_POST['uname'];
$conn->query("INSERT INTO myunits (uname) values ('$uname')");
$conn->query("INSERT INTO `process`(`type`) VALUES ('add unit')");

header('location:../myunits.php');

