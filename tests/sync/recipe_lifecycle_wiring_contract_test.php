<?php

$root = dirname(__DIR__, 2);
$mutation = recipeLifecycleWiringSource($root . '/classes/Pos/Service/PosOrderMutationService.php');
$posOrders = recipeLifecycleWiringSource($root . '/classes/PosOrderService.php');
$legacyInvoice = recipeLifecycleWiringSource($root . '/do/doadd_invoice.php');
$legacyInvoiceDelete = recipeLifecycleWiringSource($root . '/do/dodel_invoice.php');
$legacyBridge = recipeLifecycleWiringSource($root . '/classes/Recipe/LegacyInvoiceRecipeLifecycleBridge.php');
$cofeCreate = recipeLifecycleWiringSource($root . '/ajax/cofe_create_order.php');
$tableMerge = recipeLifecycleWiringSource($root . '/classes/Pos/Service/TableMergeService.php');
$recipeLifecycle = recipeLifecycleWiringSource($root . '/classes/Recipe/RecipeOrderLifecycleService.php');
$usageRepository = recipeLifecycleWiringSource($root . '/classes/Recipe/Repository/RecipeOrderLineUsageRepository.php');
$externalLineMapRepository = recipeLifecycleWiringSource($root . '/classes/Recipe/Repository/ExternalOrderLineMapRepository.php');

recipeLifecycleWiringAssertContains(
    "require_once __DIR__ . '/../../Recipe/RecipeOrderLifecycleService.php'",
    $mutation,
    'POS mutation service should load the shared recipe lifecycle facade'
);
recipeLifecycleWiringAssertContains('private $recipeLifecycleService', $mutation, 'POS mutation service should own lifecycle wiring');
recipeLifecycleWiringAssertContains('onOrderLineAdded', $mutation, 'order line add/save paths should call recipe lifecycle');
recipeLifecycleWiringAssertContains('onOrderLineCancelled', $mutation, 'order update/cancel paths should call recipe lifecycle cancellation');
recipeLifecycleWiringAssertContains('onOrderPaid', $mutation, 'paid table/takeaway/split paths should call recipe lifecycle payment hook');
recipeLifecycleWiringAssertContains('onOrderSplit', $mutation, 'split payment should call the shared split lifecycle facade');
recipeLifecycleWiringAssertContains('recordRecipeOrderLinesAdded', $mutation, 'recipe add hook should stay centralized in service helper');
recipeLifecycleWiringAssertContains('recordRecipeOrderPaid', $mutation, 'recipe paid hook should stay centralized in service helper');
recipeLifecycleWiringAssertContains('recipeSplitOriginalAdjustments', $mutation, 'split payment should centralize source/remaining recipe line context calculation');
recipeLifecycleWiringAssertContains('recordRecipeOrderSplit', $mutation, 'split payment should centralize split lifecycle facade calls');
recipeLifecycleWiringAssertContains('RecipeDecimal::divide', $mutation, 'split payment recipe quantities should use decimal-safe division');
recipeLifecycleWiringAssertContains('recipeQuantityFromLegacyStockValues', $mutation, 'POS mutation recipe contexts should reconstruct legacy fat_details quantities with a shared decimal helper');
recipeLifecycleWiringAssertNotContains('recordRecipeSplitOriginalAdjustments', $mutation, 'split payment should not use the old direct per-line lifecycle adjustment helper');
recipeLifecycleWiringAssertNotContains("abs((float) (\$row['qty_out'] ?? 0) - (float) (\$row['qty_in'] ?? 0)) / \$uVal", $mutation, 'loaded recipe order-line contexts must not divide legacy quantities through floats');
recipeLifecycleWiringAssertNotContains("abs((float) (\$line['qty_out'] ?? 0) - (float) (\$line['qty_in'] ?? 0)) / \$uVal", $mutation, 'takeaway recipe order-line contexts must not divide legacy quantities through floats');

recipeLifecycleWiringAssertContains(
    "require_once __DIR__ . '/Recipe/RecipeOrderLifecycleService.php'",
    $posOrders,
    'Moova POS order service should load the shared recipe lifecycle facade'
);
recipeLifecycleWiringAssertContains('RecipeOrderLifecycleService $recipeLifecycleService', $posOrders, 'Moova POS order service should own lifecycle wiring');
recipeLifecycleWiringAssertContains('recipeContextsFromMoovaMappedLines', $posOrders, 'Moova mapped lines should be converted to recipe contexts centrally');
recipeLifecycleWiringAssertContains('onOrderLineAdded', $posOrders, 'Moova new/edit paths should call recipe line add lifecycle');
recipeLifecycleWiringAssertContains('onOrderLineCancelled', $posOrders, 'Moova edit/cancel paths should call recipe cancellation lifecycle');
recipeLifecycleWiringAssertContains('source_order_uuid', $posOrders, 'Moova recipe contexts should preserve external order identity');
recipeLifecycleWiringAssertContains('source_line_uuid', $posOrders, 'Moova recipe contexts should preserve deterministic external line identity');
recipeLifecycleWiringAssertContains('RecipeDecimal::normalize', $posOrders, 'Moova recipe context quantities should use decimal-safe normalization');
recipeLifecycleWiringAssertContains('recipeQuantityFromLegacyStockValues', $posOrders, 'Moova mapped recipe contexts should reconstruct legacy mapped quantities with a decimal helper');
recipeLifecycleWiringAssertNotContains('number_format($qtyOut / $uVal', $posOrders, 'Moova mapped recipe contexts must not divide mapped quantities through floats');

