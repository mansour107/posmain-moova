<?php
include('../includes/connect.php'); 
$cl = $_GET['id'];

// print_r($_POST);die;
$diseses = $_POST['diseses'];
$rowrsv = $conn->query("SELECT * FROM reservations where client = '$cl' order by id desc limit 1")->fetch_assoc();
if (!empty($rowrsv)){
$id = $rowrsv['id'];

$conn->query("UPDATE `reservations` SET `diseses`= '$diseses' WHERE id = '$id'");
$conn->query("UPDATE `clients` SET `diseses`= '$diseses' WHERE id = '$cl'");

$conn->query("INSERT INTO `process`(`type`) VALUES ('add dis')");


header('location:../clprofile.php?id='.$cl);
}

?>