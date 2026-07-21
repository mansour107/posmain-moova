<?php

$jsSource = file_get_contents(__DIR__ . '/../../js/pos_barcode.js');
$posContent = file_get_contents(__DIR__ . '/../../includes/pos_content.php');
$cartRow = file_get_contents(__DIR__ . '/../../includes/pos_cart_row.php');
$cssSource = file_get_contents(__DIR__ . '/../../dist/css/pos_barcode.css');
$loadOrderSource = file_get_contents(__DIR__ . '/../../ajax/load_order.php');
$tableOrderSource = file_get_contents(__DIR__ . '/../../classes/TableOrderService.php');
$invoiceSource = file_get_contents(__DIR__ . '/../../do/doadd_invoice.php');
$mutationSource = file_get_contents(__DIR__ . '/../../classes/Pos/Service/PosOrderMutationService.php');

if ($jsSource === false || $posContent === false || $cartRow === false || $cssSource === false || $loadOrderSource === false || $tableOrderSource === false || $invoiceSource === false || $mutationSource === false) {
    throw new RuntimeException('Unable to read cashier line note sources');
}

$posLineMarkup = $posContent . $cartRow;

phase4CashierLineNoteAssert(strpos($jsSource, 'lineNoteInput') !== false, 'new cashier rows should include lineNoteInput');
phase4CashierLineNoteAssert(strpos($jsSource, 'name="itmnote[]"') !== false, 'new cashier rows should submit itmnote[]');
phase4CashierLineNoteAssert(strpos($jsSource, 'lineNoteButton') !== false, 'new cashier rows should expose note icon button');
phase4CashierLineNoteAssert(strpos($jsSource, 'pos-cart-note') !== false, 'new cashier rows should keep note icon in its own compact slot');
phase4CashierLineNoteAssert(strpos($jsSource, 'lineNoteModal') !== false, 'note icon should open a modal editor');
phase4CashierLineNoteAssert(strpos($jsSource, 'line-note-has-value') !== false, 'note icon should have a filled state');
phase4CashierLineNoteAssert(strpos($jsSource, 'line-note-empty') !== false, 'note icon should have an empty state');
phase4CashierLineNoteAssert(strpos($jsSource, 'localStorage') !== false, 'modal-applied notes should survive browser refresh as local drafts before order save');
phase4CashierLineNoteAssert(strpos($jsSource, 'saveLineNoteDraft') !== false, 'line note modal should update local draft storage');
phase4CashierLineNoteAssert(strpos($jsSource, 'getLineNoteDraft') !== false, 'cart rows should restore local draft notes');
phase4CashierLineNoteAssert(strpos($jsSource, "addItemToOrder(id, name, price, barcode, qty = 1, imageHtml = '', lineNote = '', options = {})") !== false, 'addItemToOrder should accept optional line note and row options');
phase4CashierLineNoteAssert(strpos($jsSource, 'item.note || item.kitchen_note || item.notes ||') !== false, 'loaded table items should preserve note fields when provided');
phase4CashierLineNoteAssert(strpos($jsSource, 'uVal: item.u_val || 1,') !== false, 'loaded table items should preserve u_val when provided');

phase4CashierLineNoteAssert(strpos($posLineMarkup, 'lineNoteInput') !== false, 'edit-mode cashier rows should include lineNoteInput');
phase4CashierLineNoteAssert(strpos($posLineMarkup, 'name="itmnote[]"') !== false, 'edit-mode cashier rows should submit itmnote[]');
phase4CashierLineNoteAssert(strpos($posLineMarkup, 'lineNoteButton') !== false, 'edit-mode cashier rows should expose note icon button');
phase4CashierLineNoteAssert(strpos($posLineMarkup, 'qty-decrease') !== false, 'edit-mode cashier rows should keep minus button');
phase4CashierLineNoteAssert(strpos($posLineMarkup, 'qty-increase') !== false, 'edit-mode cashier rows should keep plus button');
phase4CashierLineNoteAssert(strpos($posContent, "\$line_note = \$rowdet['notes'] ?? '';") !== false, 'edit-mode note value should stay compatible with legacy fd.notes');

