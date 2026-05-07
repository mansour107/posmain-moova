<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli("localhost", "root", "", "focus");

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die('{"found":false,"error":"Database connection failed"}');
}

$conn->set_charset("utf8");

$phone = $_POST['phone'] ?? '';

if (empty($phone)) {
    die('{"found":false,"error":"Phone number is required"}');
}

$phone = mysqli_real_escape_string($conn, $phone);
$sql = "SELECT client_name, address FROM delivery_clients WHERE phone = '$phone' AND isdeleted = 0 LIMIT 1";
$result = $conn->query($sql);

if (!$result) {
    error_log("SQL Error: " . $conn->error . " - Query: " . $sql);
    die('{"found":false,"error":"Database query failed"}');
}

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo '{"found":true,"name":"' . $row['client_name'] . '","address":"' . $row['address'] . '"}';
} else {
    echo '{"found":false}';
}

$conn->close();
?>