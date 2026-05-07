<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Manual connection settings for XAMPP
$dbhost = 'localhost';
$dbuser = 'root';
$dbpass = '';
$dbname = 'focus';

echo "Connecting to database '$dbname' on '$dbhost'...\n";
$conn = new mysqli($dbhost, $dbuser, $dbpass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}
echo "Connected successfully.\n";

echo "Checking database structure for table 'ot_head'...\n";

// Function to check if column exists
function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// Function to add column
function addColumn($conn, $table, $column, $definition) {
    echo "Adding column '$column'...\n";
    $sql = "ALTER TABLE `$table` ADD COLUMN `$column` $definition";
    if ($conn->query($sql) === TRUE) {
        echo "Successfully added column '$column'.\n";
    } else {
        echo "Error adding column '$column': " . $conn->error . "\n";
    }
}

// Check and add jal_name
if (!columnExists($conn, 'ot_head', 'jal_name')) {
    addColumn($conn, 'ot_head', 'jal_name', 'VARCHAR(255) DEFAULT NULL');
} else {
    echo "Column 'jal_name' already exists.\n";
}

// Check and add jal_notes
if (!columnExists($conn, 'ot_head', 'jal_notes')) {
    addColumn($conn, 'ot_head', 'jal_notes', 'TEXT DEFAULT NULL');
} else {
    echo "Column 'jal_notes' already exists.\n";
}

// Check and add jal_amount
if (!columnExists($conn, 'ot_head', 'jal_amount')) {
    addColumn($conn, 'ot_head', 'jal_amount', 'DECIMAL(10, 2) DEFAULT 0.00');
} else {
    echo "Column 'jal_amount' already exists.\n";
}

echo "Database repair completed.\n";
?>
