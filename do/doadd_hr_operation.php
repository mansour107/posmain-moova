<?php
include('../includes/connect.php');

$name = $_POST['name'];
$info = $_POST['description'];
$parent_id = !empty($_POST['parent_id']) ? $_POST['parent_id'] : "NULL";

// 1. Insert the Operation
$sql = "INSERT INTO hr_operations (name, description, parent_id) VALUES ('$name', '$info', $parent_id)";

if ($conn->query($sql) === TRUE) {
    $last_id = $conn->insert_id;

    // 2. Insert Steps if they exist
    if(isset($_POST['steps_desc']) && is_array($_POST['steps_desc'])) {
        $descriptions = $_POST['steps_desc'];
        $orders = $_POST['steps_order'];

        for($i = 0; $i < count($descriptions); $i++) {
            $desc = $conn->real_escape_string($descriptions[$i]);
            $order = intval($orders[$i]);
            
            $step_sql = "INSERT INTO hr_operation_steps (operation_id, description, step_order) VALUES ($last_id, '$desc', $order)";
            $conn->query($step_sql);
        }
    }

    header("Location: ../hr_operations.php");

} else {
    echo "Error: " . $sql . "<br>" . $conn->error;
}
