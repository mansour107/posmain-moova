<?php

require_once __DIR__ . '/Decimal.php';
require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/DecimalQuantity.php';
require_once __DIR__ . '/UnitPrice.php';
require_once __DIR__ . '/TaxRate.php';
require_once __DIR__ . '/RoundingPolicy.php';

/** The only pricing calculation used by certified POS order flows. */
final class FinancialPricingService
{
    public function price(array $lines, $orderDiscount = '0', array $tax = []): array
    {
        if (!$lines) {
            throw new InvalidArgumentException('ORDER_LINES_REQUIRED');
        }
        // VAT stays off until accountant-approved config enables it.
        require_once __DIR__ . '/../Accounting/TaxRoundingPolicy.php';
        $taxEnabled = TaxRoundingPolicy::isEnabled($tax);
        if (!$taxEnabled) {
            $tax = array_merge($tax, ['rate' => '0', 'inclusive' => false]);
        }
        $orderDiscount = Money::from($orderDiscount)->toString();
        $working = [];
        $unroundedSubtotal = '0.000000';
        foreach ($lines as $position => $line) {
            $quantity = DecimalQuantity::from($line['qty'] ?? $line['sell_qty'] ?? '0')->toString();
            $unitPrice = UnitPrice::from($line['price'] ?? '0')->toString();
            // Discount is explicitly a per-unit amount; this prevents the historic disagreement.
            $unitDiscount = UnitPrice::from($line['discount'] ?? '0')->toString();
            if (FinancialDecimal::compare($unitDiscount, $unitPrice, UnitPrice::SCALE) > 0) {
                throw new InvalidArgumentException('LINE_DISCOUNT_EXCEEDS_PRICE');
            }
            $gross = FinancialDecimal::multiply($quantity, $unitPrice, 6);
            $lineDiscount = FinancialDecimal::multiply($quantity, $unitDiscount, 6);
            $afterLineDiscount = FinancialDecimal::subtract($gross, $lineDiscount, 6);
            $unroundedSubtotal = FinancialDecimal::add($unroundedSubtotal, $afterLineDiscount, 6);
            $working[] = [
                'source' => $line,
                'position' => $position,
                'line_id' => (string) ($line['id'] ?? $line['detail_id'] ?? $position),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_discount' => $unitDiscount,
                'gross_unrounded' => $gross,
                'line_discount_unrounded' => $lineDiscount,
                'after_line_discount_unrounded' => $afterLineDiscount,
            ];
        }
        if (FinancialDecimal::compare($orderDiscount, RoundingPolicy::halfUp($unroundedSubtotal), Money::SCALE) > 0) {
            throw new InvalidArgumentException('ORDER_DISCOUNT_EXCEEDS_SUBTOTAL');
        }

        $allocations = $this->allocateOrderDiscount($working, $orderDiscount, $unroundedSubtotal);
        $totals = ['gross' => Money::zero(), 'discount' => Money::zero(), 'taxable' => Money::zero(), 'tax' => Money::zero(), 'net' => Money::zero()];
        $postedLines = [];
        foreach ($working as $index => $line) {
            $allocated = $allocations[$index];
            $taxable = FinancialDecimal::subtract($line['after_line_discount_unrounded'], $allocated, 6);
            $rate = $taxEnabled
                ? TaxRate::from($line['source']['tax_rate'] ?? $tax['rate'] ?? '0')->toString()
                : '0.000000';
            $inclusive = $taxEnabled && (bool) ($line['source']['tax_inclusive'] ?? $tax['inclusive'] ?? false);
            if ($inclusive && FinancialDecimal::compare($rate, '0', TaxRate::SCALE) > 0) {
                $netBase = bcdiv(bcmul($taxable, '100', 12), bcadd('100', $rate, 12), 6);
                $taxAmount = FinancialDecimal::subtract($taxable, $netBase, 6);
            } else {
                $netBase = $taxable;
                $taxAmount = bcdiv(bcmul($taxable, $rate, 12), '100', 6);
            }
            $grossPosted = Money::from(RoundingPolicy::halfUp($line['gross_unrounded']))->toString();
            $discountPosted = Money::from(RoundingPolicy::halfUp(FinancialDecimal::add($line['line_discount_unrounded'], $allocated, 6)))->toString();
            $taxablePosted = Money::from(RoundingPolicy::halfUp($taxable))->toString();
            $taxPosted = Money::from(RoundingPolicy::halfUp($taxAmount))->toString();
            $netPosted = Money::from(RoundingPolicy::halfUp($inclusive ? $taxable : FinancialDecimal::add($netBase, $taxAmount, 6)))->toString();
            $posted = array_merge($line['source'], [
                'qty' => $line['quantity'], 'price' => $line['unit_price'], 'discount' => $line['unit_discount'],
                'gross' => $grossPosted, 'line_discount' => Money::from(RoundingPolicy::halfUp($line['line_discount_unrounded']))->toString(),
                'allocated_order_discount' => Money::from(RoundingPolicy::halfUp($allocated))->toString(),
                'discount_total' => $discountPosted, 'taxable_amount' => $taxablePosted, 'tax_rate' => $rate,
                'tax_amount' => $taxPosted, 'net' => $netPosted,
            ]);
            $postedLines[] = $posted;
            $totals['gross'] = $totals['gross']->add(Money::from($grossPosted));
            $totals['discount'] = $totals['discount']->add(Money::from($discountPosted));
            $totals['taxable'] = $totals['taxable']->add(Money::from($taxablePosted));
            $totals['tax'] = $totals['tax']->add(Money::from($taxPosted));
            $totals['net'] = $totals['net']->add(Money::from($netPosted));
        }
        foreach ($totals as $key => $value) {
            $totals[$key] = $value->toString();
        }

        return ['lines' => $postedLines, 'totals' => $totals];
    }

    private function allocateOrderDiscount(array $lines, string $orderDiscount, string $subtotal): array
    {
        $count = count($lines);
        $allocations = array_fill(0, $count, '0.000000');
        if (FinancialDecimal::compare($orderDiscount, '0', Money::SCALE) === 0) {
            return $allocations;
        }
        $remaining = Money::from($orderDiscount)->toString();
        $remainders = [];
        foreach ($lines as $index => $line) {
            $raw = bcdiv(bcmul($orderDiscount, $line['after_line_discount_unrounded'], 12), $subtotal, 6);
            $rounded = RoundingPolicy::halfUp($raw);
            $allocations[$index] = $rounded;
            $remaining = FinancialDecimal::subtract($remaining, $rounded, Money::SCALE);
            $remainders[] = ['index' => $index, 'remainder' => FinancialDecimal::subtract($raw, $rounded, 6), 'line_id' => $line['line_id']];
        }
        usort($remainders, static function (array $left, array $right): int {
            $compare = FinancialDecimal::compare($right['remainder'], $left['remainder'], 6);
            return $compare !== 0 ? $compare : strcmp($left['line_id'], $right['line_id']);
        });
        $piaster = '0.01';
        while (FinancialDecimal::compare($remaining, '0', Money::SCALE) !== 0) {
            foreach ($remainders as $remainder) {
                if (FinancialDecimal::compare($remaining, '0', Money::SCALE) === 0) {
                    break;
                }
                $direction = FinancialDecimal::compare($remaining, '0', Money::SCALE) > 0 ? $piaster : '-0.01';
                $allocations[$remainder['index']] = FinancialDecimal::add($allocations[$remainder['index']], $direction, Money::SCALE);
                $remaining = FinancialDecimal::subtract($remaining, $direction, Money::SCALE);
            }
        }
        return $allocations;
    }
}
