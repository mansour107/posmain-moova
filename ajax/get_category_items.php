<?php
header('Content-Type: application/json');
include('../includes/connect.php');
require_once('../classes/Pos/Service/ItemAvailabilityService.php');

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
    $branchConfig = function_exists('posmain_app_config')
        ? (posmain_app_config()['branch'] ?? [])
        : [];
    $items = (new ItemAvailabilityService())->decorateItems($conn, $items, [
        'tenant' => (int)($branchConfig['pos_tenant'] ?? 0),
        'branch' => (int)($branchConfig['pos_branch'] ?? 0),
        'channel' => 'pos',
        'order_type' => 'takeaway',
    ]);
    echo json_encode(['success' => true, 'items' => $items]);
} else {
    echo json_encode(['success' => false, 'error' => 'لا توجد أصناف في هذه المجموعة']);
}
?>
