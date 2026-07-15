<?php

/**
 * Runtime proof: PIN override approvals use target_type=pos_action.
 * drawer_session requireApprovedIfNeeded rejects them; permission_key validation accepts them.
 */

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/ManagerApprovalService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_payin_override_scope_' . getmypid();
$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "shift-payin-override-scope-runtime-skipped-db-unavailable\n";
    exit(0);
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new ManagerApprovalService();
    $cashierId = 11;
    $managerId = 2;

    // Mirror ajax/pos_override_auth.php defaults for a payin PIN override.
    $request = $service->requestApproval($conn, [
        'action_type' => 'pos.drawer.payin',
        'target_type' => 'pos_action',
        'target_id' => null,
        'requested_by' => $cashierId,
        'permission_key' => 'pos.drawer.payin',
        'reason' => 'إيداع نقدي — يتطلب اعتماد مدير',
    ]);
    $service->decide($conn, (int) $request['id'], [
        'approved_by' => $managerId,
        'status' => 'approved',
    ]);

    $legacyMismatch = false;
    try {
        $service->requireApprovedIfNeeded(
            $conn,
            'pos.drawer.payin',
            'drawer_session',
            99,
            7.0,
            ['manager_approval_id' => (int) $request['id']],
            [
                'user_id' => $cashierId,
                'require_manager_approval' => true,
            ]
        );
    } catch (RuntimeException $exception) {
        $legacyMismatch = $exception->getMessage() === 'MANAGER_APPROVAL_SCOPE_MISMATCH';
    }
    payinOverrideScopeAssert($legacyMismatch, 'legacy drawer_session gate must reject pos_action PIN approvals');

    $validated = $service->validateApprovedPermissionOverride(
        $conn,
        (int) $request['id'],
        'pos.drawer.payin',
        $cashierId
    );
    payinOverrideScopeAssert((int) $validated['id'] === (int) $request['id'], 'permission_key validation should accept PIN override');

    $consumed = $service->consumeApproval($conn, (int) $request['id'], $cashierId);
    payinOverrideScopeAssert(!empty($consumed['consumed_at']), 'approval should be consumable after permission_key validation');

    echo "shift-payin-override-scope-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function payinOverrideScopeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
