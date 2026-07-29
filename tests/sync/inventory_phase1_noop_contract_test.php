<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Inventory/InventoryFeatureFlags.php';
require_once $root . '/classes/Inventory/InventoryScopeResolver.php';
require_once $root . '/classes/Inventory/InventoryDecimal.php';
require_once $root . '/classes/Inventory/InventoryItemPolicyService.php';
require_once $root . '/classes/Inventory/InventoryLedgerService.php';
require_once $root . '/classes/Inventory/InventoryBalanceService.php';
require_once $root . '/classes/Inventory/InventoryAuditService.php';
require_once $root . '/classes/Inventory/InventoryPermissionService.php';

$defaultFlags = new InventoryFeatureFlags([]);
inventoryPhase1Assert($defaultFlags->mode() === 'off', 'inventory flags should default to off without config');
inventoryPhase1Assert(!$defaultFlags->isEnabled(), 'inventory domain should be disabled by default');
inventoryPhase1Assert(!$defaultFlags->canWriteLedger(), 'default inventory flags must not allow ledger writes');
inventoryPhase1Assert(!$defaultFlags->isQuantityTrackingEnabled(), 'default inventory flags must not enable quantity tracking');
inventoryPhase1Assert(!$defaultFlags->shouldMirrorLegacyStock(), 'legacy mirror should default off');
inventoryPhase1Assert(!$defaultFlags->isStrictStockEnabled(), 'strict stock should default off');
inventoryPhase1Assert(!$defaultFlags->isReservationEnabled(), 'reservations should default off');
inventoryPhase1Assert(!$defaultFlags->isAccountingEnabled(), 'accounting should default off');
inventoryPhase1Assert(!$defaultFlags->isAvailabilityEnabled(), 'availability should default off');
inventoryPhase1Assert(!$defaultFlags->isSyncEnabled(), 'sync should default off');
inventoryPhase1Assert(!$defaultFlags->canExposeCostsToPayload('moova_menu'), 'public cost payloads must default off');

$invalidFlags = new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'LIVE NOW']]);
inventoryPhase1Assert($invalidFlags->mode() === 'off', 'invalid inventory mode should normalize to off');

$shadowFlags = new InventoryFeatureFlags(['inventory' => [
    'ledger_mode' => 'shadow',
    'legacy_mirror' => '1',
    'strict_stock' => '1',
    'reservations' => '1',
    'accounting' => '1',
    'availability' => '1',
    'sync' => '1',
    'cost_public_payloads' => '1',
]]);
inventoryPhase1Assert($shadowFlags->mode() === 'shadow', 'shadow mode should normalize');
inventoryPhase1Assert($shadowFlags->isEnabled(), 'shadow mode should enable the inventory domain');
inventoryPhase1Assert($shadowFlags->canWriteShadowLedger(), 'shadow mode should allow future shadow ledgers');
inventoryPhase1Assert(!$shadowFlags->canWriteLedger(), 'shadow mode should not allow live ledger writes');
inventoryPhase1Assert($shadowFlags->shouldMirrorLegacyStock(), 'legacy mirror flag should be readable when enabled');
inventoryPhase1Assert($shadowFlags->isStrictStockEnabled(), 'strict stock flag should be readable when enabled');
inventoryPhase1Assert($shadowFlags->isReservationEnabled(), 'reservation flag should be readable when enabled');
inventoryPhase1Assert($shadowFlags->isAccountingEnabled(), 'accounting flag should be readable when enabled');
inventoryPhase1Assert($shadowFlags->isAvailabilityEnabled(), 'availability flag should be readable when enabled');
inventoryPhase1Assert($shadowFlags->isSyncEnabled(), 'sync flag should be readable when enabled');
inventoryPhase1Assert($shadowFlags->canExposeCostsToPayload('internal_report'), 'cost flag should only expose costs when enabled and named');
inventoryPhase1Assert(!$shadowFlags->canExposeCostsToPayload(''), 'cost exposure should require a payload class');

$bridgeFlags = new InventoryFeatureFlags(['inventory' => ['ledger_mode' => 'bridge']]);
inventoryPhase1Assert($bridgeFlags->canWriteLedger(), 'bridge mode is the first mode that may write the future ledger');
inventoryPhase1Assert($bridgeFlags->isQuantityTrackingEnabled(), 'legacy bridge mode should continue enabling quantity tracking');

