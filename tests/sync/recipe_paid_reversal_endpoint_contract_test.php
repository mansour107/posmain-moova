<?php

$root = dirname(__DIR__, 2);
$endpoint = paidReversalContractSource($root . '/ajax/refund_order.php');
$mutation = paidReversalContractSource($root . '/classes/Pos/Service/PosOrderMutationService.php');
$lifecycle = paidReversalContractSource($root . '/classes/Recipe/RecipeOrderLifecycleService.php');
$recentOrders = paidReversalContractSource($root . '/ajax/get_recent_orders.php');
$tableOrders = paidReversalContractSource($root . '/classes/TableOrderService.php');
$legacyInvoiceDelete = paidReversalContractSource($root . '/do/dodel_invoice.php');
$legacyOperationDelete = paidReversalContractSource($root . '/do/dodel_pro.php');
$posBarcodeJs = paidReversalContractSource($root . '/js/pos_barcode.js');
$runtimeTest = paidReversalContractSource($root . '/tests/sync/recipe_paid_reversal_endpoint_runtime_test.php');

paidReversalContractAssertContains("require_csrf('pos_browser')", $endpoint, 'paid reversal endpoint must use POS CSRF protection');
paidReversalContractAssertContains('require_pos_authenticated()', $endpoint, 'paid reversal endpoint must require POS authentication');
paidReversalContractAssertContains('require_pos_lane_permission', $endpoint, 'paid reversal endpoint should enforce action-specific POS lane permission');
paidReversalContractAssertContains('reversePaidOrder', $endpoint, 'paid reversal endpoint should delegate to the POS mutation service');
paidReversalContractAssertNotContains("require_permission(\$action === 'void' ? 'pos.void.paid' : 'pos.refund'", $endpoint, 'paid reversal endpoint should defer permission to manager approval service');
paidReversalContractAssertContains('recordRequiredOrderSnapshot', $endpoint, 'paid reversal endpoint should emit a required atomic order sync snapshot');
paidReversalContractAssertContains('REFUND_TENDER_REQUIRED', $endpoint, 'cashier refund endpoint must require an explicit outgoing tender');
paidReversalContractAssertContains("'refund_payment_method' => \$refundPaymentMethod", $endpoint, 'endpoint must pass the selected tender to the central mutation service');
paidReversalContractAssertContains("'mutation_version' => \$_POST['mutation_version']", $endpoint, 'endpoint must pass the caller expected mutation version to the central mutation service');
paidReversalContractAssertContains("'refund_mode' => \$refundMode", $endpoint, 'endpoint must pass the unified partial-refund mode');
paidReversalContractAssertContains("'refund_amount' => \$refundAmount", $endpoint, 'endpoint must pass an amount selection separately from tender amount');
paidReversalContractAssertContains("'lines' => \$refundLines", $endpoint, 'endpoint must pass validated item/quantity selections');
paidReversalContractAssertContains('REFUND_LINES_REQUIRED', $endpoint, 'item mode must reject an empty selection');
paidReversalContractAssertNotContains('new RecipeOrderLifecycleService', $endpoint, 'endpoint must not instantiate recipe lifecycle directly');
paidReversalContractAssertNotContains('explodeOrderLine', $endpoint, 'endpoint must not calculate recipe requirements directly');
paidReversalContractAssertNotContains('recordRecipeConsumption', $endpoint, 'endpoint must not write recipe stock directly');

paidReversalContractAssertContains('public function reversePaidOrder', $mutation, 'POS mutation service should own paid reversal workflow');
paidReversalContractAssertContains('onOrderRefunded', $mutation, 'paid refund should call recipe refund lifecycle centrally');
paidReversalContractAssertContains('onOrderVoided', $mutation, 'paid void should call recipe void lifecycle centrally');
paidReversalContractAssertContains('resolveRecipeRefundPolicy', $mutation, 'service should resolve configured/manager-choice recipe stock policy');
paidReversalContractAssertContains('requireApprovedIfNeeded', $mutation, 'paid reversal should honor manager approval gate when enabled');
paidReversalContractAssertContains('previewRefund', $mutation, 'manager approval must evaluate the authoritative selected partial amount');
paidReversalContractAssertContains('recipeLinesForCreditNote', $mutation, 'recipe and COGS reversal must consume persisted credit-note quantities');
paidReversalContractAssertContains('COMPLETED_ORDER_EDIT_REQUIRES_REFUND', $mutation, 'completed cashier orders must not be rewritten through edit');
paidReversalContractAssertNotContains("isdeleted = CASE WHEN ? = 'void'", $mutation, 'paid void must retain the original order');
paidReversalContractAssertContains('ORDER_HAS_PAYMENT_USE_REFUND', $mutation, 'partial payments must not enter unpaid cancellation side effects');
paidReversalContractAssertContains('ORDER_TOTAL_BELOW_PAID_AMOUNT_USE_REFUND', $mutation, 'table edits must not silently reduce collected money');
paidReversalContractAssertContains('ORDER_HAS_PAYMENT_USE_REFUND', $tableOrders, 'table cancellation must independently reject collected money');
paidReversalContractAssertContains('pos_orders_use_cancel_or_refund', $legacyInvoiceDelete, 'legacy invoice deletion must reject POS orders');
paidReversalContractAssertContains('pos_orders_use_cancel_or_refund', $legacyOperationDelete, 'legacy physical deletion must reject POS orders');

paidReversalContractAssertContains('RecipeSettingsService', $lifecycle, 'recipe lifecycle should own fallback refund policy settings');
paidReversalContractAssertContains('resolveRefundPolicy', $lifecycle, 'recipe lifecycle should normalize configured and explicit refund stock policies');
paidReversalContractAssertContains("'manager_choice'", $lifecycle, 'recipe lifecycle should handle manager-choice fallback safely');
paidReversalContractAssertContains("!== 'consumed'", $lifecycle, 'recipe lifecycle should not reverse already refunded/wasted/voided usage rows');

