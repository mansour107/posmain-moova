<?php

$root = dirname(__DIR__, 2);

$syncRoute = file_get_contents($root . '/includes/sync_route.php');
shopRouterEndpointAssert(strpos($syncRoute, 'posmain_sync_db_connect_for_payload') !== false, 'sync route helper missing DB connect function');
shopRouterEndpointAssert(strpos($syncRoute, 'posmain_db_connect_for_branch_uuid') !== false, 'sync route helper should route by branch uuid');
shopRouterEndpointAssert(strpos($syncRoute, 'branch_uuid_required') !== false, 'sync route helper should fail missing branch uuid safely');
shopRouterEndpointAssert(strpos($syncRoute, 'unknown_branch_route') !== false, 'sync route helper should fail unknown branch route safely');

$endpoints = [
    'api/sync/receive_branch_events.php',
    'api/sync/branch_events.php',
    'api/sync/ack_branch_events.php',
    'api/sync/export_branch_restore.php',
    'api/moova/branch_events.php',
    'api/moova/ack_branch_events.php',
];

foreach ($endpoints as $endpoint) {
    $source = file_get_contents($root . '/' . $endpoint);
    shopRouterEndpointAssert(strpos($source, "includes/sync_route.php") !== false, $endpoint . ' should include sync route helper');
    shopRouterEndpointAssert(strpos($source, 'posmain_sync_db_connect_for_payload') !== false, $endpoint . ' should connect through branch route helper');
    shopRouterEndpointAssert(strpos($source, 'posmain_sync_router_error') !== false, $endpoint . ' should return router errors safely');
}

echo "shop-router-endpoint-contract-ok\n";

function shopRouterEndpointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
