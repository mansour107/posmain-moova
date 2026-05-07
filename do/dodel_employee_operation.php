<?php
include('../includes/connect.php');

$id = $_GET['id'];

$sql = "DELETE FROM employee_operations WHERE id=$id";

if ($conn->query($sql) === TRUE) {
    header("Location: ../employee_operations.php");
} else {
    echo "Error deleting record: " . $conn->error;
}
