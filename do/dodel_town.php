<?php include("../includes/connect.php");
$id =$_GET['id'];
$conn->query("UPDATE towns SET isdeleted  = 1 where id = $id");
header('location:../mytowns.php');