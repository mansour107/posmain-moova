<?php include('../../includes/connect.php');
session_start();
$id = $_GET['id'];
$rent  = $_GET['r'];

$conn->query("DELETE from myinstallments where contract = $id");
$conn->query("UPDATE myrents SET isdeleted = 1 where id = $id");
$conn->query("UPDATE acc_head SET rentable = 1 where id = $rent");

header("location:../../rentables.php");?>