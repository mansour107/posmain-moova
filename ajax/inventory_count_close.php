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
    $payload = inventoryCountPayload();
    $canOverrideStaleClose = auth_guard_has_permission('inventory.approve', $conn) || auth_guard_has_permission('accounting.view', $conn);
    if (!empty($payload['allow_stale_close']) && !$canOverrideStaleClose) {
        throw new RuntimeException('STALE_CLOSE_APPROVAL_REQUIRED');
    }

    $service = new InventoryCountService();
    $result = $service->close($conn, (int) ($payload['count_id'] ?? 0), [
        'user_id' => current_user_id(),
        'source_system' => 'inventory_count_ui',
        'allow_stale_close' => !empty($payload['allow_stale_close']) && $canOverrideStaleClose,
    ]);

    echo json_encode(array_merge($result, ['message' => 'تم إغلاق الجرد وتحديث المخزون']), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    inventoryCountJsonError($exception);
}
