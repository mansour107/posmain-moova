<?php

$sourcePath = __DIR__ . '/../../do_close_shift_z.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    throw new RuntimeException('Unable to read do_close_shift_z.php');
}

phase4ZCloseAssert(strpos($source, "require_once __DIR__ . '/classes/Pos/Service/ShiftDrawerReconciliationService.php'") !== false, 'Z close should require ShiftDrawerReconciliationService');
phase4ZCloseAssert(strpos($source, "require_once __DIR__ . '/classes/Pos/Service/DrawerSessionService.php'") !== false, 'Z close should require DrawerSessionService');
phase4ZCloseAssert(strpos($source, 'new ShiftDrawerReconciliationService()') !== false, 'Z close should instantiate reconciliation service');
phase4ZCloseAssert(strpos($source, 'buildForUser($conn, $reconciliation_scope)') !== false, 'Z close should recompute reconciliation server-side');
phase4ZCloseAssert(strpos($source, '$_POST[\'drawer_session_id\']') !== false, 'Z close should read drawer_session_id field');
phase4ZCloseAssert(strpos($source, '$sys_total_cash = floatval($drawer_reconciliation[\'payments\'][\'cash\'] ?? $sys_total_cash);') !== false, 'Z close should override hidden cash with server cash when available');
phase4ZCloseAssert(strpos($source, '$sys_total_visa = floatval($drawer_reconciliation[\'payments\'][\'non_cash\'] ?? $sys_total_visa);') !== false, 'Z close should override hidden non-cash with server non-cash when available');
phase4ZCloseAssert(strpos($source, '$expected_cash = $drawer_session_id > 0 ?') !== false, 'Z close should use drawer expected cash when drawer session exists');
phase4ZCloseAssert(strpos($source, "'server_recomputed' => \$has_server_reconciliation") !== false, 'Z close should persist server recomputation flag in json_details');
phase4ZCloseAssert(strpos($source, "'drawer_session_id' => \$drawer_session_id") !== false, 'Z close should persist drawer session id in json_details');
phase4ZCloseAssert(strpos($source, "'drawer_expected_cash' => \$expected_cash") !== false, 'Z close should persist drawer expected cash in json_details');
phase4ZCloseAssert(strpos($source, '$conn->begin_transaction();') !== false, 'Z close should wrap close insert and drawer close in one transaction');
phase4ZCloseAssert(strpos($source, 'closeSession($conn, $drawer_session_id') !== false, 'Z close should close drawer session when present');
phase4ZCloseAssert(strpos($source, '$conn->commit();') !== false, 'Z close should commit on success');
phase4ZCloseAssert(strpos($source, '$conn->rollback();') !== false, 'Z close should roll back on failure');
phase4ZCloseAssert(strpos($source, "header('Location: closed_sessions.php')") !== false, 'success redirect should remain');
phase4ZCloseAssert(strpos($source, "header('Location: z_report.php')") !== false, 'error redirect should remain');
phase4ZCloseAssert(strpos($source, "require_csrf('shift_close_z');") !== false, 'CSRF validation should remain');

echo "phase4-z-close-drawer-contract-ok\n";

function phase4ZCloseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
