<?php
include '../includes/connect.php';
    $id = $_GET['id'];
    $conn->query("UPDATE allowances SET isdeleted = 1 where id = $id");
    header('location:../allowences.php');


