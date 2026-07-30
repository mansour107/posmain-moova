<?php

$root = __DIR__ . '/../..';
$posJs = file_get_contents($root . '/js/pos_barcode.js');
$orderApi = file_get_contents($root . '/js/pos_order_api.js');
$mutationService = file_get_contents($root . '/classes/Pos/Service/PosOrderMutationService.php');

if ($posJs === false || $orderApi === false || $mutationService === false) {
    throw new RuntimeException('Unable to read POS split discount contract files');
}

posCashierSplitDiscountAssert(strpos($orderApi, 'function prorateMoneyByQuantity(') !== false, 'browser money kernel should prorate a partial line without binary floats');
posCashierSplitDiscountAssert(strpos($orderApi, 'function allocateProportionalMoney(') !== false, 'browser money kernel should allocate the header discount with the server rounding rule');
posCashierSplitDiscountAssert(strpos($posJs, 'money.allocateProportionalMoney(discount, selectedGross, orderGross)') !== false, 'split total should use exact aggregate discount allocation');
posCashierSplitDiscountAssert(strpos($posJs, 'money.compareDecimalStrings(totalPaid, payload.total, 2) !== 0') !== false, 'cashier must require exact tender-to-split-net equality');
posCashierSplitDiscountAssert(strpos($posJs, 'function refreshSplitPaymentLineAmounts()') !== false, 'split payment panel should refresh selected-row prices when discount changes');
posCashierSplitDiscountAssert(strpos($posJs, 'refreshSplitPaymentLineAmounts();') !== false, 'split mode should refresh its line amounts from the current discount');
posCashierSplitDiscountAssert(strpos($posJs, 'سداد الأصناف المحددة يستخدم طريقة دفع واحدة') === false, 'split payment should permit the mixed tenders supported by the server');

posCashierSplitDiscountAssert(strpos($mutationService, '$childDiscount = $this->allocateSplitHeaderDiscount(') !== false, 'server should authoritatively allocate the header discount');
posCashierSplitDiscountAssert(strpos($mutationService, '$childNet = $childGross->subtract($childDiscount);') !== false, 'server should charge the child net rather than gross');
posCashierSplitDiscountAssert(strpos($mutationService, '$remainingDiscount = $originalDiscount->subtract($childDiscount);') !== false, 'parent should retain only its exact remaining discount');
posCashierSplitDiscountAssert(strpos($mutationService, "'discount_amount' => \$childDiscount->toString()") !== false, 'split response and events should expose the allocated discount');

echo "pos-cashier-split-discount-contract-ok\n";

function posCashierSplitDiscountAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
