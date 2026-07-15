<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/connect.php';
require_once __DIR__ . '/../includes/auth_guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../classes/Inventory/NegativeStockSalePolicyService.php';
require_once __DIR__ . '/../classes/Security/SecurityAuditLogger.php';
require_once __DIR__ . '/../classes/Sync/SchemaReadinessGuard.php';

require_permission('inventory.policy.manage', $conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('METHOD_NOT_ALLOWED');
}
require_csrf('inventory_policy_write');
(new SyncSchemaReadinessGuard())->assertReady($conn);

$service = new NegativeStockSalePolicyService($appConfig ?? []);
$oldPolicy = $service->resolve($conn);
$newPolicy = $service->normalize($_POST['negative_stock_sale_policy'] ?? '');

$conn->begin_transaction();
try {
    $stmt = $conn->prepare('UPDATE settings SET negative_stock_sale_policy = ?');
    $stmt->bind_param('s', $newPolicy);
    $stmt->execute();
    $stmt->close();

    (new SecurityAuditLogger())->record($conn, 'inventory_policy_changed', [
        'target_type' => 'settings',
        'metadata' => [
            'negative_stock_sale_policy_old' => $oldPolicy,
            'negative_stock_sale_policy_new' => $newPolicy,
        ],
    ]);
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

$_SESSION['inventory_policy_flash'] = $newPolicy === $oldPolicy
    ? 'السياسة محفوظة بدون تغيير.'
    : 'تم تحديث سياسة البيع والمخزون وتسجيل التغيير.';
header('Location: ../inventory_policy.php');
exit;
