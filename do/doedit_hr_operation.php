<?php
include('../includes/connect.php');

$id = $_POST['id'];
$name = $_POST['name'];
$info = $_POST['description'];
$parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : "NULL";

$sql = "UPDATE hr_operations SET name='$name', description='$info', parent_id=$parent_id WHERE id=$id";

if ($conn->query($sql) === TRUE) {
    header("Location: ../hr_operations.php");
} else {
    echo "Error updating record: " . $conn->error;
}
