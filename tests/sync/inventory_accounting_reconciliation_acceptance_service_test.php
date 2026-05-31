<?php

$root = dirname(__DIR__, 2);

require_once $root . '/classes/Inventory/InventoryAccountingReconciliationAcceptanceService.php';

$service = new InventoryAccountingReconciliationAcceptanceService();

$problemRow = [
    'review_key' => 'missing:waste:adjustment:501',
    'accounting_journal_id' => null,
    'sample_movement_type' => 'waste',
    'sample_source_type' => 'adjustment',
    'movement_count' => '1',
    'movement_total' => '4.000000',
    'journal_debit_total' => '0.000000',
    'journal_credit_total' => '0.000000',
    'reconciliation_status' => 'missing_journal',
];
$balancedRow = array_replace($problemRow, [
    'review_key' => 'journal:7001',
    'accounting_journal_id' => '7001',
    'journal_debit_total' => '4.000000',
    'journal_credit_total' => '4.000000',
    'reconciliation_status' => 'balanced',
]);

$matchingAcceptance = $problemRow + [
    'accepted_by' => 'chief-accountant',
    'accepted_at_utc' => '2026-05-30T12:00:00Z',
    'reason' => 'Historical pilot row was posted before inventory accounting was enabled.',
];

$result = $service->evaluate([$problemRow, $balancedRow], [$matchingAcceptance]);
inventoryAccountingAcceptanceAssert((int) $result['summary']['accepted_problem_count'] === 1, 'exact matching accounting problem should be accepted');
inventoryAccountingAcceptanceAssert((int) $result['summary']['unaccepted_problem_count'] === 0, 'accepted accounting problem should not remain unaccepted');
inventoryAccountingAcceptanceAssert(!empty($result['rows'][0]['accepted_accounting_reconciliation']), 'accepted accounting row should be marked');
inventoryAccountingAcceptanceAssert(($result['rows'][0]['accounting_acceptance_reason'] ?? '') !== '', 'accepted accounting row should carry audit reason');

$totalMismatch = $matchingAcceptance;
$totalMismatch['movement_total'] = '5.000000';
$mismatchResult = $service->evaluate([$problemRow], [$totalMismatch]);
inventoryAccountingAcceptanceAssert((int) $mismatchResult['summary']['accepted_problem_count'] === 0, 'movement total mismatch must not be accepted');
inventoryAccountingAcceptanceAssert((int) $mismatchResult['summary']['unaccepted_problem_count'] === 1, 'mismatched accounting problem should remain unaccepted');
inventoryAccountingAcceptanceAssert(in_array('unused_accounting_reconciliation_acceptance_entries', $mismatchResult['blockers'], true), 'stale accounting acceptance should be a blocker');

$invalidResult = $service->evaluate([$problemRow], [[
    'review_key' => $problemRow['review_key'],
    'reconciliation_status' => $problemRow['reconciliation_status'],
]]);
inventoryAccountingAcceptanceAssert($invalidResult['blockers'] !== [], 'invalid accounting acceptance entries should produce blockers');

echo "inventory-accounting-reconciliation-acceptance-service-ok\n";

function inventoryAccountingAcceptanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
