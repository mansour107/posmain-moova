<?php

require_once __DIR__ . '/../../classes/Router/ShopRouter.php';

shopRouterAssert(PosmainShopRouter::normalizeAlias(' Owner@Example.COM ') === 'owner@example.com', 'email alias should normalize to lowercase');
shopRouterAssert(PosmainShopRouter::normalizeAlias(' +20 (100) 123-4567 ') === '+201001234567', 'phone alias should strip separators');
shopRouterAssert(PosmainShopRouter::normalizeAlias(' Cashier One ') === 'cashier one', 'username alias should lowercase and collapse whitespace');

$router = new PosmainShopRouter();
$sql = implode("\n", $router->schemaStatements());
shopRouterAssert(strpos($sql, 'CREATE TABLE IF NOT EXISTS router_shops') !== false, 'router_shops schema missing');
shopRouterAssert(strpos($sql, 'CREATE TABLE IF NOT EXISTS router_login_aliases') !== false, 'router_login_aliases schema missing');
shopRouterAssert(strpos($sql, 'CREATE TABLE IF NOT EXISTS router_branch_routes') !== false, 'router_branch_routes schema missing');
shopRouterAssert(strpos($sql, 'UNIQUE KEY uq_router_login_alias (alias_normalized)') !== false, 'global alias uniqueness missing');
shopRouterAssert(strpos($sql, 'UNIQUE KEY uq_router_branch_uuid (branch_uuid)') !== false, 'branch uuid route uniqueness missing');
shopRouterAssert(strpos($sql, 'db_pass_encrypted TEXT NULL') !== false, 'router shop passwords should be encrypted');
shopRouterAssert(strpos($sql, 'app_sessions') !== false, 'router schema should include shared sessions table');
shopRouterAssert(strpos($sql, 'failed_login_attempts') !== false, 'router schema should include login throttling table');
shopRouterAssert(strpos($sql, 'security_audit_log') !== false, 'router schema should include audit table');

$dbBootstrap = file_get_contents(__DIR__ . '/../../includes/db_bootstrap.php');
shopRouterAssert(strpos($dbBootstrap, 'posmain_router_enabled') !== false, 'db bootstrap should expose router enabled helper');
shopRouterAssert(strpos($dbBootstrap, 'posmain_session_db_connect') !== false, 'db sessions should have router/default DB helper');
shopRouterAssert(strpos($dbBootstrap, 'PosmainShopRouter::activeSessionShopId') !== false, 'db bootstrap should route web sessions by shop id');
shopRouterAssert(strpos($dbBootstrap, 'posmain_db_connect_for_branch_uuid') !== false, 'sync should have branch uuid DB routing helper');

$envExample = file_get_contents(__DIR__ . '/../../.env.example');
foreach (['POSMAIN_ROUTER_ENABLED', 'POSMAIN_ROUTER_DB_HOST', 'POSMAIN_ROUTER_DB_NAME'] as $key) {
    shopRouterAssert(strpos($envExample, $key) !== false, $key . ' should be documented in env example');
}

$tool = file_get_contents(__DIR__ . '/../../tools/shop_router.php');
foreach (['--install', '--register-shop', '--add-alias', '--add-branch-route', '--validate-shop'] as $flag) {
    shopRouterAssert(strpos($tool, $flag) !== false, $flag . ' should be supported by router CLI');
}

echo "shop-router-contract-ok\n";

function shopRouterAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
