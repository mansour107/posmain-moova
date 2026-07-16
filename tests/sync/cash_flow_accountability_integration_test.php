<?php

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/CashFlowPeriodService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function accountabilityIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_cash_accountability_' . getmypid();
$conn = new mysqli($host, $user, $pass, '', $port);

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);
    $conn->query('
        CREATE TABLE users (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            uname VARCHAR(100) NOT NULL,
            display_name VARCHAR(191) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $conn->query("INSERT INTO users (id, uname, display_name) VALUES
        (11, 'cashier_a', 'Cashier A'),
        (22, 'cashier_b', 'Cashier B'),
        (33, 'manager_c', 'Manager C')");

    $conn->query("INSERT INTO drawer_sessions
        (uuid, user_id, tenant, branch, opened_at, business_day, opened_by, opening_cash,
         closed_at, closed_by, expected_cash, counted_cash, difference, status)
        VALUES
        ('00000000-0000-4000-8000-000000000011', 11, 1, 1, '2026-07-16 08:00:00', '2026-07-16', 11, 100,
         '2026-07-16 12:00:00', 22, 150, 150, 0, 'forced_closed')");
    $closedSessionId = (int) $conn->insert_id;

    $stmt = $conn->prepare("INSERT INTO drawer_sessions
        (uuid, user_id, tenant, branch, opened_at, business_day, opened_by, opening_cash,
         status, preceding_session_id, takeover_authorized_by)
        VALUES
        ('00000000-0000-4000-8000-000000000022', 22, 1, 1, '2026-07-16 12:01:00', '2026-07-16', 22, 150,
         'open', ?, 33)");
    $stmt->bind_param('i', $closedSessionId);
    $stmt->execute();
    $newSessionId = (int) $conn->insert_id;
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO drawer_count_attempts
        (drawer_session_id, count_phase, attempt_number, counted_amount, expected_amount, variance, matched,
         tenant, branch, created_by, created_at)
        VALUES (?, 'close', 1, 150, 150, 0, 1, 1, 1, 22, '2026-07-16 11:59:00')");
    $stmt->bind_param('i', $closedSessionId);
    $stmt->execute();
    $stmt->close();

    $conn->query("INSERT INTO manager_approvals
        (action_type, target_type, target_id, requested_by, approved_by, status, permission_key, performed_by)
        VALUES ('pos.shift.paid_out', 'drawer_session', {$closedSessionId}, 22, 33, 'approved', 'pos.shift.paid_out', 22)");
    $approvalId = (int) $conn->insert_id;

    $stmt = $conn->prepare("INSERT INTO drawer_movements
        (drawer_session_id, tenant, branch, movement_type, amount, reason, created_by, manager_approval_id)
        VALUES (?, 1, 1, 'paid_out', 5, 'petty cash', 22, ?)");
    $stmt->bind_param('ii', $closedSessionId, $approvalId);
    $stmt->execute();
    $stmt->close();

    $cashFlow = new CashFlowPeriodService();
    $closed = $cashFlow->accountabilityForSession($conn, $closedSessionId);
    accountabilityIntegrationAssert(($closed['shift_owner_name'] ?? '') === 'Cashier A', 'closed shift owner');
    accountabilityIntegrationAssert(($closed['counted_by_name'] ?? '') === 'Cashier B', 'incoming cashier counted');
    accountabilityIntegrationAssert(($closed['closed_by_name'] ?? '') === 'Cashier B', 'incoming cashier closed');
    accountabilityIntegrationAssert(($closed['takeover_authorized_by_name'] ?? '') === 'Manager C', 'manager authorized takeover');
    accountabilityIntegrationAssert((int) ($closed['succeeding_session_id'] ?? 0) === $newSessionId, 'successor session projected');
    accountabilityIntegrationAssert(($closed['succeeding_shift_owner_name'] ?? '') === 'Cashier B', 'successor owner projected');

    $opened = $cashFlow->accountabilityForSession($conn, $newSessionId);
    accountabilityIntegrationAssert((int) ($opened['preceding_session_id'] ?? 0) === $closedSessionId, 'predecessor projected');
    accountabilityIntegrationAssert(($opened['preceding_shift_owner_name'] ?? '') === 'Cashier A', 'predecessor owner projected');

    $movements = $cashFlow->movements($conn, ['drawer_session_id' => $closedSessionId]);
    $movement = $movements['rows'][0] ?? [];
    accountabilityIntegrationAssert((int) ($movement['manager_approval_id'] ?? 0) === $approvalId, 'approval id projected');
    accountabilityIntegrationAssert(($movement['manager_approved_by_name'] ?? '') === 'Manager C', 'approver projected');
    accountabilityIntegrationAssert(($movement['manager_approval_permission'] ?? '') === 'pos.shift.paid_out', 'approval permission projected');

    echo "cash-flow-accountability-integration-ok\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}
