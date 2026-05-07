<?php
// Manual connection settings for XAMPP
$dbhost = 'localhost';
$dbuser = 'root';
$dbpass = '';
$dbname = 'focus';

$conn = new mysqli($dbhost, $dbuser, $dbpass, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}

echo "ID | Type | Value | Jal Name | Jal Amount | Jal Notes\n";
echo "--------------------------------------------------------\n";

$sql = "SELECT id, pro_tybe, pro_value, jal_name, jal_amount, jal_notes FROM ot_head ORDER BY id DESC LIMIT 5";
$result = $conn->query($sql);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $id = $row['id'];
        $type = $row['pro_tybe'];
        $val = $row['pro_value'];
        $jname = $row['jal_name'] ?? 'NULL';
        $jamount = $row['jal_amount'] ?? 'NULL';
        $jnotes = $row['jal_notes'] ?? 'NULL';
        
        echo "$id | $type | $val | $jname | $jamount | $jnotes\n";
    }
} else {
    echo "Query failed: " . $conn->error . "\n";
}
?>
