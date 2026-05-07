<?php
include('../includes/connect.php');
$id = $_GET['id'];
$sname = $_POST['sname'];
$info = $_POST['info'];

$sql="UPDATE services SET sname='$sname',info='$info' WHERE id = $id ";
$conn->query($sql);
header('location:../services.php');
?>
