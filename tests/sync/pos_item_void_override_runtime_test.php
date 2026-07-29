<?php

require_once __DIR__ . '/security_test_database.php';

$fixture = SecurityTestDatabase::create();
$db = $fixture->databaseName();
putenv('POSMAIN_BRANCH_UUID=79ec8b45-6fd3-4e0b-a2f2-46cab97991ea');

require_once __DIR__ . '/../../classes/Pos/Service/ManagerApprovalService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = $fixture->connect();

try {
    posItemVoidRuntimeCreateSchema($conn);

    $approvalService = new ManagerApprovalService();
    $mutationService = new PosOrderMutationService();
    $reflection = new ReflectionClass($mutationService);
    $detectMethod = $reflection->getMethod('detectPersistedLineReductions');
    $requireMethod = $reflection->getMethod('requireItemVoidApprovalIfNeeded');

    $conn->query("INSERT INTO ot_head (id, pro_tybe, order_type, payment_status, order_status, isdeleted) VALUES (501, 9, 'takeaway', 'unpaid', 'active', 0)");
    $conn->query("INSERT INTO fat_details (id, fatid, item_id, qty_out, qty_in, isdeleted, pro_tybe, fat_tybe) VALUES (9001, 501, 11, 2, 0, 0, 9, 9)");

    $noReduction = $detectMethod->invoke($mutationService, $conn, 501, [
        ['item_id' => 11, 'qty' => 2],
    ]);
    posItemVoidRuntimeAssert($noReduction === [], 'unchanged qty should not count as reduction');

    $reductions = $detectMethod->invoke($mutationService, $conn, 501, [
        ['item_id' => 11, 'qty' => 1],
    ]);
    posItemVoidRuntimeAssert(count($reductions) === 1, 'qty reduction should be detected');
    posItemVoidRuntimeAssert((int) ($reductions[0]['item_id'] ?? 0) === 11, 'reduction should include item id');

    posItemVoidRuntimeExpectException(function () use ($requireMethod, $mutationService, $conn) {
        $requireMethod->invoke($mutationService, $conn, 501, [
            ['item_id' => 11, 'qty' => 1],
        ], [], ['user_id' => 99]);
    }, 'MANAGER_APPROVAL_REQUIRED');

    $approval = $approvalService->requestApproval($conn, [
        'action_type' => 'pos.discount.manual_pct.limit',
        'target_type' => 'pos_order',
        'target_id' => 501,
        'requested_by' => 1,
    ]);
    $approvalService->decide($conn, (int) $approval['id'], ['approved_by' => 1, 'status' => 'approved']);
    posItemVoidRuntimeExpectException(function () use ($requireMethod, $mutationService, $conn, $approval) {
        $requireMethod->invoke($mutationService, $conn, 501, [
            ['item_id' => 11, 'qty' => 1],
        ], ['manager_approval_id' => (int) $approval['id']], ['user_id' => 99]);
    }, 'MANAGER_APPROVAL_SCOPE_MISMATCH');

    $validApproval = $approvalService->requestApproval($conn, [
        'action_type' => 'pos.void.item_after_send',
        'target_type' => 'pos_order',
        'target_id' => 501,
        'requested_by' => 1,
        'expires_at' => date('Y-m-d H:i:s', time() - 30),
    ]);
    $approvalService->decide($conn, (int) $validApproval['id'], ['approved_by' => 1, 'status' => 'approved']);
    posItemVoidRuntimeExpectException(function () use ($requireMethod, $mutationService, $conn, $validApproval) {
        $requireMethod->invoke($mutationService, $conn, 501, [
            ['item_id' => 11, 'qty' => 1],
        ], ['manager_approval_id' => (int) $validApproval['id']], ['user_id' => 99]);
    }, 'APPROVAL_EXPIRED');

    $freshApproval = $approvalService->requestApproval($conn, [
        'action_type' => 'pos.void.item_after_send',
        'target_type' => 'pos_order',
        'target_id' => 501,
        'requested_by' => 1,
    ]);
    $approvalService->decide($conn, (int) $freshApproval['id'], ['approved_by' => 1, 'status' => 'approved']);
    $requireMethod->invoke($mutationService, $conn, 501, [
        ['item_id' => 11, 'qty' => 1],
    ], ['manager_approval_id' => (int) $freshApproval['id']], ['user_id' => 99, 'event_source' => 'runtime_test']);
    $consumed = $approvalService->approvalById($conn, (int) $freshApproval['id'], false);
    posItemVoidRuntimeAssert(!empty($consumed['consumed_at']), 'valid approval should be consumed once');
    $voidEvent = $conn->query("
        SELECT event_type, event_source, actor_user_id, metadata_json
        FROM order_events
        WHERE order_id = 501 AND event_type = 'order.item_voided'
        ORDER BY id DESC
        LIMIT 1
    ")->fetch_assoc();
    posItemVoidRuntimeAssert(is_array($voidEvent), 'approved item void should write an audit event');
    posItemVoidRuntimeAssert((int) ($voidEvent['actor_user_id'] ?? 0) === 99, 'item void audit should identify the cashier');
    $voidMetadata = json_decode((string) ($voidEvent['metadata_json'] ?? ''), true);
    posItemVoidRuntimeAssert(
        (int) ($voidMetadata['manager_approval_id'] ?? 0) === (int) $freshApproval['id'],
        'item void audit should link the consumed manager approval'
    );

    posItemVoidRuntimeExpectException(function () use ($requireMethod, $mutationService, $conn, $freshApproval) {
        $requireMethod->invoke($mutationService, $conn, 501, [
            ['item_id' => 11, 'qty' => 1],
        ], ['manager_approval_id' => (int) $freshApproval['id']], ['user_id' => 99]);
    }, 'APPROVAL_ALREADY_CONSUMED');

    $conn->query("UPDATE ot_head SET payment_status = 'paid', order_status = 'completed' WHERE id = 501");
    posItemVoidRuntimeExpectException(function () use ($requireMethod, $mutationService, $conn) {
        $requireMethod->invoke($mutationService, $conn, 501, [
            ['item_id' => 11, 'qty' => 0],
        ], [], ['user_id' => 99]);
    }, 'PAID_ORDER_LINE_REMOVAL_DENIED');

    echo "pos-item-void-override-runtime-ok db={$db}\n";
} finally {
    $conn->close();
    $fixture->close();
}

function posItemVoidRuntimeCreateSchema(mysqli $conn): void
{
    $conn->query("
        CREATE TABLE ot_head (
            id INT NOT NULL PRIMARY KEY,
            pro_tybe INT NULL,
            order_type VARCHAR(40) NULL,
            payment_status VARCHAR(40) NULL,
            order_status VARCHAR(40) NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE fat_details (
            id INT NOT NULL PRIMARY KEY,
            fatid INT NOT NULL,
            item_id INT NOT NULL,
            qty_in DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            qty_out DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0,
            pro_tybe INT NULL,
            fat_tybe INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE sync_branch_identity (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            branch_uuid CHAR(36) NOT NULL,
            branch_name VARCHAR(255) NULL,
            pos_tenant INT NULL,
            pos_branch INT NULL,
            cloud_base_url VARCHAR(500) NULL,
            current_menu_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            UNIQUE KEY uq_sync_branch_identity_uuid (branch_uuid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        INSERT INTO sync_branch_identity (id, branch_uuid, branch_name)
        VALUES (1, '79ec8b45-6fd3-4e0b-a2f2-46cab97991ea', 'Item void fixture')
    ");
    $conn->query("
        CREATE TABLE manager_approvals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action_type VARCHAR(80) NOT NULL,
            target_type VARCHAR(80) NOT NULL,
            target_id BIGINT UNSIGNED NULL,
            requested_by BIGINT UNSIGNED NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            status ENUM('requested','approved','declined','expired') NOT NULL DEFAULT 'requested',
            reason VARCHAR(500) NULL,
            metadata_json JSON NULL,
            permission_key VARCHAR(80) NULL,
            expires_at DATETIME NULL,
            consumed_at DATETIME NULL,
            performed_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE order_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT NOT NULL,
            event_type VARCHAR(80) NOT NULL,
            event_source VARCHAR(80) NOT NULL,
            actor_user_id BIGINT NULL,
            tenant INT NOT NULL DEFAULT 0,
            branch INT NOT NULL DEFAULT 0,
            before_state_json JSON NULL,
            after_state_json JSON NULL,
            metadata_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE users (
            id INT NOT NULL PRIMARY KEY,
            uname VARCHAR(191) NOT NULL,
            password VARCHAR(255) NULL,
            userrole INT NULL,
            usertype INT NULL,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL PRIMARY KEY,
            rollname VARCHAR(191) NULL,
            edit_sales TINYINT(1) NOT NULL DEFAULT 0,
            delete_sales TINYINT(1) NOT NULL DEFAULT 0,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $conn->query("INSERT INTO usr_pwrs (id, rollname, edit_sales, delete_sales, isdeleted) VALUES (3, 'Cashier', 0, 0, 0)");
    $conn->query("INSERT INTO users (id, uname, password, userrole, usertype, isdeleted) VALUES (99, 'cashier99', '', 3, 1, 0)");
}

function posItemVoidRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function posItemVoidRuntimeExpectException(callable $fn, string $code): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        posItemVoidRuntimeAssert($e->getMessage() === $code, "expected {$code}, got {$e->getMessage()}");
        return;
    }

    throw new RuntimeException("expected exception {$code}");
}
