<?php include('../includes/connect.php');
$id = $_GET['id'];
$conn->query("DELETE FROM tasks where id = $id");
header('location:../followup.php');
