<?php
header('Content-Type: application/json');
include('../includes/connect.php');

if (!isset($_GET['category_id'])) {
    echo json_encode(['success' => false, 'error' => 'معرف المجموعة مطلوب']);
    exit;
}

$category_id = intval($_GET['category_id']);

$sql = "SELECT id, iname as name, price1 as price FROM myitems WHERE group1 = $category_id AND isdeleted = 0 ORDER BY iname";
$result = $conn->query($sql);

$items = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => intval($row['id']),
            'name' => $row['name'],
            'price' => floatval($row['price'] ?: 0)
        ];
    }
    echo json_encode(['success' => true, 'items' => $items]);
} else {
    echo json_encode(['success' => false, 'error' => 'لا توجد أصناف في هذه المجموعة']);
}
?>