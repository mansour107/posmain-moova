<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include_once('../includes/connect.php');
$user = $_SESSION['userid'];
$employee = $_COOKIE['login'];
$fptybe = $_POST['fptybe'];
$fpdate = date("Y-m-d");
$fptime = date("H:i");
$sql = "INSERT INTO `attandance`(`employee`, `fptybe`, `fpdate`, `time`, `user`) VALUES ('$employee','$fptybe','$fpdate','$fptime','$user')";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add mopile face')");

header('location:../mop.php');
?>