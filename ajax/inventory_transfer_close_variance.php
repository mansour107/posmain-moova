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
    $canApproveReason = auth_guard_has_permission('inventory.approve', $conn) || auth_guard_has_permission('accounting.view', $conn);
    $service = new InventoryTransferService();
    $result = $service->closeVariance($conn, (int) ($payload['transfer_id'] ?? 0), $payload, [
        'user_id' => current_user_id(),
        'source_system' => 'inventory_transfer_ui',
        'allow_reason_code_approval' => $canApproveReason,
    ]);

    echo json_encode(array_merge($result, ['message' => 'تم إغلاق فرق التحويل مع حفظ السبب']), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    inventoryTransferJsonError($exception);
}
