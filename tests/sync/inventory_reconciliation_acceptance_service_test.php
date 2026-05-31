<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Inventory/InventoryReconciliationAcceptanceService.php';

$service = new InventoryReconciliationAcceptanceService();

$differenceRow = [
    'pos_tenant' => 0,
    'pos_branch' => 0,
    'store_id' => 27,
    'item_id' => 1001,
    'legacy_qty' => '10.000000',
    'fat_details_qty' => '10.000000',
    'ledger_qty' => '8.000000',
    'balance_qty' => '8.000000',
    'has_difference' => true,
    'difference_reasons' => ['movement_scope_or_quantity_mismatch'],
];
$cleanRow = array_replace($differenceRow, ['item_id' => 1002]);
$cleanRow['has_difference'] = false;
$cleanRow['difference_reasons'] = [];

$matchingAcceptance = $differenceRow + [
    'accepted_by' => 'inventory-manager',
    'accepted_at_utc' => '2026-05-30T09:30:00Z',
    'reason' => 'QA fixture recipe consumption intentionally lives only in the ledger.',
];

$result = $service->evaluate([$differenceRow, $cleanRow], [$matchingAcceptance]);
inventoryReconciliationAcceptanceAssert((int) $result['summary']['accepted_difference_count'] === 1, 'exact matching difference should be accepted');
inventoryReconciliationAcceptanceAssert((int) $result['summary']['unaccepted_difference_count'] === 0, 'accepted difference should not remain unaccepted');
inventoryReconciliationAcceptanceAssert(!empty($result['rows'][0]['accepted_reconciliation']), 'accepted row should be marked');
inventoryReconciliationAcceptanceAssert(($result['rows'][0]['acceptance_reason'] ?? '') !== '', 'accepted row should carry audit reason');

$quantityMismatch = $matchingAcceptance;
$quantityMismatch['ledger_qty'] = '9.000000';
$mismatchResult = $service->evaluate([$differenceRow], [$quantityMismatch]);
inventoryReconciliationAcceptanceAssert((int) $mismatchResult['summary']['accepted_difference_count'] === 0, 'quantity mismatch must not be accepted');
inventoryReconciliationAcceptanceAssert((int) $mismatchResult['summary']['unaccepted_difference_count'] === 1, 'quantity mismatch should stay unaccepted');
inventoryReconciliationAcceptanceAssert(in_array('unused_reconciliation_acceptance_entries', $mismatchResult['blockers'], true), 'stale acceptance should be a blocker');

$invalidResult = $service->evaluate([$differenceRow], [[
    'pos_tenant' => 0,
    'pos_branch' => 0,
    'store_id' => 27,
    'item_id' => 1001,
]]);
inventoryReconciliationAcceptanceAssert($invalidResult['blockers'] !== [], 'invalid acceptance entries should produce blockers');

echo "inventory-reconciliation-acceptance-service-ok\n";

function inventoryReconciliationAcceptanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
