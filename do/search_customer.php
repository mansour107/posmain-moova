<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/db_bootstrap.php';

try {
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die('{"found":false,"error":"Database connection failed"}');
}

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
