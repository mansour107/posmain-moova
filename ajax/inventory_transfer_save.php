<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Inventory/InventoryTransferService.php';
require_once __DIR__ . '/inventory_transfer_common.php';

header('Content-Type: application/json; charset=utf-8');
inventoryTransferRequirePost();
require_csrf('inventory_transfer');
require_permission('inventory.edit', $conn);

try {
    $payload = inventoryTransferPayload();
    $service = new InventoryTransferService();
    $result = $service->createDraft($conn, $payload, [
        'user_id' => current_user_id(),
        'source_system' => 'inventory_transfer_ui',
    ]);

    echo json_encode(array_merge($result, ['message' => 'تم حفظ تحويل المخزون']), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    inventoryTransferJsonError($exception);
}
