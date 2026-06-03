<?php
include('../../includes/connect.php');
require_once('../../classes/Items/ItemCatalogStatus.php');

$search = isset($_GET['search']) ? $_GET['search'] : '';

$activeFilter = ItemCatalogStatus::activeOnlySql($conn)
    . ItemCatalogStatus::posSellableOnlySql($conn);
$sql = "SELECT id, iname FROM myitems WHERE iname LIKE ? AND isdeleted = 0 {$activeFilter} order by iname limit 50";
$stmt = $conn->prepare($sql);
$searchTerm = "%".$search."%";
$stmt->bind_param("s", $searchTerm);
$stmt->execute();
$result = $stmt->get_result();



$data = array();
while ($row = $result->fetch_assoc()) {
    $data[] = array("id" => $row['id'], "text" => $row['iname']);
}

$stmt->close();
$conn->close();

echo json_encode($data);
?>
