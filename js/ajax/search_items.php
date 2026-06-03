<?php
include('../../includes/connect.php');
require_once('../../classes/Items/ItemCatalogStatus.php');
$input = $_POST['input'];
$activeFilter = ItemCatalogStatus::activeOnlySql($conn)
    . ItemCatalogStatus::posSellableOnlySql($conn);
$sql = "SELECT * FROM myitems WHERE iname LIKE ? AND isdeleted = 0 {$activeFilter} LIMIT 50";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $inputParam);
$inputParam = '%' . $input . '%'; // Add wildcards for partial matching
$stmt->execute();
$result = $stmt->get_result();

$items = array();
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($items);
?>
