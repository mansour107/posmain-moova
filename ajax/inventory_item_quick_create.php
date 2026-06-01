<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Inventory/InventoryQuickItemCreateService.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => inventoryQuickItemArabicError('METHOD_NOT_ALLOWED'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('inventory_receiving');
require_permission('inventory.edit', $conn);

try {
    $payload = inventoryQuickItemPayload();
    $service = new InventoryQuickItemCreateService();
    $result = $service->create($conn, $payload, [
        'user_id' => current_user_id(),
        'source_system' => 'inventory_receiving_quick_create',
    ]);

    echo json_encode(array_merge($result, [
        'message' => 'تم إنشاء الصنف وإضافته إلى الاستلام',
    ]), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => $exception->getMessage(),
        'message' => inventoryQuickItemArabicError($exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE);
}

function inventoryQuickItemPayload(): array
{
    $raw = file_get_contents('php://input');
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if ($raw !== false && $raw !== '' && strpos($contentType, 'application/json') !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return $_POST;
}

function inventoryQuickItemArabicError(string $code): string
{
    $messages = [
        'METHOD_NOT_ALLOWED' => 'طريقة الطلب غير صحيحة',
        'ITEM_NAME_REQUIRED' => 'اكتب اسم الصنف',
        'ITEM_NAME_TOO_LONG' => 'اسم الصنف طويل جداً',
        'ITEM_NAME_DUPLICATE' => 'يوجد صنف بنفس الاسم بالفعل',
        'ITEM_BARCODE_DUPLICATE' => 'يوجد صنف بنفس الباركود بالفعل',
        'ITEM_BARCODE_TOO_LONG' => 'الباركود طويل جداً',
        'ITEM_TYPE_INVALID' => 'نوع الصنف غير صحيح',
        'ITEM_UNIT_REQUIRED' => 'اختر وحدة صحيحة للصنف',
        'ITEM_COST_INVALID' => 'تكلفة الصنف غير صحيحة',
        'ITEM_SCHEMA_INCOMPATIBLE' => 'بيانات الأصناف غير جاهزة لإنشاء صنف من الاستلام',
        'ITEM_UNIT_SCHEMA_INCOMPATIBLE' => 'بيانات وحدات الأصناف غير جاهزة',
        'ITEM_CREATE_FAILED' => 'تعذر إنشاء الصنف',
    ];

    return $messages[$code] ?? 'تعذر إنشاء الصنف من شاشة الاستلام';
}
