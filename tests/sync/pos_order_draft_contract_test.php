<?php

$root = realpath(__DIR__ . '/../..');
$draft = file_get_contents($root . '/js/pos_order_draft.js');
$orderApi = file_get_contents($root . '/js/pos_order_api.js');
$posBarcode = file_get_contents($root . '/js/pos_barcode.js');
$posSupermarket = file_get_contents($root . '/js/pos_supermarket.js');
$posTables = file_get_contents($root . '/js/pos_tables.js');
$posContent = file_get_contents($root . '/includes/pos_content.php');
$supermarketContent = file_get_contents($root . '/includes/pos_supermarket_content.php');
$posTablesPage = file_get_contents($root . '/pos_tables.php');
$posCss = file_get_contents($root . '/dist/css/pos_barcode.css');

posOrderDraftContractAssert(strpos($draft, 'window.POSOrderDraft') !== false, 'pos_order_draft should export POSOrderDraft');
posOrderDraftContractAssert(strpos($draft, 'markDirty') !== false, 'draft module should expose markDirty');
posOrderDraftContractAssert(strpos($draft, 'rotateIdempotencyKey') !== false, 'draft module should rotate idempotency keys');
posOrderDraftContractAssert(strpos($draft, 'data-pos-save-state') !== false, 'draft module should set save button data attribute');
posOrderDraftContractAssert(strpos($draft, 'pos-save--saved') !== false, 'draft module should apply saved CSS state');

posOrderDraftContractAssert(strpos($orderApi, 'markSaved(body)') !== false || strpos($orderApi, 'draft.markSaved') !== false, 'pos_order_api should mark draft saved on success');
posOrderDraftContractAssert(strpos($orderApi, 'canSave(action)') !== false, 'pos_order_api should block unchanged save attempts');
posOrderDraftContractAssert(strpos($orderApi, 'markSaving') !== false, 'pos_order_api should mark saving before submit');

posOrderDraftContractAssert(strpos($posBarcode, 'touchOrderDraft') !== false, 'pos_barcode should mark draft dirty on cart mutations');
posOrderDraftContractAssert(strpos($posBarcode, 'bootstrapSaved') !== false, 'pos_barcode should bootstrap saved state after loadExistingOrder');
posOrderDraftContractAssert(strpos($posBarcode, 'function ensureFormIdempotencyKey') === false, 'pos_barcode should not duplicate idempotency helper');

posOrderDraftContractAssert(strpos($posSupermarket, 'function ensureFormIdempotencyKey') === false, 'pos_supermarket should not duplicate idempotency helper');
posOrderDraftContractAssert(strpos($posSupermarket, 'touchOrderDraft') !== false, 'pos_supermarket should mark draft dirty on cart mutations');

posOrderDraftContractAssert(strpos($posTables, 'buildTableOrderFingerprint') !== false, 'pos_tables should define table fingerprint builder');
posOrderDraftContractAssert(strpos($posTables, 'getStandaloneIdempotencyKey') !== false, 'pos_tables should use draft standalone idempotency key');
posOrderDraftContractAssert(strpos($posTables, 'markSaved') !== false, 'pos_tables should mark draft saved after table save');

posOrderDraftContractAssert(strpos($posContent, 'pos_order_draft.js') !== false, 'pos_content should include pos_order_draft.js');
posOrderDraftContractAssert(strpos($supermarketContent, 'pos_order_draft.js') !== false, 'pos_supermarket_content should include pos_order_draft.js');
posOrderDraftContractAssert(strpos($posTablesPage, 'pos_order_draft.js') !== false, 'pos_tables.php should include pos_order_draft.js');

posOrderDraftContractAssert(strpos($posCss, '.pos-save--saved') !== false, 'pos_barcode.css should style saved save button state');

echo "pos-order-draft-contract-ok\n";

function posOrderDraftContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