$quantityOnlyFlags = new InventoryFeatureFlags(['inventory' => [
    'ledger_mode' => 'off',
    'quantity_tracking' => true,
    'accounting' => false,
]]);
inventoryPhase1Assert($quantityOnlyFlags->isEnabled(), 'explicit quantity tracking should enable the inventory domain');
inventoryPhase1Assert($quantityOnlyFlags->canWriteQuantityLedger(), 'quantity tracking should not require legacy ledger mode');
inventoryPhase1Assert($quantityOnlyFlags->canCaptureInventoryMovements(), 'quantity-only mode should enable invoice movement capture');
inventoryPhase1Assert(!$quantityOnlyFlags->isAccountingEnabled(), 'quantity tracking should not force accounting');

$invalidAccountingFlags = new InventoryFeatureFlags(['inventory' => [
    'ledger_mode' => 'off',
    'quantity_tracking' => false,
    'accounting' => true,
]]);
foreach (['canWriteQuantityLedger', 'canCaptureInventoryMovements'] as $writeBoundary) {
    $dependencyFailure = null;
    try {
        $invalidAccountingFlags->{$writeBoundary}();
    } catch (RuntimeException $exception) {
        $dependencyFailure = $exception->getMessage();
    }
    inventoryPhase1Assert(
        $dependencyFailure === 'INVENTORY_ACCOUNTING_REQUIRES_QUANTITY_TRACKING',
        $writeBoundary . ' should fail closed when accounting is enabled without quantity tracking'
    );
}

$scopeResolver = new InventoryScopeResolver([
    'branch' => [
        'pos_tenant' => 7,
        'pos_branch' => 11,
        'uuid' => 'branch-a',
    ],
]);
$scope = $scopeResolver->resolve([
    'det_store' => -5,
    'channel' => 'POS Counter',
    'order_type' => 'Table Order',
    'source_system' => 'Cashier',
]);
inventoryPhase1Assert($scope['pos_tenant'] === 7, 'scope should inherit tenant from branch config');
inventoryPhase1Assert($scope['pos_branch'] === 11, 'scope should inherit branch from branch config');
inventoryPhase1Assert($scope['branch_uuid'] === 'branch-a', 'scope should inherit branch uuid');
inventoryPhase1Assert($scope['store_id'] === 0, 'scope store_id should never be negative or nullable');
inventoryPhase1Assert($scope['channel'] === 'pos_counter', 'scope should normalize channel tokens');
inventoryPhase1Assert($scope['order_type'] === 'table_order', 'scope should normalize order type tokens');
inventoryPhase1Assert($scope['source'] === 'cashier', 'scope should normalize source tokens');

$policyService = new InventoryItemPolicyService();
$servicePolicy = $policyService->policyForItem(['id' => 51, 'item_type' => 'service', 'track_stock' => '1']);
inventoryPhase1Assert($servicePolicy['item_id'] === 51, 'item policy should resolve item id');
inventoryPhase1Assert($servicePolicy['item_type'] === 'service', 'item policy should normalize service item type');
inventoryPhase1Assert($servicePolicy['track_stock'] === false, 'service item must be non-stock even if track_stock is set');
inventoryPhase1Assert($servicePolicy['reason'] === 'service_item_non_stock', 'service item should explain non-stock reason');

$ingredientPolicy = $policyService->policyForItem(['item_id' => 52, 'item_type' => 'ingredient']);
inventoryPhase1Assert($ingredientPolicy['track_stock'] === true, 'ingredient should default to stock tracked');
inventoryPhase1Assert($ingredientPolicy['stock_behavior'] === 'direct_stock', 'ingredient should default to direct stock behavior');

inventoryPhase1Assert(InventoryDecimal::normalize('1.2345678') === '1.234568', 'inventory decimal should reuse rounded decimal normalization');
inventoryPhase1Assert(InventoryDecimal::compare('1.000000', '1') === 0, 'inventory decimal compare should be scale-safe');
if (function_exists('bcadd')) {
    inventoryPhase1Assert(InventoryDecimal::add('1.2', '2.345') === '3.545000', 'inventory decimal add should be decimal-safe');
}

$ledger = new InventoryLedgerService($defaultFlags);
$record = $ledger->recordMovement(['movement_type' => 'purchase', 'item_id' => 52]);
inventoryPhase1Assert($record['success'] === true, 'no-op ledger service should return success for safe construction');
inventoryPhase1Assert($record['noop'] === true, 'default ledger service should be no-op');
inventoryPhase1Assert($record['writes'] === [], 'default ledger service should return no writes');
inventoryPhase1Assert($record['intended_action'] === 'record_movement', 'ledger service should return intended action');

