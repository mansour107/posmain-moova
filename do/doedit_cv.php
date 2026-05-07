<?php 
include('../includes/connect.php');
$id = $_GET['id'];
foreach ($_POST as $key => $value) {
  $$key = $value;
}

$sql="UPDATE cvs SET name='$name',degree='$degree',address='$address',birthdate='$birthdate',phone='$phone',email='$email',skills='$skills',exp1='$exp1',exp2='$exp2',exp3='$exp3',lastsalary='$lastsalary' WHERE id = $id";
$conn->query($sql);
header('location:../cvs.php');