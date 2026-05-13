<?php
require_once __DIR__ . '/../includes/production_guard.php';
production_guard_deny_route('do/debug_schema.php');

$conn = new mysqli('localhost', 'root', '', 'focus');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$result = $conn->query("DESCRIBE ot_head");
if ($result) {
    echo "COLUMNS:\n";
    while($row = $result->fetch_assoc()) {
        echo $row['Field'] . "\n";
    }
} else {
    echo "Error describing table: " . $conn->error;
}
?>
