<?php include('../includes/connect.php');

$id = $_GET['id'];

$conn->query("UPDATE employees SET isdeleted = 1 where id = $id");
header('location:../employees.php');
