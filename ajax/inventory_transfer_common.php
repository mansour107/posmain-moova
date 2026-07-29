<?php

require_once __DIR__ . '/../includes/api_entry_classification.php';
require_once __DIR__ . '/../includes/pos_default_accounts.php';

function inventoryTransferPayload(): array
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

function inventoryTransferNormalizePayload(mysqli $conn, array $payload): array
{
    return posmain_inventory_enforce_operational_store_write($conn, $payload, 'transfer');
}

function inventoryTransferRequirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        return;
    }

    http_response_code(405);
    echo json_encode([
        'success' => false,
        'code' => 'METHOD_NOT_ALLOWED',
        'message' => inventoryTransferArabicError('METHOD_NOT_ALLOWED'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function inventoryTransferJsonError(Throwable $exception): void
{
    http_response_code(422);
    $code = $exception->getMessage();
    echo json_encode([
        'success' => false,
        'code' => $code,
        'message' => inventoryTransferArabicError($code),
    ], JSON_UNESCAPED_UNICODE);
}

function inventoryTransferArabicError(string $code): string
{
    $messages = [
        'INVENTORY_LEDGER_NOT_READY' => 'يجب تفعيل تتبع كمية المخزون قبل الإرسال أو الاستلام',
        'TRANSFER_REQUIRED' => 'اختر مستند التحويل',
        'TRANSFER_NOT_FOUND' => 'مستند التحويل غير موجود',
        'TRANSFER_STORES_REQUIRED' => 'اختر مخزن المصدر ومخزن الوجهة',
        'TRANSFER_STORES_MUST_DIFFER' => 'مخزن المصدر والوجهة يجب أن يكونا مختلفين',
        'TRANSFER_LINES_REQUIRED' => 'أضف صنفاً واحداً على الأقل',
        'TRANSFER_INVALID_TRANSITION' => 'حالة التحويل لا تسمح بهذه العملية',
        'TRANSFER_NOT_SENDABLE' => 'لا يمكن إرسال هذا التحويل',
        'TRANSFER_NOT_RECEIVABLE' => 'لا يمكن استلام هذا التحويل',
        'TRANSFER_RECEIVE_LINES_REQUIRED' => 'أدخل كمية الاستلام',
        'TRANSFER_LINE_NOT_FOUND' => 'سطر التحويل غير موجود',
        'TRANSFER_LINE_NOT_SENT' => 'لا يمكن استلام صنف لم يتم إرساله',
        'TRANSFER_OVER_RECEIVE' => 'كمية الاستلام أكبر من الكمية المرسلة',
        'TRANSFER_RECEIVE_REVERSAL_REQUIRED' => 'تقليل كمية مستلمة يحتاج معالجة مرتجع/تسوية منفصلة',
        'TRANSFER_VARIANCE_NOT_CLOSABLE' => 'لا يمكن إغلاق فرق هذا التحويل في حالته الحالية',
        'TRANSFER_VARIANCE_REASON_REQUIRED' => 'أدخل سبب فرق التحويل قبل الإغلاق',
        'TRANSFER_VARIANCE_NOT_FOUND' => 'لا يوجد فرق مفتوح لإغلاقه',
        'TRANSFER_NOT_CANCELLABLE' => 'لا يمكن إلغاء هذا التحويل في حالته الحالية',
        'TRANSFER_CANCEL_RECEIVED_LINES_NOT_ALLOWED' => 'لا يمكن إلغاء تحويل تم استلام جزء منه؛ استخدم إغلاق الفرق أو معالجة مرتجع',
        'TRANSFER_ORIGINAL_MOVEMENT_NOT_FOUND' => 'حركة الإرسال الأصلية غير موجودة',
        'TRANSFER_ORIGINAL_MOVEMENT_INVALID' => 'حركة الإرسال الأصلية غير صالحة للعكس',
        'REASON_CODE_NOT_FOUND' => 'سبب الفرق غير موجود أو غير مفعل',
        'REASON_CODE_GROUP_INVALID' => 'سبب الفرق لا يناسب تحويلات المخزون',
        'REASON_CODE_DIRECTION_INVALID' => 'اتجاه سبب الفرق غير مناسب',
        'REASON_CODE_APPROVAL_REQUIRED' => 'هذا السبب يحتاج اعتماد مدير',
        'NON_STOCK_ITEM_CANNOT_BE_TRANSFERRED' => 'لا يمكن تحويل صنف غير مخزني',
        'ITEM_UNIT_NOT_FOUND' => 'الوحدة المختارة غير مرتبطة بهذا الصنف',
        'INVALID_UNIT_CONVERSION' => 'معامل تحويل الوحدة غير صحيح',
        'METHOD_NOT_ALLOWED' => 'طريقة الطلب غير صحيحة',
        'INVENTORY_TRANSFERS_DISABLED' => 'تحويلات المخزون معطلة في وضع المخزن الواحد',
        'NON_OPERATIONAL_STORE' => 'المخزن المحدد غير نشط في هذا الفرع',
    ];

    return $messages[$code] ?? 'تعذر تنفيذ عملية التحويل';
}
