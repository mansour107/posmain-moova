<?php
include('../includes/connect.php');

$id = $_GET['id'];

$sql = "DELETE FROM hr_operations WHERE id=$id";

if ($conn->query($sql) === TRUE) {
    header("Location: ../hr_operations.php");
} else {
    echo "Error deleting record: " . $conn->error;
}