$balance = new InventoryBalanceService($defaultFlags);
$request = $balance->currentBalanceRequest($scope, 52);
inventoryPhase1Assert($request['noop'] === true && $request['writes'] === [], 'balance read contract should not write');
inventoryPhase1Assert($balance->calculateAvailable('10.000000', '2.500000', '1.000000') === '6.500000', 'available formula should use decimal math');

$audit = new InventoryAuditService($defaultFlags);
$auditResult = $audit->record(['action' => 'phase1_probe']);
inventoryPhase1Assert($auditResult['noop'] === true && $auditResult['writes'] === [], 'audit service should be no-op by default');

$permissions = new InventoryPermissionService();
inventoryPhase1Assert(!$permissions->can('inventory.adjust', []), 'inventory permission should deny by default');
inventoryPhase1Assert($permissions->can('inventory.adjust', ['permissions' => ['inventory.adjust']]), 'inventory permission should allow explicit permission');
inventoryPhase1Assert($permissions->canViewCost(['permissions' => ['inventory.*']]), 'inventory wildcard should allow cost view');
inventoryPhase1Assert($permissions->canApprove(['permissions' => ['admin']]), 'admin should allow approval');

$configSource = inventoryPhase1Source($root . '/config/app_config.php');
foreach ([
    'POSMAIN_INVENTORY_LEDGER_MODE',
    'POSMAIN_INVENTORY_QUANTITY_TRACKING',
    'POSMAIN_INVENTORY_LEGACY_MIRROR',
    'POSMAIN_INVENTORY_STRICT_STOCK',
    'POSMAIN_INVENTORY_RESERVATIONS',
    'POSMAIN_INVENTORY_ACCOUNTING',
    'POSMAIN_INVENTORY_AVAILABILITY',
    'POSMAIN_INVENTORY_SYNC',
    'POSMAIN_INVENTORY_COST_PUBLIC_PAYLOADS',
] as $flagNeedle) {
    inventoryPhase1Assert(strpos($configSource, $flagNeedle) !== false, 'app config should expose inventory flag: ' . $flagNeedle);
}
inventoryPhase1Assert(
    strpos($configSource, "'cost_public_payloads' => posmain_bool(\$branchEnv(['POSMAIN_INVENTORY_COST_PUBLIC_PAYLOADS'], '0'), false)") !== false,
    'public inventory cost payload flag should default to 0/false'
);

$runtimeReferences = inventoryPhase1RuntimeReferences($root, 'InventoryLedgerService');
$allowedLaterPhaseReferences = [
    'classes/Recipe/RecipeInventoryMovementService.php',
];
$unexpectedRuntimeReferences = array_values(array_diff($runtimeReferences, $allowedLaterPhaseReferences));
inventoryPhase1Assert(
    $unexpectedRuntimeReferences === [],
    'Phase 1 should not wire InventoryLedgerService into unexpected runtime endpoints: ' . implode(', ', $unexpectedRuntimeReferences)
);

$phase1Doc = inventoryPhase1Source($root . '/docs/inventory/phase1_noop_contracts.md');
foreach ([
    'does not change stock behavior',
    'ledger_mode` defaults to `off`',
    'service` items always return `track_stock = false`',
    'old stock behavior remains active',
] as $docNeedle) {
    inventoryPhase1Assert(strpos($phase1Doc, $docNeedle) !== false, 'Phase 1 doc should record guardrail: ' . $docNeedle);
}

echo "inventory-phase1-noop-contract-ok\n";

function inventoryPhase1Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase1Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function inventoryPhase1RuntimeReferences(string $root, string $needle): array
{
    $matches = [];
    foreach (inventoryPhase1PhpFiles($root) as $relative) {
        $source = inventoryPhase1Source($root . '/' . $relative);
        if (strpos($source, $needle) !== false) {
            $matches[] = $relative;
        }
    }

    return $matches;
}

function inventoryPhase1PhpFiles(string $root): array
{
    $excludedDirs = [
        '.git',
        'vendor',
        'node_modules',
        'tests',
        'tools',
        'docs',
        'dbase',
        'classes/Inventory',
    ];
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current) use ($root, $excludedDirs): bool {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($current->getPathname(), strlen($root) + 1));
                foreach ($excludedDirs as $excludedDir) {
                    if ($relative === $excludedDir || strpos($relative, $excludedDir . '/') === 0) {
                        return false;
                    }
                }

                return true;
            }
        )
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
    }

    sort($files);
    return $files;
}
