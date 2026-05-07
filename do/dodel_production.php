<?php
include('../includes/connect.php');
$id = $_GET['id'];
$sql = "DELETE FROM `productions` WHERE snd_id = '$id'";
$conn->query($sql); 
header('location:../production.php');
