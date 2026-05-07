<?php
include('../includes/connect.php');
$client = $_POST['client'];
$client2 = $_POST['cl'];
$user = $_POST['user'];
$visittype = $_POST['visittype'];
$notes = $_POST['notes'];
$payment = $_POST['payment'];
$paid = $_POST['paid'];
$dept = $_POST['dept'];

$sql ="INSERT INTO visits(client, user, type, notes, payment, paid, dept) VALUES ('$client', '$user', '$visittype', '$notes', '$payment', '$paid', '$dept')";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add visit')");

header('location:../visits.php');
?>