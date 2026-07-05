<?php

/**
 * Branch-level POS shift gate contract.
 *
 * Proves that posmain_pos_shift_write_blocked() uses the drawer-session state as
 * the robust source of truth, while failing open when the shift subsystem has not
 * been adopted for a branch (so normal waiter/cashier ordering is never broken).
 */

require_once __DIR__ . '/../../classes/Sync/SchemaManager.php';
require_once __DIR__ . '/../../classes/Pos/Service/DrawerSessionService.php';
require_once __DIR__ . '/../../includes/pos_shift_guard.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('POSMAIN_TEST_MYSQL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('POSMAIN_TEST_MYSQL_PORT') ?: 3307);
$user = getenv('POSMAIN_TEST_MYSQL_USER') ?: 'root';
$pass = getenv('POSMAIN_TEST_MYSQL_PASS') ?: '';
$db = 'posmain_pos_shift_branch_gate_' . getmypid();

try {
    $conn = new mysqli($host, $user, $pass, '', $port);
} catch (Throwable $e) {
    fwrite(STDERR, "pos-shift-branch-gate-skip (mysql unavailable): {$e->getMessage()}\n");
    exit(0);
}

$openSession = ['user_logged_in' => true, 'is_waiter' => 1, 'waiter_id' => 9];

try {
    $conn->query("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $conn->select_db($db);
    (new SyncSchemaManager())->apply($conn);

    $service = new DrawerSessionService();
    $tenant = 3;
    $branch = 4;

    // No sessions on record for the branch: subsystem not adopted -> fail open.
    posShiftBranchAssert($service->branchHasSessions($conn, $tenant, $branch) === false, 'branch should start with no sessions');
    posShiftBranchAssert(
        posmain_pos_shift_write_blocked($openSession, $conn, $tenant, $branch) === false,
        'writes allowed when the branch has never opened a shift'
    );

    // Open a shift on the branch: writes must be allowed.
    $shift = $service->openSession($conn, [
        'user_id' => 7,
        'tenant' => $tenant,
        'branch' => $branch,
        'opening_cash' => '50.000',
    ]);
    posShiftBranchAssert($service->branchHasSessions($conn, $tenant, $branch) === true, 'branch should now have a session');
    posShiftBranchAssert(
        $service->findOpenSessionForBranch($conn, $tenant, $branch) !== null,
        'open branch session should be found'
    );
    posShiftBranchAssert(
        posmain_pos_shift_write_blocked($openSession, $conn, $tenant, $branch) === false,
        'writes allowed while the branch shift is open'
    );

    // Close the shift: with shift history but nothing open, writes are blocked.
    $service->closeSession($conn, $shift['id'], [
        'closed_by' => 7,
        'counted_cash' => '50.000',
    ]);
    posShiftBranchAssert(
        $service->findOpenSessionForBranch($conn, $tenant, $branch) === null,
        'closed branch shift should not be found as open'
    );
    posShiftBranchAssert(
        posmain_pos_shift_write_blocked($openSession, $conn, $tenant, $branch) === true,
        'writes blocked once the branch shift is closed'
    );

    // A different branch with no sessions still fails open.
    posShiftBranchAssert(
        posmain_pos_shift_write_blocked($openSession, $conn, 0, 0) === false,
        'unrelated branch without sessions still allows writes'
    );

    // The per-session close flag blocks regardless of branch state.
    $closedFlagSession = $openSession;
    $closedFlagSession['pos_shift_closed_for_session'] = true;
    posShiftBranchAssert(
        posmain_pos_shift_write_blocked($closedFlagSession, $conn, $tenant, $branch) === true,
        'session close flag blocks even on an open branch'
    );

    echo "pos-shift-branch-gate-ok db={$db}\n";
} finally {
    $conn->query("DROP DATABASE IF EXISTS `{$db}`");
    $conn->close();
}

function posShiftBranchAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
