<?php include('../includes/connect.php');
$id = $_GET['id'];

$conn->query("UPDATE visittybes SET isdeleted = 1  where id = $id");
header('location:../vtybes.php');
