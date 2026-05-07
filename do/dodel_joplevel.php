<?php
include '../includes/connect.php';

if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_GET['id'])) {


    $id = $_GET['id'];
    $conn->query("UPDATE joplevels SET isdeleted = 1 where id = $id");
    header('location:../joplevels.php');
}
