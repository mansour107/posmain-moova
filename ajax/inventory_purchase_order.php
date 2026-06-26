<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/pos_default_accounts.php';
require_once __DIR__ . '/../classes/Inventory/InventoryPurchaseOrderService.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => 'طريقة الطلب غير صحيحة',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('inventory_receiving');
require_permission('inventory.edit', $conn);

try {
    $payload = posmain_inventory_enforce_operational_store_write($conn, inventoryPurchaseOrderPayload(), 'write');
    $action = strtolower(trim((string) ($payload['action'] ?? 'create_draft')));
    $service = new InventoryPurchaseOrderService();
    $context = [
        'user_id' => current_user_id(),
        'source_system' => 'inventory_purchase_order_ui',
    ];

    if ($action === 'create_draft') {
        $result = $service->createDraft($conn, $payload, $context);
        $message = 'تم حفظ أمر الشراء كمسودة';
    } elseif ($action === 'create_submit') {
        $result = $service->createAndSubmit($conn, $payload, $context);
        $message = 'تم إرسال أمر الشراء للموافقة';
    } elseif ($action === 'submit') {
        $result = $service->submit($conn, (int) ($payload['purchase_order_id'] ?? 0), $context);
        $message = 'تم إرسال أمر الشراء للموافقة';
    } elseif ($action === 'approve') {
        if (!auth_guard_has_permission('inventory.approve', $conn) && !auth_guard_has_permission('accounting.view', $conn)) {
            throw new RuntimeException('PURCHASE_ORDER_APPROVAL_REQUIRED');
        }
        $result = $service->approve($conn, (int) ($payload['purchase_order_id'] ?? 0), $context);
        $message = 'تم اعتماد أمر الشراء';
    } else {
        throw new InvalidArgumentException('INVALID_ACTION');
    }

    echo json_encode(array_merge($result, ['message' => $message]), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => $exception->getMessage(),
        'message' => inventoryPurchaseOrderArabicError($exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE);
}

function inventoryPurchaseOrderPayload(): array
{
    $raw = file_get_contents('php://input');
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if ($raw !== false && $raw !== '' && strpos($contentType, 'application/json') !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    $payload = $_POST;
    if (isset($payload['lines']) && is_string($payload['lines'])) {
        $decodedLines = json_decode($payload['lines'], true);
        $payload['lines'] = is_array($decodedLines) ? $decodedLines : [];
    }

    return $payload;
}

function inventoryPurchaseOrderArabicError(string $code): string
{
    $messages = [
        'DESTINATION_STORE_REQUIRED' => 'اختر المخزن',
        'PURCHASE_ORDER_LINES_REQUIRED' => 'أضف صنفاً واحداً على الأقل',
        'INVALID_PURCHASE_LINE' => 'راجع بيانات سطور الأصناف',
        'INVENTORY_ITEM_REQUIRED' => 'اختر صنفاً مسجلاً لكل سطر قبل الحفظ',
        'INVENTORY_ITEM_NOT_FOUND' => 'الصنف المختار غير مسجل في النظام',
        'INVENTORY_QTY_REQUIRED' => 'أدخل كمية صحيحة لكل صنف',
        'PURCHASE_ORDER_REQUIRED' => 'اختر أمر الشراء',
        'PURCHASE_ORDER_NOT_FOUND' => 'أمر الشراء غير موجود',
        'PURCHASE_ORDER_INVALID_TRANSITION' => 'حالة أمر الشراء لا تسمح بهذه العملية',
        'PURCHASE_ORDER_APPROVAL_REQUIRED' => 'اعتماد أمر الشراء يحتاج صلاحية اعتماد المخزون',
        'INVALID_ACTION' => 'نوع العملية غير صحيح',
    ];

    return $messages[$code] ?? 'تعذر تنفيذ عملية أمر الشراء';
}
