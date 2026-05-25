<?php

$source = file_get_contents(__DIR__ . '/../../index.php');
if ($source === false) {
    throw new RuntimeException('Unable to read index.php');
}

loginSecurityAssert(strpos($source, "require_once __DIR__ . '/classes/Security/LoginThrottleService.php'") !== false, 'index should require LoginThrottleService');
loginSecurityAssert(strpos($source, "require_once __DIR__ . '/classes/Security/SecurityAuditLogger.php'") !== false, 'index should require SecurityAuditLogger');
loginSecurityAssert(strpos($source, 'function login_security_table_exists') !== false, 'index should check security table existence');
loginSecurityAssert(strpos($source, "'failed_login_attempts'") !== false, 'index should degrade around failed_login_attempts table');
loginSecurityAssert(strpos($source, "'security_audit_log'") !== false, 'index should degrade around security_audit_log table');
loginSecurityAssert(strpos($source, '$throttle->isBlocked') !== false, 'index should check throttling before credential query');
loginSecurityAssert(strpos($source, '$throttle->recordFailure') !== false, 'index should record failed attempts');
loginSecurityAssert(strpos($source, '$throttle->recordSuccess') !== false, 'index should clear attempts on success');
loginSecurityAssert(strpos($source, "'login_success'") !== false, 'index should audit login success');
loginSecurityAssert(strpos($source, "'login_failure'") !== false, 'index should audit login failures');
loginSecurityAssert(strpos($source, "'login_throttled'") !== false, 'index should audit throttled attempts');
loginSecurityAssert(strpos($source, 'posmain_session_regenerate();') !== false, 'index should preserve secure session regeneration');
loginSecurityAssert(strpos($source, "\$_SESSION['userid']") !== false, 'index should preserve userid session key');
loginSecurityAssert(strpos($source, "\$_SESSION['login']") !== false, 'index should preserve login session key');
loginSecurityAssert(strpos($source, "\$_SESSION['posmain_shop_id']") !== false, 'router login should store selected shop id');
loginSecurityAssert(strpos($source, 'login_user_from_router_alias') !== false, 'router login should verify users inside the routed shop DB');
loginSecurityAssert(strpos($source, 'اسم المستخدم أو البريد أو الهاتف') !== false, 'login should use unified username/email/phone label');

loginSecurityAssert(
    strpos($source, 'login_throttle_blocked($conn, $loginThrottle, $user, $clientIp)') < strpos($source, 'login_user_by_uname($shopConn, $user)'),
    'throttle check should happen before credential lookup'
);

echo "login-security-integration-ok\n";

function loginSecurityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
