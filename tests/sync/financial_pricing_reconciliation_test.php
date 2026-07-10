<?php

require_once __DIR__ . '/../../classes/Financial/FinancialPricingService.php';
require_once __DIR__ . '/../../classes/Financial/FinancialTenderAllocator.php';
require_once __DIR__ . '/../../classes/Financial/Money.php';
require_once __DIR__ . '/../../classes/Accounting/PaymentReconciliationService.php';

$pricing = new FinancialPricingService();

// Plan acceptance: price 35, qty 2, discount 1/unit → 68.00
$result = $pricing->price([
    ['id' => 10, 'qty' => '2', 'price' => '35', 'discount' => '1'],
]);
assertEq($result['totals']['gross'], '70.00', 'gross');
assertEq($result['totals']['discount'], '2.00', 'discount');
assertEq($result['totals']['net'], '68.00', 'net 68.00');
assertEq(PaymentReconciliationService::recomputeTableNetFromLines([
    ['id' => 10, 'qty' => '2', 'price' => '35', 'discount' => '1'],
]), '68.00', 'shared formula');

// Zero / one-cent / large / fractional / invalid
assertEq($pricing->price([['id' => 1, 'qty' => '1', 'price' => '0.01', 'discount' => '0']])['totals']['net'], '0.01', 'one cent');
assertEq($pricing->price([['id' => 1, 'qty' => '1', 'price' => '1500000.00', 'discount' => '0']])['totals']['net'], '1500000.00', 'high value');
assertEq($pricing->price([['id' => 1, 'qty' => '0.500000', 'price' => '10.000000', 'discount' => '0']])['totals']['net'], '5.00', 'fractional qty');

expectFail(static function () use ($pricing): void {
    $pricing->price([['id' => 1, 'qty' => 2.0, 'price' => '10', 'discount' => '0']]);
}, 'FINANCIAL_DECIMAL_STRING_REQUIRED');
expectFail(static function () use ($pricing): void {
    $pricing->price([['id' => 1, 'qty' => '1', 'price' => 'NaN', 'discount' => '0']]);
}, 'FINANCIAL_DECIMAL_INVALID');
expectFail(static function () use ($pricing): void {
    $pricing->price([['id' => 1, 'qty' => '-1', 'price' => '10', 'discount' => '0']]);
}, 'FINANCIAL_AMOUNT_NEGATIVE');

// Inclusive VAT — only when explicitly enabled (VAT is off by default).
$vat = $pricing->price([
    ['id' => 1, 'qty' => '1', 'price' => '115', 'discount' => '0', 'tax_rate' => '15', 'tax_inclusive' => true],
], '0', ['enabled' => true, 'rate' => '15', 'inclusive' => true]);
assertEq($vat['totals']['tax'], '15.00', 'inclusive VAT tax');
assertEq($vat['totals']['net'], '115.00', 'inclusive VAT net');

// Exclusive VAT when enabled
$ex = $pricing->price([
    ['id' => 1, 'qty' => '1', 'price' => '100', 'discount' => '0', 'tax_rate' => '14', 'tax_inclusive' => false],
], '0', ['enabled' => true]);
assertEq($ex['totals']['tax'], '14.00', 'exclusive VAT tax');
assertEq($ex['totals']['net'], '114.00', 'exclusive VAT net');

// Tax-disabled operation ignores line tax_rate
$disabled = $pricing->price([
    ['id' => 1, 'qty' => '1', 'price' => '100', 'discount' => '0', 'tax_rate' => '14'],
]);
assertEq($disabled['totals']['tax'], '0.00', 'tax disabled ignores rate');
assertEq($disabled['totals']['net'], '100.00', 'tax disabled net');

// Exempt / tax-disabled
$exempt = $pricing->price([
    ['id' => 1, 'qty' => '1', 'price' => '50', 'discount' => '0', 'tax_rate' => '0'],
]);
assertEq($exempt['totals']['tax'], '0.00', 'exempt tax');
assertEq($exempt['totals']['net'], '50.00', 'exempt net');

// Order discount largest-remainder across lines
$split = $pricing->price([
    ['id' => 2, 'qty' => '1', 'price' => '10', 'discount' => '0'],
    ['id' => 1, 'qty' => '1', 'price' => '10', 'discount' => '0'],
], '0.01');
assertEq($split['totals']['discount'], '0.01', 'allocated discount');
assertEq($split['totals']['net'], '19.99', 'header equals line sum');
$lineSum = Money::from($split['lines'][0]['net'])->add(Money::from($split['lines'][1]['net']))->toString();
assertEq($lineSum, $split['totals']['net'], 'posted lines sum to header');

// Many-line exact header
$many = [];
for ($i = 1; $i <= 11; $i++) {
    $many[] = ['id' => $i, 'qty' => '1', 'price' => '3.33', 'discount' => '0'];
}
$manyPriced = $pricing->price($many, '0.01');
$sum = Money::zero();
foreach ($manyPriced['lines'] as $line) {
    $sum = $sum->add(Money::from($line['net']));
}
assertEq($sum->toString(), $manyPriced['totals']['net'], 'many-line header exact');

echo "financial-pricing-reconciliation-ok\n";

function assertEq(string $actual, string $expected, string $label): void
{
    if ($actual !== $expected) {
        throw new RuntimeException("{$label}: expected {$expected}, got {$actual}");
    }
}

function expectFail(callable $fn, string $code): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e->getMessage() !== $code) {
            throw new RuntimeException("expected {$code}, got " . $e->getMessage());
        }
        return;
    }
    throw new RuntimeException("expected exception {$code}");
}
