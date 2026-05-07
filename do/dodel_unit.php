<?php 
include("../includes/connect.php");

$id = $_GET['id'];

// حذف الوحدة
$conn->query("DELETE FROM myunits WHERE id = $id");

header('location:../myunits.php');
?>
