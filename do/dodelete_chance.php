<?php include('../includes/connect.php');
$id = $_GET['id'];
$conn->query("DELETE FROM `chances` WHERE id = $id");
header('location:../chances.php');
