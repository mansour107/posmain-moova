<?php
include('../../includes/connect.php');
// Get the row ID from the request
$rowId = $_GET['id'];

$sql = "DELETE FROM fat_details WHERE id = $rowId";
$conn->query($sql);
if ($conn->query($sql) === TRUE) {
    echo "Row deleted successfully";
} else {
    echo "Error deleting row: " . $conn->error;
}