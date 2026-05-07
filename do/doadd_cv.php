<?php 
include('../includes/connect.php');
session_start();

$user = $_SESSION['userid'];
$name = $_POST['name'];
$degree = $_POST['degree'];
$address = $_POST['address'];
$birthdate = $_POST['birthdate'];
$phone = $_POST['phone'];
$email = $_POST['email'];
$skills = $_POST['skills'];
$exp1 = $_POST['exp1'];
$exp2 = $_POST['exp2'];
$exp3 = $_POST['exp3'];
$lastsalary = $_POST['lastsalary'];
$expsalary = $_POST['expsalary'];
$referances = $_POST['referances'];

$sql = "INSERT INTO cvs( userid, name, degree, address, birthdate, phone, email, skills, exp1, exp2, exp3, lastsalary, expsalary, referances) VALUES ('$user', '$name', '$degree', '$address', '$birthdate', '$phone' , '$email' , '$skills' , '$exp1' , '$exp2' , '$exp3' , '$lastsalary' ,  '$expsalary' , '$referances')";
$conn->query($sql);

$conn->query("INSERT INTO `process`(`type`) VALUES ('add cv')");


header('location:../cvs.php')
?>


