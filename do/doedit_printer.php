<?php
include('../includes/connect.php');
$id = $_GET['id'];
$pname = $_POST['pname'];
$type = $_POST['type'];
$number = $_POST['number'];
$info = $_POST['info'];

$sql="UPDATE print SET pname='$pname',type='$type',number='$number',info='$info' WHERE id = $id";
$conn->query($sql);
header('location:../prints.php');
?>
