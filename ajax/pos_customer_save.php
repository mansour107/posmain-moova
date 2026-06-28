<?php
include(__DIR__ . '/../includes/ajax_header.php');
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/pos_customer_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_csrf('pos_browser');
    posmain_ensure_pos_customer_schema($conn);

    $payload = $_POST;
    if (isset($_POST['payload']) && is_string($_POST['payload'])) {
        $decoded = json_decode($_POST['payload'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $service = new PosCustomerService();
    $profile = $service->saveCustomer($conn, $payload);
    echo json_encode(['success' => true, 'customer' => $profile], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => posmain_customer_save_error_message($e)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => posmain_customer_save_error_message($e)], JSON_UNESCAPED_UNICODE);
}

function posmain_customer_save_error_message(Throwable $exception): string
{
    $code = trim($exception->getMessage());
    if ($code === 'PHONE_ALREADY_USED') {
        return 'رقم الهاتف مستخدم بالفعل لعميل آخر. اطلب من المدير دمج السجلات من شاشة العملاء.';
    }

    return $code !== '' ? $code : 'تعذر حفظ بيانات العميل';
}
