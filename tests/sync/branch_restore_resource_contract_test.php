<?php

require_once __DIR__ . '/../../classes/Sync/CloudBranchRestoreEventService.php';

function branchRestoreResourceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$branchUuid = '11111111-2222-4333-8444-555555555555';
$v1 = CloudBranchRestoreEventService::exportSignatureBody(
    $branchUuid,
    RestoreEventPhase::MENU,
    10,
    50,
    'auto'
);
branchRestoreResourceAssert(
    $v1 === '{"branch_uuid":"11111111-2222-4333-8444-555555555555","phase":"menu","after_id":10,"limit":50,"source":"auto"}',
    'v1 signed request body must remain byte-for-byte compatible'
);

$v2Request = CloudBranchRestoreEventService::normalizeExportRequest([
    'contract_version' => '2',
    'source' => 'cloud_snapshot',
    'recovery_profile' => 'operational_v1',
    'snapshot_checkpoint' => '1234',
    'history_since_utc' => '2026-06-17T00:00:00Z',
]);
branchRestoreResourceAssert($v2Request['limit'] === 25, 'recovery v2 must default to 25-row pages');
branchRestoreResourceAssert($v2Request['snapshot_checkpoint'] === 1234, 'recovery v2 must preserve the pinned checkpoint');

$v2 = CloudBranchRestoreEventService::exportSignatureBody(
    $branchUuid,
    RestoreEventPhase::MENU,
    0,
    $v2Request['limit'],
    $v2Request['source'],
    $v2Request
);
$v2Decoded = json_decode($v2, true);
branchRestoreResourceAssert((int) ($v2Decoded['contract_version'] ?? 0) === 2, 'v2 signature must bind contract version');
branchRestoreResourceAssert(($v2Decoded['recovery_profile'] ?? '') === 'operational_v1', 'v2 signature must bind recovery profile');
branchRestoreResourceAssert((int) ($v2Decoded['snapshot_checkpoint'] ?? 0) === 1234, 'v2 signature must bind checkpoint');
branchRestoreResourceAssert(($v2Decoded['history_since_utc'] ?? '') === '2026-06-17T00:00:00Z', 'v2 signature must bind one immutable history cutoff');

$captureRequest = CloudBranchRestoreEventService::normalizeExportRequest([
    'contract_version' => '2',
]);
branchRestoreResourceAssert($captureRequest['source'] === 'cloud_snapshot', 'v2 must never auto-select historical inbox replay');
branchRestoreResourceAssert($captureRequest['snapshot_checkpoint'] === null, 'an omitted checkpoint must request one hosted capture');

foreach ([
    ['contract_version' => '3'],
    ['contract_version' => '2', 'source' => 'auto'],
    ['contract_version' => '2', 'recovery_profile' => 'full_history'],
    ['contract_version' => '2', 'snapshot_checkpoint' => '-1'],
    ['contract_version' => '2', 'after_id' => '-1'],
    ['contract_version' => '2', 'limit' => '101'],
    ['contract_version' => '2', 'history_since_utc' => '2026-02-30T00:00:00Z'],
    ['contract_version' => '2', 'history_since_utc' => '2026-06-17 00:00:00'],
] as $invalid) {
    try {
        CloudBranchRestoreEventService::normalizeExportRequest($invalid);
        throw new RuntimeException('invalid recovery-v2 request was accepted');
    } catch (InvalidArgumentException $expected) {
        // Expected fail-closed validation.
    }
}

$root = dirname(__DIR__, 2);
$client = file_get_contents($root . '/classes/Sync/BranchRestoreFromHostedService.php');
$tool = file_get_contents($root . '/tools/restore_branch_from_hosted.php');
$exporter = file_get_contents($root . '/classes/Sync/CloudBranchRestoreExportService.php');
branchRestoreResourceAssert(strpos($client, 'normalizeMaxResponseBytes') !== false, 'client must cap response bodies');
branchRestoreResourceAssert(strpos($client, "'page_pause_ms'] ?? 50") !== false, 'client must pace multi-page recovery by default');
branchRestoreResourceAssert(strpos($tool, "'source' => 'cloud_snapshot'") !== false, 'operator tool must select the snapshot explicitly');
branchRestoreResourceAssert(strpos($tool, "'resume_run_uuid' =>") !== false, 'operator tool must pass an explicit exact-run resume identifier');
branchRestoreResourceAssert(strpos($tool, '--resume-run=UUID') !== false, 'operator help must document exact-run resume');
branchRestoreResourceAssert(strpos($tool, 'unchanged backup hash/checkpoint/profile/window') !== false, 'operator help must state resume binding invariants');
branchRestoreResourceAssert(strpos($tool, "min(100, (int) \$options['limit'])") !== false, 'operator page size must be capped at 100');
branchRestoreResourceAssert(strpos($exporter, 'COALESCE(closed, 0) = 0') !== false, 'operational recovery must retain every open order');
branchRestoreResourceAssert(strpos($exporter, 'GREATEST(') !== false, 'operational recovery must include recently changed closed orders');

echo "branch-restore-resource-contract-ok\n";
