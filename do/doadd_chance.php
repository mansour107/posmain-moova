<?php include('../includes/connect.php');
if (!isset($_POST['name'])){
    header("location:../chances.php");
};
$name = $_POST['name'];
$phone = $_POST['phone'];
$cdate = $_POST['cdate'];
$user = $_POST['user'];
$important = $_POST['important'];
$tybe = $_POST['tybe'];
$sql1 = "INSERT INTO  chances ( client ,  cname ,  phone ,  cdate ,  important ,  tybe ) VALUES ('$name' ,  '$name' ,  '$phone' ,  '$cdate' ,  '$important' ,  '$tybe')";
$conn->query($sql1);

$sql = "INSERT INTO tasks(name, ch_tybe, phone, user, important, urgent) VALUES ('$name','$tybe','$phone','$user','$important','$urgent')";
$conn->query($sql);

$conn->query("INSERT INTO `process`(`type`) VALUES ('add chance')");
header("location:../chances.php");
