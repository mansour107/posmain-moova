<?php
include('../includes/connect.php');

$employee_id = $_POST['employee_id'];
$operation_id = $_POST['operation_id'];
$status = "assigned";

$sql = "INSERT INTO employee_operations (employee_id, operation_id, status) VALUES ($employee_id, $operation_id, '$status')";

if ($conn->query($sql) === TRUE) {
    header("Location: ../employee_operations.php");
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}
