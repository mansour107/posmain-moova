<?php

$root = __DIR__ . '/../..';
$posContent = file_get_contents($root . '/includes/pos_content.php');
$posJs = file_get_contents($root . '/js/pos_barcode.js');
$orderApi = file_get_contents($root . '/js/pos_order_api.js');
$endpoint = file_get_contents($root . '/ajax/process_split_payment.php');
$dispatch = file_get_contents($root . '/includes/pos_api_dispatch.php');
$controller = file_get_contents($root . '/classes/Pos/Http/PosOrderController.php');
$sideEffects = file_get_contents($root . '/classes/Pos/Service/OrderMutationSideEffectsService.php');

if (
    $posContent === false
    || $posJs === false
    || $orderApi === false
    || $endpoint === false
    || $dispatch === false
    || $controller === false
    || $sideEffects === false
) {
    throw new RuntimeException('Unable to read POS split-payment contract files');
}

posCashierSplitAssert(strpos($posContent, 'pos_split_payment_enabled') !== false, 'payment modal should expose selected-item split toggle');
posCashierSplitAssert(strpos($posContent, "submitPOS('split_cash')") !== false, 'payment modal should submit selected-item payment action');
posCashierSplitAssert(strpos($posContent, 'pos-split-pay-confirm-btn" onclick="submitPOS(\'split_cash\');" style="display: none;"') !== false, 'selected-item payment button should start hidden until split mode is active');
posCashierSplitAssert(strpos($posJs, 'window.POSMainPrepareSplitPaymentFields') !== false, 'cashier JS should prepare selected-item split payload');
posCashierSplitAssert(strpos($posJs, 'pos_split_payment_payload') !== false, 'cashier JS should submit selected row payload');
posCashierSplitAssert(strpos($posJs, 'سداد الأصناف المحددة يستخدم طريقة دفع واحدة') !== false, 'cashier JS should guard single-method split payments');
posCashierSplitAssert(strpos($posJs, 'function updateSplitPaymentButtons()') !== false, 'cashier JS should hide full-payment action while selected-item mode is active');
posCashierSplitAssert(strpos($posJs, "action === 'cash' && \$('#pos_split_payment_enabled').prop('checked')") !== false, 'cashier JS should route active split mode away from full payment');
posCashierSplitAssert(strpos($posJs, "action = 'split_cash';") !== false, 'cashier JS should replace the full-payment action with split_cash at runtime');
posCashierSplitAssert(strpos($posJs, 'function paymentAmountDue()') !== false, 'cashier JS should derive amount due from split selection when enabled');
posCashierSplitAssert(strpos($posJs, 'splitPaymentPayloadFromModal().total') !== false, 'remaining balance should use selected split total instead of whole-order net');
posCashierSplitAssert(strpos($posJs, 'const change = totalPaid - amountDue') !== false, 'remaining balance should compare paid amount to the active receipt total');
posCashierSplitAssert(strpos($orderApi, "action === 'split_cash'") !== false, 'cashier API client should recognize split_cash as a routed payment action');
posCashierSplitAssert(strpos($orderApi, "return 'orders.split-payment';") !== false, 'cashier API client should target the canonical split-payment route');
posCashierSplitAssert(strpos($orderApi, 'ensureFormIdempotencyKey(form, action)') !== false, 'cashier API client should generate and retain an idempotency key for split submission');
posCashierSplitAssert(strpos($endpoint, "pos_api_dispatch(\$conn, 'orders.split-payment')") !== false, 'compatibility endpoint should delegate to the canonical dispatcher');
posCashierSplitAssert(strpos($dispatch, 'return $controller->splitPayment') !== false, 'dispatcher should route split payment to PosOrderController');
posCashierSplitAssert(strpos($controller, '$posMutationService->splitTablePayment') !== false, 'controller should route the selected-item mutation through splitTablePayment');
posCashierSplitAssert(strpos($controller, '(new OrderMutationSideEffectsService())->recordSplitPayment') !== false, 'controller should centralize split order/outbox side effects');
posCashierSplitAssert(strpos($controller, "'new_invoice_id' => \$newHeadId") !== false, 'controller should return the paid split child invoice');
posCashierSplitAssert(strpos($controller, "'print_url' => \$newHeadId > 0") !== false, 'controller should print the paid split child invoice');
posCashierSplitAssert(strpos($sideEffects, "'order.split_paid'") !== false, 'central side-effect service should emit the split-paid lifecycle event');

echo "pos-cashier-split-payment-contract-ok\n";

function posCashierSplitAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
