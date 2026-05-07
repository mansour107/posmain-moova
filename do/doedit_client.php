<?php 
include('../includes/connect.php');
$id=$_GET['id'];
$name = $_POST['name'];
$phone = $_POST['phone'];
$city = $_POST['city'];
$address = $_POST['address'];
$gender = $_POST['gender'];
$height = $_POST['height'];
$weight = $_POST['weight'];
$dateofbirth = $_POST['dateofbirth'];
$ref = $_POST['ref'];
$diseses = $_POST['diseses'];
$info = $_POST['info'];



$sql="UPDATE `clients` SET `name`='$name',`phone`='$phone',`address`='$address',`city`='$city',`height`='$height',`weight`='$weight',`dateofbirth`='$dateofbirth',`ref`='$ref',`diseses`='$diseses',`info`='$info',`gender`='$gender' WHERE id = $id";
$conn->query($sql);
header('location:../clients.php')


?>