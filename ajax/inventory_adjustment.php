<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/upload_guard.php';
require_once __DIR__ . '/../classes/Inventory/InventoryAdjustmentService.php';

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

require_csrf('inventory_adjustment');
require_permission('inventory.edit', $conn);

try {
    $payload = inventoryAdjustmentPayload();
    $action = strtolower(trim((string) ($payload['action'] ?? 'waste')));
    $storedAttachment = null;
    if (inventoryAdjustmentHasWastePhotoUpload()) {
        if ($action !== 'waste') {
            throw new InvalidArgumentException('WASTE_PHOTO_WASTE_ONLY');
        }
        $storedAttachment = inventoryAdjustmentStoreWastePhoto($_FILES['waste_photo']);
        $payload['photo_attachment'] = $storedAttachment;
    } else {
        unset($payload['photo_attachment'], $payload['attachment']);
    }
    $canViewCost = auth_guard_has_permission('accounting.view', $conn) || auth_guard_has_permission('reports.view', $conn);
    if (!$canViewCost) {
        unset($payload['unit_cost'], $payload['total_cost']);
    }
    $service = new InventoryAdjustmentService();
    $context = [
        'user_id' => current_user_id(),
        'source_system' => 'inventory_adjustment_ui',
        'allow_backdate' => auth_guard_has_permission('inventory.approve', $conn) || auth_guard_has_permission('accounting.view', $conn),
        'allow_negative_result' => auth_guard_has_permission('inventory.approve', $conn) || auth_guard_has_permission('accounting.view', $conn),
        'allow_reason_code_approval' => auth_guard_has_permission('inventory.approve', $conn),
        'can_view_cost' => $canViewCost,
    ];

    if ($action === 'waste') {
        $result = $service->recordWaste($conn, $payload, $context);
        $message = 'تم تسجيل الهالك وتحديث المخزون';
    } elseif ($action === 'adjustment') {
        $result = $service->recordAdjustment($conn, $payload, $context);
        $message = 'تم تسجيل التسوية وتحديث المخزون';
    } else {
        throw new InvalidArgumentException('INVALID_ACTION');
    }

    echo json_encode(array_merge($result, ['message' => $message]), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if (!empty($storedAttachment['path'])) {
        inventoryAdjustmentDeleteStoredPhoto((string) $storedAttachment['path']);
    }
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => $exception->getMessage(),
        'message' => inventoryAdjustmentArabicError($exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE);
}

function inventoryAdjustmentPayload(): array
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

function inventoryAdjustmentHasWastePhotoUpload(): bool
{
    if (empty($_FILES['waste_photo']) || !is_array($_FILES['waste_photo'])) {
        return false;
    }

    return (int) ($_FILES['waste_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

function inventoryAdjustmentStoreWastePhoto(array $file): array
{
    $stored = posmain_store_image_upload_with_details(
        $file,
        __DIR__ . '/../uploads/inventory_waste',
        'inventory_waste',
        5 * 1024 * 1024
    );
    $serverName = (string) ($stored['server_name'] ?? '');
    $relativePath = 'uploads/inventory_waste/' . $serverName;

    return [
        'kind' => 'waste_photo',
        'path' => $relativePath,
        'file_name' => $serverName,
        'original_name' => basename((string) ($file['name'] ?? '')),
        'mime' => (string) ($stored['mime'] ?? ''),
        'size_bytes' => (int) ($stored['size'] ?? 0),
        'sha256' => (string) ($stored['sha256'] ?? ''),
        'uploaded_at' => date('c'),
        'storage' => 'local_uploads',
    ];
}

function inventoryAdjustmentDeleteStoredPhoto(string $relativePath): void
{
    if (strpos($relativePath, 'uploads/inventory_waste/') !== 0) {
        return;
    }
    $absolute = realpath(__DIR__ . '/../uploads/inventory_waste');
    $target = __DIR__ . '/../' . $relativePath;
    if ($absolute && strpos(realpath(dirname($target)) ?: '', $absolute) === 0 && is_file($target)) {
        @unlink($target);
    }
}

function inventoryAdjustmentArabicError(string $code): string
{
    $messages = [
        'INVENTORY_LEDGER_NOT_READY' => 'يجب تفعيل وضع الجسر أو التشغيل للمخزون قبل التسجيل',
        'STORE_REQUIRED' => 'اختر المخزن',
        'ITEM_REQUIRED' => 'اختر الصنف',
        'QTY_REQUIRED' => 'أدخل الكمية',
        'REASON_REQUIRED' => 'أدخل سبب العملية',
        'ADJUSTMENT_DIRECTION_REQUIRED' => 'اختر نوع التسوية',
        'NON_STOCK_ITEM_CANNOT_BE_ADJUSTED' => 'لا يمكن تعديل صنف غير مخزني',
        'BACKDATE_PERMISSION_REQUIRED' => 'تاريخ سابق يحتاج صلاحية اعتماد',
        'NEGATIVE_RESULT_APPROVAL_REQUIRED' => 'العملية ستجعل المخزون بالسالب وتحتاج اعتماد مدير',
        'UNIT_COST_INVALID' => 'تكلفة الوحدة غير صحيحة',
        'TOTAL_COST_INVALID' => 'إجمالي التكلفة غير صحيح',
        'ITEM_UNIT_NOT_FOUND' => 'الوحدة المختارة غير مرتبطة بهذا الصنف',
        'INVALID_UNIT_CONVERSION' => 'معامل تحويل الوحدة غير صحيح',
        'REASON_CODE_NOT_FOUND' => 'سبب العملية المختار غير متاح',
        'REASON_CODE_GROUP_INVALID' => 'سبب العملية لا يناسب نوع الحركة',
        'REASON_CODE_DIRECTION_INVALID' => 'سبب العملية لا يناسب اتجاه الحركة',
        'REASON_CODE_APPROVAL_REQUIRED' => 'سبب العملية المختار يحتاج اعتماد مدير',
        'WASTE_PHOTO_INVALID' => 'صورة الهالك غير صالحة',
        'WASTE_PHOTO_WASTE_ONLY' => 'إرفاق الصورة متاح لعمليات الهالك فقط',
        'OCCURRED_AT_INVALID' => 'تاريخ العملية غير صحيح',
        'INVALID_ACTION' => 'نوع العملية غير صحيح',
        'METHOD_NOT_ALLOWED' => 'طريقة الطلب غير صحيحة',
    ];

    return $messages[$code] ?? 'تعذر تنفيذ عملية التسوية';
}
