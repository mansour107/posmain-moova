<?php include('../includes/connect.php');
$id = $_GET['id'];
$gname = $_POST['gname'];

$conn->query("UPDATE item_group SET gname = '$gname' where id = $id");
header('location:../mygroups.php');
