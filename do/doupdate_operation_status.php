<?php
include('../includes/connect.php');

$id = $_GET['id'];
$status = $_GET['status'];

$sql = "UPDATE employee_operations SET status='$status' WHERE id=$id";

if ($conn->query($sql) === TRUE) {
    header("Location: ../employee_operations.php");
} else {
    echo "Error updating record: " . $conn->error;
}
