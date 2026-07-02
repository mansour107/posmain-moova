<?php

require_once __DIR__ . '/../../classes/Items/ItemUnitConversion.php';
require_once __DIR__ . '/../../classes/Items/ItemUnitConversionFeatureFlags.php';
require_once __DIR__ . '/../../classes/Items/ItemUnitResolver.php';
require_once __DIR__ . '/../../classes/Accounting/JournalPostingGuard.php';
require_once __DIR__ . '/../../classes/Accounting/TaxRoundingPolicy.php';
require_once __DIR__ . '/../../classes/Pos/Service/InventoryMovementService.php';
require_once __DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php';

function erpContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

erpContractAssert(
    class_exists('ItemUnitConversion') && method_exists('ItemUnitConversion', 'stockQuantityFromEnteredQty'),
    'ItemUnitConversion must expose exact stock quantity helper'
);

erpContractAssert(
    method_exists('ItemUnitResolver', 'resolvePosStockFactor'),
    'ItemUnitResolver must expose server-authoritative POS factor resolution'
);

erpContractAssert(
    method_exists('InventoryMovementService', 'normalizeInvoiceLine'),
    'InventoryMovementService must normalize invoice lines'
);

$posSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php') ?: '';
erpContractAssert(
    strpos($posSource, 'resolveAuthoritativeItemFactors') !== false,
    'PosOrderMutationService must resolve authoritative item factors'
);
erpContractAssert(
    strpos($posSource, 'normalizeTakeawayItems($conn') !== false,
    'PosOrderMutationService takeaway normalization must be connection-aware'
);

$searchSource = file_get_contents(__DIR__ . '/../../ajax/search_item.php') ?: '';
erpContractAssert(
    strpos($searchSource, 'inventoryFactorForUnitRow') !== false,
    'search_item must resolve unit barcode stock factor'
);

$auditTool = __DIR__ . '/../../tools/erp_accuracy_audit.php';
erpContractAssert(is_file($auditTool), 'erp_accuracy_audit tool must exist');

erpContractAssert(
    ItemUnitConversionFeatureFlags::exactDecimalConversions(),
    'exact decimal conversions flag should default on'
);

try {
    JournalPostingGuard::assertBalancedEntries([
        ['debit' => '10', 'credit' => '0'],
        ['debit' => '0', 'credit' => '10'],
    ]);
} catch (Throwable $exception) {
    throw new RuntimeException('balanced journal entries should pass guard');
}

try {
    JournalPostingGuard::assertBalancedEntries([
        ['debit' => '10', 'credit' => '0'],
        ['debit' => '0', 'credit' => '9'],
    ]);
    throw new RuntimeException('unbalanced journal entries should fail guard');
} catch (InvalidArgumentException $exception) {
    erpContractAssert($exception->getMessage() === 'JOURNAL_NOT_BALANCED', 'journal guard should reject imbalance');
}

echo "erp_accuracy_contract_test: OK\n";
