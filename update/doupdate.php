<?php
include('../includes/connect.php');
$sql = $_POST['update'];
echo $sql;
$conn->query($sql);
header('location:../index.php');

