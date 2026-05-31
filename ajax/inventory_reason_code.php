<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Inventory/InventoryFeatureFlags.php';
require_once __DIR__ . '/../classes/Inventory/InventoryScopeResolver.php';
require_once __DIR__ . '/../classes/Inventory/InventoryReasonCodeService.php';

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

require_csrf('inventory_reason_code');
require_permission('inventory.edit', $conn);

try {
    $payload = inventoryReasonCodePayload();
    $action = strtolower(trim((string) ($payload['action'] ?? 'save')));
    $scope = (new InventoryScopeResolver((new InventoryFeatureFlags())->appConfig()))->resolve([
        'source' => 'inventory_reason_code_admin',
    ]);
    $service = new InventoryReasonCodeService();

    if ($action === 'save') {
        $result = $service->save($conn, $scope, $payload, ['user_id' => current_user_id()]);
        $message = $result['action'] === 'created'
            ? 'تم إنشاء سبب العملية'
            : 'تم حفظ سبب العملية';
    } elseif ($action === 'retire') {
        $result = $service->setActive($conn, $scope, (int) ($payload['id'] ?? 0), false);
        $message = 'تم إيقاف سبب العملية';
    } elseif ($action === 'reactivate') {
        $result = $service->setActive($conn, $scope, (int) ($payload['id'] ?? 0), true);
        $message = 'تم إعادة تفعيل سبب العملية';
    } else {
        throw new InvalidArgumentException('INVALID_ACTION');
    }

    echo json_encode(array_merge($result, ['message' => $message]), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => $exception->getMessage(),
        'message' => inventoryReasonCodeArabicError($exception->getMessage()),
    ], JSON_UNESCAPED_UNICODE);
}

function inventoryReasonCodePayload(): array
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

function inventoryReasonCodeArabicError(string $code): string
{
    $messages = [
        'INVENTORY_REASON_CODES_NOT_READY' => 'جدول أسباب العمليات غير جاهز',
        'REASON_CODE_REQUIRED' => 'أدخل كود السبب',
        'REASON_CODE_INVALID' => 'كود السبب يجب أن يبدأ بحرف أو رقم ويحتوي حروف وأرقام وشرطات فقط',
        'REASON_NAME_REQUIRED' => 'أدخل اسم السبب',
        'REASON_GROUP_INVALID' => 'مجموعة السبب غير صحيحة',
        'REASON_DIRECTION_INVALID' => 'اتجاه السبب غير صحيح',
        'REASON_CODE_DUPLICATE' => 'كود السبب مستخدم بالفعل في هذا الفرع',
        'REASON_CODE_NOT_FOUND' => 'سبب العملية غير موجود أو خارج نطاق هذا الفرع',
        'SYSTEM_REASON_CODE_LOCKED' => 'لا يمكن تعديل سبب نظامي',
        'INVALID_ACTION' => 'الإجراء غير صحيح',
        'METHOD_NOT_ALLOWED' => 'طريقة الطلب غير صحيحة',
    ];

    return $messages[$code] ?? 'تعذر حفظ سبب العملية';
}
