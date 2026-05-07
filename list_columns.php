<?php
include('includes/connect.php');

// List all columns in ot_head table
$sql = "SHOW COLUMNS FROM ot_head";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    echo "Columns in ot_head table:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "No columns found or error occurred\n";
}

$conn->close();
?>