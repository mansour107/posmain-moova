<?php
include '../includes/connect.php';

if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['name'])) {
    $id = $_GET['id'];
    foreach ($_POST as $key => $value) {
        $$key = $value;
    }

    $sql = "UPDATE allowances SET `name` = '$name' , `info` = '$info' , `tybe` = '$tybe' WHERE id = '$id' ";
    $conn->query($sql);
    header("location:../allowences.php");
}
