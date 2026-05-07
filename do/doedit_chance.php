<?php
include '../includes/connect.php';
if (isset($_GET)) {
if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['name'])) {
    $id = $_GET['id'];
    foreach ($_POST as $key => $value) {
        $$key = $value;
    }

    $sql = "UPDATE tasks SET name='$name',ch_tybe='$ch_tybe',phone='$phone',user='$user',important='$important',urgent='$urgent'  WHERE id= '$id' ";

    $conn->query($sql);
    header("location:../chances.php");
}}else{
echo "something went wrong" ;}
