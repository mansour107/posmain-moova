<?php
include('../../includes/connect.php');
$input = $_POST['input'];
$sql = "SELECT * FROM myitems WHERE iname LIKE ? AND isdeleted = 0 LIMIT 50";
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
