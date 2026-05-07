<?php include('../includes/connect.php');
print_r($_POST);

$name = $_POST['name'];
$phone = $_POST['phone'];
$address = $_POST['address'];
$city = $_POST['city'];
$gender = $_POST['gender'];
$height = $_POST['height'];
$weight = $_POST['weight'];
$dateofbirth = $_POST['dateofbirth'];
$ref = $_POST['ref'];
$info = $_POST['info'];
$diseses = $_POST['diseses'];
$info = $_POST['info'];


$sql="INSERT INTO clients (name, phone,address, city, height, weight, dateofbirth, ref,gender, diseses, info) VALUES ('$name','$phone','$address','$city','$height','$weight','$dateofbirth','$ref','$gender','$diseses','$info')";
$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('add chance')");

header("location:../clients.php");

?>