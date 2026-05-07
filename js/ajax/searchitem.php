<?php
include('../../includes/connect.php');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$input = $_GET['input']; // Assuming you're using GET method

// Prepare and execute SQL query
$sql = "SELECT id, iname FROM myitems WHERE iname LIKE '%" . $conn->real_escape_string($input) . "%' AND isdeleted = 0 LIMIT 50";
$result = $conn->query($sql);

// Check if any rows were returned
if ($result->num_rows > 0) {
    // Fetch associative array
    $data = array();
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    // Send data as JSON response
    header('Content-Type: application/json');
    echo json_encode($data);
} else {
    // No rows found
    echo json_encode(array()); // Return an empty array as JSON response
}

// Close connection
$conn->close();
?>
