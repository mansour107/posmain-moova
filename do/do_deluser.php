<?php include('../includes/connect.php') ;
$id = $_GET['id'];

$sqldel = "DELETE FROM `users` WHERE id = $id";


    $conn->query($sqldel);

    $conn->query("INSERT INTO `process`(`type`) VALUES ('delete user')");
    header('location:../users.php');
    
?>