paidReversalContractAssertContains('refund_eligible', $recentOrders, 'recent orders payload should expose paid refund eligibility');
paidReversalContractAssertContains('void_eligible', $recentOrders, 'recent orders payload should expose paid void eligibility');
paidReversalContractAssertContains('delete_eligible', $recentOrders, 'recent orders payload should expose delete eligibility');
paidReversalContractAssertContains('edit_eligible', $recentOrders, 'recent orders payload should expose immutable edit eligibility');
paidReversalContractAssertContains("COALESCE(o.payment_status, 'unpaid') = 'unpaid'", $recentOrders, 'unpaid cancellation must exclude partial payments');
paidReversalContractAssertContains('reversal_status', $recentOrders, 'recent orders must expose authoritative full or partial reversal state');
paidReversalContractAssertContains('refundable_lines', $recentOrders, 'recent orders must expose remaining immutable sale-line snapshots');
paidReversalContractAssertContains('remaining_quantity', $recentOrders, 'recent orders line context must expose remaining refundable quantity');
paidReversalContractAssertContains("'mutation_version' => max(1, (int) (\$row['mutation_version']", $recentOrders, 'recent orders must expose the expected mutation version used by refund and void');
paidReversalContractAssertContains('can_refund', $recentOrders, 'recent orders payload should keep refund capability alias');
paidReversalContractAssertContains('can_void', $recentOrders, 'recent orders payload should keep void capability alias');
paidReversalContractAssertContains('COALESCE(o.payment_date, o.completed_at, o.crtime, o.pro_date)', $recentOrders, 'recent orders must preserve the original sale time after refund metadata updates');
paidReversalContractAssertNotContains('COALESCE(o.mdtime, o.crtime, o.pro_date)', $recentOrders, 'recent orders must not replace the sale time with the refund update time');

paidReversalContractAssertContains('reversePaidOrder', $posBarcodeJs, 'POS recent-orders UI should expose paid reversal action');
paidReversalContractAssertContains('ajax/refund_order.php', $posBarcodeJs, 'POS UI should call the paid reversal endpoint');
paidReversalContractAssertContains('refund_stock_policy', $posBarcodeJs, 'POS UI should send selected recipe stock policy');
paidReversalContractAssertContains('refund_payment_method', $posBarcodeJs, 'POS UI should send the cashier-selected refund tender');
paidReversalContractAssertContains('mutation_version: paidReversalState.mutationVersion', $posBarcodeJs, 'POS UI should send the recent-order mutation version');
paidReversalContractAssertContains('refund_external_reference', $posBarcodeJs, 'POS UI should preserve explicit external settlement references');
paidReversalContractAssertContains('pending_external_amount', $posBarcodeJs, 'POS UI should distinguish pending external settlement from completed settlement');
paidReversalContractAssertContains('refund_mode', $posBarcodeJs, 'POS UI should submit full, item, or amount selection mode');
paidReversalContractAssertContains('refund_amount', $posBarcodeJs, 'POS UI should submit amount partials explicitly');
paidReversalContractAssertContains('refund_lines', $posBarcodeJs, 'POS UI should submit selected line quantities');
paidReversalContractAssertContains('original_payments', $recentOrders, 'recent orders should expose original tender context without rewriting it');
paidReversalContractAssertContains('refund_tenders', $recentOrders, 'recent orders should expose enabled outgoing refund tenders');

paidReversalContractAssertContains('refund_eligible', $posBarcodeJs, 'active recent-orders JS renderer should consume refund eligibility');
paidReversalContractAssertContains('void_eligible', $posBarcodeJs, 'active recent-orders JS renderer should consume void eligibility');
paidReversalContractAssertContains('reverse-paid-order', $posBarcodeJs, 'active recent-orders JS renderer should expose paid reversal action');
paidReversalContractAssertContains('pos-action-locked', $posBarcodeJs, 'recent-orders actions should use locked styling when override is required');
paidReversalContractAssertContains('الطلب المدفوع يُعالج بالاسترداد، ولا يُحذف', $posBarcodeJs, 'cashier guidance should clearly distinguish refund from unpaid cancellation');

paidReversalContractAssertContains('ajax/refund_order.php', $runtimeTest, 'runtime test should execute the real paid reversal endpoint');
paidReversalContractAssertContains('CREATE DATABASE', $runtimeTest, 'runtime test should isolate endpoint writes in a temporary database');
paidReversalContractAssertContains('DROP DATABASE IF EXISTS', $runtimeTest, 'runtime test should clean up its temporary database');
paidReversalContractAssertContains('HTTP_X_CSRF_TOKEN', $runtimeTest, 'runtime test should provide endpoint CSRF header');
paidReversalContractAssertContains("'pos_browser' => \$csrf", $runtimeTest, 'runtime test should seed the POS browser CSRF namespace');
paidReversalContractAssertContains('pos_request_keys', $runtimeTest, 'runtime test should verify endpoint idempotency storage');
paidReversalContractAssertContains('order_events', $runtimeTest, 'runtime test should verify endpoint order event writes');
paidReversalContractAssertContains("'POSMAIN_SYNC_OUTBOX_ENABLED' => '1'", $runtimeTest, 'runtime test should prove required reversal and drawer outbox writes');

echo "recipe-paid-reversal-endpoint-contract-ok\n";

function paidReversalContractSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $source;
}

function paidReversalContractAssertContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message);
    }
}

function paidReversalContractAssertNotContains(string $needle, string $haystack, string $message): void
{
    if (strpos($haystack, $needle) !== false) {
        throw new RuntimeException($message);
    }
}
