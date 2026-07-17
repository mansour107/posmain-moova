<?php

$root = dirname(__DIR__, 2);
$harness = file_get_contents($root . '/tools/e2e_bidirectional_operational_sync.php');
$runbook = file_get_contents($root . '/docs/bidirectional_operational_sync_certification.md');

e2eBsyncContractAssert($harness !== false, 'certification harness must be readable');
e2eBsyncContractAssert($runbook !== false, 'certification runbook must be readable');

foreach ([
    'posmain_e2e_bsync_branch_',
    'posmain_e2e_bsync_cloud_',
    'posmain_e2e_bsync_recovery_',
    'databases_disposable',
    'e2eBsyncDropDatabases',
    '--keep-databases',
] as $needle) {
    e2eBsyncContractAssert(strpos($harness, $needle) !== false, 'missing disposable topology/cleanup contract: ' . $needle);
}

e2eBsyncContractAssert(strpos($harness, 'BranchCloudSyncPollWorker') === false, 'certification must not wire automatic cloud pull');
e2eBsyncContractAssert(strpos($harness, "'cloud_pull_enabled' => false") !== false, 'branch config must disable automatic cloud pull');
e2eBsyncContractAssert(strpos($harness, "'POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED' => '0'") !== false, 'fake hosted service must not publish reverse events');
e2eBsyncContractAssert(strpos($harness, "'automatic_cloud_pull_enabled' => false") !== false, 'machine report must state asymmetric direction policy');

foreach ([
    'manual_push_local_to_hosted',
    'worker_push_new_local_change',
    'hosted_outage_retry_and_recovery',
    'duplicate_stale_and_same_version_safety',
    'manual_restore_active_branch_is_blocked',
    'guarded_manual_restore_to_empty_recovery',
] as $scenario) {
    e2eBsyncContractAssert(strpos($harness, "e2eBsyncScenario('{$scenario}'") !== false, 'missing certification scenario: ' . $scenario);
}

foreach ([
    "'scope' => 'empty'",
    "'workers_stopped' => true",
    "'expected_events'",
    "'dry_run_manifest'",
    "'confirmation_token'",
    "'backup_file'",
    'e2eBsyncReconcileSeed',
    'e2eBsyncRestoreExclusions',
    'e2eBsyncOutboxHealth',
    "'expired_locks'",
] as $needle) {
    e2eBsyncContractAssert(strpos($harness, $needle) !== false, 'missing fail-closed restore/reconciliation check: ' . $needle);
}

foreach ([
    'branch_restore_financial_bundle_test.php',
    'branch_restore_customer_bundle_test.php',
    'branch_restore_order_fulfillment_test.php',
    'branch_restore_inventory_accounting_test.php',
    'branch_restore_inventory_count_test.php',
    'branch_restore_production_batch_test.php',
    'branch_restore_purchase_receipt_test.php',
    'branch_restore_purchase_order_test.php',
    'cloud_shift_snapshot_test.php',
    'cross_branch_transfer_document_requires_source_destination_handoff_policy',
    'manual_legacy_journal_writers_require_separate_non_duplicate_ownership_audit',
    'sanitized_user_role_grant_recovery_requires_secret_free_contract',
] as $gate) {
    e2eBsyncContractAssert(strpos($harness, $gate) !== false, 'missing coverage gate or explicit blocker: ' . $gate);
}

e2eBsyncContractAssert(strpos($harness, "'production_ready' => false") !== false, 'disposable proof must not claim live production readiness');
e2eBsyncContractAssert(strpos($runbook, 'Automatic branch to hosted') !== false, 'runbook must state the automatic direction');
e2eBsyncContractAssert(strpos($runbook, 'Manual guarded hosted to branch') !== false, 'runbook must state the manual recovery direction');
e2eBsyncContractAssert(strpos($runbook, 'does not certify live production') !== false, 'runbook must distinguish disposable and live proof');

echo "e2e-bidirectional-operational-sync-contract-ok\n";

function e2eBsyncContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
