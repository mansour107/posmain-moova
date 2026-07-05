<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/doupdate_operation_status.php');

$id = $_GET['id'];
$status = $_GET['status'];

$sql = "UPDATE employee_operations SET status='$status' WHERE id=$id";

if ($conn->query($sql) === TRUE) {
    header("Location: ../employee_operations.php");
} else {
    echo "Error updating record: " . $conn->error;
}
