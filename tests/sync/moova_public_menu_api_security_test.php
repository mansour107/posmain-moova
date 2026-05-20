<?php

$root = dirname(__DIR__, 2);
$items = file_get_contents($root . '/api/items.php');
$categories = file_get_contents($root . '/api/categories.php');
$auth = file_get_contents($root . '/api/moova_menu_api_auth.php');

moovaMenuApiAssertContains("require_once __DIR__ . '/moova_menu_api_auth.php'", $items, 'items API should load Moova menu API auth');
moovaMenuApiAssertContains("posmain_menu_api_require_access($", $items, 'items API should require token access when linked');
moovaMenuApiAssertContains("require_once __DIR__ . '/moova_menu_api_auth.php'", $categories, 'categories API should load Moova menu API auth');
moovaMenuApiAssertContains("posmain_menu_api_require_access($", $categories, 'categories API should require token access when linked');

moovaMenuApiAssertContains("X-Moova-Device-Token", $auth, 'menu API auth should accept Moova device token header');
moovaMenuApiAssertContains("X-Pos-Device-Token", $auth, 'menu API auth should accept POS device token header');
moovaMenuApiAssertContains("Authorization", $auth, 'menu API auth should accept bearer auth');
moovaMenuApiAssertContains("findActiveLinkByTokenAndBranch", $auth, 'menu API auth should prefer branch-scoped token links');
moovaMenuApiAssertContains("findActiveLinkByToken", $auth, 'menu API auth should support token-only links');

echo "moova-public-menu-api-security-ok\n";

function moovaMenuApiAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}
