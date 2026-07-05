<?php

$authGuard = file_get_contents(__DIR__ . '/../../includes/auth_guard.php');
denialAuditContractAssert(strpos($authGuard, 'auth_guard_record_permission_denied') !== false, 'auth_guard must define record helper');
denialAuditContractAssert(strpos($authGuard, 'recordPermissionDenied') !== false, 'auth_guard must call SecurityAuditLogger::recordPermissionDenied');
denialAuditContractAssert(strpos($authGuard, "deny_json_or_redirect('PERMISSION_DENIED', 403, null, \$permission)") !== false, 'require_permission must pass permission to deny helper');

echo "permission-denial-audit-contract-ok\n";

function denialAuditContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
