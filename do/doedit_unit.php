<?php
include('../includes/connect.php');
$id = $_GET['id'];
$uname = $_POST['uname'];

$sql = "UPDATE myunits SET uname='$uname'  WHERE id = '$id'";

$conn->query($sql);
header('location:../myunits.php');

