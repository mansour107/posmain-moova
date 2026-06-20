<?php

require_once __DIR__ . '/../../classes/Sync/RestoreEventPhase.php';

restoreEventPhaseAssert(RestoreEventPhase::classify([
    'event_type' => 'menu.item_saved',
    'aggregate_type' => 'menu_item',
    'payload' => ['item_uuid' => '11111111-1111-1111-1111-111111111111'],
]) === RestoreEventPhase::MENU, 'menu events should classify as menu');

restoreEventPhaseAssert(RestoreEventPhase::classify([
    'event_type' => 'table.saved',
    'aggregate_type' => 'table',
    'payload' => ['table_uuid' => '22222222-2222-2222-2222-222222222222'],
]) === RestoreEventPhase::TABLES, 'table events should classify as tables');

restoreEventPhaseAssert(RestoreEventPhase::classify([
    'event_type' => 'order.saved',
    'aggregate_type' => 'order',
    'payload' => ['order' => ['order_uuid' => '33333333-3333-3333-3333-333333333333']],
]) === RestoreEventPhase::ORDERS, 'order events should classify as orders');

$endpoint = file_get_contents(dirname(__DIR__, 2) . '/api/sync/export_branch_restore.php');
restoreEventPhaseAssert(strpos($endpoint, 'export_branch_restore') !== false || strpos($endpoint, 'CloudBranchRestoreEventService') !== false, 'export endpoint should wire restore service');
restoreEventPhaseAssert(strpos($endpoint, 'sync_route.php') !== false, 'export endpoint should use sync route helper');

echo "branch-restore-contract-ok\n";

function restoreEventPhaseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
