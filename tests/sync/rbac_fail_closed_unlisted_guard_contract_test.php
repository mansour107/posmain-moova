<?php

/**
 * Behavioral contract: unlisted route/page paths must fail closed.
 *
 * Parent integration is expected to replace soft-allow unlisted handling with
 * explicit denial. This test does not edit production guards; it asserts the
 * post-integration contract against the current guard sources.
 */

$root = realpath(__DIR__ . '/../..');
rbacFailClosedAssert($root !== false, 'unable to resolve repository root');

$routeGuardPath = $root . '/includes/rbac_route_guard.php';
$pageGuardPath = $root . '/includes/page_guard.php';
rbacFailClosedAssert(is_file($routeGuardPath), 'missing rbac_route_guard.php');
rbacFailClosedAssert(is_file($pageGuardPath), 'missing page_guard.php');

$routeGuard = (string) file_get_contents($routeGuardPath);
$pageGuard = (string) file_get_contents($pageGuardPath);

// --- Route guard: unlisted relativePath must deny, not soft-allow ---
rbacFailClosedAssert(
    preg_match(
        '/\$entry\s*=\s*\$manifest\[\$relativePath\]\s*\?\?\s*null\s*;/',
        $routeGuard
    ) === 1,
    'rbac_guard_route must resolve manifest entry by relative path'
);

$routeFailOpen = preg_match(
    '/if\s*\(\s*!is_array\(\s*\$entry\s*\)\s*\)\s*\{\s*require_login\(\)\s*;\s*return\s*;\s*\}/s',
    $routeGuard
) === 1;

rbacFailClosedAssert(
    !$routeFailOpen,
    'rbac_guard_route must not soft-allow unlisted routes via require_login()+return (fail closed required)'
);

$routeFailClosed = preg_match(
    '/if\s*\(\s*!is_array\(\s*\$entry\s*\)\s*\)\s*\{[^}]{0,400}(deny_json_or_redirect|http_response_code\s*\(\s*403|ROUTE_UNLISTED|ENDPOINT_UNLISTED|PERMISSION_DENIED)/s',
    $routeGuard
) === 1;

rbacFailClosedAssert(
    $routeFailClosed,
    'rbac_guard_route unlisted branch must deny (403 / ROUTE_UNLISTED / deny_json_or_redirect)'
);

// --- Page guard: unlisted page must deny after login, not no-op return ---
rbacFailClosedAssert(
    strpos($pageGuard, 'function page_guard_from_manifest') !== false,
    'page_guard_from_manifest must exist'
);

$pageFailOpen = preg_match(
    '/function\s+page_guard_from_manifest\s*\([^)]*\)\s*:\s*void\s*\{[^}]*require_login\(\)\s*;[^}]*if\s*\(\s*!is_array\(\s*\$entry\s*\)\s*\)\s*\{\s*return\s*;\s*\}/s',
    $pageGuard
) === 1;

rbacFailClosedAssert(
    !$pageFailOpen,
    'page_guard_from_manifest must not soft-allow unlisted pages via bare return (fail closed required)'
);

$pageFailClosed = preg_match(
    '/function\s+page_guard_from_manifest\s*\([^)]*\)\s*:\s*void\s*\{[^}]{0,800}if\s*\(\s*!is_array\(\s*\$entry\s*\)\s*\)\s*\{[^}]{0,400}(deny_json_or_redirect|http_response_code\s*\(\s*403|PAGE_UNLISTED|ROUTE_UNLISTED|PERMISSION_DENIED)/s',
    $pageGuard
) === 1;

rbacFailClosedAssert(
    $pageFailClosed,
    'page_guard_from_manifest unlisted branch must deny (403 / PAGE_UNLISTED / deny_json_or_redirect)'
);

echo "rbac-fail-closed-unlisted-guard-contract-ok\n";

function rbacFailClosedAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
