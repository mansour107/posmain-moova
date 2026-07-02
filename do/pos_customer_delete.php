<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/pos_customer_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/PosCustomerService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';

header('Content-Type: application/json; charset=utf-8');
require_admin_or_permission('customers.manage', $conn);

if (!function_exists('pos_customer_admin_audit')) {
    function pos_customer_admin_audit(mysqli $conn, string $eventType, array $options = []): void
    {
        try {
            (new SecurityAuditLogger())->record($conn, $eventType, $options);
        } catch (Throwable $exception) {
            error_log('POS customer admin audit skipped: ' . $exception->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_csrf('customers_manage');
    posmain_ensure_pos_customer_schema($conn);

    $customerId = (int) ($_POST['customer_id'] ?? $_POST['id'] ?? 0);
    if ($customerId < 1) {
        throw new InvalidArgumentException('CUSTOMER_ID_REQUIRED');
    }

    $service = new PosCustomerService();
    $profile = $service->getProfile($conn, $customerId, false);
    $service->softDeleteCustomer($conn, $customerId);

    pos_customer_admin_audit($conn, 'pos_customer.deleted', [
        'actor_user_id' => (int) ($_SESSION['userid'] ?? 0),
        'metadata' => [
            'customer_id' => $customerId,
            'display_name' => (string) ($profile['display_name'] ?? ''),
            'primary_phone' => (string) ($profile['primary_phone'] ?? ''),
        ],
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'تم حذف العميل بنجاح',
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
