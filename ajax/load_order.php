<?php
// تحميل بيانات الطلب النشط للطاولة
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

$root_path = dirname(__DIR__);
include($root_path . '/includes/connect.php');
require_once($root_path . '/classes/TableOrderService.php');
require_once($root_path . '/classes/Pos/Service/ModifierLineNoteService.php');
require_once($root_path . '/classes/Pos/Service/LegacyOrderLinePresentationService.php');
require_once($root_path . '/classes/Pos/Service/PreparationSelectionService.php');
require_once($root_path . '/classes/Financial/Money.php');
require_once($root_path . '/classes/Recipe/RecipeDecimal.php');

try {
    if (!isset($_POST['order_id']) || empty($_POST['order_id'])) {
        echo json_encode(['success' => false, 'error' => 'Order ID is required']);
        exit;
    }
    
    $order_id = intval($_POST['order_id']);
    $posted_table_id = isset($_POST['table_id']) ? intval($_POST['table_id']) : null;
    $tableOrderService = new TableOrderService();
    $loaded = $tableOrderService->loadOrderWithItems($conn, $order_id, $posted_table_id);

    if (!$loaded) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $order = $loaded['order'];
    $items = [];
    $customizationService = new ModifierLineNoteService();
    $linePresentation = new LegacyOrderLinePresentationService();
    $preparationSelection = new PreparationSelectionService();

    foreach ($loaded['items'] as $item) {
        $presentedLine = $linePresentation->presentSaleLine($item);
        $qty = RecipeDecimal::normalize($presentedLine['qty']);
        $customizations = ['modifiers' => [], 'notes' => []];
        try {
            $customizations = $customizationService->fetchLineCustomizations($conn, (int) $order['id'], (int) $item['id']);
        } catch (Throwable $ignored) {
            $customizations = ['modifiers' => [], 'notes' => []];
        }
        $lineNote = trim((string) ($item['kitchen_note'] ?? $item['notes'] ?? ''));
        $preparationValues = $preparationSelection->fetchLineValues(
            $conn,
            (int) $order['id'],
            (int) $item['id']
        );
        if ($lineNote === '' && !empty($customizations['notes'])) {
            $lineNote = trim(implode("\n", array_map(static function ($note) {
                return (string) ($note['note_text'] ?? '');
            }, $customizations['notes'])));
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
            'detail_id' => (int) ($item['id'] ?? 0),
            'item_id' => $item['item_id'],
            'item_name' => $item['item_name'] ?: 'صنف غير معروف',
            'item_desc' => $item['item_desc'] ?: '',
            'barcode' => $item['barcode'] ?: $item['item_id'],
            'qty' => $qty,
            'price' => $price,
            'base_price' => $basePrice,
            'u_val' => RecipeDecimal::normalize($presentedLine['u_val']),
            'subtotal' => Money::from($item['det_value'] ?? '0')->toString(),
            'note' => $lineNote,
            'kitchen_note' => $lineNote,
            'modifiers' => $customizations['modifiers'],
            'preparation_values' => $preparationValues,
            'allows_sugar_spoons' => $preparationSelection->itemAllowsSugarSpoons($conn, (int) $item['item_id']),
        ];
    }
    
    echo json_encode([
        'success' => true,
        'order' => [
            'id' => $order['id'],
            'table_id' => $order['table_id'],
            'table_name' => $order['table_name'],
            'order_type' => $order['order_type'],
            'payment_status' => $order['payment_status'],
            'invoice_status' => $order['invoice_status'],
            'order_status' => $order['order_status'],
            'emp_id' => $order['emp_id'],
            'acc1' => $order['acc1'],
            'store_id' => $order['store_id'],
            'fund_id' => $order['acc_fund'] ?? 0,
            'total' => Money::from($order['fat_total'] ?? '0')->toString(),
            'discount' => Money::from($order['fat_disc'] ?? '0')->toString(),
            'net' => Money::from($order['fat_net'] ?? '0')->toString(),
            'paid' => Money::from($order['paid_amount'] ?? '0')->toString(),
            'remaining' => Money::from($order['remaining_amount'] ?? '0')->toString()
        ],
        'items' => $items
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>
