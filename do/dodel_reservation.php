<?php
include('../includes/connect.php');
$id = $_GET['id'];
$conn->query("DELETE FROM `reservations` WHERE id= $id");
header('location:../reservations.php');
?>