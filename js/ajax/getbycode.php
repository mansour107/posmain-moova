<?php

header('Content-Type: application/json');
include('../../includes/connect.php');
if (!isset($_GET['barcode'])) {
    echo json_encode(["error" => "Barcode not provided"]);
    exit;
}

$barcode = $_GET['barcode'];
if ($conn->connect_error) {
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$result = $conn->query("SELECT * FROM myitems WHERE barcode = '$barcode'");
echo json_encode($result->num_rows > 0 ? $result->fetch_assoc() : ["error" => "No item found"]);
$conn->close();
?>
