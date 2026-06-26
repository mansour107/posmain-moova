<?php

require_once __DIR__ . '/../includes/pos_default_accounts.php';

function inventoryCountPayload(): array
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

function inventoryCountNormalizePayload(mysqli $conn, array $payload): array
{
    return posmain_inventory_enforce_operational_store_write($conn, $payload, 'write');
}

function inventoryCountJsonError(Throwable $exception): void
{
    http_response_code(422);
    $code = $exception->getMessage();
    echo json_encode([
        'success' => false,
        'code' => $code,
        'message' => inventoryCountArabicError($code),
    ], JSON_UNESCAPED_UNICODE);
}

function inventoryCountArabicError(string $code): string
{
    $messages = [
        'INVENTORY_LEDGER_NOT_READY' => 'يجب تفعيل وضع الجسر أو التشغيل للمخزون قبل إغلاق الجرد',
        'STORE_REQUIRED' => 'اختر المخزن',
        'COUNT_REQUIRED' => 'اختر مستند الجرد',
        'COUNT_NOT_FOUND' => 'مستند الجرد غير موجود',
        'COUNT_NOT_EDITABLE' => 'لا يمكن تعديل الجرد بعد الإرسال',
        'COUNT_INVALID_TRANSITION' => 'حالة الجرد لا تسمح بهذه العملية',
        'COUNT_LINES_REQUIRED' => 'أضف أو احفظ صنفاً واحداً على الأقل',
        'COUNT_APPROVAL_REQUIRED' => 'اعتماد الجرد يحتاج صلاحية اعتماد المخزون',
        'COUNT_NOT_APPROVED' => 'يجب اعتماد الجرد قبل الإغلاق',
        'COUNT_NOT_CLOSED' => 'يمكن عكس أثر الجرد بعد الإغلاق فقط',
        'COUNT_LINE_MISSING_COUNTED_QTY' => 'كل الأصناف تحتاج كمية معدودة قبل الإغلاق',
        'COUNT_AUTOFILL_EMPTY' => 'لم يتم العثور على أصناف مطابقة لنطاق الجرد',
        'COUNT_CATEGORY_UNSUPPORTED' => 'جرد التصنيف يحتاج ربط الأصناف بالتصنيفات',
        'COUNT_LOW_STOCK_UNSUPPORTED' => 'جرد الأصناف المنخفضة يحتاج إعداد مستويات المخزون',
        'CATEGORY_REQUIRED' => 'اختر التصنيف',
        'COUNT_STALE_SNAPSHOT' => 'تغير المخزون بعد فتح الجرد، راجع الفروقات قبل الإغلاق',
        'STALE_CLOSE_APPROVAL_REQUIRED' => 'إغلاق جرد تغير مخزونه يحتاج اعتماد مدير',
        'COUNT_REVERSAL_APPROVAL_REQUIRED' => 'عكس أثر الجرد المغلق يحتاج صلاحية اعتماد أو محاسبة',
        'NON_STOCK_ITEM_CANNOT_BE_COUNTED' => 'لا يمكن جرد صنف غير مخزني',
        'ITEM_UNIT_NOT_FOUND' => 'الوحدة المختارة غير مرتبطة بهذا الصنف',
        'INVALID_UNIT_CONVERSION' => 'معامل تحويل الوحدة غير صحيح',
        'METHOD_NOT_ALLOWED' => 'طريقة الطلب غير صحيحة',
        'NON_OPERATIONAL_STORE' => 'المخزن المحدد غير نشط في هذا الفرع',
    ];

    return $messages[$code] ?? 'تعذر تنفيذ عملية الجرد';
}

function inventoryCountRequirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        return;
    }

    http_response_code(405);
    echo json_encode([
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => inventoryCountArabicError('METHOD_NOT_ALLOWED'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
