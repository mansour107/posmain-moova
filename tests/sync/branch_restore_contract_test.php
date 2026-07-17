<?php

require_once __DIR__ . '/../../classes/Sync/RestoreEventPhase.php';
require_once __DIR__ . '/../../classes/Sync/BranchRestoreSafetyGuard.php';

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

restoreEventPhaseAssert(RestoreEventPhase::classify([
    'event_type' => 'customer.saved',
    'aggregate_type' => 'customer',
    'payload' => [
        'snapshot_type' => 'operational_row',
        'domain' => 'customer',
        'table' => 'customers',
        'row' => ['id' => 1],
    ],
]) === RestoreEventPhase::OPERATIONAL, 'operational row events should classify as operational');

restoreEventPhaseAssert(RestoreEventPhase::classify([
    'event_type' => 'shop_settings.saved',
    'aggregate_type' => 'shop_settings',
    'payload' => ['snapshot_type' => 'shop_settings', 'settings' => ['id' => 1]],
]) === RestoreEventPhase::OPERATIONAL, 'shop settings events should classify as operational');

restoreEventPhaseAssert(RestoreEventPhase::classify([
    'event_type' => 'inventory.count_snapshot',
    'aggregate_type' => 'inventory_count',
    'payload' => ['snapshot_type' => 'inventory_count_bundle'],
]) === RestoreEventPhase::OPERATIONAL, 'inventory count bundles should classify as operational');

restoreEventPhaseAssert(RestoreEventPhase::classify([
    'event_type' => 'production.batch_snapshot',
    'aggregate_type' => 'production_batch',
    'payload' => ['snapshot_type' => 'production_batch_bundle'],
]) === RestoreEventPhase::OPERATIONAL, 'production batch bundles should classify as operational');

restoreEventPhaseAssert(in_array(RestoreEventPhase::OPERATIONAL, RestoreEventPhase::all(), true), 'operational phase should be included in restore phases');

$endpoint = file_get_contents(dirname(__DIR__, 2) . '/api/sync/export_branch_restore.php');
restoreEventPhaseAssert(strpos($endpoint, 'export_branch_restore') !== false || strpos($endpoint, 'CloudBranchRestoreEventService') !== false, 'export endpoint should wire restore service');
restoreEventPhaseAssert(strpos($endpoint, 'sync_route.php') !== false, 'export endpoint should use sync route helper');

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/classes/Sync/BranchRestoreFromHostedService.php');
$guard = file_get_contents($root . '/classes/Sync/BranchRestoreSafetyGuard.php');
$pairing = file_get_contents($root . '/classes/Sync/BranchPairingService.php');
$tool = file_get_contents($root . '/tools/restore_branch_from_hosted.php');
$ajax = file_get_contents($root . '/ajax/sync_credentials.php');
$settings = file_get_contents($root . '/setting.php');
$config = file_get_contents($root . '/config/app_config.php');

restoreEventPhaseAssert(strpos($service, 'assertApplyAuthorized') !== false, 'service-level apply must pass the safety guard');
restoreEventPhaseAssert(strpos($service, 'restoreStream') !== false, 'apply must rerun a current dry-run before mutation');
restoreEventPhaseAssert(strpos($service, "'reconciliation'") !== false, 'apply must report reconciliation');
restoreEventPhaseAssert(strpos($guard, 'restore_target_business_database_is_not_empty') !== false, 'guard must block non-empty business databases');
restoreEventPhaseAssert(strpos($guard, 'restore_backup_file_too_old') !== false, 'guard must validate backup freshness');
restoreEventPhaseAssert(strpos($guard, 'restore_worker_process_is_still_active') !== false, 'guard must reject an active worker pid');
restoreEventPhaseAssert(strpos($guard, 'restore_dry_run_manifest_mismatch') !== false, 'guard must bind apply to the dry-run manifest');
restoreEventPhaseAssert(strpos($pairing, 'auto_restore_from_hosted') === false, 'pairing must not accept an automatic hosted restore path');
restoreEventPhaseAssert(strpos($pairing, "'automatic' => false") !== false, 'pairing response must state that restore is manual');
restoreEventPhaseAssert(strpos($tool, '--backup-file=') !== false, 'CLI apply must document backup evidence');
restoreEventPhaseAssert(strpos($tool, '--workers-stopped') !== false, 'CLI apply must document stopped-worker acknowledgement');
restoreEventPhaseAssert(strpos($tool, '--dry-run-manifest=') !== false, 'CLI apply must require dry-run manifest evidence');
restoreEventPhaseAssert(strpos($tool, '--receipt-file=') !== false, 'CLI apply must write an append-only receipt path');
restoreEventPhaseAssert(strpos($ajax, 'Hosted restore apply is disabled in the web UI') !== false, 'web endpoint must reject restore apply');
restoreEventPhaseAssert(strpos($settings, "payload.append('apply', '1')") === false, 'settings UI must not request restore apply');
restoreEventPhaseAssert(strpos($config, "['POSMAIN_CLOUD_PULL_ENABLED'], '0'") !== false, 'generic cloud pull must default off');
restoreEventPhaseAssert(BranchRestoreSafetyGuard::confirmationToken(
    '11111111-2222-4333-8444-555555555555',
    str_repeat('a', 64)
) === 'RESTORE_EMPTY_111111112222_AAAAAAAAAAAAAAAA', 'confirmation token must be branch and manifest scoped');

echo "branch-restore-contract-ok\n";

function restoreEventPhaseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
