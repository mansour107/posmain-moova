<?php

$root = dirname(__DIR__, 2);

function posItemVoidContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$mutation = file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php');
$posJs = file_get_contents($root . '/js/pos_barcode.js');
$cartRow = file_get_contents($root . '/includes/pos_cart_row.php');
$posBarcode = file_get_contents($root . '/pos_barcode.php');
$posContent = file_get_contents($root . '/includes/pos_content.php');
$loadOrder = file_get_contents($root . '/ajax/load_order.php');
$dispatch = file_get_contents($root . '/includes/pos_api_dispatch.php');

posItemVoidContractAssert(strpos($mutation, 'requireItemVoidApprovalIfNeeded') !== false, 'mutation service should gate persisted line removals');
posItemVoidContractAssert(strpos($mutation, 'detectPersistedLineReductions') !== false, 'mutation service should diff persisted lines');
posItemVoidContractAssert(strpos($mutation, 'pos.void.item_after_send') !== false, 'mutation service should use item void permission key');
posItemVoidContractAssert(strpos($mutation, 'PAID_ORDER_LINE_REMOVAL_DENIED') !== false, 'mutation service should reject paid-order line removals');

posItemVoidContractAssert(strpos($posJs, 'posmainPersistedLineNeedsVoidApproval') !== false, 'POS JS should gate persisted line removal');
posItemVoidContractAssert(strpos($posJs, 'posmainRequestItemVoidOverride') !== false, 'POS JS should request manager override for persisted removals');
posItemVoidContractAssert(strpos($posJs, 'data-persisted-line') !== false, 'POS JS should mark persisted cart lines');
posItemVoidContractAssert(strpos($posJs, 'POSMAIN_ACTING_CAN_VOID_PERSISTED') !== false, 'POS JS should honor server-side persisted void capability flag');
posItemVoidContractAssert(strpos($posBarcode, 'POSMAIN_ACTING_CAN_VOID_PERSISTED') !== false, 'POS page should inject persisted void capability flag');
posItemVoidContractAssert(strpos($posContent, 'name="edit_id"') !== false, 'POS form should include edit_id for order edits');

posItemVoidContractAssert(strpos($cartRow, 'data-persisted-line') !== false, 'cart row renderer should mark persisted lines');
posItemVoidContractAssert(strpos($loadOrder, 'detail_id') !== false, 'load order should expose detail ids');

posItemVoidContractAssert(strpos($dispatch, 'MANAGER_APPROVAL_REQUIRED') !== false, 'POS API dispatch should map manager approval required');
posItemVoidContractAssert(strpos($dispatch, 'PAID_ORDER_LINE_REMOVAL_DENIED') !== false, 'POS API dispatch should map paid line removal denial');

echo "pos-item-void-override-contract-ok\n";
