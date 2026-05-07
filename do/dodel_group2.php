<?php include("../includes/connect.php");
$id =$_GET['id'];
$conn->query("UPDATE item_group2 SET isdeleted  = 1 where id = $id");
header('location:../item_categories.php');