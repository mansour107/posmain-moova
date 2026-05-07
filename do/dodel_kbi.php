<?php include("../includes/connect.php");
$id =$_GET['id'];
$conn->query("UPDATE kbis SET isdeleted  = 1 where id = $id");
header('location:../kbis.php');