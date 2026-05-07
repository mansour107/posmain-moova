<?php
include('../includes/connect.php');

// Ensure input data is sanitized to prevent SQL injection
$id = intval($_GET['id']);  // Use intval to safely get the ID from the query
$name = $conn->real_escape_string($_POST['name']);
$info = $conn->real_escape_string($_POST['info']);
$phone = $conn->real_escape_string($_POST['phone']);
$user = $conn->real_escape_string($_POST['user']);
$tasktybe = $conn->real_escape_string($_POST['tasktybe']);
$important = intval($_POST['important']);  // assuming it's an integer
$urgent = intval($_POST['urgent']);  // assuming it's an integer

// Update the task
$sql = "UPDATE `tasks` SET 
    `name` = '$name', 
    `phone` = '$phone', 
    `info` = '$info',
    `user` = '$user', 
    `tasktybe` = '$tasktybe', 
    `important` = $important,
    `urgent` = $urgent 
    WHERE `id` = $id";

// Execute the update query and check for success
if ($conn->query($sql) === TRUE) {
    // Log the process only if the update was successful
    $conn->query("INSERT INTO `process`(`type`) VALUES ('update task')");
    // Redirect to tasks page
    header('location:../tasks.php');
} else {
    // If the update fails, log or show an error
    echo "Error updating task: " . $conn->error;
}

$conn->close();
