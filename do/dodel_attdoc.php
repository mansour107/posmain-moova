<?php include('../includes/connect.php');
$doc = $_GET['doc'];
$conn->query("DELETE FROM `attlog` WHERE attdoc = $doc");
$conn->query("DELETE FROM `attdocs` WHERE id = $doc");
header('location:../calcsalary.php');
?>
