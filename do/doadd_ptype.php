<?php
include('../includes/connect.php');
$pname = $_POST['pname'];
$info = $_POST['info'];

$sql="INSERT INTO paper_types ( pname,  info) VALUES ( '$pname',  '$info') ";
$conn->query($sql);
header('location:../ptypes.php');
?>
