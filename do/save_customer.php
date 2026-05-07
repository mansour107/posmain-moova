<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// استخدام نفس إعدادات قاعدة البيانات من الملف الرئيسي
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
$customer_phone = mysqli_real_escape_string($conn, $phone);
$customer_address = mysqli_real_escape_string($conn, $address);

$sql = "INSERT INTO delivery_clients (client_name, phone, address) VALUES ('$customer_name', '$customer_phone', '$customer_address')";

if ($conn->query($sql)) {
    echo '{"success":true}';
} else {
    error_log("SQL Error: " . $conn->error . " - Query: " . $sql);
    echo '{"success":false,"error":"' . $conn->error . '"}';
}

$conn->close();
?>