<?php
include('../includes/connect.php');
$id= $_GET['id'];
$pname = $_POST['pname'];
$info = $_POST['info'];

$sql="UPDATE prods SET pname='$oname',info='$info' WHERE id = $id";
$conn->query($sql);
header('location:../prods.php');
?>
