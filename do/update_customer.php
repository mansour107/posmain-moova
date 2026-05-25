<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/db_bootstrap.php';

try {
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die('{"success":false,"error":"Database connection failed"}');
}

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
