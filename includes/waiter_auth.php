<?php
/**
 * Legacy waiter authentication is quarantined.
 *
 * Waiters must use the main login (local PIN or hosted password) and
 * PostLoginRouteService / POS lane permissions. Dedicated waiter_login.php
 * is intentionally not part of the production auth contract.
 */
http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Legacy waiter login is disabled. Use the main login screen.\n";
exit;
