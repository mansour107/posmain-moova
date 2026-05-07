<?php
include('../includes/connect.php');

$operation_id = $_POST['operation_id'];
$description = $_POST['description'];
$step_order = $_POST['step_order'];

$sql = "INSERT INTO hr_operation_steps (operation_id, description, step_order) VALUES ($operation_id, '$description', $step_order)";

if ($conn->query($sql) === TRUE) {
    header("Location: ../edit_hr_operation.php?id=$operation_id");
} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}
