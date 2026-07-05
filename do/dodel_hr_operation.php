<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_hr_operation.php');

$id = $_GET['id'];

$sql = "DELETE FROM hr_operations WHERE id=$id";

if ($conn->query($sql) === TRUE) {
    header("Location: ../hr_operations.php");
} else {
    echo "Error deleting record: " . $conn->error;
}
