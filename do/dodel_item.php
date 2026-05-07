<?php include('../includes/connect.php');
$password = $_POST['password'];
$srvrpass = $rowstg['edit_pass'];
if ($password == $rowstg['edit_pass']) { 
$id = $_GET['id'];
$conn->query("UPDATE myitems SET isdeleted = 1 where id = $id");
header('location:../myitems.php');
}else{
header("location:../myitems.php?pass='$password'&srvr='$srvrpass'");
}