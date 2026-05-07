<?php
include('../includes/connect.php');

$id = $_GET['id'];
$name = $_POST['name'];
$info = $_POST['info'];
$phone = $_POST['phone'];
$user = $_POST['user'];
$tasktybe = $_POST['tasktybe'];
$important = $_POST['important'];
$urgent = $_POST['urgent'];
$emp_comment = $_POST['emp_comment'];
$cl_comment = $_POST['cl_comment'];

$sql = "UPDATE `tasks` SET `name` = '$name' , `phone` = '$phone' , `info` = '$info',
`user` = '$user' , `tasktybe` = '$tasktybe' ,`emp_comment` = '$emp_comment' ,`cl_comment` = '$cl_comment' , `important` = '$important' ,
`urgent` = '$urgent' WHERE `id` = $id";





$conn->query($sql);
$conn->query("INSERT INTO `process`(`type`) VALUES ('delete task')");

header('location:../tasks.php');
