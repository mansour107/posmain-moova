<?php
include('../includes/connect.php');
$id= $_GET['id'];
$rowchkvst = $conn->query("SELECT * FROM reservations where client = '$id'") ;
if (empty($rowchkvst)) {
   $conn->query("DELETE FROM `clients` WHERE id = '$id'");
}else {
    header('location:../clients.php?w=del');
}