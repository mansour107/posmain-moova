<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Inventory/InventoryStockLevelService.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => inventoryStockLevelArabicError('METHOD_NOT_ALLOWED'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('inventory_stock_level');
require_permission('inventory.edit', $conn);

try {
    $payload = inventoryStockLevelPayload();
    $service = new InventoryStockLevelService();
    $result = $service->save($conn, $payload, [
        'user_id' => current_user_id(),
        'source_system' => 'inventory_stock_level_ui',
        'allow_policy_approval' => auth_guard_has_permission('inventory.approve', $conn) || auth_guard_has_permission('accounting.view', $conn),
    ]);

    echo json_encode(array_merge($result, ['message' => 'تم حفظ مستويات المخزون']), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => $exception->getMessage(),
        'message' => inventoryStockLevelArabicError($exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE);
}

function inventoryStockLevelPayload(): array
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

function inventoryStockLevelArabicError(string $code): string
{
    $messages = [
        'STORE_REQUIRED' => 'اختر المخزن',
        'ITEM_REQUIRED' => 'اختر الصنف',
        'NON_STOCK_ITEM_CANNOT_HAVE_LEVELS' => 'لا يمكن ضبط مستويات لصنف غير مخزني',
        'MINIMUM_LEVEL_INVALID' => 'الحد الأدنى غير صحيح',
        'REORDER_LEVEL_INVALID' => 'نقطة الطلب غير صحيحة',
        'PAR_LEVEL_INVALID' => 'المستهدف غير صحيح',
        'MAXIMUM_LEVEL_INVALID' => 'الحد الأعلى غير صحيح',
        'SAFETY_STOCK_INVALID' => 'مخزون الأمان غير صحيح',
        'ITEM_UNIT_NOT_FOUND' => 'الوحدة المفضلة غير مرتبطة بهذا الصنف',
        'SUPPLIER_NOT_FOUND' => 'المورد الافتراضي غير صحيح أو غير متاح',
        'REORDER_BELOW_MINIMUM' => 'نقطة الطلب يجب أن تكون أكبر من أو تساوي الحد الأدنى',
        'PAR_BELOW_REORDER' => 'المستهدف يجب أن يكون أكبر من أو يساوي نقطة الطلب',
        'MAXIMUM_BELOW_PAR' => 'الحد الأعلى يجب أن يكون أكبر من أو يساوي المستهدف',
        'STOCK_LEVEL_APPROVAL_REQUIRED' => 'تغيير مستوى مخزون موجود يحتاج اعتماد مدير',
        'METHOD_NOT_ALLOWED' => 'طريقة الطلب غير صحيحة',
    ];

    return $messages[$code] ?? 'تعذر حفظ مستويات المخزون';
}
