<?php
include('../includes/connect.php');
$id = $_GET['id'];
$name = $_POST['name'];
$value = $_POST['value'];
$sql = "UPDATE visittybes SET name='$name' , value='$value'  WHERE id = '$id'";
$conn->query($sql);
header('location:../vtybes.php');

