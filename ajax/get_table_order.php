<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/get_table_order.php');
require_once('../classes/TableOrderService.php');
require_once('../classes/Pos/Service/ModifierLineNoteService.php');
require_once('../classes/Pos/Service/LegacyOrderLinePresentationService.php');
require_once('../classes/Pos/Service/PreparationSelectionService.php');
require_once('../classes/Financial/Money.php');
require_once('../classes/Recipe/RecipeDecimal.php');

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
        $preparationService = new PreparationSelectionService();
        $linePresentation = new LegacyOrderLinePresentationService();
        foreach ($tableOrderService->queryAll($conn, "
            SELECT fd.*, i.iname, i.price1 AS sprice, i.barcode
            FROM fat_details fd
            LEFT JOIN myitems i ON fd.item_id = i.id
            WHERE fd.fatid = ?
              AND fd.isdeleted = 0
            ORDER BY fd.id ASC
        ", [$orderId]) as $item) {
            $presentedLine = $linePresentation->presentSaleLine($item);
            $qty = RecipeDecimal::normalize($presentedLine['qty']);
            $customizations = ['modifiers' => [], 'notes' => []];
            try {
                $customizations = $customizationService->fetchLineCustomizations($conn, $orderId, (int) $item['id']);
            } catch (Throwable $ignored) {
                $customizations = ['modifiers' => [], 'notes' => []];
            }
            $modifierLineTotal = RecipeDecimal::zero();
            foreach ($customizations['modifiers'] as $modifier) {
                $modifierLineTotal = RecipeDecimal::add(
                    $modifierLineTotal,
                    RecipeDecimal::normalize($modifier['line_delta'] ?? '0')
                );
            }
            $price = RecipeDecimal::normalize($presentedLine['price']);
            $basePrice = $price;
            if (RecipeDecimal::compare($qty, '0') > 0 && RecipeDecimal::compare($modifierLineTotal, '0') > 0) {
                $basePrice = RecipeDecimal::subtract(
                    $basePrice,
                    RecipeDecimal::divide($modifierLineTotal, $qty)
                );
                if (RecipeDecimal::compare($basePrice, '0') < 0) {
                    $basePrice = RecipeDecimal::zero();
                }
            }
            $items[] = [
                'id' => $item['item_id'],
                'name' => $item['iname'],
                'price' => $price,
                'base_price' => $basePrice,
                'qty' => $qty,
                'u_val' => RecipeDecimal::normalize($presentedLine['u_val']),
                'subtotal' => Money::from($item['det_value'] ?? '0')->toString(),
                'barcode' => $item['barcode'] ?: $item['item_id'],
                'modifiers' => $customizations['modifiers'],
                'preparation_values' => $preparationService->fetchLineValues(
                    $conn,
                    $orderId,
                    (int) $item['id']
                ),
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
