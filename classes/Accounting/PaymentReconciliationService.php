<?php

require_once __DIR__ . '/../Recipe/RecipeDecimal.php';
require_once __DIR__ . '/TaxRoundingPolicy.php';

final class PaymentReconciliationService
{
    public static function assertOrderPaymentMatchesNet($orderNet, array $payments, int $scale = 6): void
    {
        $expected = RecipeDecimal::normalize($orderNet, $scale);
        $paid = RecipeDecimal::zero($scale);
        foreach ($payments as $payment) {
            $paid = RecipeDecimal::add($paid, $payment, $scale);
        }

        if (RecipeDecimal::compare($paid, $expected, 2) < 0) {
            throw new InvalidArgumentException('PAYMENT_LESS_THAN_NET');
        }
    }

    public static function recomputeTableNetFromLines(array $lines, $headerDiscount = '0', int $scale = 6): string
    {
        $subtotal = RecipeDecimal::zero($scale);
        foreach ($lines as $line) {
            $qty = RecipeDecimal::normalize($line['qty'] ?? $line['sell_qty'] ?? '0', $scale);
            $price = RecipeDecimal::normalize($line['price'] ?? '0', $scale);
            $discount = RecipeDecimal::normalize($line['discount'] ?? '0', $scale);
            $subtotal = RecipeDecimal::add(
                $subtotal,
                RecipeDecimal::multiply($qty, RecipeDecimal::subtract($price, $discount, $scale), $scale),
                $scale
            );
        }

        return RecipeDecimal::subtract($subtotal, RecipeDecimal::normalize($headerDiscount, $scale), $scale);
    }
}
