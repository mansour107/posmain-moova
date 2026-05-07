<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli("localhost", "root", "", "focus");

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die('{"success":false,"error":"Database connection failed"}');
}

$conn->set_charset("utf8");

$phone = $_POST['phone'] ?? '';
$name = $_POST['name'] ?? '';
$address = $_POST['address'] ?? '';

if (empty($phone) || empty($name) || empty($address)) {
    die('{"success":false,"error":"Missing required fields"}');
}

$customer_name = mysqli_real_escape_string($conn, $name);
$customer_address = mysqli_real_escape_string($conn, $address);
$phone = mysqli_real_escape_string($conn, $phone);

$sql = "UPDATE delivery_clients SET client_name = '$customer_name', address = '$customer_address' WHERE phone = '$phone' AND isdeleted = 0";

if ($conn->query($sql)) {
    if ($conn->affected_rows > 0) {
        echo '{"success":true}';
    } else {
        echo '{"success":false,"error":"No customer found to update"}';
    }
} else {
    error_log("SQL Error: " . $conn->error . " - Query: " . $sql);
    echo '{"success":false,"error":"' . $conn->error . '"}';
}

$conn->close();
?>