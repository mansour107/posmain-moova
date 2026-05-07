<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

include('../includes/connect.php');
ob_clean(); // Clean any headers/whitespace from includes

header('Content-Type: application/json');

if (!isset($_GET['order_id'])) {
    echo json_encode(['success' => false, 'message' => 'Order ID required']);
    exit;
}

$order_id = intval($_GET['order_id']);

try {
    $query = "SELECT fd.id, fd.item_id, m.iname, fd.qty_out, fd.price, fd.pro_id 
              FROM fat_details fd
              JOIN myitems m ON fd.item_id = m.id
              WHERE fd.pro_id = ? AND fd.isdeleted = 0";
              
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => $row['id'], // detail id
            'item_id' => $row['item_id'],
            'name' => $row['iname'],
            'qty' => floatval($row['qty_out']),
            'price' => floatval($row['price']),
            'total' => floatval($row['qty_out']) * floatval($row['price'])
        ];
    }
    
    echo json_encode(['success' => true, 'items' => $items]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
