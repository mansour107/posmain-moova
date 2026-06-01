<?php

header('Content-Type: application/json');
include('../../includes/connect.php');
require_once('../../classes/Items/ItemCatalogStatus.php');
if (!isset($_GET['barcode'])) {
    echo json_encode(["error" => "Barcode not provided"]);
    exit;
}

$barcode = substr(trim((string) $_GET['barcode']), 0, 120);
if ($conn->connect_error) {
    echo json_encode(["error" => "Connection failed"]);
    exit;
}

$activeFilter = ItemCatalogStatus::activeOnlySql($conn);
$stmt = $conn->prepare("SELECT * FROM myitems WHERE barcode = ? {$activeFilter} LIMIT 1");
$stmt->bind_param('s', $barcode);
$stmt->execute();
$result = $stmt->get_result();
echo json_encode($result->num_rows > 0 ? $result->fetch_assoc() : ["error" => "No item found"]);
$stmt->close();
$conn->close();
?>
