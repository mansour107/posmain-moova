<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_employee_operation.php');

$id = $_GET['id'];

$sql = "DELETE FROM employee_operations WHERE id=$id";

if ($conn->query($sql) === TRUE) {
    header("Location: ../employee_operations.php");
} else {
    echo "Error deleting record: " . $conn->error;
}
