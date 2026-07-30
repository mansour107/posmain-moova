<?php

/**
 * Commercial V1 Step 2 contract: exact money, tax-off, tender rules, cash change facts.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/classes/Financial/FinancialMoneyInput.php';
require_once $root . '/classes/Financial/Money.php';
require_once $root . '/classes/Pos/Http/PosRequest.php';
require_once $root . '/classes/Financial/FinancialInvoicePostingService.php';
require_once $root . '/classes/Pos/Service/PaymentMethodService.php';

function step2ContractAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$floatRejected = false;
try {
    FinancialMoneyInput::money(12.5);
} catch (InvalidArgumentException $e) {
    $floatRejected = $e->getMessage() === 'FINANCIAL_DECIMAL_STRING_REQUIRED';
}
step2ContractAssert($floatRejected, 'money boundary must reject PHP floats');

$jsonFloatRejected = false;
try {
    new PosRequest(['paid' => 10.5, 'items' => [['qty' => 1.0]]]);
} catch (InvalidArgumentException $e) {
    $jsonFloatRejected = str_starts_with($e->getMessage(), 'JSON_FLOAT_MONEY_REJECTED');
}
step2ContractAssert($jsonFloatRejected, 'PosRequest must reject JSON floats');

$stringOk = FinancialMoneyInput::moneyString('10.50');
step2ContractAssert($stringOk === '10.50', 'canonical money strings must normalize to 2dp');
step2ContractAssert(
    FinancialDecimal::normalizeLegacy(0.0, 2, true) === '0.00',
    'trusted database read values must have an explicit float compatibility adapter'
);

$mutation = file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php');
step2ContractAssert(
    str_contains($mutation, 'NON_CASH_TENDER_EXCEEDS_REMAINING'),
    'takeaway payment must reject non-cash over-tender'
);
step2ContractAssert(
    str_contains($mutation, 'change_due') && str_contains($mutation, 'tendered_amount'),
    'mutation service must expose tendered/applied/change facts'
);
step2ContractAssert(
    str_contains($mutation, 'private function moneyFromStored')
        && str_contains($mutation, 'Money::fromLegacy')
        && str_contains($mutation, 'private function quantityStringFromStored')
        && str_contains($mutation, 'DecimalQuantity::fromLegacy')
        && str_contains($mutation, 'private function unitPriceStringFromStored')
        && str_contains($mutation, 'UnitPrice::fromLegacy')
        && str_contains($mutation, "\$catalogPrice = \$this->unitPriceStringFromStored(\$row['price1']"),
    'trusted database numerics must cross an explicit legacy adapter without weakening certified request rejection'
);

$invoice = file_get_contents($root . '/classes/Financial/FinancialInvoicePostingService.php');
step2ContractAssert(
    str_contains($invoice, 'TAX_DISABLED_NONZERO_TAX_REJECTED'),
    'invoice posting must fail closed on nonzero tax'
);

$schema = file_get_contents($root . '/classes/Sync/SchemaManager.php');
step2ContractAssert(
    str_contains($schema, "ADD COLUMN tendered_amount")
    && str_contains($schema, "ADD COLUMN applied_amount")
    && str_contains($schema, "ADD COLUMN change_due"),
    'order_payments schema must include cash settlement fact columns'
);

$paymentMethodService = file_get_contents($root . '/classes/Pos/Service/PaymentMethodService.php');
step2ContractAssert(
    str_contains($paymentMethodService, 'cash_drawer')
    && str_contains($paymentMethodService, 'manual_external')
    && str_contains($paymentMethodService, 'reference_required'),
    'tender settlement policies must remain explicit'
);

$deliveryStatusEndpoint = file_get_contents($root . '/ajax/delivery_status_update.php');
$refundEndpoint = file_get_contents($root . '/ajax/refund_order.php');
$overrideEndpoint = file_get_contents($root . '/ajax/pos_override_auth.php');
$inventoryMovement = file_get_contents($root . '/classes/Pos/Service/InventoryMovementService.php');
$cashFlowPeriod = file_get_contents($root . '/classes/Pos/Service/CashFlowPeriodService.php');
$shiftReport = file_get_contents($root . '/classes/ShiftReport.php');
$operationsReport = file_get_contents($root . '/classes/Pos/Service/OperationsReportService.php');
$recentOrdersEndpoint = file_get_contents($root . '/ajax/get_recent_orders.php');
$cashShiftWorkspace = file_get_contents($root . '/classes/Pos/Service/CashShiftWorkspaceService.php');
$tableAmountEndpoint = file_get_contents($root . '/ajax/get_table_amount.php');
$tableOrderEndpoint = file_get_contents($root . '/ajax/get_table_order.php');
$tableItemsEndpoint = file_get_contents($root . '/ajax/get_table_items.php');
$modifierLineService = file_get_contents($root . '/classes/Pos/Service/ModifierLineNoteService.php');
$tableBrowser = file_get_contents($root . '/js/pos_tables.js');
$loadOrderEndpoint = file_get_contents($root . '/ajax/load_order.php');
$cartRowRenderer = file_get_contents($root . '/includes/pos_cart_row.php');
$posContent = file_get_contents($root . '/includes/pos_content.php');
$tablesEndpoint = file_get_contents($root . '/ajax/get_tables.php');
$deliveryZonesEndpoint = file_get_contents($root . '/ajax/delivery_zones_list.php');
$categoryItemsEndpoint = file_get_contents($root . '/ajax/get_category_items.php');
$searchItemsEndpoint = file_get_contents($root . '/ajax/search_items.php');
$tableWorkspace = file_get_contents($root . '/tables.php');
$tablePaymentModal = file_get_contents($root . '/elements/pos/payment_modal.php');
$browserMoney = file_get_contents($root . '/js/pos_order_api.js');
$cashierBrowser = file_get_contents($root . '/js/pos_barcode.js');
$browserCapabilities = file_get_contents($root . '/js/posmain_capabilities.js');
$deliveryBrowser = file_get_contents($root . '/js/pos_delivery.js');
$deliveryBoardBrowser = file_get_contents($root . '/js/delivery_board.js');
$deliveryQueueBrowser = file_get_contents($root . '/js/pos_delivery_queue.js');
$shiftMovementBrowser = file_get_contents($root . '/js/pos_shift_expenses.js');
step2ContractAssert(
    str_contains($deliveryStatusEndpoint, 'FinancialMoneyInput::moneyString')
        && !str_contains($deliveryStatusEndpoint, '(float)'),
    'delivery COD and tip write boundary must reject floating-point amounts'
);
step2ContractAssert(
    str_contains($refundEndpoint, 'FinancialMoneyInput::money($refundAmount)->isPositive()')
        && !str_contains($refundEndpoint, '(float) $refundAmount'),
    'refund amount write boundary must validate an exact positive money string'
);
step2ContractAssert(
    str_contains($overrideEndpoint, 'FinancialMoneyInput::moneyString')
        && !str_contains($overrideEndpoint, '(float) $_POST'),
    'manager approval amount boundary must preserve exact money'
);
step2ContractAssert(
    str_contains($inventoryMovement, "RecipeDecimal::add(\$totals['det_value']")
        && !str_contains($inventoryMovement, '$totals[\'det_value\'] +=')
        && !str_contains($inventoryMovement, '(float) RecipeDecimal'),
    'inventory line value, COGS, profit, and quantity normalization must remain fixed-point'
);
step2ContractAssert(
    str_contains($cashFlowPeriod, 'FinancialDecimal::add')
        && str_contains($cashFlowPeriod, 'FinancialDecimal::subtract')
        && !str_contains($cashFlowPeriod, '(float)')
        && !str_contains($cashFlowPeriod, 'floatval')
        && !str_contains($cashFlowPeriod, 'array_sum'),
    'cash-flow, refund-custody, and drawer reconciliation totals must remain fixed-point'
);
step2ContractAssert(
    str_contains($shiftReport, 'Money::fromLegacy')
        && !str_contains($shiftReport, 'FinancialMoneyInput::'),
    'shift read models must normalize trusted database numerics without weakening write-boundary float rejection'
);
step2ContractAssert(
    str_contains($operationsReport, 'RoundingPolicy::halfUp')
        && str_contains($operationsReport, 'FinancialDecimal::compare')
        && str_contains($operationsReport, 'FinancialDecimal::normalizeLegacy')
        && !str_contains($operationsReport, '(float)')
        && !str_contains($operationsReport, 'number_format'),
    'canonical operating reports must preserve exact posted money and deterministic averages'
);
step2ContractAssert(
    str_contains($recentOrdersEndpoint, 'recentOrdersSubtractFloorZero')
        && str_contains($recentOrdersEndpoint, 'FinancialDecimal::compare')
        && !str_contains($recentOrdersEndpoint, '(float)')
        && !str_contains($recentOrdersEndpoint, 'floatval')
        && !str_contains($recentOrdersEndpoint, 'number_format'),
    'recent-order refund eligibility and remaining balances must be derived with exact decimals'
);
step2ContractAssert(
    str_contains($cashShiftWorkspace, 'FinancialDecimal::add')
        && !str_contains($cashShiftWorkspace, '(float)'),
    'cash-shift backlog variance amounts must remain fixed-point'
);
step2ContractAssert(
    str_contains($tableAmountEndpoint, 'tableAmountSubtractFloorZero')
        && str_contains($tableAmountEndpoint, 'FinancialDecimal::normalize')
        && !str_contains($tableAmountEndpoint, 'floatval')
        && !str_contains($tableAmountEndpoint, 'max(0,'),
    'table balance reads must preserve exact total, discount, paid, net, and remaining amounts'
);
step2ContractAssert(
    str_contains($tableOrderEndpoint, 'RecipeDecimal::divide')
        && str_contains($tableOrderEndpoint, 'Money::from')
        && !str_contains($tableOrderEndpoint, '(float)')
        && !str_contains($tableOrderEndpoint, 'floatval'),
    'table order reload must preserve exact quantities, prices, modifiers, and subtotals'
);
step2ContractAssert(
    str_contains($tableItemsEndpoint, 'LegacyOrderLinePresentationService')
        && str_contains($tableItemsEndpoint, 'Money::from')
        && !str_contains($tableItemsEndpoint, 'floatval'),
    'split-payment item reads must use sell-unit presentation and exact persisted line totals'
);
step2ContractAssert(
    str_contains($modifierLineService, 'FinancialDecimal::normalize')
        && str_contains($modifierLineService, 'RecipeDecimal::multiply')
        && !str_contains($modifierLineService, '(float)')
        && !str_contains($modifierLineService, 'number_format'),
    'modifier quantities, price deltas, and line totals must remain fixed-point'
);
step2ContractAssert(
    str_contains($tableBrowser, 'posTableSerializableItems')
        && str_contains($tableBrowser, 'posTableDecimalString')
        && str_contains($tableBrowser, 'items: posTableSerializableItems(currentOrder.items)')
        && str_contains($tableBrowser, 'posTableLineTotal')
        && str_contains($tableBrowser, 'moneyFromPercentage')
        && str_contains($tableBrowser, 'subtractDecimalStrings')
        && str_contains($tableBrowser, "qty: posTableQuantity(item.qty, '1')")
        && !str_contains($tableBrowser, 'Number(existingItem.qty)')
        && !str_contains($tableBrowser, 'Number(item.subtotal)')
        && !str_contains($tableBrowser, "parseFloat($('#discount')")
        && !str_contains($tableBrowser, "parseFloat($('#total')"),
    'table browser writes and all preceding cart/discount calculations must use the shared exact money kernel'
);
step2ContractAssert(
    str_contains($loadOrderEndpoint, 'RecipeDecimal::divide')
        && str_contains($loadOrderEndpoint, 'Money::from')
        && !str_contains($loadOrderEndpoint, '(float)')
        && !str_contains($loadOrderEndpoint, 'floatval'),
    'cashier order reload must preserve exact line and order money'
);
step2ContractAssert(
    str_contains($cartRowRenderer, 'RoundingPolicy::halfUp')
        && str_contains($cartRowRenderer, 'Money::from')
        && !str_contains($cartRowRenderer, '(float)')
        && !str_contains($cartRowRenderer, 'number_format'),
    'server-rendered edit cart rows must not lose unit-price or subtotal precision'
);
step2ContractAssert(
    str_contains($posContent, "Money::from(\$pos_initial_cart_total_value)")
        && !str_contains($posContent, '$pos_initial_cart_total_value +='),
    'server-rendered edit cart total must use exact money accumulation'
);
step2ContractAssert(
    str_contains($tablesEndpoint, "Money::from(\$row['fat_net']")
        && !str_contains($tablesEndpoint, "(float) \$row['fat_net']"),
    'table availability reads must expose exact active-order balances'
);
step2ContractAssert(
    str_contains($deliveryZonesEndpoint, "Money::from(\$row['fee']")
        && !str_contains($deliveryZonesEndpoint, "(float) \$row['fee']"),
    'delivery fee catalog reads must expose exact money strings'
);
foreach ([
    'category item prices' => $categoryItemsEndpoint,
    'search item prices' => $searchItemsEndpoint,
] as $surface => $source) {
    step2ContractAssert(
        str_contains($source, 'FinancialDecimal::normalize')
            && !str_contains($source, 'floatval')
            && !str_contains($source, '(float)'),
        $surface . ' must expose exact catalog price strings'
    );
}
step2ContractAssert(
    str_contains($tableWorkspace, '$netMoney = Money::from')
        && str_contains($tableWorkspace, "\$item['presented_total'] = Money::from")
        && !str_contains($tableWorkspace, "floatval(\$order_data['fat_total']")
        && !str_contains($tableWorkspace, "number_format(\$order_totals['total']"),
    'table workspace totals and persisted line values must render from exact posted decimals'
);
step2ContractAssert(
    str_contains($tableWorkspace, 'posTablePageMoneyApi().percentageFromMoney')
        && str_contains($tableWorkspace, 'allocateProportionalMoney(currentSplitOrderDiscount')
        && str_contains($tableWorkspace, 'mutation_version: currentSplitMutationVersion')
        && str_contains($tableWorkspace, 'posTablePageMutationActive[requestScope]')
        && str_contains($tableWorkspace, 'currentOrderRemainingAmount')
        && str_contains($tableWorkspace, 'id="modal_discount" value="0" min="0" step="0.01" readonly')
        && !str_contains($tableWorkspace, 'parseFloat')
        && !str_contains($tableWorkspace, 'Number(')
        && !str_contains($tableWorkspace, '.toFixed('),
    'legacy dine-in payment and split-payment UI must submit exact due amounts with optimistic concurrency and duplicate-click protection'
);
step2ContractAssert(
    str_contains($tablePaymentModal, 'tablePaymentMoneyApi().addDecimalStrings')
        && str_contains($tablePaymentModal, 'mutation_version: mutationVersion')
        && str_contains($tablePaymentModal, 'idempotency_key: getPOSTableIdempotencyKey(requestScope)')
        && str_contains($tablePaymentModal, 'paymentData.tenders')
        && str_contains($tablePaymentModal, 'posTablePaymentRequestActive')
        && str_contains($tablePaymentModal, 'tablePaymentMoney(response.remaining)')
        && str_contains($tablePaymentModal, 'id="payment_discount" step="0.01" readonly')
        && !str_contains($tablePaymentModal, 'parseFloat')
        && !str_contains($tablePaymentModal, 'Number(')
        && !str_contains($tablePaymentModal, '.toFixed('),
    'table-editor payment modal must use exact new-tender amounts, explicit tenders, idempotency, and expected versions'
);
step2ContractAssert(
    str_contains($tableAmountEndpoint, "'mutation_version' => \$mutationVersion")
        && str_contains($tableItemsEndpoint, "'mutation_version' => max(1")
        && str_contains($tableItemsEndpoint, "'order_discount' => Money::from"),
    'table payment reads must expose the exact due facts and mutation version used by certified writes'
);
step2ContractAssert(
    str_contains($browserMoney, 'function moneyFromPercentage')
        && str_contains($browserMoney, 'function percentageFromMoney')
        && str_contains($browserMoney, 'function quantityFromIntegerRatio')
        && str_contains($browserMoney, 'function prorateMoneyByQuantity'),
    'browser money kernel must own discount, scale-barcode, and partial-refund decimal calculations'
);
step2ContractAssert(
    str_contains($cashierBrowser, 'money.percentageFromMoney(discount, total)')
        && str_contains($cashierBrowser, 'money.moneyFromPercentage(total, percentage)')
        && str_contains($cashierBrowser, 'money.compareDecimalStrings(price, catalog, 6) !== 0')
        && str_contains($cashierBrowser, 'quantityFromIntegerRatio(weightStr, cfg.weight_divisor)')
        && str_contains($cashierBrowser, "quantity: quantity,")
        && !str_contains($cashierBrowser, 'parsedAmount - paidReversalState.remainingRefundableAmount')
        && !str_contains($cashierBrowser, 'quantity.toFixed(6)'),
    'cashier discount, price override, weighed item, and refund write boundaries must avoid binary floats'
);
step2ContractAssert(
    str_contains($browserCapabilities, 'decimal.compareDecimalStrings')
        && !str_contains($browserCapabilities, 'parseFloat(amount)')
        && !str_contains($browserCapabilities, 'parseFloat(row.limit_value)'),
    'money-affecting permission limits must compare exact decimal strings and fail closed'
);
step2ContractAssert(
    str_contains($deliveryBrowser, 'function deliveryMoney')
        && str_contains($deliveryBrowser, "fee: '0.00'")
        && str_contains($deliveryBrowser, "delivery_fee: deliveryMoney(state.fee)")
        && !str_contains($deliveryBrowser, 'parseFloat($option.data(\'fee\')')
        && !str_contains($deliveryBrowser, 'Number(window.posDeliveryState.fee)'),
    'delivery zone fees must remain exact strings from catalog selection through order submission'
);
step2ContractAssert(
    str_contains($deliveryBoardBrowser, "}, '0.00');")
        && str_contains($deliveryBoardBrowser, 'addDecimalStrings(sum')
        && !str_contains($deliveryBoardBrowser, 'Number(order.cod_amount'),
    'delivery COD summary must add exact server money strings'
);
step2ContractAssert(
    str_contains($deliveryQueueBrowser, 'compareDecimalStrings(money(value)')
        && !str_contains($deliveryQueueBrowser, 'Number(order.remaining_amount'),
    'cashier delivery settlement prompts must use exact remaining balances'
);
step2ContractAssert(
    str_contains($shiftMovementBrowser, 'compareDecimalStrings(money(value)')
        && !str_contains($shiftMovementBrowser, 'Number(summary.total'),
    'shift cash-movement visibility and totals must not reinterpret exact server money as floats'
);

echo "commercial-v1-step2-money-contract-ok\n";
