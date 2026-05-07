<?php
include('../includes/connect.php');

$name = $_POST['name'];
$info = $_POST['info'];
$phone = $_POST['phone'];
$user = $_POST['user'];
$tasktybe = $_POST['tasktybe'];
$important = $_POST['important'];
$urgent = $_POST['urgent'];
$emp_comment = $_POST['emp_comment'];
$cl_comment = $_POST['cl_comment'];

$sql="INSERT INTO tasks(name, info , emp_comment, cl_comment, phone, user, tasktybe, important, urgent) VALUES ('$name','$info','$emp_comment','$cl_comment','$phone','$user','$tasktybe','$important','$urgent') ";
if (!$conn->query($sql)) {
    die("Error description: " . $conn->error);
}
$conn->query("INSERT INTO `process`(`type`) VALUES ('add task')");

header('location:../tasks.php');
