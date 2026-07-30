<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/get_table_items.php');
require_once('../classes/Financial/Money.php');
require_once('../classes/Pos/Service/LegacyOrderLinePresentationService.php');
require_once('../classes/TableOrderService.php');
ob_clean(); // Clean any headers/whitespace from includes

header('Content-Type: application/json');

if (!isset($_GET['order_id'])) {
    echo json_encode(['success' => false, 'message' => 'Order ID required']);
    exit;
}

$order_id = intval($_GET['order_id']);

try {
    $tableOrderService = new TableOrderService();
    $order = $tableOrderService->queryOne(
        $conn,
        'SELECT id, fat_total, fat_disc, fat_net, mutation_version
         FROM ot_head
         WHERE id = ?
           AND isdeleted = 0
         LIMIT 1',
        [$order_id]
    );
    if (!$order) {
        throw new RuntimeException('ORDER_NOT_FOUND');
    }

    $query = "SELECT fd.id, fd.item_id, m.iname, fd.qty_in, fd.qty_out, fd.u_val, fd.price, fd.det_value, fd.fatid
	              FROM fat_details fd
	              JOIN myitems m ON fd.item_id = m.id
	              WHERE fd.fatid = ? AND fd.isdeleted = 0";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    $linePresentation = new LegacyOrderLinePresentationService();
    while ($row = $result->fetch_assoc()) {
        $presentedLine = $linePresentation->presentSaleLine($row);
        $items[] = [
            'id' => $row['id'], // detail id
            'item_id' => $row['item_id'],
            'name' => $row['iname'],
            'qty' => $presentedLine['qty'],
            'price' => $presentedLine['price'],
            'total' => Money::from($row['det_value'] ?? '0')->toString()
        ];
    }

    echo json_encode([
        'success' => true,
        'items' => $items,
        'order_total' => Money::from($order['fat_total'] ?? '0')->toString(),
        'order_discount' => Money::from($order['fat_disc'] ?? '0')->toString(),
        'order_net' => Money::from($order['fat_net'] ?? '0')->toString(),
        'mutation_version' => max(1, (int) ($order['mutation_version'] ?? 1)),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
