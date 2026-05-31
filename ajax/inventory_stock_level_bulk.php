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
        'message' => inventoryStockLevelBulkArabicError('METHOD_NOT_ALLOWED'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_csrf('inventory_stock_level');
require_permission('inventory.edit', $conn);

try {
    $payload = inventoryStockLevelBulkPayload();
    $action = strtolower(trim((string) ($payload['action'] ?? 'import_csv')));
    if ($action === 'import_csv' && !auth_guard_has_permission('system.tools.run', $conn)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'code' => 'TECHNICAL_IMPORT_FORBIDDEN',
            'message' => inventoryStockLevelBulkArabicError('TECHNICAL_IMPORT_FORBIDDEN'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $service = new InventoryStockLevelService();
    $context = [
        'user_id' => current_user_id(),
        'source_system' => 'inventory_stock_level_bulk_ui',
        'allow_policy_approval' => auth_guard_has_permission('inventory.approve', $conn) || auth_guard_has_permission('accounting.view', $conn),
    ];
    if ($action === 'import_csv') {
        $result = $service->importCsv($conn, (string) ($payload['csv'] ?? ''), $context);
        $message = 'تم استيراد ' . (int) ($result['imported_count'] ?? 0) . ' مستوى مخزون';
    } elseif ($action === 'category_update') {
        $result = $service->updateCategory($conn, $payload, $context);
        $message = 'تم تحديث ' . (int) ($result['updated_count'] ?? 0) . ' صنف في التصنيف';
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
        'message' => inventoryStockLevelBulkArabicError($exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE);
}

function inventoryStockLevelBulkPayload(): array
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

function inventoryStockLevelBulkArabicError(string $code): string
{
    if (preg_match('/^CSV_ROW_(\d+)_(.+)$/', $code, $matches)) {
        return 'خطأ في السطر ' . (int) $matches[1] . ': ' . inventoryStockLevelBulkArabicError($matches[2]);
    }

    $messages = [
        'CSV_EMPTY' => 'ملف الاستيراد فارغ',
        'CSV_TOO_LARGE' => 'الملف كبير جداً، الحد الأقصى 500 صف في المرة الواحدة',
        'CSV_MISSING_STORE_ID' => 'ملف الاستيراد يحتاج عمود store_id',
        'CSV_MISSING_ITEM_ID' => 'ملف الاستيراد يحتاج عمود item_id',
        'CSV_READ_FAILED' => 'تعذر قراءة ملف الاستيراد',
        'CATEGORY_REQUIRED' => 'اختر التصنيف',
        'CATEGORY_ITEMS_NOT_FOUND' => 'لا توجد أصناف مخزنية نشطة في هذا التصنيف',
        'CATEGORY_TOO_LARGE' => 'التصنيف كبير جداً، الحد الأقصى 500 صنف في المرة الواحدة',
        'CATEGORY_UPDATE_NOT_SUPPORTED' => 'تحديث التصنيف غير مدعوم في هذا التركيب',
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
        'TECHNICAL_IMPORT_FORBIDDEN' => 'استيراد CSV التقني يحتاج صلاحية مدير النظام',
        'INVALID_ACTION' => 'نوع العملية غير صحيح',
        'METHOD_NOT_ALLOWED' => 'طريقة الطلب غير صحيحة',
    ];

    return $messages[$code] ?? 'تعذر استيراد مستويات المخزون';
}
