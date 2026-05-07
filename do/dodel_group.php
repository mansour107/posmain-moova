<?php include("../includes/connect.php");
$id =$_GET['id'];
$conn->query("UPDATE item_group SET isdeleted  = 1 where id = $id");
header('location:../mygroups.php');