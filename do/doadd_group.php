<?php include('../includes/connect.php');
$gname = $_POST['gname'];
$conn->query("INSERT INTO item_group (gname) values ('$gname')");
$conn->query("INSERT INTO `process`(`type`) VALUES ('add group')");

header('location:../mygroups.php');