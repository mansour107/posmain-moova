<?php
include '../includes/connect.php';

if ($_SERVER['REQUEST_METHOD'] == "POST") {
    foreach ($_POST as $key => $value) {
        $$key = $value;
    }
    $sql = "INSERT INTO joplevels (`name`,`info`) VALUES ('$name','$info')";
    $res = $conn->query($sql);
    $conn->query("INSERT INTO `process`(`type`) VALUES ('add jop level')");

    header("location:../joplevels.php");
}
