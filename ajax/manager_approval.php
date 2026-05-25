<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
include('../includes/connect.php');
require_once('../includes/auth_guard.php');
require_once('../includes/csrf.php');
require_once('../classes/Pos/Service/ManagerApprovalService.php');

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

require_csrf('pos_browser');

$action = strtolower(trim((string) ($_POST['action'] ?? '')));
if ($action !== 'approve_recipe_stock_override') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'code' => 'VALIDATION_FAILED',
        'message' => 'نوع الاعتماد غير صحيح',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_permission('pos.recipe_stock_override', $conn);

try {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    if ($itemId <= 0) {
        throw new InvalidArgumentException('ITEM_ID_REQUIRED');
    }

    $userId = (int) ($_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('AUTH_REQUIRED');
    }

    $reason = trim((string) ($_POST['reason'] ?? ''));
    if ($reason === '') {
        $reason = 'recipe_stock_override';
    }

    $service = new ManagerApprovalService();
    $conn->begin_transaction();
    $request = $service->requestApproval($conn, [
        'action_type' => 'recipe.stock_override',
        'target_type' => 'item',
        'target_id' => $itemId,
        'requested_by' => $userId,
        'reason' => $reason,
        'metadata' => [
            'unavailable_reason' => trim((string) ($_POST['unavailable_reason'] ?? '')),
            'source' => 'pos_grid',
        ],
    ]);
    $approved = $service->decide($conn, (int) $request['id'], [
        'approved_by' => $userId,
        'status' => 'approved',
        'reason' => $reason,
    ]);
    $conn->commit();

    echo json_encode([
        'success' => true,
        'code' => 'OK',
        'approval_id' => (int) $approved['id'],
        'message' => 'تم اعتماد البيع بواسطة المدير',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }

    echo json_encode(posmain_exception_payload(
        $exception,
        'تعذر اعتماد المدير',
        'MANAGER_APPROVAL_FAILED',
        true,
        'manager_approval'
    ), JSON_UNESCAPED_UNICODE);
}
