<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Inventory/InventoryCountService.php';
require_once __DIR__ . '/inventory_count_common.php';

header('Content-Type: application/json; charset=utf-8');
inventoryCountRequirePost();
require_csrf('inventory_count');
require_permission('inventory.edit', $conn);

try {
    $payload = inventoryCountNormalizePayload($conn, inventoryCountPayload());
    $service = new InventoryCountService();
    $result = $service->createDraft($conn, $payload, [
        'user_id' => current_user_id(),
        'source_system' => 'inventory_count_ui',
    ]);

    echo json_encode(array_merge($result, ['message' => 'تم إنشاء جرد جديد']), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    inventoryCountJsonError($exception);
}
