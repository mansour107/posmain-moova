<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Inventory/InventoryBalanceRebuildAcceptanceService.php';

$service = new InventoryBalanceRebuildAcceptanceService();

$candidateRow = [
    'pos_tenant' => 0,
    'pos_branch' => 0,
    'branch_uuid' => '',
    'store_id' => 27,
    'item_id' => 1,
    'derived_qty_on_hand' => '-26.000000',
    'current_qty_on_hand' => '-26.000000',
    'qty_difference' => '0.000000',
    'derived_moving_average_cost' => '10.038462',
    'current_moving_average_cost' => '1.000000',
    'movement_count' => 20,
    'last_movement_id' => 350,
    'current_last_movement_id' => 350,
    'current_balance_exists' => true,
    'has_difference' => false,
    'has_cost_difference' => true,
    'has_last_movement_difference' => false,
    'needs_rebuild' => true,
];
$cleanRow = array_replace($candidateRow, [
    'item_id' => 2,
    'derived_moving_average_cost' => '5.000000',
    'current_moving_average_cost' => '5.000000',
    'has_cost_difference' => false,
    'needs_rebuild' => false,
]);

$matchingAcceptance = $candidateRow + [
    'accepted_by' => 'inventory-accountant',
    'accepted_at_utc' => '2026-05-30T17:00:00Z',
    'reason' => 'Historical negative-stock valuation reviewed before cutover.',
];

$result = $service->evaluate([$candidateRow, $cleanRow], [$matchingAcceptance]);
inventoryBalanceRebuildAcceptanceAssert((int) $result['summary']['accepted_rebuild_candidate_count'] === 1, 'exact rebuild candidate should be accepted');
inventoryBalanceRebuildAcceptanceAssert((int) $result['summary']['unaccepted_rebuild_candidate_count'] === 0, 'accepted rebuild candidate should not remain unaccepted');
inventoryBalanceRebuildAcceptanceAssert(!empty($result['rows'][0]['accepted_balance_rebuild_difference']), 'accepted rebuild row should be marked');
inventoryBalanceRebuildAcceptanceAssert(($result['rows'][0]['balance_rebuild_acceptance_reason'] ?? '') !== '', 'accepted rebuild row should carry audit reason');

$staleAcceptance = $matchingAcceptance;
$staleAcceptance['derived_moving_average_cost'] = '0.000000';
$mismatch = $service->evaluate([$candidateRow], [$staleAcceptance]);
inventoryBalanceRebuildAcceptanceAssert((int) $mismatch['summary']['accepted_rebuild_candidate_count'] === 0, 'stale rebuild cost must not be accepted');
inventoryBalanceRebuildAcceptanceAssert((int) $mismatch['summary']['unaccepted_rebuild_candidate_count'] === 1, 'stale accepted rebuild candidate should remain unaccepted');
inventoryBalanceRebuildAcceptanceAssert(in_array('unused_balance_rebuild_acceptance_entries', $mismatch['blockers'], true), 'unused rebuild acceptance should block');

$invalid = $service->evaluate([$candidateRow], [[
    'pos_tenant' => 0,
    'pos_branch' => 0,
    'store_id' => 27,
    'item_id' => 1,
]]);
inventoryBalanceRebuildAcceptanceAssert($invalid['blockers'] !== [], 'invalid rebuild acceptance should produce blockers');

echo "inventory-balance-rebuild-acceptance-service-ok\n";

function inventoryBalanceRebuildAcceptanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
