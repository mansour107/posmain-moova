<?php

$root = dirname(__DIR__, 2);
$authSource = (string) file_get_contents($root . '/includes/auth_guard.php');
$connectSource = (string) file_get_contents($root . '/includes/connect.php');
$posSource = (string) file_get_contents($root . '/pos_barcode.php');
$indexSource = (string) file_get_contents($root . '/index.php');

userLifecycleSessionGuardAssert(
    strpos($authSource, 'function auth_guard_user_is_active') !== false,
    'auth_guard must expose auth_guard_user_is_active'
);
userLifecycleSessionGuardAssert(
    strpos($authSource, 'function auth_guard_enforce_active_session_user') !== false,
    'auth_guard must enforce active terminal session users'
);
userLifecycleSessionGuardAssert(
    strpos($authSource, 'function pos_enforce_active_pos_lane') !== false,
    'auth_guard must enforce active POS acting user lane'
);
userLifecycleSessionGuardAssert(
    strpos($authSource, 'pos_enforce_active_pos_lane($conn)') !== false,
    'require_pos_authenticated must validate active acting POS user'
);
userLifecycleSessionGuardAssert(
    strpos($connectSource, 'auth_guard_enforce_active_session_user($conn)') !== false,
    'connect.php must invalidate deactivated terminal sessions'
);
userLifecycleSessionGuardAssert(
    strpos($posSource, 'pos_enforce_active_pos_lane($conn)') !== false,
    'pos_barcode.php must validate active acting user after unlock'
);
userLifecycleSessionGuardAssert(
    strpos($indexSource, 'user_deactivated') !== false,
    'index.php must surface deactivated-user login message'
);

echo "user-lifecycle-session-guard-contract-ok\n";

function userLifecycleSessionGuardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
