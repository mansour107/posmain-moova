<?php

$root = dirname(__DIR__, 2);
$surfaces = [
    'classes/Sync/BranchBulkPushJobService.php',
    'classes/Sync/BranchPairingService.php',
    'classes/Sync/CloudBranchRegistryService.php',
    'classes/Sync/ItemImageSyncQueueService.php',
    'classes/Sync/SyncRuntimeSettings.php',
    'classes/Sync/BranchWorkerDaemon.php',
    'classes/Pos/Service/DrawerSessionCloseSummaryService.php',
    'classes/Pos/Service/KdsStationService.php',
    'includes/kds_bootstrap.php',
    'do/doedit_settings.php',
    'do/doedit_inventory_policy.php',
];

foreach ($surfaces as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    runtimeSchemaAssert(is_string($source), 'unable to read ' . $relative);
    runtimeSchemaAssert(strpos($source, 'SchemaReadinessGuard') !== false, $relative . ' must enforce schema readiness');
    foreach (['->apply(', 'applyKdsSchema(', 'applyPosCustomerSchema(', 'CREATE TABLE', 'ALTER TABLE', 'DROP TABLE'] as $forbidden) {
        runtimeSchemaAssert(strpos($source, $forbidden) === false, $relative . ' must not execute runtime DDL: ' . $forbidden);
    }
}

$customerBootstrap = file_get_contents($root . '/includes/pos_customer_bootstrap.php');
runtimeSchemaAssert(strpos($customerBootstrap, 'pendingPosCustomerStatements') !== false, 'customer runtime writes must use the scoped customer schema readiness check');
$ensureStart = strpos($customerBootstrap, "function posmain_ensure_pos_customer_schema");
$ensureSource = $ensureStart === false ? '' : substr($customerBootstrap, $ensureStart);
runtimeSchemaAssert(strpos($ensureSource, 'applyPosCustomerSchema') === false, 'customer request-time readiness must not execute DDL');
runtimeSchemaAssert(strpos($ensureSource, 'SCHEMA_MIGRATIONS_PENDING') === false || strpos($ensureSource, 'POS_CUSTOMER_SCHEMA_MIGRATIONS_PENDING') !== false, 'customer writes must not depend on unrelated global migrations');

$schemaManager = file_get_contents($root . '/classes/Sync/SchemaManager.php');
runtimeSchemaAssert(strpos($schemaManager, 'DROP TABLE closed_orders') === false, 'generic schema manager must never drop closed_orders');
runtimeSchemaAssert(strpos($schemaManager, 'backfillClosedOrders') === false, 'generic schema manager must never rewrite closed_orders');

$policyEndpoint = file_get_contents($root . '/do/doedit_inventory_policy.php');
foreach ([
    "require_permission('inventory.policy.manage'",
    "require_csrf('inventory_policy_write')",
    "'inventory_policy_changed'",
    'begin_transaction()',
] as $required) {
    runtimeSchemaAssert(strpos($policyEndpoint, $required) !== false, 'inventory policy endpoint must enforce: ' . $required);
}

echo "runtime-schema-readiness-contract-ok\n";

function runtimeSchemaAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
