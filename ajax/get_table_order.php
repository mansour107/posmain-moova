<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../classes/TableOrderService.php');
require_once('../classes/Pos/Service/ModifierLineNoteService.php');

header('Content-Type: application/json');

try {
    $tableId = isset($_GET['table_id']) ? intval($_GET['table_id']) : 0;
    if (!$tableId) {
        throw new Exception('بيانات الطاولة غير صحيحة');
    }

    $tableOrderService = new TableOrderService();
    $table = $tableOrderService->requireTable($conn, $tableId);
    $order = $tableOrderService->findActiveOrderByTableId($conn, $tableId);

    if ($order) {
        $orderId = (int) $order['id'];
        $items = [];
        $customizationService = new ModifierLineNoteService();
        foreach ($tableOrderService->queryAll($conn, "
            SELECT fd.*, i.iname, i.price1 AS sprice, i.barcode
            FROM fat_details fd
            LEFT JOIN myitems i ON fd.item_id = i.id
            WHERE fd.fatid = ?
              AND fd.isdeleted = 0
            ORDER BY fd.id ASC
        ", [$orderId]) as $item) {
            $qty = floatval($item['qty_out']) - floatval($item['qty_in']);
            $customizations = ['modifiers' => [], 'notes' => []];
            try {
                $customizations = $customizationService->fetchLineCustomizations($conn, $orderId, (int) $item['id']);
            } catch (Throwable $ignored) {
                $customizations = ['modifiers' => [], 'notes' => []];
            }
            $modifierLineTotal = 0.0;
            foreach ($customizations['modifiers'] as $modifier) {
                $modifierLineTotal += (float) ($modifier['line_delta'] ?? 0);
            }
            $basePrice = (float) $item['price'];
            if ($qty > 0 && $modifierLineTotal > 0) {
                $basePrice = max(0, $basePrice - ($modifierLineTotal / $qty));
            }
            $items[] = [
                'id' => $item['item_id'],
                'name' => $item['iname'],
                'price' => floatval($item['price']),
                'base_price' => $basePrice,
                'qty' => $qty,
                'subtotal' => floatval($item['det_value']),
                'barcode' => $item['barcode'] ?: $item['item_id'],
                'modifiers' => $customizations['modifiers'],
            ];
        }
        
        echo json_encode([
            'success' => true,
            'has_order' => true,
            'order' => array_merge($order, [
                'table_name' => $table['tname'],
            ]),
            'items' => $items
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'has_order' => false,
            'order' => null,
            'items' => []
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
