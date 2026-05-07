<?php include('../includes/connect.php');
$id = $_POST['id'];
$emp_comment = $_POST['emp_comment'];

$conn->query("UPDATE tasks SET isdeleted = 1 , `emp_comment`='$emp_comment' where id = $id");
header('location:../tasks.php');
