<?php

require_once __DIR__ . '/../../includes/auth_guard.php';
require_once __DIR__ . '/../../classes/Security/PermissionService.php';

function overrideApproverRoleHintRuntimeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_override_role_hint_' . getmypid();

$conn = @new mysqli($host, $user, $pass, '', $port);
if ($conn->connect_errno) {
    echo "override-approver-role-hint-runtime-skipped-db-unavailable\n";
    exit(0);
}

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    $conn->query("
        CREATE TABLE usr_pwrs (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            rollname VARCHAR(120) NULL,
            role_key VARCHAR(40) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            isdeleted TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $conn->query("
        CREATE TABLE role_capabilities (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            role_id INT NOT NULL,
            permission_key VARCHAR(80) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            limit_value DECIMAL(15,4) NULL,
            is_unlimited TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_role_perm (role_id, permission_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("INSERT INTO usr_pwrs (id, rollname, role_key, is_system, is_active) VALUES
        (1, 'مالك', 'owner', 1, 1),
        (2, 'مدير', 'manager', 1, 1),
        (3, 'كاشير', 'cashier', 1, 1)");
    $conn->query("INSERT INTO role_capabilities (role_id, permission_key, is_enabled) VALUES
        (1, 'pos.void.item_after_send', 1),
        (2, 'pos.void.item_after_send', 0),
        (3, 'pos.void.item_after_send', 0),
        (1, 'pos.drawer.payin', 1),
        (2, 'pos.drawer.payin', 1),
        (3, 'pos.drawer.payin', 0)");

    $svc = new PermissionService($conn);

    $voidRoles = $svc->approverRolesForPermission('pos.void.item_after_send');
    $voidKeys = array_values(array_filter(array_map(
        static fn(array $r): string => (string) ($r['role_key'] ?? ''),
        $voidRoles
    )));
    overrideApproverRoleHintRuntimeAssert($voidKeys === ['owner'], 'void after send should list owner only');
    overrideApproverRoleHintRuntimeAssert(($voidRoles[0]['name'] ?? '') === 'مالك', 'void hint should use Arabic owner role name');

    $payinRoles = $svc->approverRolesForPermission('pos.drawer.payin');
    $payinKeys = array_values(array_filter(array_map(
        static fn(array $r): string => (string) ($r['role_key'] ?? ''),
        $payinRoles
    )));
    overrideApproverRoleHintRuntimeAssert($payinKeys === ['owner', 'manager'], 'payin should list owner then manager');

    $index = $svc->approverRoleIndex();
    overrideApproverRoleHintRuntimeAssert(
        ($index['pos.void.item_after_send'][0]['name'] ?? '') === 'مالك',
        'index void entry should start with owner label'
    );
    overrideApproverRoleHintRuntimeAssert(
        count($index['pos.drawer.payin'] ?? []) === 2,
        'index payin should include two approver roles'
    );

    echo "override-approver-role-hint-runtime-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}
