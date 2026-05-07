<?php include('../includes/connect.php');
$id = $_GET['id'];
$name = $_POST['name'];

$conn->query("UPDATE towns SET name = '$name' where id = $id");
header('location:../mytowns.php');