recipeLifecycleWiringAssertContains('LegacyInvoiceRecipeLifecycleBridge', $legacyInvoice, 'legacy invoice path should route through the recipe lifecycle bridge');
recipeLifecycleWiringAssertContains('recordExistingLinesCancelled', $legacyInvoice, 'legacy table edits should release existing recipe reservations through the bridge');
recipeLifecycleWiringAssertContains('recordCurrentLinesAdded', $legacyInvoice, 'legacy invoice saves should record added recipe lines through the bridge');
recipeLifecycleWiringAssertContains('recordCurrentOrderPaid', $legacyInvoice, 'legacy paid POS orders should call the paid lifecycle through the bridge');
recipeLifecycleWiringAssertContains('assertLegacyEditAllowed', recipeLifecycleWiringSource($root . '/do/doedit_invoice.php'), 'legacy invoice edit should guard already-consumed recipe stock through the bridge');
recipeLifecycleWiringAssertContains('recordExistingLinesCancelled', recipeLifecycleWiringSource($root . '/do/doedit_invoice.php'), 'legacy invoice edit should release old pending recipe lines through the bridge');
recipeLifecycleWiringAssertContains('recordCurrentLinesAdded', recipeLifecycleWiringSource($root . '/do/doedit_invoice.php'), 'legacy invoice edit should add replacement recipe lines through the bridge');
recipeLifecycleWiringAssertContains('LegacyInvoiceRecipeLifecycleBridge', $legacyInvoiceDelete, 'legacy invoice delete should route through the recipe lifecycle bridge');
recipeLifecycleWiringAssertContains('recordCurrentOrderDeleted', $legacyInvoiceDelete, 'legacy invoice delete should release or void recipe usage through the bridge before soft delete');
recipeLifecycleWiringAssertContains('recordCurrentOrderRefunded', $legacyBridge, 'legacy invoice bridge should expose a refund lifecycle path for operator reversals');
recipeLifecycleWiringAssertNotContains('(float)', $legacyBridge, 'legacy invoice bridge should not coerce recipe quantities through floats');
recipeLifecycleWiringAssertNotContains('float $', $legacyBridge, 'legacy invoice bridge should not accept recipe quantities as float parameters');
recipeLifecycleWiringAssertContains('divideScaledDecimal', $legacyBridge, 'legacy invoice bridge should divide legacy quantities with decimal-safe helpers');
recipeLifecycleWiringAssertContains('LegacyInvoiceRecipeLifecycleBridge', $cofeCreate, 'legacy Cofe order creation should route through the recipe lifecycle bridge');
recipeLifecycleWiringAssertContains('recordExternalLinesAdded', $cofeCreate, 'Cofe order creation should record external recipe lines through the bridge');
recipeLifecycleWiringAssertContains('recordExternalOrderPaid', $cofeCreate, 'Cofe order creation should record paid external recipe lines through the bridge');
recipeLifecycleWiringAssertContains('persistCofeIdempotencyKey', $cofeCreate, 'Cofe replay guard should persist the idempotency key after the order header is inserted');
recipeLifecycleWiringAssertContains('UPDATE ot_head SET cofe_idempotency_key', $cofeCreate, 'Cofe replay guard should make later replays return the existing order');
recipeLifecycleWiringAssertContains('cofeColumnExists', $cofeCreate, 'Cofe idempotency persistence should tolerate older schemas without the column');
recipeLifecycleWiringAssertContains(
    "require_once __DIR__ . '/../../Recipe/RecipeOrderLifecycleService.php'",
    $tableMerge,
    'table merge service should load the shared recipe lifecycle facade'
);
recipeLifecycleWiringAssertContains('RecipeOrderLifecycleService $recipeLifecycleService', $tableMerge, 'table merge service should own lifecycle wiring');
recipeLifecycleWiringAssertContains('recordRecipeMergedLines', $tableMerge, 'table merge should centralize recipe reservation transfer handling');
recipeLifecycleWiringAssertContains('onOrderMerged', $tableMerge, 'table merge should call the shared order-merge lifecycle facade');
recipeLifecycleWiringAssertNotContains('onOrderLineCancelled', $tableMerge, 'table merge should not bypass the shared order-merge lifecycle facade with direct cancellation calls');
recipeLifecycleWiringAssertNotContains('onOrderLineAdded', $tableMerge, 'table merge should not bypass the shared order-merge lifecycle facade with direct add calls');
recipeLifecycleWiringAssertContains('RecipeDecimal::divide', $tableMerge, 'table merge recipe quantities should use decimal-safe division');
recipeLifecycleWiringAssertNotContains('function recipeLineQuantity(array $row): float', $tableMerge, 'table merge recipe quantities must not return floats');
recipeLifecycleWiringAssertNotContains("abs((float) (\$row['qty_out']", $tableMerge, 'table merge recipe quantities must not coerce qty_out through float math');
recipeLifecycleWiringAssertContains('public function onOrderMerged', $recipeLifecycle, 'recipe lifecycle facade should expose first-class merge handling');
recipeLifecycleWiringAssertContains('source_lines', $recipeLifecycle, 'recipe lifecycle merge handling should accept source lines');
recipeLifecycleWiringAssertContains('destination_lines', $recipeLifecycle, 'recipe lifecycle merge handling should accept destination lines');
recipeLifecycleWiringAssertContains('onOrderLineCancelled', $recipeLifecycle, 'recipe lifecycle merge handling should release source-order recipe reservations');
recipeLifecycleWiringAssertContains('onOrderLineAdded', $recipeLifecycle, 'recipe lifecycle merge handling should reserve destination-order recipe lines');
recipeLifecycleWiringAssertContains('public function onOrderSplit', $recipeLifecycle, 'recipe lifecycle facade should expose first-class split handling');
recipeLifecycleWiringAssertContains('remaining_lines', $recipeLifecycle, 'recipe lifecycle split handling should accept remaining original lines');
recipeLifecycleWiringAssertContains('paid_lines', $recipeLifecycle, 'recipe lifecycle split handling should accept paid child lines');
recipeLifecycleWiringAssertContains('splitPaidOrderContext', $recipeLifecycle, 'recipe lifecycle split handling should route paid child consumption through order payment lifecycle');
recipeLifecycleWiringAssertContains('$context->sourceLineUuid ?? $context->orderLineUuid ?? $context->fatDetailId', $recipeLifecycle, 'recipe usage idempotency should prefer external provider line identity before local POS line/detail identity');
foreach (['Recipe usage source_channel is invalid', 'Recipe usage status is invalid', 'Recipe usage order_qty must be positive', 'Recipe usage cost_total cannot be negative', 'Recipe usage idempotency key is required'] as $guard) {
    recipeLifecycleWiringAssertContains($guard, $usageRepository, 'usage repository should guard lifecycle row invariant: ' . $guard);
}
foreach (['previewed', 'reserved', 'consumed', 'released', 'voided', 'refunded', 'wasted', 'moova', 'cofe', 'sync'] as $usageEnum) {
    recipeLifecycleWiringAssertContains($usageEnum, $usageRepository, 'usage repository should allow schema enum value: ' . $usageEnum);
}
foreach ([
    'External order line source_channel is invalid',
    'External order line external_order_id is required',
    'External order line external_line_id is required',
    'External order line item_id must be positive',
    'External order line idempotency key is required',
    'External order line line_status is invalid',
    'External order line modifiers_hash must be a sha256 hex digest when provided',
    'External order line modifiers_json must be valid JSON when provided',
] as $guard) {
    recipeLifecycleWiringAssertContains($guard, $externalLineMapRepository, 'external order line map repository should guard replay identity invariant: ' . $guard);
}
foreach (['moova', 'cofe', 'api', 'sync', 'active', 'cancelled', 'changed', 'merged', 'split'] as $mapEnum) {
    recipeLifecycleWiringAssertContains($mapEnum, $externalLineMapRepository, 'external order line map repository should allow schema enum value: ' . $mapEnum);
}

foreach ([
    'ajax/process_table_payment.php',
    'ajax/process_split_payment.php',
    'do/doadd_invoice.php',
    'do/doedit_invoice.php',
    'do/dodel_invoice.php',
    'ajax/refund_order.php',
    'ajax/cofe_create_order.php',
] as $endpoint) {
    $source = recipeLifecycleWiringSource($root . '/' . $endpoint);
    recipeLifecycleWiringAssertNotContains('new RecipeOrderLifecycleService', $source, $endpoint . ' should not instantiate recipe lifecycle directly');
    recipeLifecycleWiringAssertNotContains('explodeOrderLine', $source, $endpoint . ' should not calculate recipe requirements directly');
    recipeLifecycleWiringAssertNotContains('recordRecipeConsumption', $source, $endpoint . ' should not write recipe stock directly');
}

echo "recipe-lifecycle-wiring-contract-ok\n";

function recipeLifecycleWiringSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function recipeLifecycleWiringAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function recipeLifecycleWiringAssertNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message);
    }
}
