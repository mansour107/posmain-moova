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
    $context = [
        'user_id' => current_user_id(),
        'source_system' => 'inventory_count_ui',
    ];

    if (!empty($payload['count_id'])) {
        $result = $service->saveLines($conn, (int) $payload['count_id'], $payload['lines'] ?? [], $context);
        $message = 'تم حفظ كميات الجرد';
    } else {
        $result = $service->createDraft($conn, $payload, $context);
        $message = 'تم إنشاء جرد جديد';
    }

    echo json_encode(array_merge($result, ['message' => $message]), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    inventoryCountJsonError($exception);
}
