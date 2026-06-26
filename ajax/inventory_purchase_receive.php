<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/pos_default_accounts.php';
require_once __DIR__ . '/../classes/Inventory/InventoryPurchaseReceivingService.php';

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
    $payload = posmain_inventory_enforce_operational_store_write($conn, inventoryPurchaseReceivePayload(), 'write');
    $action = strtolower(trim((string) ($payload['action'] ?? 'receive')));
    $service = new InventoryPurchaseReceivingService();
    $context = [
        'user_id' => current_user_id(),
        'source_system' => 'inventory_receiving_ui',
    ];

    if ($action === 'return') {
        $result = $service->returnItems($conn, $payload, $context);
        $message = 'تم تسجيل مردود المشتريات وتحديث المخزون';
    } elseif ($action === 'receive') {
        $result = $service->receive($conn, $payload, $context);
        $message = 'تم استلام المشتريات وتحديث المخزون';
    } else {
        throw new InvalidArgumentException('INVALID_ACTION');
    }

    echo json_encode(array_merge($result, [
        'message' => $message,
    ]), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => $exception->getMessage(),
        'message' => inventoryPurchaseReceiveArabicError($exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE);
}

function inventoryPurchaseReceivePayload(): array
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

function inventoryPurchaseReceiveArabicError(string $code): string
{
    $messages = [
        'INVENTORY_LEDGER_NOT_READY' => 'يجب تفعيل وضع الجسر أو التشغيل للمخزون قبل الاستلام من هذه الشاشة',
        'DESTINATION_STORE_REQUIRED' => 'اختر المخزن',
        'RECEIPT_LINES_REQUIRED' => 'أضف صنفاً واحداً على الأقل',
        'RETURN_LINES_REQUIRED' => 'أضف صنفاً واحداً على الأقل للمردود',
        'SUPPLIER_INVOICE_DUPLICATE' => 'رقم فاتورة المورد مسجل من قبل لهذا المورد',
        'INVALID_PURCHASE_LINE' => 'راجع بيانات سطور الأصناف',
        'INVENTORY_ITEM_REQUIRED' => 'اختر صنفاً مسجلاً لكل سطر قبل التسجيل',
        'INVENTORY_ITEM_NOT_FOUND' => 'الصنف المختار غير مسجل في النظام',
        'INVENTORY_QTY_REQUIRED' => 'أدخل كمية صحيحة لكل صنف',
        'NON_STOCK_ITEM_CANNOT_BE_RECEIVED' => 'لا يمكن استلام صنف غير مخزني',
        'NON_STOCK_ITEM_CANNOT_BE_RETURNED' => 'لا يمكن رد صنف غير مخزني',
        'ITEM_UNIT_NOT_FOUND' => 'الوحدة المختارة غير معرفة لهذا الصنف',
        'INVALID_UNIT_CONVERSION' => 'معامل تحويل الوحدة غير صحيح',
        'PURCHASE_ORDER_UNIT_MISMATCH' => 'وحدة الاستلام لا تطابق وحدة أمر الشراء',
        'INVALID_ACTION' => 'نوع العملية غير صحيح',
    ];

    return $messages[$code] ?? 'تعذر تنفيذ عملية الاستلام';
}
