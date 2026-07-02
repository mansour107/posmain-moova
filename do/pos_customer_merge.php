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

    $sourceId = (int) ($_POST['source_id'] ?? $_POST['source_customer_id'] ?? 0);
    $targetId = (int) ($_POST['target_id'] ?? $_POST['target_customer_id'] ?? 0);
    if ($sourceId < 1 || $targetId < 1) {
        throw new InvalidArgumentException('MERGE_IDS_REQUIRED');
    }

    $service = new PosCustomerService();
    $profile = $service->mergeCustomers($conn, $sourceId, $targetId);

    pos_customer_admin_audit($conn, 'pos_customer.merged', [
        'actor_user_id' => (int) ($_SESSION['userid'] ?? 0),
        'metadata' => [
            'source_customer_id' => $sourceId,
            'target_customer_id' => $targetId,
            'target_phone' => (string) ($profile['primary_phone'] ?? ''),
        ],
    ]);

    echo json_encode([
        'success' => true,
        'customer' => $profile,
        'message' => 'تم دمج العميلين بنجاح',
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
