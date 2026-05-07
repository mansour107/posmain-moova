<?php include('../includes/connect.php');
$id = $_GET['id'];
$kname = $_POST['kname'];
$info = $_POST['info'];

$conn->query("UPDATE kbis SET kname = '$kname',info = '$info' where id = $id");
header('location:../kbis.php');
