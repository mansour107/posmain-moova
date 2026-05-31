<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Inventory/InventoryOperationalHardeningService.php';

$service = new InventoryOperationalHardeningService();
$pagination = $service->pagination(['limit' => 9999, 'offset' => -10], 100, 250);
inventoryPhase17Assert($pagination['limit'] === 250, 'pagination should cap oversized limits');
inventoryPhase17Assert($pagination['offset'] === 0, 'pagination should normalize negative offsets');
inventoryPhase17Assert($service->operatorMessage('stock_unavailable', [
    'item_name' => 'Milk',
    'store_name' => 'Kitchen Store',
    'qty' => '0',
]) === 'Milk stock is 0 in Kitchen Store.', 'operator stock message should be specific');
inventoryPhase17Assert(in_array('idx_inventory_item_time', $service->requiredIndexes()['inventory_movements'], true), 'required indexes should include movement item-time lookup');

$healthTool = inventoryPhase17Source($root . '/tools/inventory_operational_health_check.php');
$stockRead = inventoryPhase17Source($root . '/classes/Inventory/InventoryStockReadService.php');
$adjustmentPage = inventoryPhase17Source($root . '/inventory_adjustments.php');
$adjustmentEndpoint = inventoryPhase17Source($root . '/ajax/inventory_adjustment.php');
$itemSummaryPage = inventoryPhase17Source($root . '/item_summery.php');
$docs = inventoryPhase17Source($root . '/docs/inventory/phase17_hardening_contracts.md');

foreach ([
    'inventoryOperationalHealthIndexExists',
    'inventoryOperationalHealthEndpointSecurity',
    'requiredIndexes',
    'adjustment_cost_payload_guard',
    'adjustment_endpoint_cost_guard',
    'inventory_endpoint_security_missing_controls',
    'endpoint_security_issue_count',
    'skipped_endpoint_helper_count',
    'skipped_endpoint_helpers',
    'missing_required_inventory_indexes',
    'index_check_status',
    'database_unreachable',
    'inventory_operational_health_database_unreachable',
] as $needle) {
    inventoryPhase17Assert(strpos($healthTool, $needle) !== false, 'health check should contain: ' . $needle);
}
inventoryPhase17Assert(strpos($stockRead, 'LIMIT {$limit} OFFSET {$offset}') !== false, 'movement history should remain paginated');
inventoryPhase17Assert(strpos($adjustmentPage, 'const inventoryAdjustmentCanViewCost') !== false, 'adjustment UI should expose only a permission boolean for cost behavior');
inventoryPhase17Assert(strpos($adjustmentPage, '<?php if ($inventoryAdjustmentCanViewCost): ?> data-cost=') !== false, 'adjustment UI should not embed item cost unless cost is allowed');
inventoryPhase17Assert(strpos($adjustmentPage, 'if (inventoryAdjustmentCanViewCost) {' . "\n" . '        payload.unit_cost') !== false, 'adjustment UI should omit unit_cost from hidden-cost submissions');
inventoryPhase17Assert(strpos($adjustmentEndpoint, 'unset($payload[\'unit_cost\'], $payload[\'total_cost\'])') !== false, 'adjustment endpoint should ignore crafted cost fields when cost is hidden');
inventoryPhase17Assert(strpos($itemSummaryPage, 'inventory_phase17_item_summary_can_view_cost') !== false, 'item movement page should gate cost columns');
inventoryPhase17Assert(strpos($itemSummaryPage, 'inventory_phase17_item_summary_date') !== false, 'item movement page should sanitize date filters before legacy SQL');
inventoryPhase17Assert(strpos($itemSummaryPage, '$page = max(1') !== false, 'item movement page should normalize negative page offsets');
inventoryPhase17Assert(strpos($itemSummaryPage, 'مصدر الرصيد: دفتر المخزون') !== false, 'item movement page should show an operator-friendly stock source badge');
inventoryPhase17Assert(strpos($itemSummaryPage, '<th>id</th>') === false, 'item movement page should not expose raw technical id column in normal table');
inventoryPhase17Assert(
    !preg_match('/\b(INSERT\s+INTO|UPDATE\s+[^A-Za-z_]|DELETE\s+FROM|DROP\s+TABLE|DROP\s+DATABASE|TRUNCATE)\b/i', $healthTool),
    'operational health check must remain read-only'
);

foreach ([
    'without changing inventory write behavior',
    '`php tools/inventory_operational_health_check.php --json`',
    'Milk stock is 0 in Kitchen Store.',
    'planned required-index count',
    '`summary.index_check_status`',
    'cost values must not be embedded',
    'item movement history hides raw technical IDs',
    'deterministic idempotency keys',
] as $needle) {
    inventoryPhase17Assert(strpos($docs, $needle) !== false, 'phase 17 docs should explain: ' . $needle);
}

echo "inventory-phase17-hardening-contract-ok\n";

function inventoryPhase17Source(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function inventoryPhase17Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
