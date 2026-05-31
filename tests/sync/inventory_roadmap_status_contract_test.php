<?php

$root = dirname(__DIR__, 2);
$status = inventoryRoadmapStatusSource($root . '/docs/inventory/roadmap_status.md');

foreach ([
    'Inventory Roadmap Status',
    'Current Verdict',
    'Latest Readiness Signals',
    'Phase Matrix',
    'Production Readiness Aggregate',
    'Phase Evidence Index',
    'Do Not Treat As Complete Yet',
    'Next Safe Work Without DB',
    'Work That Must Wait For DB',
] as $needle) {
    inventoryRoadmapStatusAssert(strpos($status, $needle) !== false, 'roadmap status should include section: ' . $needle);
}

foreach (range(0, 18) as $phase) {
    inventoryRoadmapStatusAssert(
        strpos($status, '| ' . $phase . ' |') !== false,
        'roadmap status should include phase row: ' . $phase
    );
}

$phaseEvidence = [
    0 => [
        'docs/inventory/phase0_completion_audit.md',
        'tests/sync/inventory_write_path_map_contract_test.php',
    ],
    1 => [
        'docs/inventory/phase1_noop_contracts.md',
        'tests/sync/inventory_phase1_noop_contract_test.php',
    ],
    2 => [
        'docs/inventory/phase2_schema_contracts.md',
        'tests/sync/inventory_phase2_schema_contract_test.php',
    ],
    3 => [
        'docs/inventory/phase3_ledger_contracts.md',
        'tests/sync/inventory_phase3_ledger_service_test.php',
    ],
    4 => [
        'docs/inventory/phase4_shadow_bridge_contracts.md',
        'tests/sync/inventory_phase4_invoice_bridge_test.php',
    ],
    5 => [
        'docs/inventory/phase5_reconciliation_contracts.md',
        'tests/sync/inventory_reconciliation_check_contract_test.php',
    ],
    6 => [
        'docs/inventory/phase6_purchase_bridge_contracts.md',
        'tests/sync/inventory_phase6_receiving_service_test.php',
        'tests/sync/inventory_phase6_purchase_order_service_test.php',
    ],
    7 => [
        'docs/inventory/phase7_count_contracts.md',
        'tests/sync/inventory_phase7_count_service_test.php',
    ],
    8 => [
        'docs/inventory/phase8_transfer_contracts.md',
        'tests/sync/inventory_phase8_transfer_service_test.php',
    ],
    9 => [
        'docs/inventory/phase9_adjustment_contracts.md',
        'tests/sync/inventory_phase9_adjustment_service_test.php',
        'tests/sync/inventory_adjustment_endpoint_runtime_test.php',
    ],
    10 => [
        'docs/inventory/phase10_production_contracts.md',
        'tests/sync/inventory_phase10_production_surface_contract_test.php',
        'tests/sync/recipe_production_endpoint_runtime_test.php',
    ],
    11 => [
        'docs/inventory/phase11_pos_availability_contracts.md',
        'tests/sync/inventory_phase11_pos_availability_contract_test.php',
    ],
    12 => [
        'docs/inventory/phase12_accounting_contracts.md',
        'tests/sync/inventory_phase12_accounting_service_test.php',
    ],
    13 => [
        'docs/inventory/phase13_reports_contracts.md',
        'tests/sync/inventory_phase13_reports_contract_test.php',
        'tests/sync/inventory_phase13_reports_service_test.php',
    ],
    14 => [
        'docs/inventory/phase14_migration_contracts.md',
        'tests/sync/inventory_phase14_migration_tools_contract_test.php',
        'tests/sync/inventory_phase14_migration_service_test.php',
    ],
    15 => [
        'docs/inventory/phase15_cutover_contracts.md',
        'tests/sync/inventory_phase15_cutover_contract_test.php',
        'tests/sync/inventory_phase15_cutover_service_test.php',
    ],
    16 => [
        'docs/inventory/phase16_legacy_retirement_contracts.md',
        'tests/sync/inventory_phase16_legacy_retirement_contract_test.php',
    ],
    17 => [
        'docs/inventory/phase17_hardening_contracts.md',
        'tests/sync/inventory_phase17_hardening_contract_test.php',
    ],
    18 => [
        'docs/inventory/phase18_stock_level_contracts.md',
        'tests/sync/inventory_phase18_stock_level_service_test.php',
    ],
];

foreach ($phaseEvidence as $phase => $paths) {
    foreach ($paths as $relativePath) {
        inventoryRoadmapStatusAssert(is_file($root . '/' . $relativePath), 'phase evidence file should exist for phase ' . $phase . ': ' . $relativePath);
        inventoryRoadmapStatusAssert(strpos($status, '`' . $relativePath . '`') !== false, 'roadmap status should name phase evidence file: ' . $relativePath);
    }
}

foreach ([
    'php tests/sync/inventory_write_path_map_contract_test.php',
    'php tools/inventory_operational_health_check.php --json',
    'php tools/inventory_cutover_readiness.php --json',
    'php tools/inventory_legacy_retirement_check.php --json',
    'php tools/inventory_production_readiness.php --json',
    'browser_operator_qa_evidence_missing',
    'inventory_operational_health_database_unreachable',
    'inventory_cutover_readiness_database_unreachable',
    'required_index_count = 9',
    'Source-proven; live DB proof must be rerun',
] as $needle) {
    inventoryRoadmapStatusAssert(strpos($status, $needle) !== false, 'roadmap status should preserve readiness signal: ' . $needle);
}

foreach ([
    'Do not run existing-DB `fat_details` trigger retirement apply',
    'Do not re-enable retired legacy stock endpoint files',
    'Do not turn off the `myitems.itmqty` compatibility mirror',
    'Do not claim production-ready Foodics-level inventory',
    'Live ledger cutover.',
    'Historical migration apply.',
    'Legacy trigger retirement apply.',
    'Production readiness claims.',
] as $needle) {
    inventoryRoadmapStatusAssert(strpos($status, $needle) !== false, 'roadmap status should keep cutover/retirement guardrail: ' . $needle);
}

foreach ([
    'docs/inventory/write_path_map.md',
    'tests/sync/inventory_write_path_map_contract_test.php',
    'docs/inventory/phase15_cutover_contracts.md',
    'docs/inventory/phase16_legacy_retirement_contracts.md',
    'docs/inventory/phase17_hardening_contracts.md',
] as $relativePath) {
    inventoryRoadmapStatusAssert(is_file($root . '/' . $relativePath), 'roadmap evidence file should exist: ' . $relativePath);
}

echo "inventory-roadmap-status-contract-ok\n";

function inventoryRoadmapStatusSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryRoadmapStatusAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
