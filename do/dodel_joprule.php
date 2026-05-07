<?php include('../includes/connect.php');
$password = $_POST['password'];
$id = $_GET['id'];
$conn->query("UPDATE joprules SET isdeleted = 1 where id = $id");
header('location:../joprules.php');
