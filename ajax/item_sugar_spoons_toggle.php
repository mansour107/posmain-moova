<?php

require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('ajax/item_sugar_spoons_toggle.php');

require_once __DIR__ . '/../classes/Pos/Service/PreparationSelectionService.php';
require_once __DIR__ . '/../classes/Sync/MenuItemSyncRecorder.php';
require_once __DIR__ . '/../classes/Sync/OperationalSyncRecorder.php';

header('Content-Type: application/json; charset=utf-8');

$itemId = (int) ($_POST['item_id'] ?? 0);
$enabled = (int) ($_POST['enabled'] ?? 0) === 1;
if ($itemId < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'الصنف غير صالح'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conn->prepare('SELECT id FROM myitems WHERE id = ? AND COALESCE(isdeleted, 0) = 0 LIMIT 1');
$stmt->bind_param('i', $itemId);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc() !== null;
$stmt->close();
if (!$exists) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'الصنف غير موجود'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $configId = (new PreparationSelectionService())->setItemSugarAllowed($conn, $itemId, $enabled, current_user_id());
    if ($configId > 0) {
        posmain_record_operational_row_sync($conn, 'item_preparation_config', $configId, 'item_sugar_toggle');
    }
    posmain_record_menu_item_sync($conn, $itemId, 'item_sugar_toggle');
    echo json_encode(['success' => true, 'enabled' => $enabled], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'تعذر حفظ إعداد ملاعق السكر'], JSON_UNESCAPED_UNICODE);
}
