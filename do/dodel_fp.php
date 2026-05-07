<?php include('../includes/connect.php');
$password = $_POST['password'];
$id = $_GET['id'];
// $conn->query("UPDATE attandance SET isdeleted = 1 where id = $id");
$sql = "UPDATE attandance SET isdeleted = 1 where id = $id";
$conn->query($sql);


header('location:../manualattandance.php');
