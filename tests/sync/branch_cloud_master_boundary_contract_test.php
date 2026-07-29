<?php

$root = dirname(__DIR__, 2);
$worker = file_get_contents($root . '/classes/Sync/BranchCloudSyncPollWorker.php');
$master = file_get_contents($root . '/classes/Sync/BranchCloudMasterApplyService.php');
$inbox = file_get_contents($root . '/classes/Sync/SyncInboxService.php');
$cloudRecipe = file_get_contents($root . '/classes/Sync/CloudRecipeMasterProjectionService.php');
$restore = file_get_contents($root . '/classes/Sync/BranchRestoreFromHostedService.php');

function masterBoundaryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "master-boundary-contract-failed: {$message}\n");
        exit(1);
    }
}

masterBoundaryAssert(
    strpos($worker, 'BranchCloudMasterApplyService') !== false
        && strpos($worker, 'BranchRestoreEventApplyService') === false,
    'live cloud poller must use the narrow master-data applier, never the broad restore applier'
);
masterBoundaryAssert(
    strpos($master, 'CLOUD_OPERATIONAL_OR_UNKNOWN_EVENT_DENIED') !== false,
    'unknown and operational cloud events must be explicitly denied'
);
masterBoundaryAssert(
    strpos($master, "'pos_menu_item'") !== false
        && strpos($master, "'recipe_bundle'") !== false,
    'only the catalog and recipe-master snapshot contracts may be projected'
);
masterBoundaryAssert(
    strpos($master, "'menu.edit'") !== false
        && strpos($master, "'recipe.manage'") !== false,
    'incoming master changes must preserve administrator capability evidence'
);
masterBoundaryAssert(
    strpos($worker, 'MAX_CLOCK_DRIFT_SECONDS = 60') !== false
        && strpos($worker, 'server_time_utc') !== false
        && strpos($worker, 'cloud server clock drift exceeds 60 seconds') !== false,
    'authenticated cloud polling must fail closed when the responding server clock drifts beyond 60 seconds'
);
masterBoundaryAssert(
    strpos($master, 'MASTER_EVENT_CLOCK_DRIFT') === false
        && strpos($master, 'MASTER_EVENT_TRUSTED_CLOCK_REQUIRED') !== false,
    'valid delayed offline master events must retain their trusted origin time instead of being misclassified as clock drift'
);
masterBoundaryAssert(
    strpos($restore, 'BranchRestoreEventApplyService') !== false,
    'broad operational apply must remain isolated in the explicit restore workflow'
);
masterBoundaryAssert(
    strpos($inbox, 'CloudRecipeMasterProjectionService') !== false
        && strpos($cloudRecipe, "hash_equals('branch:'") !== false
        && strpos($cloudRecipe, "'recipe.manage'") !== false,
    'branch-to-cloud recipe projection must require the authenticated branch node and recipe administrator evidence'
);
masterBoundaryAssert(
    strpos($cloudRecipe, 'MASTER_RECIPE_CREATION_REQUIRES_COMPLETE_SNAPSHOT') !== false
        && strpos($cloudRecipe, 'validateIncomingFields') !== false,
    'recipe creation and dependency validation must fail before a partial cloud projection'
);
masterBoundaryAssert(
    strpos($worker, "'denied' => 0") !== false
        && strpos($worker, "'ack_declined'") !== false,
    'declined cloud-originated writes must be visible and acknowledged as declined'
);

echo "branch-cloud-master-boundary-contract-ok\n";