phase4CashierLineNoteAssert(
    strpos($cssSource, 'grid-template-areas: "value decrease qty increase name note delete"') !== false
        || strpos($cssSource, 'grid-template-areas: "price qty name note delete"') !== false,
    'cart row should keep quantity controls and note icon slot'
);
phase4CashierLineNoteAssert(strpos($cssSource, '.pos-cart-note') !== false, 'cart row should style a compact note icon slot');
phase4CashierLineNoteAssert(strpos($cssSource, 'white-space: normal !important;') !== false, 'cart item names should wrap instead of one-line truncating');
phase4CashierLineNoteAssert(strpos($cssSource, '.lineNoteButton.line-note-empty') !== false, 'empty note icon color style expected');
phase4CashierLineNoteAssert(strpos($cssSource, '.lineNoteButton.line-note-has-value') !== false, 'filled note icon color style expected');

phase4CashierLineNoteAssert(strpos($tableOrderSource, 'order_line_notes') !== false, 'table order loader should read Phase 4 line notes when available');
phase4CashierLineNoteAssert(strpos($tableOrderSource, 'AS kitchen_note') !== false, 'table order loader should expose kitchen_note');
phase4CashierLineNoteAssert(strpos($loadOrderSource, "'note' => \$lineNote") !== false, 'load order endpoint should return note field');
phase4CashierLineNoteAssert(strpos($loadOrderSource, "'kitchen_note' => \$lineNote") !== false, 'load order endpoint should return kitchen_note field');

phase4CashierLineNoteAssert(strpos($invoiceSource, "require_once('../classes/Pos/Service/ModifierLineNoteService.php');") !== false, 'legacy invoice route should load ModifierLineNoteService');
phase4CashierLineNoteAssert(strpos($invoiceSource, 'posmainInvoicePersistKitchenLineNote(') !== false, 'legacy invoice route should persist kitchen line notes');
phase4CashierLineNoteAssert(strpos($invoiceSource, "\$_POST['itmnote'][\$index] ?? ''") !== false, 'legacy invoice route should read posted itmnote[] by row index');
phase4CashierLineNoteAssert(strpos($invoiceSource, 'saveLineCustomizations(') !== false, 'legacy invoice route should use ModifierLineNoteService when available');
phase4CashierLineNoteAssert(strpos($invoiceSource, 'order_line_notes') !== false, 'legacy invoice route should target Phase 4 line notes table');

phase4CashierLineNoteAssert(strpos($mutationSource, "require_once __DIR__ . '/ModifierLineNoteService.php';") !== false, 'mutation service should load ModifierLineNoteService');
phase4CashierLineNoteAssert(strpos($mutationSource, 'private $modifierLineNoteService;') !== false, 'mutation service should store ModifierLineNoteService');
phase4CashierLineNoteAssert(strpos($mutationSource, '?ModifierLineNoteService $modifierLineNoteService = null') !== false, 'mutation service should accept optional ModifierLineNoteService');
phase4CashierLineNoteAssert(strpos($mutationSource, "'note' => (string) (\$request['itmnote'][\$index] ?? '')") !== false, 'takeaway normalization should carry itmnote[]');
phase4CashierLineNoteAssert(strpos($mutationSource, 'lineNoteFromItem(') !== false, 'mutation service should normalize note fields from item arrays');
phase4CashierLineNoteAssert(strpos($mutationSource, 'persistLineNoteIfAvailable(') !== false, 'mutation service should persist line notes after inserting detail rows');
phase4CashierLineNoteAssert(strpos($mutationSource, 'insertTableOrderItems($conn, $orderId, $storeId, $items, $context)') !== false, 'table save path should pass context into line note persistence');
phase4CashierLineNoteAssert(strpos($mutationSource, 'saveLineCustomizations(') !== false, 'mutation service should use ModifierLineNoteService when available');
phase4CashierLineNoteAssert(strpos($mutationSource, 'replaceKitchenLineNoteDirectly(') !== false, 'mutation service should keep note persistence best-effort when modifier validation is unavailable');

echo "phase4-cashier-line-note-contract-ok\n";

function phase4CashierLineNoteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
