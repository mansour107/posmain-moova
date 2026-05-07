<?php
include('includes/connect.php');

// Check if order_type column exists in ot_head table
$sql = "SHOW COLUMNS FROM ot_head LIKE 'order_type'";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo "Column 'order_type' exists in ot_head table\n";
    // Show the column details
    $row = $result->fetch_assoc();
    print_r($row);
} else {
    echo "Column 'order_type' does not exist in ot_head table\n";
}

// Check if age column exists
$sql2 = "SHOW COLUMNS FROM ot_head LIKE 'age'";
$result2 = $conn->query($sql2);

if ($result2 && $result2->num_rows > 0) {
    echo "Column 'age' exists in ot_head table\n";
    // Show the column details
    $row = $result2->fetch_assoc();
    print_r($row);
} else {
    echo "Column 'age' does not exist in ot_head table\n";
}

$conn->close();
?>