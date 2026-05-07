<?php
include '../includes/connect.php';

if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['name'])) {

    foreach ($_POST as $key => $value) {
        $$key = $value;
    }
    $sql = "INSERT INTO departments (`name` , `info` ) VALUES ('$name' , '$info')";
    $res = $conn->query($sql);

    $conn->query("INSERT INTO `process`(`type`) VALUES ('add department')");

    header("location:../departments.php");
}
