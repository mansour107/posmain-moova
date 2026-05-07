<?php
include '../includes/connect.php';
    $id = $_GET['id'];
    $conn->query("UPDATE acc_head SET isdeleted = 1 where id = $id");
    header('location:../acc_report.php');


