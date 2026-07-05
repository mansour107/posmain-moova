<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_operation_step.php');

$id = $_GET['id'];
$op_id = $_GET['op_id'];

$sql = "DELETE FROM hr_operation_steps WHERE id=$id";

if ($conn->query($sql) === TRUE) {
    header("Location: ../edit_hr_operation.php?id=$op_id");
} else {
    echo "Error deleting record: " . $conn->error;
}
